<?php

namespace Tests\Feature;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Models\PsxTrade;
use App\Models\User;
use App\Services\Psx\PsxPortfolioService;
use Database\Seeders\PsxSampleTradesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PsxModuleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('username', 'atif')->first());
    }

    private function sid(string $sym): int { return PsxStock::where('symbol', $sym)->value('id'); }
    private function broker(): PsxBroker { return PsxBroker::default(); }

    private function trade(string $date, array $items, ?int $brokerId = null): \Illuminate\Testing\TestResponse
    {
        return $this->post('/psx_trades', [
            'trade_date' => $date, 'psx_broker_id' => $brokerId ?? $this->broker()->id,
            'items' => array_map(fn ($i) => ['type' => $i[0], 'psx_stock_id' => $this->sid($i[1]), 'qty' => $i[2], 'price' => $i[3],
                'other_charges' => $i[4] ?? 0, 'commission' => 999999], $items),   // client commission must be ignored
        ]);
    }

    public function test_sample_data_and_all_pages_render(): void
    {
        $this->seed(PsxSampleTradesSeeder::class);
        $this->assertSame(22, PsxTrade::count());
        $r = app(PsxPortfolioService::class)->result();
        $this->assertSame([], $r['shortfalls']);
        $this->assertEqualsWithDelta(-42667.01, array_sum(array_column($r['matches'], 'net_pl')), 0.05);

        $t = PsxTrade::latest('id')->first();
        foreach (['/psx_stocks', '/psx_brokers', '/psx_trades', '/psx_trades/create', "/psx_trades/{$t->id}", "/psx_trades/{$t->id}/edit",
                  "/psx_trades/{$t->id}/print", '/reports/psx-holdings', '/reports/psx-holdings?symbol=TRG&broker_id=1',
                  '/reports/psx-profit-loss', '/reports/psx-profit-loss?tab=SW',
                  '/reports/psx-profit-loss?symbol=TRG&intraday=yes&from_date=2026-10-15&to_date=2026-10-31&broker_id=1'] as $url) {
            $res = $this->get($url);
            $this->assertSame(200, $res->status(), $url . ' => ' . substr(strip_tags($res->getContent()), 0, 800));
        }
        $csv = $this->get('/reports/psx-profit-loss?export=csv');
        $csv->assertOk();
        $this->assertStringContainsString('Net P/L', $csv->streamedContent());
        $this->assertSame(79, substr_count(trim($csv->streamedContent()), "\n")); // header + 79 matches
    }

    public function test_store_buy_then_sell_with_server_side_commission_and_intraday_waiver(): void
    {
        $this->trade('2026-11-10', [['buy', 'TRG', 2000, 50]])->assertRedirect();
        $this->trade('2026-11-12', [['buy', 'TRG', 1000, 52], ['sell', 'TRG', 2500, 55, 10]])->assertRedirect();

        $buy = DB::table('psx_trade_items')->where('type', 'buy')->orderBy('id')->first();
        $this->assertEquals(150.00, $buy->commission);              // max(0.05*2000=100, 0.15% of 100000=150)
        $this->assertEquals(100150.00, $buy->net_amount);

        $r = app(PsxPortfolioService::class)->result();
        $this->assertCount(2, $r['matches']);
        [$m1, $m2] = $r['matches'];
        // same-day buy is matched first (day trade: no buy commission), then the older lot
        $this->assertEquals(1000, $m1['qty']);  $this->assertTrue($m1['is_intraday']);
        $this->assertEquals(0, $m1['buy_commission']);
        $this->assertEquals(78, $m1['buy_waived']);                  // max(50, 0.15% of 52,000 = 78)
        $this->assertEquals(3000, $m1['gross_pl']);
        $this->assertEquals(1500, $m2['qty']);  $this->assertFalse($m2['is_intraday']);
        $this->assertEquals(7500, $m2['gross_pl']);
        $this->assertEquals(112.5, $m2['buy_commission']);           // 150 x 1500/2000
        $h = array_values($r['holdings']);
        $this->assertCount(1, $h);  $this->assertEquals(500, $h[0]['qty']);  $this->assertEquals(50, $h[0]['avg_cost']);

        $this->get('/psx_trades/2')->assertOk()->assertSee('Profit / loss on sales');
    }

    public function test_oversell_is_blocked(): void
    {
        $this->trade('2026-11-10', [['buy', 'TRG', 1000, 50]]);
        $this->trade('2026-11-11', [['sell', 'TRG', 1500, 55]])->assertSessionHasErrors('items');
        $this->assertSame(1, PsxTrade::count());
        // selling before the buy date is also blocked
        $this->trade('2026-11-09', [['sell', 'TRG', 100, 55]])->assertSessionHasErrors('items');
        // same shares at another broker are not available
        $b2 = PsxBroker::create(['name' => 'Other', 'min_commission_per_share' => 0.05, 'commission_rate' => 0.15, 'is_active' => true]);
        $this->trade('2026-11-11', [['sell', 'TRG', 100, 55]], $b2->id)->assertSessionHasErrors('items');
        // opening balance makes it possible
        $this->trade('2026-11-11', [['opening', 'TRG', 100, 40], ['sell', 'TRG', 100, 55]], $b2->id)->assertSessionHasNoErrors();
    }

    public function test_edit_and_delete_respect_later_sales(): void
    {
        $this->trade('2026-11-10', [['buy', 'TRG', 1000, 50]]);
        $this->trade('2026-11-11', [['sell', 'TRG', 800, 55]]);
        $buy = PsxTrade::first();

        $payload = fn ($qty) => ['trade_date' => '2026-11-10', 'psx_broker_id' => $this->broker()->id,
            'items' => [['type' => 'buy', 'psx_stock_id' => $this->sid('TRG'), 'qty' => $qty, 'price' => 50]]];

        $this->put("/psx_trades/{$buy->id}", $payload(500))->assertSessionHasErrors('items');
        $this->put("/psx_trades/{$buy->id}", $payload(900))->assertRedirect("/psx_trades/{$buy->id}")->assertSessionHasNoErrors();
        $this->assertEquals(900, $buy->items()->first()->qty);

        $this->delete("/psx_trades/{$buy->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted($buy);
        $sell = PsxTrade::latest('id')->first();
        $this->delete("/psx_trades/{$sell->id}")->assertRedirect('/psx_trades');
        $this->delete("/psx_trades/{$buy->id}")->assertRedirect('/psx_trades');
        $this->assertSame([], app(PsxPortfolioService::class)->rows());
        $this->assertSame('PSX-000003', PsxTrade::nextNumber());   // numbers are not reused
    }

    public function test_validation(): void
    {
        $this->post('/psx_trades', ['trade_date' => '', 'items' => []])->assertSessionHasErrors(['trade_date', 'psx_broker_id', 'items']);
        $this->trade('2026-11-10', [['buy', 'TRG', 0, 50]])->assertSessionHasErrors('items.0.qty');
    }

    public function test_masters_crud_and_delete_guards(): void
    {
        $this->post('/psx_stocks', ['symbol' => ' luck ', 'name' => 'Lucky Cement', 'is_active' => 1])->assertRedirect('/psx_stocks');
        $s = PsxStock::where('symbol', 'LUCK')->firstOrFail();
        $this->post('/psx_stocks', ['symbol' => 'LUCK'])->assertSessionHasErrors('symbol');
        $this->put("/psx_stocks/{$s->id}", ['symbol' => 'LUCK', 'name' => 'Lucky Cement Ltd'])->assertRedirect();
        $this->assertFalse($s->fresh()->is_active);

        $this->trade('2026-11-10', [['buy', 'TRG', 100, 50]]);
        $this->delete('/psx_stocks/' . $this->sid('TRG'))->assertSessionHas('error');
        $this->delete("/psx_stocks/{$s->id}")->assertSessionHas('success');

        $this->post('/psx_brokers', ['name' => 'Second', 'min_commission_per_share' => 0.03, 'commission_rate' => 0.1, 'is_default' => 1])->assertRedirect();
        $this->assertSame(1, PsxBroker::where('is_default', true)->count());
        $this->delete('/psx_brokers/' . PsxBroker::where('name', 'Default Broker')->value('id'))->assertSessionHas('error'); // has trades
    }

    public function test_no_accounting_side_effects_and_permissions(): void
    {
        $v = DB::table('vouchers')->count();
        $this->trade('2026-11-10', [['buy', 'TRG', 1000, 50]]);
        $this->trade('2026-11-11', [['sell', 'TRG', 1000, 55]]);
        $this->assertSame($v, DB::table('vouchers')->count());

        $u = User::factory()->create(['username' => 'nobody']);
        $this->actingAs($u)->get('/psx_trades')->assertForbidden();
        $this->actingAs($u)->post('/psx_trades', [])->assertForbidden();
    }

    /** Client's video: buy 1000 ANL @ 9 and sell @ 9.10 the same day. */
    public function test_intraday_trade_shows_no_buy_commission_and_blank_rows_are_ignored(): void
    {
        $this->post('/psx_trades', [
            'trade_date' => '2026-11-18', 'psx_broker_id' => $this->broker()->id,
            'items' => [
                ['type' => 'buy', 'psx_stock_id' => $this->sid('ANL'), 'qty' => 1000, 'price' => 9],
                ['type' => 'sell', 'psx_stock_id' => $this->sid('ANL'), 'qty' => 1000, 'price' => 9.10],
                ['type' => 'buy', 'psx_stock_id' => '', 'qty' => '', 'price' => ''],   // empty row
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $t = PsxTrade::firstOrFail();
        $this->assertCount(2, $t->items);

        $html = $this->get("/psx_trades/{$t->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Waived', $html);
        $this->assertStringNotContainsString('after intraday waiver', $html);
        $this->assertStringContainsString('Intraday: no buy commission on 1,000 sold same day', $html);
        // buy row: commission 0.00, net 9,000.00; sell row: commission 50.00, net 9,050.00
        $this->assertMatchesRegularExpression('/9\.0000<\/td>\s*<td[^>]*>9,000\.00<\/td>\s*<td[^>]*>\s*0\.00/', $html);
        $this->assertMatchesRegularExpression('/>\s*9,000\.00<\/td>\s*<td><small><\/small><\/td>/', $html);
        $this->assertStringContainsString('Net receivable from broker', $html);
        $this->assertMatchesRegularExpression('/Net receivable from broker<\/td>\s*<td[^>]*>50\.00/', $html);

        $m = app(PsxPortfolioService::class)->result()['matches'][0];
        $this->assertEquals(0, $m['buy_commission']);
        $this->assertEquals(50, $m['sell_commission']);
        $this->assertEquals(50, $m['net_pl']);   // 100 gross - 50 commission (one side only)

        // Same trade sold next day: commission on both sides
        $this->trade('2026-11-18', [['buy', 'KEL', 1000, 9]]);
        $this->trade('2026-11-19', [['sell', 'KEL', 1000, 9.10]]);

        // Day trade in a stock already held: same-day shares are matched, old shares stay
        $this->trade('2026-11-20', [['buy', 'ANL', 2000, 8]]);
        $this->trade('2026-11-21', [['buy', 'ANL', 1000, 9], ['sell', 'ANL', 1000, 9.10]]);
        $d = collect(app(PsxPortfolioService::class)->result()['matches'])->where('symbol', 'ANL')->last();
        $this->assertTrue($d['is_intraday']);
        $this->assertEquals(9, $d['buy_price']);
        $this->assertEquals(0, $d['buy_commission']);
        $k = collect(app(PsxPortfolioService::class)->result()['matches'])->firstWhere('symbol', 'KEL');
        $this->assertEquals(50, $k['buy_commission']);
        $this->assertEquals(0, $k['net_pl']);
    }

    public function test_dashboard_all_periods_filters_and_empty_state(): void
    {
        $this->get('/reports/psx-dashboard')->assertOk()->assertSee('No shares sold in this period.')->assertSee('No trades yet.');

        $this->seed(PsxSampleTradesSeeder::class);
        foreach (['today', 'week', 'month', '30d', 'year', 'all'] as $p) {
            $this->get("/reports/psx-dashboard?period=$p")->assertOk();
        }
        $this->get('/reports/psx-dashboard?period=custom&from_date=2026-11-01&to_date=2026-11-05&broker_id=1')->assertOk();

        // all-time figures match the P/L service
        $r = app(PsxPortfolioService::class)->result();
        $net = array_sum(array_column($r['matches'], 'net_pl'));
        $html = $this->get('/reports/psx-dashboard?period=all')->assertOk()
            ->assertSee('Realised net P/L by selling day')->assertSee('Module summary')->getContent();
        $this->assertStringContainsString('Rs ' . number_format(abs($net)), $html);
        $this->assertStringContainsString(count($r['matches']) . ' closed', $html);

        // custom range only counts sells inside it
        $inRange = array_filter($r['matches'], fn ($m) => $m['sell_date'] >= '2026-11-03' && $m['sell_date'] <= '2026-11-03');
        $this->get('/reports/psx-dashboard?period=custom&from_date=2026-11-03&to_date=2026-11-03')
            ->assertSee(count($inRange) . ' closed');

        $u = User::factory()->create(['username' => 'nobody2']);
        $this->actingAs($u)->get('/reports/psx-dashboard')->assertForbidden();
    }
}
