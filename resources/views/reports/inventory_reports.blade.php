@extends('layouts.app')
@section('title', 'Inventory Reports')

@section('content')
<div class="tabs">
    <ul class="nav nav-tabs card-header-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab=='IL'?'active fw-bold':'' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'IL']) }}">
                Item Ledger
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='SR'?'active fw-bold':'' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'SR']) }}">
                Stock Inhand
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='STR'?'active fw-bold':'' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'STR']) }}">
                Stock Transfer
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='LWS'?'active fw-bold':'' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => 'LWS']) }}">
                Lot Wise Stock
            </a>
        </li>
    </ul>

    <div class="tab-content pt-3">

        {{-- ============================================================ --}}
        {{-- 1. ITEM LEDGER TAB                                           --}}
        {{-- ============================================================ --}}
        <div id="IL" class="tab-pane fade {{ $tab=='IL'?'show active':'' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-4">
                <input type="hidden" name="tab" value="IL">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="small fw-bold">Product</label>
                        <select name="item_id" class="form-select form-select-sm" required>
                            <option value="">-- Select Product --</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold">Location (Optional)</label>
                        <select name="location_id" class="form-select form-select-sm">
                            <option value="">-- All Locations --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}"
                                    {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">From</label>
                        <input type="date" name="from_date" value="{{ $from }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">To</label>
                        <input type="date" name="to_date" value="{{ $to }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter Ledger</button>
                    </div>
                </div>
            </form>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Qty In</th>
                        <th>Qty Out</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @if(request('item_id'))
                        <tr class="table-info">
                            <td colspan="5" class="text-end fw-bold">Opening Balance:</td>
                            <td class="fw-bold">{{ $openingQty }}</td>
                        </tr>
                        @php $currentBal = $openingQty; @endphp
                        @forelse($itemLedger as $row)
                            @php $currentBal += ($row['qty_in'] - $row['qty_out']); @endphp
                            <tr>
                                <td>{{ $row['date'] }}</td>
                                <td>
                                    <span class="badge {{ $row['qty_in'] > 0 ? 'bg-success' : 'bg-danger' }}">
                                        {{ $row['type'] }}
                                    </span>
                                </td>
                                <td>{{ $row['description'] }}</td>
                                <td class="text-success">{{ $row['qty_in'] ?: '-' }}</td>
                                <td class="text-danger">{{ $row['qty_out'] ?: '-' }}</td>
                                <td class="fw-bold">{{ $currentBal }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center">No transactions in this period.</td>
                            </tr>
                        @endforelse
                    @else
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                Please select a product to generate ledger.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- ============================================================ --}}
        {{-- 2. STOCK INHAND TAB                                          --}}
        {{-- ============================================================ --}}
        <div id="SR" class="tab-pane fade {{ $tab=='SR'?'show active':'' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-4">
                <input type="hidden" name="tab" value="SR">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="small fw-bold">Product (Optional)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold">Location (Optional)</label>
                        <select name="location_id" class="form-select form-select-sm">
                            <option value="">-- All Locations --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}"
                                    {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success btn-sm w-100">Filter</button>
                    </div>
                </div>
            </form>

            <table class="table table-sm table-striped table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Product Name</th>
                        <th>Variation (SKU)</th>
                        <th>Location</th>
                        <th>Lot Number</th>
                        <th class="text-end">Current Qty</th>
                        <th>Date Received</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stockInHand as $stock)
                        <tr>
                            <td><strong>{{ $stock['product'] }}</strong></td>
                            <td><code class="text-dark">{{ $stock['variation'] }}</code></td>
                            <td><span class="badge bg-info text-dark">{{ $stock['location'] }}</span></td>
                            <td><span class="badge bg-secondary">{{ $stock['lot_number'] }}</span></td>
                            <td class="fw-bold text-end">
                                {{ number_format($stock['quantity'], 2) }} {{ $stock['unit'] }}
                            </td>
                            <td><small>{{ $stock['created_at'] }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-3">No inventory found in stock lots.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($stockInHand->isNotEmpty())
                <tfoot class="table-light">
                    <tr>
                        <th colspan="4" class="text-end">Total Available:</th>
                        <th class="text-end">{{ number_format($stockInHand->sum('quantity'), 2) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>

        {{-- ============================================================ --}}
        {{-- 3. STOCK TRANSFER TAB                                        --}}
        {{-- ============================================================ --}}
        <div id="STR" class="tab-pane fade {{ $tab=='STR'?'show active':'' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-4">
                <input type="hidden" name="tab" value="STR">
                <div class="row g-2">
                    <div class="col-md-2">
                        <label class="small fw-bold">From Location</label>
                        <select name="from_location_id" class="form-select form-select-sm">
                            <option value="">-- All --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}"
                                    {{ request('from_location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">To Location</label>
                        <select name="to_location_id" class="form-select form-select-sm">
                            <option value="">-- All --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}"
                                    {{ request('to_location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold">From Date</label>
                        <input type="date" name="from_date" value="{{ $from }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold">To Date</label>
                        <input type="date" name="to_date" value="{{ $to }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning btn-sm w-100 fw-bold">Filter Transfers</button>
                    </div>
                </div>
            </form>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Ref #</th>
                        <th>Product</th>
                        <th>Source (From)</th>
                        <th>Destination (To)</th>
                        <th>Qty Transferred</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stockTransfers as $st)
                        <tr>
                            <td>{{ $st['date'] }}</td>
                            <td>TRN-{{ str_pad($st['reference'], 5, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $st['product'] }}</td>
                            <td><span class="text-danger">{{ $st['from'] }}</span></td>
                            <td><span class="text-success">{{ $st['to'] }}</span></td>
                            <td class="fw-bold">{{ $st['quantity'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No transfers recorded in this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ============================================================ --}}
        {{-- 4. LOT WISE STOCK TAB                                        --}}
        {{-- ============================================================ --}}
        <div id="LWS" class="tab-pane fade {{ $tab=='LWS'?'show active':'' }}">
            <form method="GET" class="border p-3 bg-light rounded mb-4">
                <input type="hidden" name="tab" value="LWS">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="small fw-bold">Product (Optional)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}"
                                    {{ request('item_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">Location (Optional)</label>
                        <select name="location_id" class="form-select form-select-sm">
                            <option value="">-- All Locations --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}"
                                    {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">Lot Number (Optional)</label>
                        <input type="text" name="lot_number" value="{{ request('lot_number') }}"
                               class="form-control form-control-sm" placeholder="e.g. LOT-001">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">From Date</label>
                        <input type="date" name="from_date" value="{{ $from }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold">To Date</label>
                        <input type="date" name="to_date" value="{{ $to }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </div>
            </form>

            {{------ Section A: Current Stock by Lot & Location ---- --}}
            <h6 class="fw-bold text-dark mb-2">
                📦 Lot-wise Stock Available
                <small class="text-muted fw-normal">(All locations including vendors &amp; customers)</small>
            </h6>
            <table class="table table-sm table-bordered table-striped align-middle mb-4">
                <thead class="table-dark">
                    <tr>
                        <th>Lot Number</th>
                        <th>Product</th>
                        <th>Variation (SKU)</th>
                        <th>Location</th>
                        <th class="text-end">Available Qty</th>
                        <th>Date Received</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lotWiseStock as $stock)
                        <tr>
                            <td><span class="badge bg-secondary">{{ $stock['lot_number'] }}</span></td>
                            <td><strong>{{ $stock['product'] }}</strong></td>
                            <td><code class="text-dark">{{ $stock['variation'] }}</code></td>
                            <td><span class="badge bg-info text-dark">{{ $stock['location'] }}</span></td>
                            <td class="fw-bold text-end">
                                {{ number_format($stock['quantity'], 2) }} {{ $stock['unit'] }}
                            </td>
                            <td><small>{{ $stock['created_at'] }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">
                                No stock found. Use the filters above to search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($lotWiseStock->isNotEmpty())
                <tfoot class="table-light">
                    <tr>
                        <th colspan="4" class="text-end">Total Available:</th>
                        <th class="text-end">{{ number_format($lotWiseStock->sum('quantity'), 2) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
                @endif
            </table>

            {{-- ---- Section B: Transfer Movement Ledger per Lot ---- --}}
            <h6 class="fw-bold text-dark mb-2">
                🔄 Lot-wise Transfer Movements
                @if(request('lot_number'))
                    <span class="badge bg-dark ms-1">{{ request('lot_number') }}</span>
                @endif
            </h6>

            @if(!request('lot_number'))
                <div class="alert alert-info py-2 mb-0">
                    Enter a <strong>Lot Number</strong> in the filter above to view its full transfer movement history.
                </div>
            @else
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Ref #</th>
                            <th>Product</th>
                            <th>Lot No.</th>
                            <th>From Location</th>
                            <th>To Location</th>
                            <th class="text-end">Qty Moved</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lotWiseLedger as $row)
                            <tr>
                                <td>{{ $row['date'] }}</td>
                                <td>TRN-{{ str_pad($row['reference'], 5, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $row['product'] }}</td>
                                <td><span class="badge bg-secondary">{{ $row['lot_number'] }}</span></td>
                                <td><span class="text-danger fw-bold">{{ $row['from_location'] }}</span></td>
                                <td><span class="text-success fw-bold">{{ $row['to_location'] }}</span></td>
                                <td class="text-end fw-bold">{{ $row['quantity'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-3">
                                    No transfer movements found for lot
                                    <strong>{{ request('lot_number') }}</strong>
                                    in the selected period.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>

                </table>
            @endif

        </div>{{-- end LWS tab --}}

    </div>{{-- end tab-content --}}
</div>{{-- end tabs --}}
@endsection