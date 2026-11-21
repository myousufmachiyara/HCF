<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Models\PsxTrade;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use App\Services\Psx\PsxPortfolioService;
use App\Services\Psx\TradeMatcher;
use Illuminate\Http\Request;

/**
 * PSX Holdings and Profit/Loss reports, built from saved trades (read only).
 */
class PsxReportController extends Controller
{
    public function __construct(private PsxPortfolioService $portfolio) {}

    /** PSX dashboard: summary of every part of the module for a period. */
    public function dashboard(Request $request)
    {
        $brokerId = $request->integer('broker_id') ?: null;
        $period   = $request->input('period', 'month');
        [$from, $to] = DashboardPeriod::range($period, $request->input('from_date'), $request->input('to_date'));

        $result = $this->portfolio->result($brokerId);
        $inRange = fn ($date) => (!$from || $date >= $from) && (!$to || $date <= $to);

        $rows    = array_values(array_filter($result['trades'], fn ($t) => $inRange($t['date'])));
        $matches = array_values(array_filter($result['matches'], fn ($m) => $inRange($m['sell_date'])));
        $summary = TradeMatcher::summary($matches);
        $holdings = $result['holdings'];

        // Realised net P/L per selling day (or per month for long ranges), with running total
        $days    = $from && $to ? Carbon::parse($from)->diffInDays(Carbon::parse($to)) : null;
        $monthly = $days === null ? count(array_unique(array_column($matches, 'sell_date'))) > 45 : $days > 62;
        $buckets = [];
        foreach ($matches as $m) {
            $key = $monthly ? substr($m['sell_date'], 0, 7) : $m['sell_date'];
            $buckets[$key] = ($buckets[$key] ?? 0) + $m['net_pl'];
        }
        ksort($buckets);
        $running = 0;
        $plSeries = [];
        foreach ($buckets as $key => $net) {
            $running += $net;
            $plSeries[] = [
                'label'   => $monthly ? Carbon::parse($key . '-01')->format('M Y') : Carbon::parse($key)->format('d M'),
                'net'     => round($net, 2),
                'running' => round($running, 2),
            ];
        }

        // Holdings at cost by stock (all brokers combined): top 8 + Other
        $alloc = collect($holdings)->groupBy('symbol')
            ->map(fn ($g, $sym) => ['symbol' => $sym, 'cost' => $g->sum('cost_value'), 'qty' => $g->sum('qty')])
            ->sortByDesc('cost')->values();
        $allocation = $alloc->take(8)->all();
        if ($alloc->count() > 8) {
            $rest = $alloc->slice(8);
            $allocation[] = ['symbol' => 'Other (' . $rest->count() . ')', 'cost' => $rest->sum('cost'), 'qty' => $rest->sum('qty')];
        }

        // Realised net P/L by stock: best 5 and worst 5
        $byStock = collect($matches)->groupBy('symbol')
            ->map(fn ($g, $sym) => ['symbol' => $sym, 'net' => round($g->sum('net_pl'), 2), 'trades' => $g->count()])
            ->sortByDesc('net')->values();
        $winners = $byStock->filter(fn ($s) => $s['net'] > 0)->take(5)->values()->all();
        $losers  = $byStock->filter(fn ($s) => $s['net'] < 0)->sortBy('net')->take(5)->values()->all();

        // Intraday vs delivery
        $split = [];
        foreach (['Intraday' => true, 'Delivery' => false] as $label => $flag) {
            $set = array_filter($matches, fn ($m) => $m['is_intraday'] === $flag);
            $split[$label] = TradeMatcher::summary(array_values($set));
        }

        $types = fn ($type) => array_values(array_filter($rows, fn ($t) => $t['type'] === $type));

        return view('psx.dashboard', [
            'filters'   => ['broker_id' => $brokerId, 'period' => $period, 'from' => $from, 'to' => $to],
            'brokers'   => PsxBroker::orderBy('name')->get(['id', 'name']),
            'summary'   => $summary,
            'activity'  => [
                'buys'       => count($types('buy')),
                'sells'      => count($types('sell')),
                'bought'     => array_sum(array_column($types('buy'), 'value')),
                'sold'       => array_sum(array_column($types('sell'), 'value')),
                'commission' => array_sum(array_column($rows, 'commission_charged')),
                'saved'      => array_sum(array_column($rows, 'waived')),
                'days'       => count(array_unique(array_column($rows, 'date'))),
            ],
            'portfolio' => [
                'stocks' => count(array_unique(array_column($holdings, 'symbol'))),
                'cost'   => array_sum(array_column($holdings, 'cost_value')),
                'lots'   => array_sum(array_map(fn ($h) => count($h['lots']), $holdings)),
            ],
            'plSeries'   => $plSeries,
            'monthly'    => $monthly,
            'allocation' => $allocation,
            'winners'    => $winners,
            'losers'     => $losers,
            'split'      => $split,
            'recent'     => array_slice(array_reverse($result['trades']), 0, 8),
            'modules'    => [
                'trades'      => PsxTrade::when($brokerId, fn ($q) => $q->where('psx_broker_id', $brokerId))->count(),
                'last_trade'  => PsxTrade::when($brokerId, fn ($q) => $q->where('psx_broker_id', $brokerId))->max('trade_date'),
                'stocks'      => PsxStock::count(),
                'stocks_live' => PsxStock::where('is_active', true)->count(),
                'brokers'     => PsxBroker::where('is_active', true)->count(),
                'default'     => PsxBroker::default(),
                'all_net'     => array_sum(array_column($result['matches'], 'net_pl')),
            ],
        ]);
    }

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
