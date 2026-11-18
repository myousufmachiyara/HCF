@extends('layouts.app')

@section('title', 'PSX | Trades')

@section('content')
@php
  $count = fn ($type) => count(array_filter($rows, fn ($t) => $t['type'] === $type));
  $sum   = fn ($type, $field) => array_sum(array_column(array_filter($rows, fn ($t) => $t['type'] === $type), $field));
  $badge = ['buy' => 'bg-success', 'sell' => 'bg-danger', 'opening' => 'bg-info'];
@endphp
<div class="row">
  <div class="col">
    @include('psx._alerts')

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
          <h4 class="mb-0">Rs {{ number_format(array_sum(array_column($rows, 'commission')) - array_sum(array_column($rows, 'waived')), 2) }}</h4>
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
          @if ($brokers->count() > 1)
          <div class="col-md-3">
            <select id="filterBroker" class="form-control">
              <option value="">All brokers</option>
              @foreach ($brokers as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
            </select>
          </div>
          @endif
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover" id="datatable-psx-trades">
            <thead>
              <tr>
                <th>Trade #</th>
                <th>Date</th>
                <th>Type</th>
                <th>Stock</th>
                <th>Broker</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Price</th>
                <th class="text-end">Value</th>
                <th class="text-end">Commission</th>
                <th class="text-end">Intraday Waived</th>
                <th class="text-end">Other Charges</th>
                <th class="text-end">Net Amount</th>
                <th>Remarks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($rows as $t)
              <tr>
                <td data-order="{{ $t['trade_id'] }}"><a href="{{ route('psx_trades.show', $t['trade_id']) }}">{{ $t['trade_no'] }}</a></td>
                <td class="text-nowrap" data-order="{{ $t['date'] }}">{{ \Carbon\Carbon::parse($t['date'])->format('d-M-Y') }}</td>
                <td><span class="badge {{ $badge[$t['type']] }}">{{ ucfirst($t['type']) }}</span></td>
                <td>
                  <strong>{{ $t['symbol'] }}</strong>
                  @if($t['stock_name'])<br><small class="text-muted">{{ $t['stock_name'] }}</small>@endif
                </td>
                <td>{{ $t['broker'] }}</td>
                <td class="text-end">{{ number_format($t['qty']) }}</td>
                <td class="text-end">{{ number_format($t['price'], 2) }}</td>
                <td class="text-end">{{ number_format($t['value'], 2) }}</td>
                <td class="text-end">{{ number_format($t['commission'], 2) }}</td>
                <td class="text-end {{ $t['waived'] > 0 ? 'text-success' : 'text-muted' }}">{{ $t['waived'] > 0 ? number_format($t['waived'], 2) : '-' }}</td>
                <td class="text-end">{{ $t['other_charges'] > 0 ? number_format($t['other_charges'], 2) : '-' }}</td>
                <td class="text-end">{{ number_format($t['net_amount'], 2) }}</td>
                <td><small>{{ $t['remarks'] }}</small></td>
                <td class="text-nowrap">
                  <a href="{{ route('psx_trades.show', $t['trade_id']) }}" class="text-dark me-1" title="View"><i class="fas fa-eye"></i></a>
                  @can('psx_trades.edit')
                  <a href="{{ route('psx_trades.edit', $t['trade_id']) }}" class="text-primary me-1" title="Edit"><i class="fas fa-edit"></i></a>
                  @endcan
                  @can('psx_trades.print')
                  <a href="{{ route('psx_trades.print', $t['trade_id']) }}" target="_blank" class="text-success me-1" title="Print"><i class="fas fa-print"></i></a>
                  @endcan
                  @can('psx_trades.delete')
                  <form action="{{ route('psx_trades.destroy', $t['trade_id']) }}" method="POST" class="d-inline"
                        onsubmit="return confirm('Delete trade {{ $t['trade_no'] }}? All its rows will be deleted.')">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-link p-0 m-0 text-danger" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                  @endcan
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <small class="text-muted">
          One line per stock row. A trade (#) can hold several rows; edit, print and delete apply to the whole trade.
          Net amount: for a buy, value + charges (paid to the broker); for a sell, value − charges (received).
        </small>
      </div>
    </section>
  </div>
</div>
<script>
  $(document).ready(function () {
    var table = $('#datatable-psx-trades').DataTable({ pageLength: 50, order: [[1, 'desc'], [0, 'desc']] });
    $('#filterType').on('change', function () {
      table.column(2).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
    });
    $('#filterSymbol').on('input change', function () {
      table.column(3).search(this.value).draw();
    });
    $('#filterBroker').on('change', function () {
      table.column(4).search(this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '', true, false).draw();
    });
  });
</script>
@endsection
