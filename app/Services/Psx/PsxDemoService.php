<?php

namespace App\Services\Psx;

use App\Models\PsxBroker;
use App\Models\PsxStock;

/**
 * Feeds the demo screens (Trades, Holdings, P/L).
 *
 * Sample trades come from DemoTradeData and are run through the real
 * TradeMatcher using the default broker's commission settings from the
 * Brokers master. Nothing is saved; no vouchers or ledger entries are made.
 */
class PsxDemoService
{
    private ?array $result = null;

    public function broker(): ?PsxBroker
    {
        return PsxBroker::default();
    }

    public function matcher(): TradeMatcher
    {
        return TradeMatcher::fromBroker($this->broker());
    }

    public function result(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $result = $this->matcher()->run(DemoTradeData::all());
        $names  = PsxStock::pluck('name', 'symbol');

        foreach ($result['trades'] as &$t) {
            $t['stock_name'] = $names[$t['symbol']] ?? null;
        }
        unset($t);

        foreach ($result['holdings'] as &$h) {
            $h['stock_name'] = $names[$h['symbol']] ?? null;
        }
        unset($h);

        return $this->result = $result;
    }
}
