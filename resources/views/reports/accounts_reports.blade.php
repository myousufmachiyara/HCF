@extends('layouts.app')

@section('title', 'Accounting Reports')

@section('content')

{{-- ── Print-only styles ──────────────────────────────────────── --}}
<style>
@media print {
    .no-print { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    body { font-size: 12px; }
}
.report-action-btn {
    font-size: 11px;
    padding: 1px 6px;
    border-radius: 4px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.voucher-link {
    color: var(--bs-primary);
    text-decoration: none;
    font-weight: 500;
    cursor: pointer;
}
.voucher-link:hover { text-decoration: underline; }
</style>

<div class="tabs">

    {{-- ── Tab navigation ────────────────────────────────────────── --}}
    <ul class="nav nav-tabs" id="reportTabs" role="tablist">
        @foreach ([
            'general_ledger'   => 'General Ledger',
            'trial_balance'    => 'Trial Balance',
            'profit_loss'      => 'Profit & Loss',
            'balance_sheet'    => 'Balance Sheet',
            'party_ledger'     => 'Party Ledger',
            'receivables'      => 'Receivables',
            'payables'         => 'Payables',
            'cash_book'        => 'Cash Book',
            'bank_book'        => 'Bank Book',
            'journal_book'     => 'Journal / Day Book',
            'expense_analysis' => 'Expense Analysis',
            'cash_flow'        => 'Cash Flow',
        ] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                   id="{{ $key }}-tab"
                   data-bs-toggle="tab"
                   href="#{{ $key }}"
                   role="tab">
                    {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content mt-3" id="reportTabsContent">

        @foreach ([
            'general_ledger'   => 'General Ledger',
            'trial_balance'    => 'Trial Balance',
            'profit_loss'      => 'Profit & Loss',
            'balance_sheet'    => 'Balance Sheet',
            'party_ledger'     => 'Party Ledger',
            'receivables'      => 'Receivables',
            'payables'         => 'Payables',
            'cash_book'        => 'Cash Book',
            'bank_book'        => 'Bank Book',
            'journal_book'     => 'Journal / Day Book',
            'expense_analysis' => 'Expense Analysis',
            'cash_flow'        => 'Cash Flow',
        ] as $key => $label)

        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
             id="{{ $key }}" role="tabpanel">

            {{-- ── Filter form ──────────────────────────────────── --}}
            <form method="GET" action="{{ route('reports.accounts') }}"
                  class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="{{ $key }}">
                <input type="hidden" name="tab"    value="{{ $key }}">

                <div class="col-md-2">
                    <input type="date" name="from_date"
                           value="{{ request('from_date', $from) }}"
                           class="form-control" required>
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date"
                           value="{{ request('to_date', $to) }}"
                           class="form-control" required>
                </div>

                {{-- Account selector (relevant for GL and party ledger) --}}
                <div class="col-md-4">
                    <select name="account_id" class="form-control select2">
                        <option value="">-- All Accounts --</option>
                        @foreach ($chartOfAccounts as $coa)
                            <option value="{{ $coa->id }}"
                                {{ request('account_id') == $coa->id ? 'selected' : '' }}>
                                {{ $coa->account_code }} — {{ $coa->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>

                {{-- ── PDF export button ──────────────────────── --}}
                <div class="col-md-2">
                    <button type="button"
                            class="btn btn-danger w-100"
                            onclick="exportReportPDF('{{ $key }}', '{{ $label }}')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </form>

            {{-- ── Report heading (shows in PDF) ──────────────── --}}
            <div class="d-none d-print-block mb-2">
                <h5 class="mb-0">{{ $label }}</h5>
                <small class="text-muted">Period: {{ $from }} to {{ $to }}</small>
            </div>

            {{-- ── Report table ─────────────────────────────────── --}}
            <div class="table-responsive" id="report-table-{{ $key }}">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">

                        {{-- FIX 1: Correct column headers per report type --}}
                        @if ($key === 'general_ledger')
                            <tr>
                                <th>Date</th>
                                <th>Account</th>       {{-- FIX: was "Voucher" --}}
                                <th>Voucher / Ref</th>  {{-- FIX: was "Account" --}}
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'trial_balance')
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        @elseif ($key === 'profit_loss')
                            <tr>
                                <th>Particulars</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        @elseif ($key === 'balance_sheet')
                            <tr>
                                <th>Assets</th>
                                <th class="text-end">Amount</th>
                                <th>Liabilities &amp; Equity</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        @elseif ($key === 'party_ledger')
                            <tr>
                                <th>Date</th>
                                <th>Party</th>
                                <th>Voucher / Ref</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'receivables')
                            <tr>
                                <th>Account</th>
                                <th class="text-end">Total Receivable</th>
                            </tr>
                        @elseif ($key === 'payables')
                            <tr>
                                <th>Account</th>
                                <th class="text-end">Total Payable</th>
                            </tr>
                        @elseif ($key === 'cash_book')
                            <tr>
                                <th>Date</th>
                                <th>Debit Account</th>
                                <th>Credit Account</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'bank_book')
                            <tr>
                                <th>Date</th>
                                <th>Debit Account</th>
                                <th>Credit Account</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        @elseif ($key === 'journal_book')
                            <tr>
                                <th>Date</th>
                                <th>Voucher</th>
                                <th>Debit Account</th>
                                <th>Credit Account</th>
                                <th class="text-end">Amount</th>
                                <th class="no-print text-center">Actions</th>
                            </tr>
                        @elseif ($key === 'expense_analysis')
                            <tr>
                                <th>Expense Head</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        @elseif ($key === 'cash_flow')
                            <tr>
                                <th>Activity</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        @endif

                    </thead>
                    <tbody>

                    @forelse ($reports[$key] ?? [] as $rowIndex => $row)
                        <tr>
                            {{-- ──────────────────────────────────────────────────
                                 FIX 2 + 3: Custom row rendering for reports that
                                 need clickable voucher links and action buttons.
                                 General ledger and party ledger have their columns
                                 in the correct order: Date | Account | Voucher/Ref
                            ────────────────────────────────────────────────────── --}}

                            @if ($key === 'general_ledger')
                                {{-- row: [date, account_name, voucher_ref, dr, cr, balance] --}}
                                <td>{{ $row[0] }}</td>
                                <td>{{ $row[1] }}</td>
                                <td>
                                    @php $ref = $row[2]; @endphp
                                    @if (str_starts_with($ref, 'Voucher #'))
                                        @php $vid = intval(str_replace('Voucher #', '', $ref)); @endphp
                                        <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                           target="_blank" class="voucher-link" title="Print voucher">
                                            {{ $ref }}
                                        </a>
                                        <span class="no-print ms-1">
                                            <a href="{{ route('vouchers.index', ['type' => 'journal']) }}#{{ $vid }}"
                                               class="report-action-btn btn btn-outline-secondary btn-sm"
                                               title="View voucher">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </span>
                                    @else
                                        <span class="text-muted fst-italic">{{ $ref }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row[3] }}</td>
                                <td class="text-end">{{ $row[4] }}</td>
                                <td class="text-end fw-bold">{{ $row[5] }}</td>

                            @elseif ($key === 'party_ledger')
                                {{-- row: [date, account_name, voucher_ref, dr, cr, balance] --}}
                                <td>{{ $row[0] }}</td>
                                <td>{{ $row[1] }}</td>
                                <td>
                                    @php $ref = $row[2]; @endphp
                                    @if (str_starts_with($ref, 'Voucher #'))
                                        @php $vid = intval(explode(' ', str_replace('Voucher #', '', $ref))[0]); @endphp
                                        <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                           target="_blank" class="voucher-link" title="Print voucher">
                                            {{ $ref }}
                                        </a>
                                    @elseif (str_starts_with($ref, 'PI-'))
                                        @php $pid = intval(str_replace('PI-', '', $ref)); @endphp
                                        <a href="{{ route('purchase_invoices.print', $pid) }}"
                                           target="_blank" class="voucher-link text-success" title="Print purchase invoice">
                                            {{ $ref }}
                                        </a>
                                    @elseif (str_starts_with($ref, 'SI-'))
                                        @php $sid = intval(str_replace('SI-', '', $ref)); @endphp
                                        <a href="{{ route('sale_invoices.print', $sid) }}"
                                           target="_blank" class="voucher-link text-primary" title="Print sale invoice">
                                            {{ $ref }}
                                        </a>
                                    @else
                                        <span class="text-muted fst-italic">{{ $ref }}</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row[3] }}</td>
                                <td class="text-end">{{ $row[4] }}</td>
                                <td class="text-end fw-bold">{{ $row[5] }}</td>

                            @elseif ($key === 'journal_book')
                                {{-- row: [date, voucher_ref, dr_account, cr_account, amount] --}}
                                <td>{{ $row[0] }}</td>
                                <td>
                                    @php
                                        $ref = $row[1];
                                        $vid = intval(str_replace('Voucher #', '', $ref));
                                    @endphp
                                    @if ($vid)
                                        <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                           target="_blank" class="voucher-link" title="Print voucher">
                                            {{ $ref }}
                                        </a>
                                    @else
                                        {{ $ref }}
                                    @endif
                                </td>
                                <td>{{ $row[2] }}</td>
                                <td>{{ $row[3] }}</td>
                                <td class="text-end fw-bold">{{ $row[4] }}</td>
                                {{-- FIX 3: Action buttons on journal book rows --}}
                                <td class="text-center no-print">
                                    @if ($vid)
                                        <a href="{{ route('vouchers.print', ['type' => 'journal', 'id' => $vid]) }}"
                                           target="_blank"
                                           class="report-action-btn btn btn-outline-success btn-sm"
                                           title="Print">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <a href="{{ route('vouchers.edit', ['type' => 'journal', 'id' => $vid]) }}"
                                           class="report-action-btn btn btn-outline-primary btn-sm"
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif
                                </td>

                            @else
                                {{-- Default rendering for all other reports --}}
                                @foreach ($row as $colIndex => $col)
                                    <td class="{{ is_numeric(str_replace(',', '', $col)) && $col !== '' ? 'text-end' : '' }}
                                               {{ ($key === 'profit_loss' && in_array($col, ['REVENUE','LESS: COST OF GOODS SOLD','GROSS PROFIT','OPERATING EXPENSES','NET PROFIT / LOSS'])) ? 'fw-bold table-active' : '' }}">
                                        {{ $col }}
                                    </td>
                                @endforeach
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-3">
                                No data found for the selected period.
                            </td>
                        </tr>
                    @endforelse

                    </tbody>

                    {{-- Totals footer for receivables and payables --}}
                    @if (in_array($key, ['receivables', 'payables']) && count($reports[$key] ?? []) > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Total:</td>
                            <td class="text-end">
                                {{ number_format(
                                    collect($reports[$key])->sum(fn($r) => (float) str_replace(',', '', $r[1])),
                                    2
                                ) }}
                            </td>
                        </tr>
                    </tfoot>
                    @endif

                </table>
            </div>

        </div>
        @endforeach

    </div>
</div>

{{-- ════════════════════════════════════════════════════════════════
     PDF EXPORT — uses print CSS, opens a clean print window
     with just the table and report heading
════════════════════════════════════════════════════════════════ --}}
<script>
function exportReportPDF(reportKey, reportLabel) {
    // Collect current filter values
    const from      = document.querySelector(`#${reportKey} input[name="from_date"]`)?.value
                   || '{{ $from }}';
    const to        = document.querySelector(`#${reportKey} input[name="to_date"]`)?.value
                   || '{{ $to }}';
    const accountEl = document.querySelector(`#${reportKey} select[name="account_id"]`);
    const accountName = accountEl?.options[accountEl.selectedIndex]?.text || 'All Accounts';

    // Grab the table HTML
    const tableEl = document.getElementById(`report-table-${reportKey}`);
    if (!tableEl) return;

    // Clone it, remove action columns marked no-print
    const clone = tableEl.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(el => el.remove());

    const html = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>${reportLabel}</title>
    <style>
        body  { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; color: #222; }
        h2    { font-size: 15px; margin: 0 0 2px; }
        p     { font-size: 10px; color: #555; margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th    { background: #1a1a2e; color: #fff; padding: 5px 7px; text-align: left; font-size: 11px; }
        td    { padding: 4px 7px; border-bottom: 0.5px solid #ddd; font-size: 11px; }
        tr:nth-child(even) td { background: #f9f9f9; }
        .text-end { text-align: right; }
        .fw-bold  { font-weight: bold; }
        .table-active td { background: #e9ecef !important; font-weight: bold; }
        .fst-italic { font-style: italic; color: #888; }
    </style>
</head>
<body>
    <h2>${reportLabel}</h2>
    <p>Period: ${from} &nbsp;to&nbsp; ${to} &nbsp;&nbsp;|&nbsp;&nbsp; Account: ${accountName}</p>
    ${clone.innerHTML}
    <script>window.onload = function() { window.print(); }<\/script>
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}

// ── Tab activation from URL hash / query param ──────────────────
document.addEventListener('DOMContentLoaded', function () {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        let tab = urlParams.get('tab') || window.location.hash.replace('#', '');

        if (tab) {
            const el = document.querySelector(`.nav-link[href="#${tab}"]`);
            if (el && typeof bootstrap !== 'undefined') {
                new bootstrap.Tab(el).show();
                history.replaceState(null, null,
                    window.location.pathname + window.location.search + '#' + tab);
            } else if (el) {
                document.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
                document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show', 'active'));
                el.classList.add('active');
                const pane = document.querySelector(el.getAttribute('href'));
                if (pane) pane.classList.add('show', 'active');
            }
        }
    } catch (e) {
        console.error('Tab activation error', e);
    }
});
</script>

@endsection