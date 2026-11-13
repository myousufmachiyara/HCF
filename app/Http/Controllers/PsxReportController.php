<?php

namespace App\Http\Controllers;

use App\Models\PsxStock;
use App\Services\Psx\PsxDemoService;
use App\Services\Psx\TradeMatcher;
use Illuminate\Http\Request;

/**
 * PSX Holdings and Profit/Loss reports - DEMO data (read only).
 */
class PsxReportController extends Controller
{
    public function holdings(PsxDemoService $demo)
    {
        $holdings = $demo->result()['holdings'];

        return view('psx.reports.holdings', [
            'holdings' => $holdings,
            'totals'   => [
                'cost'       => array_sum(array_column($holdings, 'cost_value')),
                'commission' => array_sum(array_column($holdings, 'commission')),
            ],
            'broker'   => $demo->broker(),
        ]);
    }

    public function profitLoss(Request $request, PsxDemoService $demo)
    {
        $from     = $request->input('from_date');
        $to       = $request->input('to_date');
        $symbol   = $request->input('symbol');
        $intraday = $request->input('intraday');   // '', 'yes', 'no'
        $tab      = $request->input('tab', 'TW');

        $matches = array_values(array_filter($demo->result()['matches'], function ($m) use ($from, $to, $symbol, $intraday) {
            return (!$from || $m['sell_date'] >= $from)
                && (!$to || $m['sell_date'] <= $to)
                && (!$symbol || $m['symbol'] === $symbol)
                && ($intraday !== 'yes' || $m['is_intraday'])
                && ($intraday !== 'no' || !$m['is_intraday']);
        }));

        // latest sells first
        usort($matches, fn ($a, $b) => [$b['sell_date'], $b['sell_trade_no']] <=> [$a['sell_date'], $a['sell_trade_no']]);

        $byStock = collect($matches)->groupBy('symbol')->map(fn ($rows, $sym) => array_merge(
            ['symbol' => $sym],
            TradeMatcher::summary($rows->all())
        ))->sortBy('symbol')->values();

        return view('psx.reports.profit_loss', [
            'matches' => $matches,
            'summary' => TradeMatcher::summary($matches),
            'byStock' => $byStock,
            'symbols' => PsxStock::orderBy('symbol')->pluck('symbol'),
            'broker'  => $demo->broker(),
            'filters' => compact('from', 'to', 'symbol', 'intraday'),
            'tab'     => $tab,
        ]);
    }
}
