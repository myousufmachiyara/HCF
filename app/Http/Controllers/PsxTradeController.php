<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use App\Models\PsxStock;
use App\Services\Psx\PsxDemoService;

/**
 * PSX Trades - DEMO / PREVIEW ONLY.
 *
 * Shows sample trades and the entry form. There is no store/update/delete:
 * nothing is saved and no vouchers or ledger entries are created.
 */
class PsxTradeController extends Controller
{
    public function index(PsxDemoService $demo)
    {
        $result = $demo->result();

        return view('psx.trades.index', [
            'trades'   => array_reverse($result['trades']),   // latest first
            'broker'   => $demo->broker(),
            'settings' => $demo->matcher()->settings(),
        ]);
    }

    public function create(PsxDemoService $demo)
    {
        $holdings = collect($demo->result()['holdings'])->map(fn ($h) => $h['qty']);

        return view('psx.trades.create', [
            'stocks'   => PsxStock::active()->orderBy('symbol')->get(),
            'brokers'  => PsxBroker::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'holdings' => $holdings,
        ]);
    }
}
