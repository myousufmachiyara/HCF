<?php

namespace App\Services\Psx;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Models\PsxTrade;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Imports the client's "PSX Trade Calculator" workbook.
 *
 * Sheet layout (first sheet, row 1 = headings, data from row 2):
 *   B Stock Name | C Purchase Date | D Purchase Price | E Purchase Qty
 *   F Selling Date | G Selling Price | H Selling Qty
 * Other columns are Excel formulas and are ignored; the system recalculates.
 *
 * Each row becomes a BUY (if the purchase side is filled) and a SELL (if the
 * selling side is filled). Rows are grouped into one trade per date.
 * Commission is worked out from the chosen broker's settings, as on manual entry.
 */
class PsxExcelImporter
{
    private const HEADINGS = [
        'B' => 'stock name', 'C' => 'purchase date', 'D' => 'purchase price', 'E' => 'purchase qty',
        'F' => 'selling date', 'G' => 'selling price', 'H' => 'selling qty',
    ];

    /**
     * Reads and checks the workbook. Nothing is saved.
     *
     * @return array{rows: array, errors: array, warnings: array, new_symbols: array, symbols: array, excel_rows: int, buys: int, sells: int, from: ?string, to: ?string}
     */
    public function parse(string $path): array
    {
        $out = ['rows' => [], 'errors' => [], 'warnings' => [], 'new_symbols' => [], 'symbols' => [],
                'excel_rows' => 0, 'buys' => 0, 'sells' => 0, 'from' => null, 'to' => null];

        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (\Throwable $e) {
            $out['errors'][] = 'The file could not be read as an Excel workbook (.xlsx / .xls).';
            return $out;
        }

        // Check the headings so a wrong file is not imported by mistake
        foreach (self::HEADINGS as $col => $expected) {
            $found = strtolower(trim((string) $sheet->getCell($col . '1')->getValue()));
            if (!str_starts_with($found, $expected)) {
                $out['errors'][] = "Column $col heading should start with \"" . ucwords($expected) . "\" but is \"" . ($found ?: 'empty') . "\". "
                    . 'Please upload the PSX Trade Calculator sheet (the stock name must be in column B).';
                return $out;
            }
        }

        $lastRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $lastRow; $r++) {
            $raw = [];
            foreach (array_keys(self::HEADINGS) as $col) {
                $raw[$col] = $sheet->getCell($col . $r)->getValue();
            }
            $raw = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $raw);
            $filled = fn (...$cols) => array_filter($cols, fn ($c) => $raw[$c] !== null && $raw[$c] !== '');

            if (!$filled('B', 'C', 'D', 'E', 'F', 'G', 'H')) {
                continue;   // empty row
            }
            $out['excel_rows']++;

            $symbol = strtoupper((string) $raw['B']);
            if ($symbol === '') {
                $out['errors'][] = "Row $r: stock name is empty.";
                continue;
            }
            if (!preg_match('/^[A-Z0-9\-\.]{1,20}$/', $symbol)) {
                $out['errors'][] = "Row $r: stock name \"$symbol\" may only contain letters, numbers, dash or dot.";
                continue;
            }

            $sides = [
                'buy'  => ['C', 'D', 'E', 'purchase'],
                'sell' => ['F', 'G', 'H', 'selling'],
            ];
            $dates = [];
            foreach ($sides as $type => [$dCol, $pCol, $qCol, $word]) {
                $present = $filled($dCol, $pCol, $qCol);
                if (!$present) {
                    continue;
                }
                if (count($present) < 3) {
                    $missing = array_diff([$dCol, $pCol, $qCol], $present);
                    $names = array_map(fn ($c) => self::HEADINGS[$c], $missing);
                    $out['errors'][] = "Row $r ($symbol): $word side is incomplete, missing " . implode(', ', $names) . '.';
                    continue;
                }
                $date  = $this->date($raw[$dCol]);
                $price = $this->number($raw[$pCol]);
                $qty   = $this->number($raw[$qCol]);

                if (!$date) {
                    $out['errors'][] = "Row $r ($symbol): $word date \"{$raw[$dCol]}\" is not a date.";
                    continue;
                }
                if ($price === null || $price <= 0) {
                    $out['errors'][] = "Row $r ($symbol): $word price must be a number above zero.";
                    continue;
                }
                if ($qty === null || $qty <= 0) {
                    $out['errors'][] = "Row $r ($symbol): $word quantity must be a number above zero.";
                    continue;
                }

                $dates[$type] = $date;
                $out[$type === 'buy' ? 'buys' : 'sells']++;
                $out['rows'][] = [
                    'date' => $date, 'type' => $type, 'symbol' => $symbol,
                    'qty' => $qty, 'price' => $price, 'excel_row' => $r, 'remarks' => "Excel row $r",
                ];
            }

            if (isset($dates['buy'], $dates['sell']) && $dates['sell'] < $dates['buy']) {
                $out['warnings'][] = "Row $r ($symbol): sold on " . Carbon::parse($dates['sell'])->format('d-M-Y')
                    . ' before the purchase date ' . Carbon::parse($dates['buy'])->format('d-M-Y') . '. Shares held earlier will be used.';
            }
        }

        if (!$out['rows'] && !$out['errors']) {
            $out['errors'][] = 'No trades were found in the sheet.';
        }

        $out['symbols'] = array_values(array_unique(array_column($out['rows'], 'symbol')));
        sort($out['symbols']);
        $known = PsxStock::withTrashed()->pluck('symbol')->all();
        $out['new_symbols'] = array_values(array_diff($out['symbols'], $known));

        $dates = array_column($out['rows'], 'date');
        $out['from'] = $dates ? min($dates) : null;
        $out['to']   = $dates ? max($dates) : null;

        return $out;
    }

    /**
     * Runs the trades through the matcher with the broker's settings and checks
     * that no sale is larger than the shares held. With $keepExisting the
     * current trades are included (append mode).
     */
    public function simulate(array $parsed, PsxBroker $broker, bool $keepExisting): array
    {
        $rows = [];
        if ($keepExisting) {
            $rows = app(PsxPortfolioService::class)->rows([], $broker->id);
        }
        $matcher = TradeMatcher::fromBroker($broker);
        foreach ($parsed['rows'] as $i => $r) {
            $rows[] = $r + [
                'id'         => 'x' . $i,
                'trade_no'   => 'Excel row ' . $r['excel_row'],
                'group'      => $broker->id . '|' . $r['symbol'],
                'broker'     => $broker->name,
                'waiver'     => $broker->intraday_buy_waiver,
                'commission' => $matcher->commission($r['qty'], $r['price']),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));   // stable: keeps sheet order within a day

        $result = (new TradeMatcher())->run($rows);
        $errors = array_map(fn ($s) => sprintf(
            '%s: sale of %s on %s (%s) is more than the %s shares held at that date.',
            $s['symbol'], number_format($s['qty'] + $s['held']), Carbon::parse($s['date'])->format('d-M-Y'), $s['trade_no'], number_format($s['held'])
        ), $result['shortfalls']);

        return ['result' => $result, 'summary' => TradeMatcher::summary($result['matches']), 'errors' => $errors];
    }

    /**
     * Saves the trades. In replace mode every existing PSX trade is deleted first.
     * All in one database transaction: if anything fails, nothing changes.
     *
     * @return array{trades: int, items: int, stocks_created: int, deleted: int}
     */
    public function import(array $parsed, PsxBroker $broker, bool $replace, ?int $userId): array
    {
        return DB::transaction(function () use ($parsed, $broker, $replace, $userId) {
            $deleted = 0;
            if ($replace) {
                $deleted = PsxTrade::withTrashed()->count();
                DB::table('psx_trade_items')->delete();
                DB::table('psx_trades')->delete();
            }

            $stocks  = PsxStock::withTrashed()->get()->keyBy('symbol');
            $created = 0;
            foreach ($parsed['symbols'] as $sym) {
                if (!isset($stocks[$sym])) {
                    $stocks[$sym] = PsxStock::create(['symbol' => $sym, 'is_active' => true, 'remarks' => 'Added by Excel import', 'created_by' => $userId]);
                    $created++;
                } elseif ($stocks[$sym]->trashed()) {
                    $stocks[$sym]->restore();
                }
            }

            $matcher = TradeMatcher::fromBroker($broker);
            $byDate  = collect($parsed['rows'])->groupBy('date')->sortKeys();
            $items   = 0;

            foreach ($byDate as $date => $dayRows) {
                $trade = PsxTrade::create([
                    'trade_no'      => PsxTrade::nextNumber(),
                    'trade_date'    => $date,
                    'psx_broker_id' => $broker->id,
                    'remarks'       => 'Imported from Excel',
                    'created_by'    => $userId,
                ]);

                // buys before sells within the day, otherwise sheet order
                $ordered = $dayRows->sortBy(fn ($r) => [$r['type'] === 'sell' ? 1 : 0, $r['excel_row']])->values();
                foreach ($ordered as $r) {
                    $value = round($r['qty'] * $r['price'], 2);
                    $comm  = $matcher->commission($r['qty'], $r['price']);
                    $trade->items()->create([
                        'type'          => $r['type'],
                        'psx_stock_id'  => $stocks[$r['symbol']]->id,
                        'qty'           => $r['qty'],
                        'price'         => $r['price'],
                        'value'         => $value,
                        'commission'    => $comm,
                        'other_charges' => 0,
                        'net_amount'    => $r['type'] === 'sell' ? $value - $comm : $value + $comm,
                        'remarks'       => $r['remarks'],
                    ]);
                    $items++;
                }
            }

            return ['trades' => $byDate->count(), 'items' => $items, 'stocks_created' => $created, 'deleted' => $deleted];
        });
    }

    private function date($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v)->toDateString();
        }
        if (is_numeric($v) && $v > 20000 && $v < 80000) {          // Excel date serial
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        if (is_string($v) && $v !== '') {
            foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd-M-Y', 'd M Y', 'd/m/y', 'd-M-y'] as $fmt) {
                try {
                    $d = Carbon::createFromFormat('!' . $fmt, $v);
                    if ($d && $d->format($fmt) === $v || $d && strtolower($d->format($fmt)) === strtolower($v)) {
                        return $d->toDateString();
                    }
                } catch (\Throwable) {
                }
            }
        }
        return null;
    }

    private function number($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v)) {
            $clean = str_replace([',', 'Rs', 'rs', ' '], '', $v);
            return is_numeric($clean) ? (float) $clean : null;
        }
        return null;
    }
}
