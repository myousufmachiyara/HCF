<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PsxSampleTradesSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Main dashboard. Builds a small business through the real screens
 * (purchases, sales, returns), then checks every dashboard figure against
 * hand-worked numbers and against the existing reports.
 */
class MainDashboardTest extends TestCase
{
    use DatabaseMigrations;

    protected bool $seed = true;
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->actingAs(User::where('username', 'atif')->first());
    }

    private function account(string $code, string $name, string $type, int $shoa): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'account_code' => $code, 'shoa_id' => $shoa, 'name' => $name, 'account_type' => $type,
            'receivables' => 0, 'payables' => 0, 'credit_limit' => 0, 'opening_date' => '2026-01-01',
            'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(string $name, int $unit, bool $active = true): int
    {
        return DB::table('products')->insertGetId([
            'category_id' => $this->ids['cat'], 'name' => $name, 'sku' => strtoupper(substr($name, 0, 4)) . rand(100, 999),
            'opening_stock' => 0, 'selling_price' => 0, 'measurement_unit' => $unit, 'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A small business: 3 purchases, 4 sales (1 cash), 1 sale return, 1 purchase return. */
    private function buildBusiness(): void
    {
        // PurchaseReturnController saves purchase_invoice_id, but no migration in the repo creates it
        // (the live database presumably has it). Add it here so purchase returns can be tested.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('purchase_return_items', 'purchase_invoice_id')) {
            \Illuminate\Support\Facades\Schema::table('purchase_return_items', fn ($t) => $t->unsignedBigInteger('purchase_invoice_id')->nullable());
        }
        $this->ids['cat'] = DB::table('product_categories')->insertGetId(['name' => 'Building', 'code' => 'BLD', 'created_at' => now(), 'updated_at' => now()]);
        $c1 = $this->account('103001', 'Ali Traders', 'customer', 3);
        $c2 = $this->account('103002', 'Walk-in Customer', 'customer', 3);
        $c3 = $this->account('103003', 'Bilal Builders', 'customer', 3);
        $v1 = $this->account('201101', 'Lucky Suppliers', 'vendor', 5);
        $v2 = $this->account('201102', 'Paint House', 'vendor', 5);
        $p1 = $this->product('Cement Bag', 4);
        $p2 = $this->product('Steel Rod', 1);
        $p3 = $this->product('Paint Tin', 3);
        $p4 = $this->product('Tile Box', 3);              // never bought: out of stock
        $this->product('Old Item', 3, false);             // inactive: ignored
        $this->ids += compact('c1', 'c2', 'c3', 'v1', 'v2', 'p1', 'p2', 'p3', 'p4');

        $pi = fn ($date, $vendor, $items) => $this->post('/purchase_invoices', [
            'invoice_date' => $date, 'vendor_id' => $vendor, 'bill_no' => 'B-' . $date,
            'items' => array_map(fn ($i) => ['item_id' => $i[0], 'quantity' => $i[1], 'unit' => $i[2], 'price' => $i[3]], $items),
        ])->assertSessionHasNoErrors();
        $pi('2026-10-05', $v1, [[$p1, 100, 4, 1000], [$p2, 500, 1, 200]]);   // 200,000
        $pi('2026-11-03', $v2, [[$p3, 50, 3, 1500]]);                        //  75,000
        $pi('2026-11-10', $v1, [[$p1, 50, 4, 1100]]);                        //  55,000

        $si = fn ($date, $acc, $type, $items, $disc = 0, $paid = null) => $this->post('/sale_invoices', array_filter([
            'date' => $date, 'account_id' => $acc, 'type' => $type, 'discount' => $disc,
            'items' => array_map(fn ($i) => ['product_id' => $i[0], 'quantity' => $i[1], 'sale_price' => $i[2]], $items),
            'payment_account_id' => $paid ? 1 : null, 'amount_received' => $paid,
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();
        $si('2026-10-20', $c1, 'credit', [[$p1, 20, 1300]]);                          // 26,000 (October)
        $si('2026-11-05', $c1, 'credit', [[$p1, 30, 1350], [$p2, 100, 260]], 500);   // 66,000
        $si('2026-11-12', $c2, 'cash', [[$p3, 10, 2000]], 0, 20000);                  // 20,000 cash, paid
        $si('2026-11-18', $c3, 'credit', [[$p3, 40, 1900]]);                          // 76,000

        $this->post('/sale_return', ['customer_id' => $c1, 'return_date' => '2026-11-15',
            'items' => [['product_id' => $p2, 'qty' => 10, 'price' => 260]]])->assertSessionHasNoErrors();     // 2,600
        $pi3 = DB::table('purchase_invoices')->orderByDesc('id')->value('id');
        $this->post('/purchase_return', ['vendor_id' => $v1, 'return_date' => '2026-11-16',
            'items' => [['item_id' => $p1, 'invoice_id' => $pi3, 'quantity' => 5, 'unit' => 4, 'price' => 1100]]])->assertSessionHasNoErrors(); // 5,500
    }

    public function test_dashboard_figures_match_hand_worked_numbers_and_reports(): void
    {
        $this->buildBusiness();
        $this->seed(PsxSampleTradesSeeder::class);

        $res = $this->get('/')->assertOk();   // default: this month (1-21 Nov)
        $s = $res->viewData('sales');
        $this->assertSame(3, $s['count']);
        $this->assertEquals(162000, $s['net']);
        $this->assertEquals(500, $s['discount']);
        $this->assertEquals(20000, $s['cash']);
        $this->assertEquals(142000, $s['credit']);
        $this->assertSame('Bilal Builders', $s['customers'][0]['name']);   // 76,000
        $this->assertSame('Paint Tin', $s['products'][0]->name);           // 96,000

        $p = $res->viewData('purchases');
        $this->assertSame(2, $p['count']);
        $this->assertEquals(130000, $p['total']);

        $r = $res->viewData('returns');
        $this->assertEquals(['count' => 1, 'total' => 2600.0], $r['sale']);
        $this->assertEquals(['count' => 1, 'total' => 5500.0], $r['purchase']);

        $inv = $res->viewData('inventory');
        $this->assertSame(4, $inv['active']);
        $this->assertSame(2, $inv['in_stock']);                               // cement 95, steel 410
        $this->assertEqualsCanonicalizing(['Paint Tin', 'Tile Box'], $inv['out_of_stock']->pluck('name')->all());
        $this->assertEquals(95 + 410, $inv['units']);

        // Receivables / payables / profit agree with the Accounts reports
        $a = $res->viewData('accounts');
        $acc = $this->get('/reports/accounts?from_date=2026-11-01&to_date=2026-11-21')->assertOk()->viewData('reports');
        $num = fn ($v) => (float) str_replace(',', '', $v);
        $this->assertEqualsWithDelta(collect($acc['receivables'])->sum(fn ($x) => $num($x[1])), $a['receivables'], 0.01);
        $this->assertEqualsWithDelta(collect($acc['payables'])->sum(fn ($x) => $num($x[1])), $a['payables'], 0.01);
        $this->assertEqualsWithDelta(89400 + 76000, $a['receivables'], 0.01);   // Ali 26,000+66,000-2,600; Bilal 76,000; walk-in paid
        $this->assertEqualsWithDelta(249500 + 75000, $a['payables'], 0.01);     // Lucky 200,000+55,000-5,500; Paint House 75,000
        $netRow = collect($acc['profit_loss'])->first(fn ($x) => $x[0] === 'NET PROFIT / LOSS');
        $this->assertEqualsWithDelta($num($netRow[1]), $a['net'], 0.01);
        $this->assertEquals(20000, $a['cash']);

        // Stock value = Stock in Hand account balance
        $tb = collect($this->get('/reports/accounts?from_date=2026-01-01&to_date=2026-11-21')->viewData('reports')['trial_balance'])
            ->first(fn ($x) => $x[0] === 'Stock in Hand');
        $this->assertEqualsWithDelta($num($tb[2]) - $num($tb[3]), $inv['value'], 0.01);

        // All time includes October
        $all = $this->get('/?period=all')->assertOk();
        $this->assertEquals(188000, $all->viewData('sales')['net']);
        $this->assertEquals(330000, $all->viewData('purchases')['total']);
        $this->assertNotEmpty($all->viewData('psx'));

        // Custom range
        $c = $this->get('/?period=custom&from_date=2026-11-05&to_date=2026-11-12')->assertOk();
        $this->assertEquals(86000, $c->viewData('sales')['net']);
        $this->assertEquals(55000, $c->viewData('purchases')['total']);

        foreach (['today', 'week', '30d', 'year'] as $period) {
            $this->get("/?period=$period")->assertOk();
        }

        $html = $this->get('/')->getContent();
        foreach (['Net sales', 'Rs 162,000', 'Sales vs purchases by day', 'Who owes you', 'Ali Traders', 'Out of stock', 'Tile Box', 'Modules', 'PSX Trading'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        if ($path = env('DUMP_DEMO_DB')) {
            @unlink($path);
            DB::statement('VACUUM INTO ?', [$path]);
        }
    }

    public function test_empty_database_shows_empty_states(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('No sales or purchases in this period.')
            ->assertSee('No sale invoices yet.')
            ->assertSee('Nothing outstanding.');
    }

    public function test_sections_follow_permissions(): void
    {
        $this->buildBusiness();

        $role = Role::create(['name' => 'sales_clerk', 'guard_name' => 'web']);
        $role->givePermissionTo(['sale_invoices.index', 'sale_invoices.create']);
        $clerk = User::factory()->create(['username' => 'clerk']);
        $clerk->assignRole($role);

        $res = $this->actingAs($clerk)->get('/')->assertOk();
        $this->assertNotNull($res->viewData('sales'));
        foreach (['purchases', 'accounts', 'inventory', 'returns', 'psx'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $res->viewData('can') ? array_filter($res->viewData('can')) : []);
        }
        $full = $res->getContent();
        $start = strpos($full, '<div class="home-dash">');
        $html = substr($full, $start, strpos($full, '<script>', $start) - $start);   // visible dashboard only
        $this->assertStringContainsString('Net sales', $html);
        foreach (['Receivables', 'Who owes you', 'Purchases', 'Out of stock', 'PSX Trading'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }

        $nobody = User::factory()->create(['username' => 'nobody']);
        $this->actingAs($nobody)->get('/')->assertOk()->assertSee('Your role has no modules on the dashboard yet.');
    }
}
