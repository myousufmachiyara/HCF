<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Models\PsxTrade;
use App\Services\Psx\PsxPortfolioService;
use App\Services\Psx\TradeMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * PSX Trades - CRUD.
 *
 * Accounting is NOT connected yet: saving a trade creates no vouchers or ledger entries.
 */
class PsxTradeController extends Controller
{
    public function __construct(private PsxPortfolioService $portfolio) {}

    public function index()
    {
        $result = $this->portfolio->result();

        return view('psx.trades.index', [
            'rows'    => array_reverse($result['trades']),   // latest first
            'brokers' => PsxBroker::orderBy('name')->pluck('name'),
        ]);
    }

    public function create()
    {
        return view('psx.trades.form', $this->formData(null));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::beginTransaction();
        try {
            $this->assertNoOversell($data, null);

            $trade = PsxTrade::create([
                'trade_no'         => PsxTrade::nextNumber(),
                'trade_date'       => $data['trade_date'],
                'psx_broker_id'    => $data['psx_broker_id'],
                'contract_note_no' => $data['contract_note_no'] ?? null,
                'remarks'          => $data['remarks'] ?? null,
                'created_by'       => Auth::id(),
            ]);
            $trade->items()->createMany($data['items']);

            DB::commit();
            Log::info('[PSX] Trade created', ['id' => $trade->id, 'trade_no' => $trade->trade_no, 'user' => Auth::id()]);

            return redirect()->route('psx_trades.show', $trade->id)->with('success', "Trade {$trade->trade_no} saved.");
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PSX] Trade create failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Could not save the trade: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        return view('psx.trades.show', $this->showData($id));
    }

    public function print($id)
    {
        return view('psx.trades.print', $this->showData($id));
    }

    public function edit($id)
    {
        $trade = PsxTrade::with('items.stock')->findOrFail($id);
        return view('psx.trades.form', $this->formData($trade));
    }

    public function update(Request $request, $id)
    {
        $trade = PsxTrade::with('items.stock')->findOrFail($id);
        $data  = $this->validated($request);

        DB::beginTransaction();
        try {
            $this->assertNoOversell($data, $trade);

            $trade->update([
                'trade_date'       => $data['trade_date'],
                'psx_broker_id'    => $data['psx_broker_id'],
                'contract_note_no' => $data['contract_note_no'] ?? null,
                'remarks'          => $data['remarks'] ?? null,
            ]);
            $trade->items()->delete();
            $trade->items()->createMany($data['items']);

            DB::commit();
            Log::info('[PSX] Trade updated', ['id' => $trade->id, 'user' => Auth::id()]);

            return redirect()->route('psx_trades.show', $trade->id)->with('success', "Trade {$trade->trade_no} updated.");
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PSX] Trade update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Could not update the trade: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $trade = PsxTrade::with('items.stock')->findOrFail($id);

        // Deleting a buy must not leave a later sale with no shares behind it
        $problems = $this->shortfallMessages(
            $this->portfolio->rows([$trade->id]),
            $this->groupsOf($trade)
        );
        if ($problems) {
            return back()->with('error', "Trade {$trade->trade_no} cannot be deleted. Later sales depend on its shares: " . implode(' ', $problems));
        }

        $trade->delete();
        Log::info('[PSX] Trade deleted', ['id' => $trade->id, 'user' => Auth::id()]);

        return redirect()->route('psx_trades.index')->with('success', "Trade {$trade->trade_no} deleted.");
    }

    // ───────────────────────────────────────────────────────────

    private function formData(?PsxTrade $trade): array
    {
        $holdings = [];
        $rows = $this->portfolio->rows($trade ? [$trade->id] : []);
        foreach ((new TradeMatcher())->run($rows)['holdings'] as $group => $h) {
            $holdings[$group] = $h['qty'];
        }

        $items = old('items', $trade
            ? $trade->items->map(fn ($i) => [
                'type'          => $i->type,
                'psx_stock_id'  => $i->psx_stock_id,
                'qty'           => $i->qty + 0,
                'price'         => $i->price + 0,
                'other_charges' => $i->other_charges + 0,
                'remarks'       => $i->remarks,
            ])->all()
            : []);

        $stockIds = collect($items)->pluck('psx_stock_id')->filter()->all();

        return [
            'trade'    => $trade,
            'items'    => array_values($items),
            'stocks'   => PsxStock::where('is_active', true)->orWhereIn('id', $stockIds)->orderBy('symbol')->get(),
            'brokers'  => PsxBroker::where('is_active', true)
                ->when($trade, fn ($q) => $q->orWhere('id', $trade->psx_broker_id))
                ->orderByDesc('is_default')->orderBy('name')->get(),
            'holdings' => $holdings,
        ];
    }

    private function showData($id): array
    {
        $trade  = PsxTrade::with(['items.stock', 'broker', 'creator'])->findOrFail($id);
        $result = $this->portfolio->result();

        $waived = collect($result['trades'])->where('trade_id', $trade->id)->pluck('waived', 'id');

        return [
            'trade'   => $trade,
            'waived'  => $waived,
            'sells'   => array_values(array_filter($result['matches'], fn ($m) => $m['sell_trade_id'] === $trade->id)),
            'buys'    => array_values(array_filter($result['matches'], fn ($m) => $m['buy_trade_id'] === $trade->id && $m['sell_trade_id'] !== $trade->id)),
        ];
    }

    /** Validates the form and works out value / commission / net for each row (server side). */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'trade_date'            => 'required|date',
            'psx_broker_id'         => 'required|exists:psx_brokers,id',
            'contract_note_no'      => 'nullable|string|max:100',
            'remarks'               => 'nullable|string|max:1000',
            'items'                 => 'required|array|min:1',
            'items.*.type'          => 'required|in:buy,sell,opening',
            'items.*.psx_stock_id'  => 'required|exists:psx_stocks,id',
            'items.*.qty'           => 'required|numeric|gt:0',
            'items.*.price'         => 'required|numeric|gt:0',
            'items.*.other_charges' => 'nullable|numeric|min:0',
            'items.*.remarks'       => 'nullable|string|max:255',
        ], [
            'items.required'                => 'Add at least one row.',
            'items.*.psx_stock_id.required' => 'Select a stock on every row.',
            'items.*.qty.gt'                => 'Quantity must be more than zero.',
            'items.*.price.gt'              => 'Price must be more than zero.',
        ]);

        $matcher = TradeMatcher::fromBroker(PsxBroker::find($data['psx_broker_id']));

        $data['items'] = array_map(function ($row) use ($matcher) {
            $qty   = (float) $row['qty'];
            $price = (float) $row['price'];
            $other = (float) ($row['other_charges'] ?? 0);
            $value = round($qty * $price, 2);
            $comm  = $row['type'] === 'opening' ? 0 : $matcher->commission($qty, $price);

            return [
                'type'          => $row['type'],
                'psx_stock_id'  => (int) $row['psx_stock_id'],
                'qty'           => $qty,
                'price'         => $price,
                'value'         => $value,
                'commission'    => $comm,
                'other_charges' => $other,
                'net_amount'    => $row['type'] === 'sell' ? $value - $comm - $other : $value + $comm + $other,
                'remarks'       => $row['remarks'] ?? null,
            ];
        }, array_values($data['items']));

        return $data;
    }

    /**
     * Re-runs FIFO with this trade in place and refuses the save if any sale
     * (this one or a later one) would sell more shares than are held.
     */
    private function assertNoOversell(array $data, ?PsxTrade $existing): void
    {
        $broker  = PsxBroker::find($data['psx_broker_id']);
        $symbols = PsxStock::whereIn('id', array_column($data['items'], 'psx_stock_id'))->pluck('symbol', 'id');

        $rows = $this->portfolio->rows($existing ? [$existing->id] : []);
        $sortId = $existing?->id ?? PHP_INT_MAX;

        foreach ($data['items'] as $i => $item) {
            $rows[] = [
                'id'         => 'new-' . $i,
                'trade_id'   => $existing?->id,
                'trade_no'   => $existing?->trade_no ?? 'this trade',
                'sort_id'    => $sortId,
                'date'       => date('Y-m-d', strtotime($data['trade_date'])),
                'type'       => $item['type'],
                'symbol'     => $symbols[$item['psx_stock_id']],
                'broker'     => $broker->name,
                'group'      => $broker->id . '|' . $symbols[$item['psx_stock_id']],
                'qty'        => $item['qty'],
                'price'      => $item['price'],
                'commission' => $item['commission'],
            ];
        }

        // keep saved order: date, then trade, then row
        foreach ($rows as $k => &$r) {
            $r['sort_id'] = $r['sort_id'] ?? $r['trade_id'];
            $r['pos'] = $k;
        }
        unset($r);
        usort($rows, fn ($a, $b) => [$a['date'], $a['sort_id'], $a['pos']] <=> [$b['date'], $b['sort_id'], $b['pos']]);

        $groups = array_unique(array_merge(
            array_map(fn ($r) => $r['group'], array_filter($rows, fn ($r) => str_starts_with((string) $r['id'], 'new-'))),
            $existing ? $this->groupsOf($existing) : []
        ));

        $problems = $this->shortfallMessages($rows, $groups);
        if ($problems) {
            throw ValidationException::withMessages(['items' => $problems]);
        }
    }

    private function shortfallMessages(array $rows, array $groups): array
    {
        $messages = [];
        foreach ((new TradeMatcher())->run($rows)['shortfalls'] as $s) {
            if (!in_array($s['group'], $groups, true)) {
                continue;
            }
            $messages[] = sprintf(
                '%s (%s): sale of %s on %s in %s is more than the %s shares held.',
                $s['symbol'],
                $s['broker'],
                number_format($s['qty'] + $s['held']),
                date('d-M-Y', strtotime($s['date'])),
                $s['trade_no'],
                number_format($s['held'])
            );
        }
        return array_values(array_unique($messages));
    }

    private function groupsOf(PsxTrade $trade): array
    {
        return $trade->items->map(fn ($i) => $trade->psx_broker_id . '|' . $i->stock->symbol)->unique()->values()->all();
    }
}
