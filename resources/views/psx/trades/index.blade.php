@extends('layouts.app')

@section('title', 'PSX | Trades')

@section('content')
@php
  $count = fn ($type) => count(array_filter($trades, fn ($t) => $t['type'] === $type));
  $sum   = fn ($type, $field) => array_sum(array_column(array_filter($trades, fn ($t) => $t['type'] === $type), $field));
  $badge = ['buy' => 'bg-success', 'sell' => 'bg-danger', 'opening' => 'bg-info'];
@endphp
<div class="row">
  <div class="col">
    @include('psx._demo_banner', ['broker' => $broker])

    <div class="row mb-3">
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-success"><div class="card-body py-3">
          <small class="text-muted">Buys</small>
          <h4 class="mb-0">{{ $count('buy') }} <small class="text-muted fs-6">Rs {{ number_format($sum('buy', 'value'), 0) }}</small></h4>
        </div></section>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-danger"><div class="card-body py-3">
          <small class="text-muted">Sells</small>
          <h4 class="mb-0">{{ $count('sell') }} <small class="text-muted fs-6">Rs {{ number_format($sum('sell', 'value'), 0) }}</small></h4>
        </div></section>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-info"><div class="card-body py-3">
          <small class="text-muted">Opening balances</small>
          <h4 class="mb-0">{{ $count('opening') }}</h4>
        </div></section>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-warning"><div class="card-body py-3">
          <small class="text-muted">Commission (after intraday waiver)</small>
          <h4 class="mb-0">Rs {{ number_format(array_sum(array_column($trades, 'commission')) - array_sum(array_column($trades, 'waived')), 2) }}</h4>
        </div></section>
      </div>
    </div>

    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">All Trades</h2>
        @can('psx_trades.create')
        <a href="{{ route('psx_trades.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Trade</a>
        @endcan
      </header>

      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-md-2">
            <select id="filterType" class="form-control">
              <option value="">All types</option>
              <option value="Buy">Buy</option>
              <option value="Sell">Sell</option>
              <option value="Opening">Opening</option>
            </select>
          </div>
          <div class="col-md-3">
            <input type="text" id="filterSymbol" class="form-control" placeholder="Stock symbol, e.g. TRG">
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover" id="datatable-psx-trades">
            <thead>
              <tr>
                <th>Trade #</th>
                <th>Date</th>
                <th>Type</th>
                <th>Stock</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Price</th>
                <th class="text-end">Value</th>
                <th class="text-end">Commission</th>
                <th class="text-end">Intraday Waived</th>
                <th class="text-end">Net Amount</th>
                <th>Remarks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($trades as $t)
              <tr>
                <td>{{ $t['trade_no'] }}</td>
                <td class="text-nowrap" data-order="{{ $t['date'] }}">{{ \Carbon\Carbon::parse($t['date'])->format('d-M-Y') }}</td>
                <td><span class="badge {{ $badge[$t['type']] }}">{{ ucfirst($t['type']) }}</span></td>
                <td>
                  <strong>{{ $t['symbol'] }}</strong>
                  @if($t['stock_name'])<br><small class="text-muted">{{ $t['stock_name'] }}</small>@endif
                </td>
                <td class="text-end">{{ number_format($t['qty']) }}</td>
                <td class="text-end">{{ number_format($t['price'], 2) }}</td>
                <td class="text-end">{{ number_format($t['value'], 2) }}</td>
                <td class="text-end">{{ number_format($t['commission'], 2) }}</td>
                <td class="text-end {{ $t['waived'] > 0 ? 'text-success' : 'text-muted' }}">{{ $t['waived'] > 0 ? number_format($t['waived'], 2) : '-' }}</td>
                <td class="text-end">{{ number_format($t['net_amount'], 2) }}</td>
                <td><small>{{ $t['remarks'] }}</small></td>
                <td class="text-nowrap" style="opacity:.35">
                  <span class="text-muted me-1" title="Disabled in demo"><i class="fas fa-edit"></i></span>
                  <span class="text-muted me-1" title="Disabled in demo"><i class="fas fa-print"></i></span>
                  <span class="text-muted" title="Disabled in demo"><i class="fas fa-trash"></i></span>
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <small class="text-muted">
          Net amount: for a buy, value + commission (money paid to the broker); for a sell, value − commission (money received).
          The intraday waiver is applied automatically once a same-day sell is matched against the buy.
        </small>
      </div>
    </section>
  </div>
</div>
<script>
  $(document).ready(function () {
    var table = $('#datatable-psx-trades').DataTable({ pageLength: 50, order: [[0, 'desc']] });
    $('#filterType').on('change', function () {
      table.column(2).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
    });
    $('#filterSymbol').on('keyup change', function () {
      table.column(3).search(this.value).draw();
    });
  });
</script>
@endsection
