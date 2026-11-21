<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Period presets shared by the dashboards.
 * range() returns [from, to] as Y-m-d strings; null means open-ended.
 */
class DashboardPeriod
{
    public const NAMES = [
        'today'  => 'Today',
        'week'   => 'This week',
        'month'  => 'This month',
        '30d'    => 'Last 30 days',
        'year'   => 'This year',
        'all'    => 'All time',
        'custom' => 'Custom',
    ];

    public static function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $today = Carbon::today();

        return match ($period) {
            'today'  => [$today->toDateString(), $today->toDateString()],
            'week'   => [$today->copy()->startOfWeek()->toDateString(), $today->toDateString()],
            '30d'    => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            'month'  => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'year'   => [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            'custom' => [$from ?: null, $to ?: null],
            default  => [null, null],
        };
    }

    public static function label(string $period, ?string $from, ?string $to): string
    {
        if ($period !== 'custom') {
            return self::NAMES[$period] ?? 'All time';
        }
        return ($from ? Carbon::parse($from)->format('d M Y') : 'Start') . ' – ' . ($to ? Carbon::parse($to)->format('d M Y') : 'today');
    }

    /** Group by month when the range is longer than ~2 months. */
    public static function monthly(?string $from, ?string $to, int $distinctDays): bool
    {
        if ($from && $to) {
            return Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 62;
        }
        return $distinctDays > 45;
    }
}
