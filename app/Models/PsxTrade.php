<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PsxTrade extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'trade_no',
        'trade_date',
        'psx_broker_id',
        'contract_note_no',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'trade_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(PsxTradeItem::class)->orderBy('id');
    }

    public function broker()
    {
        return $this->belongsTo(PsxBroker::class, 'psx_broker_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextNumber(): string
    {
        $last = static::withTrashed()->max('id') ?? 0;
        return 'PSX-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }
}
