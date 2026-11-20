<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Services\Psx\PsxPortfolioService;
use App\Services\Psx\TradeMatcher;
use Illuminate\Http\Request;

/**
 * PSX Holdings and Profit/Loss reports, built from saved trades (read only).
 */
class PsxReportController extends Controller
{
    public function __construct(private PsxPortfolioService $portfolio) {}

    public function holdings(Request $request)
    {
        $brokerId = $request->integer('broker_id') ?: null;
        $symbol   = $request->input('symbol');

        $holdings = array_filter(
            $this->portfolio->result($brokerId)['holdings'],
            fn ($h) => !$symbol || $h['symbol'] === $symbol
        );

        $names = PsxStock::withTrashed()->pluck('name', 'symbol');
        foreach ($holdings as &$h) {
            $h['stock_name'] = $names[$h['symbol']] ?? null;
        }
        unset($h);

        return view('psx.reports.holdings', [
            'holdings' => $holdings,
            'totals'   => [
                'cost'       => array_sum(array_column($holdings, 'cost_value')),
                'commission' => array_sum(array_column($holdings, 'commission')),
            ],
            'brokers'  => PsxBroker::orderBy('name')->get(['id', 'name']),
            'symbols'  => PsxStock::orderBy('symbol')->pluck('symbol'),
            'filters'  => ['broker_id' => $brokerId, 'symbol' => $symbol],
        ]);
    }

    public function profitLoss(Request $request)
    {
        $from     = $request->input('from_date');
        $to       = $request->input('to_date');
        $symbol   = $request->input('symbol');
        $intraday = $request->input('intraday');   // '', 'yes', 'no'
        $brokerId = $request->integer('broker_id') ?: null;
        $tab      = $request->input('tab', 'TW');

        $matches = array_values(array_filter($this->portfolio->result($brokerId)['matches'], function ($m) use ($from, $to, $symbol, $intraday) {
            return (!$from || $m['sell_date'] >= $from)
                && (!$to || $m['sell_date'] <= $to)
                && (!$symbol || $m['symbol'] === $symbol)
                && ($intraday !== 'yes' || $m['is_intraday'])
                && ($intraday !== 'no' || !$m['is_intraday']);
        }));

        // latest sells first
        usort($matches, fn ($a, $b) => [$b['sell_date'], $b['sell_trade_no']] <=> [$a['sell_date'], $a['sell_trade_no']]);

        if ($request->input('export') === 'csv') {
            return $this->csv($matches);
        }

        $byStock = collect($matches)->groupBy('symbol')->map(fn ($rows, $sym) => array_merge(
            ['symbol' => $sym],
            TradeMatcher::summary($rows->all())
        ))->sortBy('symbol')->values();

        return view('psx.reports.profit_loss', [
            'matches' => $matches,
            'summary' => TradeMatcher::summary($matches),
            'byStock' => $byStock,
            'symbols' => PsxStock::orderBy('symbol')->pluck('symbol'),
            'brokers' => PsxBroker::orderBy('name')->get(['id', 'name']),
            'filters' => ['from' => $from, 'to' => $to, 'symbol' => $symbol, 'intraday' => $intraday, 'broker_id' => $brokerId],
            'tab'     => $tab,
        ]);
    }

    private function csv(array $matches)
    {
        $headers = ['Stock', 'Broker', 'Purchase Date', 'Purchase Price', 'Selling Date', 'Selling Price', 'Matched Qty',
            'Purchase Value', 'Selling Value', 'Buy Commission', 'Sell Commission', 'Total Commission',
            'Gross P/L', 'Net P/L', 'Net P/L %', 'Type', 'Buy Trade #', 'Sell Trade #'];

        return response()->streamDownload(function () use ($matches, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($matches as $m) {
                fputcsv($out, [
                    $m['symbol'], $m['broker'], $m['buy_date'], $m['buy_price'], $m['sell_date'], $m['sell_price'], $m['qty'],
                    $m['purchase_value'], $m['selling_value'], $m['buy_commission'], $m['sell_commission'],
                    $m['total_commission'], $m['gross_pl'], $m['net_pl'], $m['net_pl_percent'],
                    $m['is_intraday'] ? 'Intraday' : ($m['buy_type'] === 'opening' ? 'Opening' : 'Delivery'),
                    $m['buy_trade_no'], $m['sell_trade_no'],
                ]);
            }
            fclose($out);
        }, 'psx-profit-loss-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
