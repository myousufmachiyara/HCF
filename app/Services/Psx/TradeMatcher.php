<?php

namespace App\Services\Psx;

/**
 * PSX trade matching engine (FIFO).
 *
 * Pure calculation - reads an array of trades, writes nothing to the database,
 * creates no vouchers. The same class will be reused when trades are stored.
 *
 * Rules (from the client's Excel, with the recommended options):
 *   - Commission per trade  = MAX(min_per_share x qty, rate% x value)
 *   - Opening balances carry no commission.
 *   - Sells are matched against the oldest open buy lot first (FIFO).
 *   - Intraday: when a buy lot is matched with a sell on the SAME date,
 *     the buy commission for the matched quantity only is waived.
 *   - A sell larger than holdings is reported as a shortfall (would be blocked).
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

    public function settings(): array
    {
        return [
            'min_per_share'   => $this->minPerShare,
            'rate_percent'    => $this->ratePercent,
            'intraday_waiver' => $this->intradayWaiver,
        ];
    }

    public function commission(float $qty, float $price): float
    {
        $value = $qty * $price;
        return round(max($this->minPerShare * $qty, ($this->ratePercent / 100) * $value), 2);
    }

    /**
     * @param array $trades each: date (Y-m-d), type (opening|buy|sell), symbol, qty, price, [other_charges], [remarks]
     */
    public function run(array $trades): array
    {
        // 1. Normalise, sort and number the trades
        foreach ($trades as $i => &$t) {
            $t['seq']    = $i;
            $t['symbol'] = strtoupper(trim($t['symbol']));
            $t['qty']    = (float) $t['qty'];
            $t['price']  = (float) $t['price'];
        }
        unset($t);

        usort($trades, fn ($a, $b) =>
            [$a['date'], self::TYPE_ORDER[$a['type']], $a['seq']] <=> [$b['date'], self::TYPE_ORDER[$b['type']], $b['seq']]
        );

        foreach ($trades as $i => &$t) {
            $t['trade_no']      = 'PSX-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            $t['value']         = round($t['qty'] * $t['price'], 2);
            $t['commission']    = $t['type'] === 'opening' ? 0.0 : $this->commission($t['qty'], $t['price']);
            $t['other_charges'] = (float) ($t['other_charges'] ?? 0);
            $t['waived']        = 0.0;
            $t['net_amount']    = $t['type'] === 'sell'
                ? $t['value'] - $t['commission'] - $t['other_charges']
                : $t['value'] + $t['commission'] + $t['other_charges'];
        }
        unset($t);

        // 2. FIFO matching per symbol
        $lots       = [];   // symbol => list of open lots
        $matches    = [];
        $shortfalls = [];
        $byNo       = [];   // trade_no => index in $trades (for waiver totals)

        foreach ($trades as $idx => $t) {
            $byNo[$t['trade_no']] = $idx;
            $sym = $t['symbol'];

            if ($t['type'] !== 'sell') {
                $lots[$sym][] = [
                    'trade_no'     => $t['trade_no'],
                    'type'         => $t['type'],
                    'date'         => $t['date'],
                    'price'        => $t['price'],
                    'qty_original' => $t['qty'],
                    'qty_open'     => $t['qty'],
                    'commission'   => $t['commission'],
                ];
                continue;
            }

            $remaining = $t['qty'];
            while ($remaining > 0 && !empty($lots[$sym])) {
                $lot = &$lots[$sym][0];
                $qty = min($remaining, $lot['qty_open']);

                $buyComm  = $lot['commission'] * $qty / $lot['qty_original'];
                $sellComm = $t['commission'] * $qty / $t['qty'];
                $intraday = $lot['type'] === 'buy' && $lot['date'] === $t['date'];
                $waived   = ($intraday && $this->intradayWaiver) ? $buyComm : 0.0;
                $buyCharged = $buyComm - $waived;

                $purchaseValue = $qty * $lot['price'];
                $sellingValue  = $qty * $t['price'];
                $gross         = $sellingValue - $purchaseValue;
                $totalComm     = $buyCharged + $sellComm;
                $net           = $gross - $totalComm;

                $matches[] = [
                    'symbol'           => $sym,
                    'buy_trade_no'     => $lot['trade_no'],
                    'buy_type'         => $lot['type'],
                    'buy_date'         => $lot['date'],
                    'buy_price'        => $lot['price'],
                    'sell_trade_no'    => $t['trade_no'],
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
                    'holding_days'     => (int) ((strtotime($t['date']) - strtotime($lot['date'])) / 86400),
                ];

                if ($waived > 0) {
                    $trades[$byNo[$lot['trade_no']]]['waived'] += $waived;
                }

                $lot['qty_open'] -= $qty;
                $remaining       -= $qty;
                if ($lot['qty_open'] <= 0) {
                    array_shift($lots[$sym]);
                }
                unset($lot);
            }

            if ($remaining > 0) {
                $shortfalls[] = [
                    'trade_no' => $t['trade_no'],
                    'symbol'   => $sym,
                    'date'     => $t['date'],
                    'qty'      => $remaining,
                ];
            }
        }

        foreach ($trades as &$t) {
            $t['waived'] = round($t['waived'], 2);
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

        foreach ($lots as $sym => $open) {
            $open = array_values(array_filter($open, fn ($l) => $l['qty_open'] > 0));
            if (!$open) {
                continue;
            }

            $qty  = array_sum(array_column($open, 'qty_open'));
            $cost = array_sum(array_map(fn ($l) => $l['qty_open'] * $l['price'], $open));
            $comm = array_sum(array_map(fn ($l) => $l['commission'] * $l['qty_open'] / $l['qty_original'], $open));

            $holdings[$sym] = [
                'symbol'     => $sym,
                'qty'        => $qty,
                'avg_cost'   => $qty > 0 ? $cost / $qty : 0,
                'cost_value' => round($cost, 2),
                'commission' => round($comm, 2),
                'since'      => $open[0]['date'],
                'last_price' => end($open)['price'],
                'lots'       => $open,
            ];
        }

        ksort($holdings);
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
