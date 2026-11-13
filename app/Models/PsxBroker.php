<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PsxBroker extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'client_code',
        'contact_person',
        'phone',
        'email',
        'min_commission_per_share',
        'commission_rate',
        'intraday_buy_waiver',
        'is_default',
        'is_active',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'min_commission_per_share' => 'float',
        'commission_rate'          => 'float',
        'intraday_buy_waiver'      => 'boolean',
        'is_default'               => 'boolean',
        'is_active'                => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Broker whose settings drive the demo calculations. */
    public static function default(): ?self
    {
        return static::active()->orderByDesc('is_default')->orderBy('id')->first();
    }
}
