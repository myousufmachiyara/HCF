<?php

namespace App\Http\Controllers;

use App\Models\PsxStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PsxStockController extends Controller
{
    public function index()
    {
        $stocks = PsxStock::orderBy('symbol')->get();
        return view('psx.stocks', compact('stocks'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = Auth::id();

        PsxStock::create($data);

        return redirect()->route('psx_stocks.index')->with('success', 'Stock added successfully.');
    }

    public function update(Request $request, $id)
    {
        $stock = PsxStock::findOrFail($id);
        $stock->update($this->validated($request, $stock->id));

        return redirect()->route('psx_stocks.index')->with('success', 'Stock updated successfully.');
    }

    public function destroy($id)
    {
        $stock = PsxStock::findOrFail($id);

        if ($stock->tradeItems()->whereHas('trade')->exists()) {
            return back()->with('error', "{$stock->symbol} is used in trades and cannot be deleted. Mark it inactive instead.");
        }

        $stock->delete();

        return redirect()->route('psx_stocks.index')->with('success', 'Stock deleted successfully.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['symbol' => strtoupper(trim((string) $request->symbol))]);

        $data = $request->validate([
            'symbol'  => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9\-\.]+$/',
                Rule::unique('psx_stocks', 'symbol')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'name'    => 'nullable|string|max:255',
            'sector'  => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
        ], [
            'symbol.regex' => 'Symbol may only contain letters, numbers, dash or dot.',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
