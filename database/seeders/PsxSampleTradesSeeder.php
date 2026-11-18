<?php

namespace Database\Seeders;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Models\PsxTrade;
use App\Services\Psx\DemoTradeData;
use App\Services\Psx\TradeMatcher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL: loads the trades from the client's Excel sheet as real PSX trades
 * (one trade per date, default broker), so the reports have data for a demo.
 *
 *   php artisan db:seed --class=PsxSampleTradesSeeder
 *
 * Runs only when there are no PSX trades yet. Remove them with
 * "Delete" on the Trades screen, or by truncating psx_trade_items / psx_trades.
 */
class PsxSampleTradesSeeder extends Seeder
{
    public function run(): void
    {
        if (PsxTrade::withTrashed()->exists()) {
            $this->command?->warn('PSX trades already exist. Sample trades were not loaded.');
            return;
        }

        $broker = PsxBroker::default();
        if (!$broker) {
            $this->command?->error('No active broker. Run PsxModuleSeeder first.');
            return;
        }
        $matcher = TradeMatcher::fromBroker($broker);

        $rows   = collect(DemoTradeData::all())->groupBy('date')->sortKeys();
        $stocks = PsxStock::withTrashed()->pluck('id', 'symbol');

        DB::transaction(function () use ($rows, $stocks, $broker, $matcher) {
            foreach ($rows as $date => $dayRows) {
                $trade = PsxTrade::create([
                    'trade_no'      => PsxTrade::nextNumber(),
                    'trade_date'    => $date,
                    'psx_broker_id' => $broker->id,
                    'remarks'       => 'Sample data from Excel',
                ]);

                foreach ($dayRows as $r) {
                    $stockId = $stocks[$r['symbol']] ?? ($stocks[$r['symbol']] = PsxStock::create(['symbol' => $r['symbol'], 'is_active' => true])->id);
                    $value = round($r['qty'] * $r['price'], 2);
                    $comm  = $r['type'] === 'opening' ? 0 : $matcher->commission($r['qty'], $r['price']);

                    $trade->items()->create([
                        'type'          => $r['type'],
                        'psx_stock_id'  => $stockId,
                        'qty'           => $r['qty'],
                        'price'         => $r['price'],
                        'value'         => $value,
                        'commission'    => $comm,
                        'other_charges' => 0,
                        'net_amount'    => $r['type'] === 'sell' ? $value - $comm : $value + $comm,
                        'remarks'       => $r['remarks'],
                    ]);
                }
            }
        });

        $this->command?->info('Loaded ' . count(DemoTradeData::all()) . ' sample trade rows in ' . $rows->count() . ' trades.');
    }
}
