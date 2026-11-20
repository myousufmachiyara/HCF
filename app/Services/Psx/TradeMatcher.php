<?php

namespace App\Services\Psx;

/**
 * PSX trade matching engine (FIFO).
 *
 * Pure calculation: takes an array of trade rows and returns matches,
 * holdings and shortfalls. Writes nothing and creates no vouchers.
 *
 * Rules:
 *   - Commission per row = MAX(min_per_share x qty, rate% x value), unless a stored
 *     'commission' is passed in (saved trades keep the commission worked out at entry).
 *   - Opening balances carry no commission.
 *   - A sell is matched first against buys of the SAME DATE (day trade), then against the
 *     oldest open lots of the same group (FIFO).
 *     The group is broker + stock for saved trades, so shares are sold from the broker
 *     account that holds them.
 *   - Intraday: when a buy lot is matched with a sell on the SAME date, the buy commission
 *     for the matched quantity only is waived (if the buy's broker allows it).
 *   - A sell larger than the holding is reported as a shortfall.
 *
 * Input row keys: date (Y-m-d), type (opening|buy|sell), symbol, qty, price,
 *   optional: commission, other_charges, waiver (bool), group, broker, id, trade_id, trade_no, remarks.
 */
class TradeMatcher
{
    private const TYPE_ORDER = ['opening' => 0, 'buy' => 1, 'sell' => 2];

    public function __construct(
        private float $minPerShare = 0.05,
        private float $ratePercent = 0.15,
        private bool $intradayWaiver = true,
    ) {}

    public static function fromBroker($broker): self
    {
        if (!$broker) {
            return new self();
        }

        return new self(
            (float) $broker->min_commission_per_share,
            (float) $broker->commission_rate,
            (bool) $broker->intraday_buy_waiver,
        );
    }

    public function commission(float $qty, float $price): float
    {
        return round(max($this->minPerShare * $qty, ($this->ratePercent / 100) * $qty * $price), 2);
    }

    public function run(array $trades): array
    {
        // 1. Normalise and sort (date, then opening > buy > sell, then entry order)
        $trades = array_values($trades);
        foreach ($trades as $i => &$t) {
            $t['seq']    = $i;
            $t['symbol'] = strtoupper(trim($t['symbol']));
            $t['group']  = $t['group'] ?? $t['symbol'];
            $t['qty']    = (float) $t['qty'];
            $t['price']  = (float) $t['price'];
        }
        unset($t);

        usort($trades, fn ($a, $b) =>
            [$a['date'], self::TYPE_ORDER[$a['type']], $a['seq']] <=> [$b['date'], self::TYPE_ORDER[$b['type']], $b['seq']]
        );

        foreach ($trades as $i => &$t) {
            $t['trade_no']      = $t['trade_no'] ?? ('PSX-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT));
            $t['value']         = round($t['qty'] * $t['price'], 2);
            $t['commission']    = $t['type'] === 'opening'
                ? 0.0
                : (isset($t['commission']) ? (float) $t['commission'] : $this->commission($t['qty'], $t['price']));
            $t['other_charges'] = (float) ($t['other_charges'] ?? 0);
            $t['waiver']        = (bool) ($t['waiver'] ?? $this->intradayWaiver);
            $t['waived']        = 0.0;
            $t['net_amount']    = $t['type'] === 'sell'
                ? $t['value'] - $t['commission'] - $t['other_charges']
                : $t['value'] + $t['commission'] + $t['other_charges'];
        }
        unset($t);

        // 2. FIFO matching per group
        $lots       = [];
        $matches    = [];
        $shortfalls = [];

        foreach ($trades as $idx => $t) {
            $g = $t['group'];

            if ($t['type'] !== 'sell') {
                $lots[$g][] = [
                    'idx'          => $idx,
                    'trade_no'     => $t['trade_no'],
                    'trade_id'     => $t['trade_id'] ?? null,
                    'type'         => $t['type'],
                    'date'         => $t['date'],
                    'price'        => $t['price'],
                    'qty_original' => $t['qty'],
                    'qty_open'     => $t['qty'],
                    'commission'   => $t['commission'],
                    'waiver'       => $t['waiver'],
                    'symbol'       => $t['symbol'],
                    'broker'       => $t['broker'] ?? null,
                ];
                continue;
            }

            // Same-day buys are matched first (a day trade: one commission), then older lots FIFO
            if (!empty($lots[$g])) {
                usort($lots[$g], fn ($a, $b) =>
                    [($a['type'] === 'buy' && $a['date'] === $t['date']) ? 0 : 1, $a['date'], $a['idx']]
                    <=> [($b['type'] === 'buy' && $b['date'] === $t['date']) ? 0 : 1, $b['date'], $b['idx']]
                );
            }

            $remaining = $t['qty'];
            while ($remaining > 0 && !empty($lots[$g])) {
                $lot = &$lots[$g][0];
                $qty = min($remaining, $lot['qty_open']);

                $buyComm    = $lot['commission'] * $qty / $lot['qty_original'];
                $sellComm   = $t['commission'] * $qty / $t['qty'];
                $intraday   = $lot['type'] === 'buy' && $lot['date'] === $t['date'];
                $waived     = ($intraday && $lot['waiver']) ? $buyComm : 0.0;
                $buyCharged = $buyComm - $waived;

                $purchaseValue = $qty * $lot['price'];
                $sellingValue  = $qty * $t['price'];
                $gross         = $sellingValue - $purchaseValue;
                $totalComm     = $buyCharged + $sellComm;
                $net           = $gross - $totalComm;

                $matches[] = [
                    'symbol'           => $t['symbol'],
                    'broker'           => $t['broker'] ?? null,
                    'buy_trade_no'     => $lot['trade_no'],
                    'buy_trade_id'     => $lot['trade_id'],
                    'buy_type'         => $lot['type'],
                    'buy_date'         => $lot['date'],
                    'buy_price'        => $lot['price'],
                    'sell_trade_no'    => $t['trade_no'],
                    'sell_trade_id'    => $t['trade_id'] ?? null,
                    'sell_date'        => $t['date'],
                    'sell_price'       => $t['price'],
                    'qty'              => $qty,
                    'purchase_value'   => round($purchaseValue, 2),
                    'selling_value'    => round($sellingValue, 2),
                    'buy_commission'   => round($buyCharged, 2),
                    'buy_waived'       => round($waived, 2),
                    'sell_commission'  => round($sellComm, 2),
                    'total_commission' => round($totalComm, 2),
                    'gross_pl'         => round($gross, 2),
                    'net_pl'           => round($net, 2),
                    'net_pl_percent'   => $purchaseValue > 0 ? round($net / $purchaseValue * 100, 2) : null,
                    'is_intraday'      => $intraday,
                    'holding_days'     => (int) round((strtotime($t['date']) - strtotime($lot['date'])) / 86400),
                ];

                if ($waived > 0) {
                    $trades[$lot['idx']]['waived'] += $waived;
                }

                $lot['qty_open'] -= $qty;
                $remaining       -= $qty;
                if ($lot['qty_open'] <= 0.000001) {
                    array_shift($lots[$g]);
                }
                unset($lot);
            }

            if ($remaining > 0.000001) {
                $shortfalls[] = [
                    'trade_no' => $t['trade_no'],
                    'trade_id' => $t['trade_id'] ?? null,
                    'group'    => $g,
                    'symbol'   => $t['symbol'],
                    'broker'   => $t['broker'] ?? null,
                    'date'     => $t['date'],
                    'qty'      => $remaining,
                    'held'     => $t['qty'] - $remaining,
                ];
            }
        }

        // Commission actually charged per row: a buy sold the same day carries no commission
        foreach ($trades as &$t) {
            $t['waived']             = round($t['waived'], 2);
            $t['commission_charged'] = round($t['commission'] - $t['waived'], 2);
            $t['net_charged']        = $t['type'] === 'sell'
                ? $t['value'] - $t['commission_charged'] - $t['other_charges']
                : $t['value'] + $t['commission_charged'] + $t['other_charges'];
            $t['intraday_qty']       = $t['commission'] > 0 && $t['type'] === 'buy'
                ? round($t['qty'] * $t['waived'] / $t['commission'], 2)
                : 0.0;
        }
        unset($t);

        return [
            'trades'     => $trades,
            'matches'    => $matches,
            'holdings'   => $this->holdings($lots),
            'shortfalls' => $shortfalls,
        ];
    }

    private function holdings(array $lots): array
    {
        $holdings = [];

        foreach ($lots as $group => $open) {
            $open = array_values(array_filter($open, fn ($l) => $l['qty_open'] > 0.000001));
            usort($open, fn ($a, $b) => [$a['date'], $a['idx']] <=> [$b['date'], $b['idx']]);
            if (!$open) {
                continue;
            }

            $qty  = array_sum(array_column($open, 'qty_open'));
            $cost = array_sum(array_map(fn ($l) => $l['qty_open'] * $l['price'], $open));
            $comm = array_sum(array_map(fn ($l) => $l['commission'] * $l['qty_open'] / $l['qty_original'], $open));

            $holdings[$group] = [
                'group'      => $group,
                'symbol'     => $open[0]['symbol'],
                'broker'     => $open[0]['broker'],
                'qty'        => $qty,
                'avg_cost'   => $qty > 0 ? $cost / $qty : 0,
                'cost_value' => round($cost, 2),
                'commission' => round($comm, 2),
                'since'      => $open[0]['date'],
                'last_price' => end($open)['price'],
                'lots'       => $open,
            ];
        }

        uasort($holdings, fn ($a, $b) => [$a['symbol'], $a['broker']] <=> [$b['symbol'], $b['broker']]);
        return $holdings;
    }

    /** Excel-style portfolio summary for a list of matches. */
    public static function summary(array $matches): array
    {
        $net = array_column($matches, 'net_pl');

        return [
            'trades'     => count($matches),
            'qty'        => array_sum(array_column($matches, 'qty')),
            'turnover'   => array_sum(array_column($matches, 'selling_value')),
            'gross'      => array_sum(array_column($matches, 'gross_pl')),
            'commission' => array_sum(array_column($matches, 'total_commission')),
            'waived'     => array_sum(array_column($matches, 'buy_waived')),
            'net'        => array_sum($net),
            'winning'    => count(array_filter($net, fn ($v) => $v > 0)),
            'losing'     => count(array_filter($net, fn ($v) => $v < 0)),
            'intraday'   => count(array_filter($matches, fn ($m) => $m['is_intraday'])),
        ];
    }
}
