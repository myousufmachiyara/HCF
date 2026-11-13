<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PsxStock extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'symbol',
        'name',
        'sector',
        'is_active',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function setSymbolAttribute($value): void
    {
        $this->attributes['symbol'] = strtoupper(trim((string) $value));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
