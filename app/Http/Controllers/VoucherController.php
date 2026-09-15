<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VoucherController extends Controller
{
    public function index($type)
    {
        $vouchers = Voucher::with(['debitAccount', 'creditAccount'])
            ->where('voucher_type', $type)
            ->whereNull('deleted_at')
            ->latest()
            ->get();

        $accounts = ChartOfAccounts::all();

        return view('vouchers.index', [
            'vouchers' => $vouchers,
            'accounts' => $accounts,
            'type'     => $type,
        ]);
    }

    public function create($type)
    {
        $accounts = ChartOfAccounts::all();
        return view('vouchers.create', compact('accounts', 'type'));
    }

    public function show($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount'])->findOrFail($id);
        return response()->json($voucher);
    }

    public function edit($type, $id)
    {
        $voucher  = Voucher::findOrFail($id);
        $accounts = ChartOfAccounts::all();
        return view('vouchers.edit', compact('voucher', 'accounts', 'type'));
    }

    public function store(Request $request, $type)
    {
        try {
            // ── Detect PHP silent upload failure ──────────────────
            // When file exceeds PHP's post_max_size or upload_max_filesize,
            // PHP silently drops the entire request body. Laravel sees an
            // empty request with no error — voucher fails with no message.
            if (
                $_SERVER['REQUEST_METHOD'] === 'POST' &&
                (int) $_SERVER['CONTENT_LENGTH'] > 0 &&
                empty($_POST) && empty($_FILES)
            ) {
                $limit = ini_get('post_max_size');
                return back()->with('error',
                    "Upload failed: file size exceeds the server limit ({$limit}). " .
                    "Please upload a smaller file or no attachment."
                );
            }

            $request->validate([
                'date'      => 'required|date',
                'ac_dr_sid' => 'required|numeric',
                'ac_cr_sid' => 'required|numeric|different:ac_dr_sid',
                'amount'    => 'required|numeric|min:1',
                'remarks'   => 'nullable|string',
                'att.*'     => 'nullable|file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,zip,doc,docx,xls,xlsx',
            ], [
                'att.*.max'   => 'Each attachment must be under 10MB.',
                'att.*.mimes' => 'Allowed file types: jpg, png, pdf, zip, doc, xls.',
            ]);

            // ── Store attachments ──────────────────────────────────
            $attachments = [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $index => $file) {
                    // Check PHP-level upload error codes explicitly
                    if (!$file->isValid()) {
                        $errorMsg = [
                            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
                            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE limit.',
                            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server.',
                            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
                        ][$file->getError()] ?? 'Unknown upload error (code ' . $file->getError() . ').';

                        return back()->withInput()
                            ->with('error', "Attachment " . ($index + 1) . ": {$errorMsg}");
                    }

                    $path = $file->store("attachments/{$type}", 'public_uploads');

                    if (!$path) {
                        return back()->withInput()
                            ->with('error', "Failed to save attachment " . ($index + 1) . ". Check folder permissions.");
                    }

                    $attachments[] = $path;
                    Log::info("[Voucher] Attachment stored: {$path}");
                }
            }

            $voucher = Voucher::create([
                'voucher_type' => $type,
                'date'         => $request->date,
                'ac_dr_sid'    => $request->ac_dr_sid,
                'ac_cr_sid'    => $request->ac_cr_sid,
                'amount'       => $request->amount,
                'remarks'      => $request->remarks,
                'attachments'  => json_encode($attachments),
            ]);

            Log::info("[Voucher] Stored {$type} voucher #{$voucher->id}", [
                'attachments' => $attachments,
            ]);

            return back()->with('success', ucfirst($type) . ' voucher added successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Flatten all validation messages into one flash message
            // so they show in the modal's session alert div
            $errors = collect($e->errors())->flatten()->join(' | ');
            return back()->withInput()->with('error', 'Validation failed: ' . $errors);

        } catch (\Throwable $e) {
            Log::error("[Voucher] Store {$type} error: " . $e->getMessage(), [
                'trace'   => $e->getTraceAsString(),
                'request' => $request->except(['att']),
            ]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $type, $id)
    {
        try {
            $request->validate([
                'date'      => 'required|date',
                'ac_dr_sid' => 'required|numeric',
                'ac_cr_sid' => 'required|numeric|different:ac_dr_sid',
                'amount'    => 'required|numeric|min:1',
                'remarks'   => 'nullable|string',
                'att.*'     => 'nullable|file|max:5120',
            ]);

            $voucher = Voucher::findOrFail($id);

            // ── Keep existing attachments, append new ones ─────────
            // FIX: decode existing JSON properly before merging
            $existing = [];
            if (!empty($voucher->attachments)) {
                $decoded = is_array($voucher->attachments)
                    ? $voucher->attachments
                    : json_decode($voucher->attachments, true);
                $existing = is_array($decoded) ? $decoded : [];
            }

            $newAttachments = [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $path = $file->store("attachments/{$type}", 'public_uploads');
                    if ($path) {
                        $newAttachments[] = $path;
                        Log::info("[Voucher] New attachment stored: {$path}");
                    }
                }
            }

            $allAttachments = array_merge($existing, $newAttachments);

            $voucher->update([
                'date'        => $request->date,
                'ac_dr_sid'   => $request->ac_dr_sid,
                'ac_cr_sid'   => $request->ac_cr_sid,
                'amount'      => $request->amount,
                'remarks'     => $request->remarks,
                'attachments' => json_encode($allAttachments),
            ]);

            Log::info("[Voucher] Updated {$type} voucher #{$id}");

            return back()->with('success', ucfirst($type) . ' voucher updated successfully!');

        } catch (\Throwable $e) {
            Log::error("[Voucher] Update {$type} #{$id} error: " . $e->getMessage());
            return back()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function destroy($type, $id)
    {
        try {
            $voucher = Voucher::findOrFail($id);

            // ── Delete physical files ──────────────────────────────
            if (!empty($voucher->attachments)) {
                $paths = is_array($voucher->attachments)
                    ? $voucher->attachments
                    : json_decode($voucher->attachments, true);

                if (is_array($paths)) {
                    foreach ($paths as $path) {
                        if (Storage::disk('public_uploads')->exists($path)) {
                            Storage::disk('public_uploads')->delete($path);
                        }
                    }
                }
            }

            $voucher->delete();

            return back()->with('success', ucfirst($type) . ' voucher deleted successfully.');

        } catch (\Throwable $e) {
            Log::error("[Voucher] Destroy {$type} #{$id} error: " . $e->getMessage());
            return back()->with('error', 'Something went wrong while deleting.');
        }
    }

    public function print($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('HCF');
        $pdf->SetTitle(ucfirst($type) . ' Voucher #' . $voucher->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 40);
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, ucfirst($type) . ' Voucher', 0, 1, 'R');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="40%">
            <tr>
                <td>
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr>
                            <td width="30%"><b>Voucher #</b></td>
                            <td width="40%">' . $voucher->id . '</td>
                        </tr>
                        <tr>
                            <td width="30%"><b>Date</b></td>
                            <td width="40%">' . Carbon::parse($voucher->date)->format('d-m-Y') . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="8%">S.No</th>
                <th width="36%">Debit Account</th>
                <th width="36%">Credit Account</th>
                <th width="20%">Amount</th>
            </tr>
            <tr>
                <td>1</td>
                <td>' . e($voucher->debitAccount->name  ?? '-') . '</td>
                <td>' . e($voucher->creditAccount->name ?? '-') . '</td>
                <td align="right">' . number_format($voucher->amount, 2) . '</td>
            </tr>
            <tr style="background-color:#f5f5f5;">
                <td colspan="3" align="right"><b>Total</b></td>
                <td align="right"><b>' . number_format($voucher->amount, 2) . '</b></td>
            </tr>
        </table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        if (!empty($voucher->remarks)) {
            $pdf->writeHTML(
                '<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($voucher->remarks)) . '</span>',
                true, false, true, false, ''
            );
        }

        $pdf->Ln(20);
        $yPos      = $pdf->GetY();
        $lineWidth = 40;

        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Prepared By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output(strtolower($type) . '_voucher_' . $voucher->id . '.pdf', 'I');
    }
}