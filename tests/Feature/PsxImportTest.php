<?php

namespace Tests\Feature;

use App\Models\PsxStock;
use App\Models\PsxTrade;
use App\Models\User;
use App\Services\Psx\PsxPortfolioService;
use Database\Seeders\PsxSampleTradesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PsxImportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::where('username', 'atif')->first());
    }

    /** Builds an .xlsx in the client's layout. Row: [stock, buyDate, buyPrice, buyQty, sellDate, sellPrice, sellQty] */
    private function sheet(array $rows, ?array $headings = null): UploadedFile
    {
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle('PSX Trade Calculator');
        $ws->fromArray($headings ?? ['SNO.', 'Stock Name', 'Purchase Date', 'Purchase Price (Rs)', 'Purchase Qty', 'Selling Date', 'Selling Price (Rs)', 'Selling Qty', 'Matched Qty'], null, 'A1');
        foreach ($rows as $i => $r) {
            $n = $i + 2;
            $ws->setCellValue("A$n", $i + 1);
            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $k => $col) {
                $v = $r[$k] ?? null;
                if (in_array($col, ['C', 'F']) && is_string($v) && preg_match('/^\d{4}-\d\d-\d\d$/', $v)) {
                    $v = ExcelDate::PHPToExcel(new \DateTime($v));          // real Excel date cell
                    $ws->getStyle("$col$n")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }
                $ws->setCellValue("$col$n", $v);
            }
            $ws->setCellValue("I$n", "=IF(OR(E$n=\"\",H$n=\"\"),\"\",MIN(E$n,H$n))");   // formula columns are ignored
        }
        $path = tempnam(sys_get_temp_dir(), 'psx') . '.xlsx';
        (new Xlsx($book))->save($path);
        return new UploadedFile($path, 'psx_trading_calculator.xlsx', null, null, true);
    }

    private function goodRows(): array
    {
        return [
            ['BFBIO', '2026-10-29', 123.85, 1000, '2026-10-30', 127.5, 1000],    // delivery
            ['PASL', '2026-10-30', 5.15, 10000, '2026-10-30', 5.39, 10000],      // intraday
            ['newco', '2026-11-04', 11.96, 2500],                                // still held, new stock, lower case
            ['KEL', '30/10/2026', 5.86, 25000, '05/11/2026', '6.10', '25,000'],  // text date + text numbers
        ];
    }

    private function preview(UploadedFile $file, string $mode = 'replace')
    {
        return $this->post('/psx_trades/import/preview', ['file' => $file, 'psx_broker_id' => 1, 'mode' => $mode]);
    }

    private function token($res): array
    {
        $html = $res->getContent();
        preg_match('/name="token" value="([^"]+)"/', $html, $t);
        preg_match('/name="ext" value="([^"]+)"/', $html, $e);
        return ['token' => $t[1] ?? '', 'ext' => $e[1] ?? 'xlsx'];
    }

    public function test_replace_import_clears_old_trades_and_restarts_numbers(): void
    {
        $this->seed(PsxSampleTradesSeeder::class);
        $this->assertSame(22, PsxTrade::count());
        $this->get('/psx_trades/import')->assertOk()->assertSee('22 trades');

        $res = $this->preview($this->sheet($this->goodRows()))->assertOk()
            ->assertSee('The sheet is ready to import')
            ->assertSee('NEWCO')
            ->assertSee('Delete all <strong>22 existing PSX trades</strong>', false);
        $form = $this->token($res);

        // replace without ticking the box is refused
        $this->post('/psx_trades/import/confirm', $form + ['psx_broker_id' => 1, 'mode' => 'replace'])->assertSessionHasErrors('confirm');
        $this->assertSame(22, PsxTrade::count());

        $this->post('/psx_trades/import/confirm', $form + ['psx_broker_id' => 1, 'mode' => 'replace', 'confirm' => 1])
            ->assertRedirect('/psx_trades')->assertSessionHas('success');

        // 4 dates: 29 Oct, 30 Oct, 4 Nov, 5 Nov -> 4 trades, numbered from 1 again
        $this->assertSame(4, PsxTrade::count());
        $this->assertSame(['PSX-000001', 'PSX-000002', 'PSX-000003', 'PSX-000004'], PsxTrade::orderBy('id')->pluck('trade_no')->all());
        $this->assertSame(['2026-10-29', '2026-10-30', '2026-11-04', '2026-11-05'],
            PsxTrade::orderBy('id')->get()->map(fn ($t) => $t->trade_date->toDateString())->all());
        $this->assertTrue(PsxStock::where('symbol', 'NEWCO')->exists());
        $this->assertSame([], Storage::disk('local')->files('psx-imports'));   // upload removed

        $r = app(PsxPortfolioService::class)->result();
        $this->assertCount(3, $r['matches']);
        $pasl = collect($r['matches'])->firstWhere('symbol', 'PASL');
        $this->assertTrue($pasl['is_intraday']);
        $this->assertEquals(0, $pasl['buy_commission']);
        $kel = collect($r['matches'])->firstWhere('symbol', 'KEL');
        $this->assertEquals(25000, $kel['qty']);
        $this->assertEquals(6.10, $kel['sell_price']);
        $this->assertSame(['NEWCO'], array_column($r['holdings'], 'symbol'));

        // next manual trade continues the numbering
        $this->assertSame('PSX-000005', PsxTrade::nextNumber());
    }

    public function test_append_mode_keeps_existing_trades(): void
    {
        $this->post('/psx_trades/import/confirm', ['token' => '00000000-0000-0000-0000-000000000000', 'ext' => 'xlsx', 'psx_broker_id' => 1, 'mode' => 'append'])
            ->assertRedirect('/psx_trades/import')->assertSessionHas('error');   // expired / unknown upload

        $this->post('/psx_trades', ['trade_date' => '2026-10-01', 'psx_broker_id' => 1,
            'items' => [['type' => 'buy', 'psx_stock_id' => PsxStock::where('symbol', 'TRG')->value('id'), 'qty' => 100, 'price' => 50]]]);
        $res = $this->preview($this->sheet($this->goodRows()), 'append')->assertOk()->assertSee('will be added to the 1 existing trades');
        $this->post('/psx_trades/import/confirm', $this->token($res) + ['psx_broker_id' => 1, 'mode' => 'append'])->assertRedirect('/psx_trades');
        $this->assertSame(5, PsxTrade::count());
    }

    public function test_problems_are_listed_and_block_the_import(): void
    {
        $res = $this->preview($this->sheet([
            ['', '2026-10-29', 10, 100],                               // no stock name
            ['ABC', '2026-10-29', 10],                                 // missing purchase qty
            ['ABC', 'next week', 10, 100],                             // not a date
            ['ABC', '2026-10-29', -5, 100],                            // bad price
            ['XYZ', '2026-10-29', 10, 100, '2026-10-30', 11, 500],    // sells more than bought
        ]))->assertOk();
        $html = $res->getContent();
        foreach (['Row 2: stock name is empty.', 'Row 3 (ABC): purchase side is incomplete, missing purchase qty.',
                  'Row 4 (ABC): purchase date &quot;next week&quot; is not a date.', 'Row 5 (ABC): purchase price must be a number above zero.'] as $msg) {
            $this->assertStringContainsString($msg, $html, $msg);
        }
        $this->assertStringNotContainsString('id="confirmForm"', $html);

        // only the oversell left: reported by the holdings check
        $res = $this->preview($this->sheet([['XYZ', '2026-10-29', 10, 100, '2026-10-30', 11, 500]]))->assertOk();
        $res->assertSee('XYZ: sale of 500 on 30-Oct-2026 (Excel row 2) is more than the 100 shares held at that date.');
        $this->assertSame(0, PsxTrade::count());
    }

    public function test_wrong_file_and_permissions(): void
    {
        $this->preview($this->sheet([['A', 'B']], ['Name', 'Amount', 'Date']))->assertOk()
            ->assertSee('Please upload the PSX Trade Calculator sheet');
        $this->post('/psx_trades/import/preview', ['file' => UploadedFile::fake()->create('notes.pdf', 10), 'psx_broker_id' => 1, 'mode' => 'replace'])
            ->assertSessionHasErrors('file');

        $u = User::factory()->create(['username' => 'nobody']);
        $this->actingAs($u)->get('/psx_trades/import')->assertForbidden();
        $this->actingAs($u)->post('/psx_trades/import/confirm', [])->assertForbidden();
    }

    public function test_backup_export(): void
    {
        $this->seed(PsxSampleTradesSeeder::class);
        $csv = $this->get('/psx_trades/export')->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $this->assertStringStartsWith('"Trade #",Date,Broker', $lines[0]);
        $this->assertCount(1 + 181, $lines);   // heading + 180 Excel rows + 1 opening balance
    }

    public function test_each_sale_is_paired_with_the_purchase_on_its_row(): void
    {
        // Two TRG purchases; row 3's sale belongs to row 3's (later, dearer) purchase, as in the sheet
        $res = $this->preview($this->sheet([
            ['TRG', '2026-11-02', 50, 1000],
            ['TRG', '2026-11-03', 60, 1000, '2026-11-05', 58, 1000],
        ]))->assertOk();
        $this->post('/psx_trades/import/confirm', $this->token($res) + ['psx_broker_id' => 1, 'mode' => 'replace', 'confirm' => 1])->assertRedirect('/psx_trades');

        $this->assertSame(1, \App\Models\PsxTradeItem::whereNotNull('buy_item_id')->count());
        $r = app(PsxPortfolioService::class)->result();
        $this->assertEquals(60, $r['matches'][0]['buy_price']);          // not FIFO's 50
        $this->assertEquals(-2000, $r['matches'][0]['gross_pl']);
        $this->assertEquals(50, array_values($r['holdings'])[0]['avg_price']);

        // replacing again works with linked rows present
        $res = $this->preview($this->sheet([['KEL', '2026-11-02', 9, 1000]]))->assertOk();
        $this->post('/psx_trades/import/confirm', $this->token($res) + ['psx_broker_id' => 1, 'mode' => 'replace', 'confirm' => 1])->assertRedirect('/psx_trades');
        $this->assertSame(1, \App\Models\PsxTradeItem::count());
    }
}
