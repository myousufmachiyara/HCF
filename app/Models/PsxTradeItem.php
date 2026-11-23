<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PsxTradeItem extends Model
{
    protected $fillable = [
        'psx_trade_id',
        'type',
        'psx_stock_id',
        'buy_item_id',
        'qty',
        'price',
        'value',
        'commission',
        'other_charges',
        'net_amount',
        'remarks',
    ];

    protected $casts = [
        'qty'           => 'float',
        'price'         => 'float',
        'value'         => 'float',
        'commission'    => 'float',
        'other_charges' => 'float',
        'net_amount'    => 'float',
    ];

    public function trade()
    {
        return $this->belongsTo(PsxTrade::class, 'psx_trade_id');
    }

    public function stock()
    {
        return $this->belongsTo(PsxStock::class, 'psx_stock_id')->withTrashed();
    }

    /** The purchase this sale closes, when one was chosen. */
    public function buyItem()
    {
        return $this->belongsTo(PsxTradeItem::class, 'buy_item_id');
    }
}
