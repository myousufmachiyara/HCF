<?php

namespace App\Services\Psx;

use App\Models\PsxTradeItem;

/**
 * Builds the portfolio (matches, holdings, shortfalls) from SAVED trades.
 * Read only: no vouchers or ledger entries.
 */
class PsxPortfolioService
{
    /**
     * Saved trade rows in matcher format.
     *
     * @param array $excludeTradeIds trade headers to leave out (e.g. the one being edited)
     * @param int|null $brokerId     only this broker's trades
     * @param string|null $upTo      only trades on or before this date (Y-m-d)
     */
    public function rows(array $excludeTradeIds = [], ?int $brokerId = null, ?string $upTo = null): array
    {
        $items = PsxTradeItem::query()
            ->join('psx_trades', 'psx_trades.id', '=', 'psx_trade_items.psx_trade_id')
            ->join('psx_stocks', 'psx_stocks.id', '=', 'psx_trade_items.psx_stock_id')
            ->join('psx_brokers', 'psx_brokers.id', '=', 'psx_trades.psx_broker_id')
            ->whereNull('psx_trades.deleted_at')
            ->when($excludeTradeIds, fn ($q) => $q->whereNotIn('psx_trades.id', $excludeTradeIds))
            ->when($brokerId, fn ($q) => $q->where('psx_trades.psx_broker_id', $brokerId))
            ->when($upTo, fn ($q) => $q->whereDate('psx_trades.trade_date', '<=', $upTo))
            ->orderBy('psx_trades.trade_date')
            ->orderBy('psx_trades.id')
            ->orderBy('psx_trade_items.id')
            ->get([
                'psx_trade_items.*',
                'psx_trades.trade_no',
                'psx_trades.trade_date',
                'psx_trades.psx_broker_id',
                'psx_stocks.symbol',
                'psx_stocks.name as stock_name',
                'psx_brokers.name as broker_name',
                'psx_brokers.intraday_buy_waiver',
            ]);

        return $items->map(fn ($i) => [
            'id'            => $i->id,
            'key'           => $i->type === 'sell' ? null : (string) $i->id,
            'link'          => $i->type === 'sell' && $i->buy_item_id ? (string) $i->buy_item_id : null,
            'trade_id'      => $i->psx_trade_id,
            'trade_no'      => $i->trade_no,
            'date'          => substr((string) $i->trade_date, 0, 10),
            'type'          => $i->type,
            'symbol'        => $i->symbol,
            'stock_name'    => $i->stock_name,
            'broker_id'     => $i->psx_broker_id,
            'broker'        => $i->broker_name,
            'group'         => $i->psx_broker_id . '|' . $i->symbol,
            'qty'           => (float) $i->qty,
            'price'         => (float) $i->price,
            'commission'    => (float) $i->commission,
            'other_charges' => (float) $i->other_charges,
            'waiver'        => (bool) $i->intraday_buy_waiver,
            'remarks'       => $i->remarks,
        ])->all();
    }

    public function result(?int $brokerId = null, ?string $upTo = null): array
    {
        return (new TradeMatcher())->run($this->rows([], $brokerId, $upTo));
    }
}
