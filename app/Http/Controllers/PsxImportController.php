<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use App\Models\PsxTrade;
use App\Services\Psx\PsxExcelImporter;
use App\Services\Psx\PsxPortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PSX Trades: import the client's Excel sheet (upload -> preview -> confirm),
 * and export the current trades as a CSV backup.
 * No vouchers or ledger entries are created.
 */
class PsxImportController extends Controller
{
    private const DIR = 'psx-imports';

    public function __construct(private PsxExcelImporter $importer) {}

    public function form()
    {
        return view('psx.import.form', [
            'brokers'  => PsxBroker::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'existing' => PsxTrade::count(),
            'rows'     => DB::table('psx_trade_items')->join('psx_trades', 'psx_trades.id', '=', 'psx_trade_items.psx_trade_id')->whereNull('psx_trades.deleted_at')->count(),
        ]);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'file'          => 'required|file|mimes:xlsx,xls|max:10240',
            'psx_broker_id' => 'required|exists:psx_brokers,id',
            'mode'          => 'required|in:replace,append',
        ], [
            'file.mimes' => 'Upload the Excel file (.xlsx or .xls).',
        ]);

        $this->cleanOldUploads();

        $token = (string) Str::uuid();
        $ext   = strtolower($request->file('file')->getClientOriginalExtension()) === 'xls' ? 'xls' : 'xlsx';
        Storage::disk('local')->putFileAs(self::DIR, $request->file('file'), "$token.$ext");

        return $this->previewView($token, $ext, (int) $request->psx_broker_id, $request->mode, $request->file('file')->getClientOriginalName());
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'token'         => ['required', 'regex:/^[0-9a-f\-]{36}$/'],
            'ext'           => 'required|in:xlsx,xls',
            'psx_broker_id' => 'required|exists:psx_brokers,id',
            'mode'          => 'required|in:replace,append',
            'confirm'       => 'required_if:mode,replace|accepted_if:mode,replace',
        ], [
            'confirm.required_if' => 'Tick the box to confirm that the existing PSX trades will be deleted.',
            'confirm.accepted_if' => 'Tick the box to confirm that the existing PSX trades will be deleted.',
        ]);

        $file = self::DIR . "/{$data['token']}.{$data['ext']}";
        if (!Storage::disk('local')->exists($file)) {
            return redirect()->route('psx_trades.import')->with('error', 'The uploaded file has expired. Please upload it again.');
        }

        $broker = PsxBroker::findOrFail($data['psx_broker_id']);
        $parsed = $this->importer->parse(Storage::disk('local')->path($file));
        $check  = $parsed['errors'] ? ['errors' => []] : $this->importer->simulate($parsed, $broker, $data['mode'] === 'append');
        if ($parsed['errors'] || $check['errors']) {
            return redirect()->route('psx_trades.import')->with('error', 'The sheet has problems and was not imported. Upload it again to see them.');
        }

        try {
            $done = $this->importer->import($parsed, $broker, $data['mode'] === 'replace', Auth::id());
        } catch (\Throwable $e) {
            Log::error('[PSX] Excel import failed', ['error' => $e->getMessage()]);
            return redirect()->route('psx_trades.import')->with('error', 'Import failed, nothing was changed: ' . $e->getMessage());
        }

        Storage::disk('local')->delete($file);
        Log::info('[PSX] Excel imported', $done + ['user' => Auth::id(), 'mode' => $data['mode'], 'broker' => $broker->id]);

        $msg = "Imported {$done['items']} rows as {$done['trades']} trades (one per date)";
        if ($done['stocks_created']) {
            $msg .= ", and added {$done['stocks_created']} new stocks";
        }
        if ($data['mode'] === 'replace') {
            $msg .= ". {$done['deleted']} earlier trades were deleted";
        }

        return redirect()->route('psx_trades.index')->with('success', $msg . '.');
    }

    /** CSV of every saved trade row (backup before replacing). */
    public function export()
    {
        $rows = app(PsxPortfolioService::class)->result()['trades'];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Trade #', 'Date', 'Broker', 'Type', 'Stock', 'Qty', 'Price', 'Value', 'Commission charged', 'Other charges', 'Net amount', 'Remarks']);
            foreach ($rows as $t) {
                fputcsv($out, [$t['trade_no'], $t['date'], $t['broker'], $t['type'], $t['symbol'], $t['qty'], $t['price'],
                    $t['value'], $t['commission_charged'], $t['other_charges'], $t['net_charged'], $t['remarks']]);
            }
            fclose($out);
        }, 'psx-trades-backup-' . date('Y-m-d-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function previewView(string $token, string $ext, int $brokerId, string $mode, string $name)
    {
        $broker = PsxBroker::findOrFail($brokerId);
        $parsed = $this->importer->parse(Storage::disk('local')->path(self::DIR . "/$token.$ext"));
        $check  = $parsed['errors'] ? null : $this->importer->simulate($parsed, $broker, $mode === 'append');

        return view('psx.import.preview', [
            'token'    => $token,
            'ext'      => $ext,
            'name'     => $name,
            'broker'   => $broker,
            'mode'     => $mode,
            'parsed'   => $parsed,
            'check'    => $check,
            'errors'   => array_merge($parsed['errors'], $check['errors'] ?? []),
            'existing' => PsxTrade::count(),
            'holdings' => $check ? count($check['result']['holdings']) : 0,
            'invested' => $check ? array_sum(array_column($check['result']['holdings'], 'cost_value')) : 0,
        ]);
    }

    private function cleanOldUploads(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIR) as $f) {
            if ($disk->lastModified($f) < now()->subDay()->getTimestamp()) {
                $disk->delete($f);
            }
        }
    }
}
