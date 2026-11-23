<?php

namespace App\Services\Psx;

use Carbon\Carbon;

/**
 * PSX report figures. "Cost" always includes the buy commission; "proceeds"
 * are after the sell commission, so net P/L = proceeds - cost.
 * Read only: no vouchers or ledger entries.
 */
class PsxReportService
{
    public function __construct(private PsxPortfolioService $portfolio) {}

    /**
     * Open positions as of a date. $merge = true combines brokers into one line per stock.
     */
    public function positions(?string $asOf, ?int $brokerId, bool $merge = true): array
    {
        $holdings = $this->portfolio->result($brokerId, $asOf)['holdings'];

        if (!$merge) {
            return array_values($holdings);
        }

        $out = [];
        foreach ($holdings as $h) {
            $s = $h['symbol'];
            if (!isset($out[$s])) {
                $out[$s] = $h + ['brokers' => []];
                $out[$s]['brokers'] = [$h['broker']];
                continue;
            }
            $o = &$out[$s];
            $o['qty']         += $h['qty'];
            $o['price_value'] += $h['price_value'];
            $o['commission']  += $h['commission'];
            $o['cost_value']  += $h['cost_value'];
            $o['since']        = min($o['since'], $h['since']);
            if ($h['last_date'] >= $o['last_date']) {
                $o['last_date']  = $h['last_date'];
                $o['last_price'] = $h['last_price'];
            }
            $o['lots']      = array_merge($o['lots'], $h['lots']);
            $o['brokers'][] = $h['broker'];
            unset($o);
        }

        foreach ($out as &$o) {
            $o['avg_price'] = $o['qty'] ? $o['price_value'] / $o['qty'] : 0;
            $o['avg_cost']  = $o['qty'] ? $o['cost_value'] / $o['qty'] : 0;
            $o['broker']    = implode(', ', array_unique($o['brokers']));
            usort($o['lots'], fn ($a, $b) => [$a['date'], $a['idx']] <=> [$b['date'], $b['idx']]);
        }
        unset($o);

        ksort($out);
        return array_values($out);
    }

    /**
     * Per stock for a period: opening position, bought, sold (cost / proceeds / P&L), closing position.
     * Opening = position at the end of the day before $from. Costs include commission.
     */
    public function movement(?string $from, string $to, ?int $brokerId): array
    {
        $before  = $from ? Carbon::parse($from)->subDay()->toDateString() : null;
        $opening = $before ? $this->bySymbol($this->portfolio->result($brokerId, $before)['holdings']) : [];
        $result  = $this->portfolio->result($brokerId, $to);
        $closing = $this->bySymbol($result['holdings']);

        $inPeriod = fn ($d) => (!$from || $d >= $from) && $d <= $to;
        $rows = [];
        $blank = fn ($sym) => ['symbol' => $sym,
            'open_qty' => 0, 'open_cost' => 0, 'buy_qty' => 0, 'buy_cost' => 0, 'opening_entries' => 0,
            'sell_qty' => 0, 'sold_cost' => 0, 'proceeds' => 0, 'net_pl' => 0,
            'close_qty' => 0, 'close_cost' => 0];

        foreach ($opening as $sym => $h) {
            $rows[$sym] ??= $blank($sym);
            $rows[$sym]['open_qty']  = $h['qty'];
            $rows[$sym]['open_cost'] = $h['cost'];
        }
        foreach ($result['trades'] as $t) {
            if ($t['type'] === 'sell' || !$inPeriod($t['date'])) {
                continue;
            }
            $rows[$t['symbol']] ??= $blank($t['symbol']);
            $rows[$t['symbol']]['buy_qty']  += $t['qty'];
            $rows[$t['symbol']]['buy_cost'] += $t['value'] + $t['commission_charged'];
            if ($t['type'] === 'opening') {
                $rows[$t['symbol']]['opening_entries'] += $t['qty'];
            }
        }
        foreach ($result['matches'] as $m) {
            if (!$inPeriod($m['sell_date'])) {
                continue;
            }
            $rows[$m['symbol']] ??= $blank($m['symbol']);
            $rows[$m['symbol']]['sell_qty']  += $m['qty'];
            $rows[$m['symbol']]['sold_cost'] += $m['buy_cost'];
            $rows[$m['symbol']]['proceeds']  += $m['sell_proceeds'];
            $rows[$m['symbol']]['net_pl']    += $m['net_pl'];
        }
        foreach ($closing as $sym => $h) {
            $rows[$sym] ??= $blank($sym);
            $rows[$sym]['close_qty']  = $h['qty'];
            $rows[$sym]['close_cost'] = $h['cost'];
        }

        ksort($rows);
        $rows = array_values($rows);

        $totals = [];
        foreach (['open_cost', 'buy_cost', 'sold_cost', 'proceeds', 'net_pl', 'close_cost'] as $k) {
            $totals[$k] = array_sum(array_column($rows, $k));
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Commission charged, grouped by month / day / stock / broker, with turnover and realised net P/L.
     * Commission is counted on the trade date; a buy sold the same day carries none.
     */
    public function commission(?string $from, ?string $to, ?int $brokerId, string $by = 'month'): array
    {
        $result   = $this->portfolio->result($brokerId, $to);
        $inPeriod = fn ($d) => (!$from || $d >= $from) && (!$to || $d <= $to);
        $key = fn ($date, $symbol, $broker) => match ($by) {
            'day'    => $date,
            'stock'  => $symbol,
            'broker' => $broker,
            default  => substr($date, 0, 7),
        };

        $rows = [];
        $blank = ['buy_value' => 0, 'sell_value' => 0, 'buy_comm' => 0, 'sell_comm' => 0, 'saved' => 0, 'other' => 0, 'net_pl' => 0, 'rows' => 0];
        foreach ($result['trades'] as $t) {
            if ($t['type'] === 'opening' || !$inPeriod($t['date'])) {
                continue;
            }
            $k = $key($t['date'], $t['symbol'], $t['broker']);
            $rows[$k] ??= $blank;
            $rows[$k]['rows']++;
            $rows[$k][$t['type'] === 'buy' ? 'buy_value' : 'sell_value'] += $t['value'];
            $rows[$k][$t['type'] === 'buy' ? 'buy_comm' : 'sell_comm']   += $t['commission_charged'];
            $rows[$k]['saved'] += $t['waived'];
            $rows[$k]['other'] += $t['other_charges'];
        }
        foreach ($result['matches'] as $m) {
            if (!$inPeriod($m['sell_date'])) {
                continue;
            }
            $k = $key($m['sell_date'], $m['symbol'], $m['broker']);
            $rows[$k] ??= $blank;
            $rows[$k]['net_pl'] += $m['net_pl'];
        }

        ksort($rows);
        $out = [];
        foreach ($rows as $k => $r) {
            $label = match ($by) {
                'day'   => Carbon::parse($k)->format('d M Y'),
                'month' => Carbon::parse($k . '-01')->format('F Y'),
                default => $k,
            };
            $out[] = ['key' => $k, 'label' => $label, 'total' => $r['buy_comm'] + $r['sell_comm']] + $r;
        }

        $totals = $blank + ['total' => 0];
        foreach ($out as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        return ['rows' => $out, 'totals' => $totals];
    }

    private function bySymbol(array $holdings): array
    {
        $out = [];
        foreach ($holdings as $h) {
            $out[$h['symbol']]['qty']  = ($out[$h['symbol']]['qty'] ?? 0) + $h['qty'];
            $out[$h['symbol']]['cost'] = ($out[$h['symbol']]['cost'] ?? 0) + $h['cost_value'];
        }
        return $out;
    }
}
