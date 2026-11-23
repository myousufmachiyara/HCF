<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Models\PsxTrade;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use App\Services\Psx\PsxPortfolioService;
use App\Services\Psx\PsxReportService;
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

    /** Open positions as of a date: one line per stock (or per stock and broker) with the purchases behind it. */
    public function holdings(Request $request, PsxReportService $reports)
    {
        $brokerId = $request->integer('broker_id') ?: null;
        $symbol   = $request->input('symbol');
        $asOf     = $request->input('as_of') ?: Carbon::today()->toDateString();
        $merge    = $request->input('view', 'stock') !== 'broker';

        $positions = array_values(array_filter(
            $reports->positions($asOf, $brokerId, $merge),
            fn ($h) => !$symbol || $h['symbol'] === $symbol
        ));

        $names = PsxStock::withTrashed()->pluck('name', 'symbol');
        foreach ($positions as &$h) {
            $h['stock_name'] = $names[$h['symbol']] ?? null;
        }
        unset($h);

        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($positions, $merge) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Stock', 'Company', $merge ? 'Brokers' : 'Broker', 'Shares held', 'Avg buy price', 'Avg cost incl. commission',
                    'Value at buy price', 'Commission', 'Cost incl. commission', 'Held since', 'Purchases']);
                foreach ($positions as $h) {
                    fputcsv($out, [$h['symbol'], $h['stock_name'], $h['broker'], $h['qty'], round($h['avg_price'], 4), round($h['avg_cost'], 4),
                        $h['price_value'], $h['commission'], $h['cost_value'], $h['since'], count($h['lots'])]);
                }
                fclose($out);
            }, 'psx-open-positions-' . $asOf . '.csv', ['Content-Type' => 'text/csv']);
        }

        return view('psx.reports.holdings', [
            'holdings' => $positions,
            'totals'   => [
                'qty'        => array_sum(array_column($positions, 'qty')),
                'value'      => array_sum(array_column($positions, 'price_value')),
                'commission' => array_sum(array_column($positions, 'commission')),
                'cost'       => array_sum(array_column($positions, 'cost_value')),
                'lots'       => array_sum(array_map(fn ($h) => count($h['lots']), $positions)),
            ],
            'brokers'  => PsxBroker::orderBy('name')->get(['id', 'name']),
            'symbols'  => PsxStock::orderBy('symbol')->pluck('symbol'),
            'filters'  => ['broker_id' => $brokerId, 'symbol' => $symbol, 'as_of' => $asOf, 'view' => $merge ? 'stock' : 'broker'],
        ]);
    }

    /** Per stock for a period: opening, bought, sold, closing (costs incl. commission). */
    public function movement(Request $request, PsxReportService $reports)
    {
        $brokerId = $request->integer('broker_id') ?: null;
        $period   = $request->input('period', 'month');
        [$from, $to] = DashboardPeriod::range($period, $request->input('from_date'), $request->input('to_date'));
        $to = $to ?: Carbon::today()->toDateString();

        $data = $reports->movement($from, $to, $brokerId);
        if ($symbol = $request->input('symbol')) {
            $data['rows'] = array_values(array_filter($data['rows'], fn ($r) => $r['symbol'] === $symbol));
        }
        if (!$request->boolean('all')) {   // hide stocks with no position and no activity
            $data['rows'] = array_values(array_filter($data['rows'], fn ($r) => $r['open_qty'] || $r['buy_qty'] || $r['sell_qty'] || $r['close_qty']));
        }
        foreach (['open_cost', 'buy_cost', 'sold_cost', 'proceeds', 'net_pl', 'close_cost'] as $k) {
            $data['totals'][$k] = array_sum(array_column($data['rows'], $k));
        }

        return view('psx.reports.movement', $data + [
            'brokers' => PsxBroker::orderBy('name')->get(['id', 'name']),
            'symbols' => PsxStock::orderBy('symbol')->pluck('symbol'),
            'filters' => ['period' => $period, 'from' => $from, 'to' => $to, 'broker_id' => $brokerId, 'symbol' => $symbol],
            'label'   => DashboardPeriod::label($period, $from, $to),
        ]);
    }

    /** Commission paid by month / day / stock / broker, next to realised net P/L. */
    public function commission(Request $request, PsxReportService $reports)
    {
        $brokerId = $request->integer('broker_id') ?: null;
        $period   = $request->input('period', 'year');
        $by       = in_array($request->input('by'), ['month', 'day', 'stock', 'broker']) ? $request->input('by') : 'month';
        [$from, $to] = DashboardPeriod::range($period, $request->input('from_date'), $request->input('to_date'));

        return view('psx.reports.commission', $reports->commission($from, $to, $brokerId, $by) + [
            'brokers' => PsxBroker::orderBy('name')->get(['id', 'name']),
            'filters' => ['period' => $period, 'from' => $from, 'to' => $to, 'broker_id' => $brokerId, 'by' => $by],
            'label'   => DashboardPeriod::label($period, $from, $to),
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
            'cols'    => $request->input('cols') === 'excel' ? 'excel' : 'net',
        ]);
    }

    private function csv(array $matches)
    {
        $headers = ['Stock', 'Broker', 'Purchase Date', 'Purchase Price', 'Cost incl. Commission', 'Selling Date', 'Selling Price', 'Proceeds after Commission', 'Matched Qty',
            'Purchase Value', 'Selling Value', 'Buy Commission', 'Sell Commission', 'Total Commission',
            'Gross P/L', 'Net P/L', 'Net P/L %', 'Type', 'Buy Trade #', 'Sell Trade #'];

        return response()->streamDownload(function () use ($matches, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($matches as $m) {
                fputcsv($out, [
                    $m['symbol'], $m['broker'], $m['buy_date'], $m['buy_price'], $m['buy_cost'], $m['sell_date'], $m['sell_price'], $m['sell_proceeds'], $m['qty'],
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
