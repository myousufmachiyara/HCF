@extends('layouts.app')
@section('title', 'Inventory Reports')

@section('content')
<div class="tabs">

    {{-- ── Tab navigation ──────────────────────────────────────────── --}}
    <ul class="nav nav-tabs card-header-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'IL' ? 'active fw-bold' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'IL']) }}">
                Item Ledger
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'SR' ? 'active fw-bold' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'SR']) }}">
                Stock In Hand
            </a>
        </li>
    </ul>

    <div class="tab-content pt-3">

        {{-- ============================================================ --}}
        {{-- 1. ITEM LEDGER                                               --}}
        {{-- ============================================================ --}}
        <div id="IL" class="tab-pane fade {{ $tab === 'IL' ? 'show active' : '' }}">

            <form method="GET" class="border p-3 bg-light rounded mb-4">
                <input type="hidden" name="tab" value="IL">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="small fw-bold">
                            Product <span class="text-danger">*</span>
                        </label>
                        <select name="item_id" class="form-select form-select-sm" required>
                            <option value="">-- Select Product --</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold">From</label>
                        <input type="date" name="from_date" value="{{ $from }}"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold">To</label>
                        <input type="date" name="to_date" value="{{ $to }}"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            Filter Ledger
                        </button>
                    </div>
                </div>
            </form>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th class="text-end">Qty In</th>
                        <th class="text-end">Qty Out</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @if (request('item_id'))

                        {{-- Opening balance row --}}
                        <tr class="table-info">
                            <td>{{ $from }}</td>
                            <td colspan="2" class="fw-bold">Opening Balance</td>
                            <td class="text-end">—</td>
                            <td class="text-end">—</td>
                            <td class="text-end fw-bold">
                                {{ number_format($openingQty, 2) }}
                            </td>
                        </tr>

                        @php $runningBalance = $openingQty; @endphp

                        @forelse ($itemLedger as $row)
                            @php
                                $qtyIn  = (float) $row['qty_in'];
                                $qtyOut = (float) $row['qty_out'];
                                $runningBalance += ($qtyIn - $qtyOut);

                                $badgeClass = match ($row['type']) {
                                    'Purchase'        => 'bg-success',
                                    'Sale'            => 'bg-danger',
                                    'Purchase Return' => 'bg-warning text-dark',
                                    'Sale Return'     => 'bg-info text-dark',
                                    default           => 'bg-secondary',
                                };
                            @endphp
                            <tr>
                                <td>{{ $row['date'] }}</td>
                                <td>
                                    <span class="badge {{ $badgeClass }}">
                                        {{ $row['type'] }}
                                    </span>
                                </td>
                                <td>{{ $row['description'] }}</td>
                                <td class="text-end text-success">
                                    {{ $qtyIn  > 0 ? number_format($qtyIn,  2) : '—' }}
                                </td>
                                <td class="text-end text-danger">
                                    {{ $qtyOut > 0 ? number_format($qtyOut, 2) : '—' }}
                                </td>
                                <td class="text-end fw-bold">
                                    {{ number_format($runningBalance, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-3">
                                    No transactions found in this period.
                                </td>
                            </tr>
                        @endforelse

                        @if ($itemLedger->count() > 0)
                            <tr class="table-secondary fw-bold">
                                <td colspan="5" class="text-end">Closing Balance</td>
                                <td class="text-end">
                                    {{ number_format($runningBalance, 2) }}
                                </td>
                            </tr>
                        @endif

                    @else
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">
                                Please select a product to generate the ledger.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- ============================================================ --}}
        {{-- 2. STOCK IN HAND                                             --}}
        {{-- ============================================================ --}}
        <div id="SR" class="tab-pane fade {{ $tab === 'SR' ? 'show active' : '' }}">

            <form method="GET" class="border p-3 bg-light rounded mb-4">
                <input type="hidden" name="tab" value="SR">
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="small fw-bold">Product (optional — leave blank for all)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success btn-sm w-100">
                            Show Stock
                        </button>
                    </div>
                </div>
            </form>

            <table class="table table-sm table-striped table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Product</th>
                        <th>Variation (SKU)</th>
                        <th class="text-end">Current Stock</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockInHand as $stock)
                        <tr>
                            <td><strong>{{ $stock['product'] }}</strong></td>
                            <td>
                                <code class="text-dark">{{ $stock['variation'] }}</code>
                            </td>
                            <td class="text-end fw-bold
                                {{ $stock['quantity'] <= 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($stock['quantity'], 2) }}
                            </td>
                            <td>{{ $stock['unit'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-3 text-muted">
                                No stock found. Click "Show Stock" to load.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($stockInHand->isNotEmpty())
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="2" class="text-end">Total Units In Stock:</td>
                        <td class="text-end">
                            {{ number_format($stockInHand->sum('quantity'), 2) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                @endif
            </table>

        </div>

    </div>{{-- end tab-content --}}
</div>{{-- end tabs --}}
@endsection