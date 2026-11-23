@extends('layouts.app')

@section('title', 'PSX | Profit & Loss')

@section('content')
@php
  $pl  = fn ($n) => $n > 0 ? 'text-success' : ($n < 0 ? 'text-danger' : '');
  $qs  = fn ($tab) => array_merge(['tab' => $tab], array_filter([
            'from_date' => $filters['from'], 'to_date' => $filters['to'],
            'symbol' => $filters['symbol'], 'intraday' => $filters['intraday'], 'broker_id' => $filters['broker_id'],
         ]));
  $winRate = $summary['trades'] ? $summary['winning'] / $summary['trades'] * 100 : 0;
@endphp
<style>
  @media print { .no-print { display: none !important; } }
  #plTable td, #plTable th { white-space: nowrap; }
</style>
<div class="row">
  <div class="col">
    {{-- Filters --}}
    <section class="card no-print mb-3">
      <div class="card-body">
        <form method="GET" action="{{ route('reports.psx_pl') }}">
          <input type="hidden" name="tab" value="{{ $tab }}">
          <div class="row g-3 align-items-end">
            <div class="col-md-2">
              <label>Sold From</label>
              <input type="date" name="from_date" class="form-control" value="{{ $filters['from'] }}">
            </div>
            <div class="col-md-2">
              <label>Sold To</label>
              <input type="date" name="to_date" class="form-control" value="{{ $filters['to'] }}">
            </div>
            <div class="col-md-2">
              <label>Broker</label>
              <select name="broker_id" class="form-control">
                <option value="">All brokers</option>
                @foreach ($brokers as $b)
                  <option value="{{ $b->id }}" {{ $filters['broker_id'] == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Stock</label>
              <select name="symbol" class="form-control select2-js">
                <option value="">All stocks</option>
                @foreach ($symbols as $sym)
                  <option value="{{ $sym }}" {{ $filters['symbol'] === $sym ? 'selected' : '' }}>{{ $sym }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Trade Type</label>
              <select name="intraday" class="form-control">
                <option value="">All</option>
                <option value="yes" {{ $filters['intraday'] === 'yes' ? 'selected' : '' }}>Intraday only</option>
                <option value="no" {{ $filters['intraday'] === 'no' ? 'selected' : '' }}>Delivery only</option>
              </select>
            </div>
            <div class="col-md-2">
              <button class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
              <a href="{{ route('reports.psx_pl', ['tab' => $tab]) }}" class="btn btn-default">Reset</a>
            </div>
            <div class="col-12">
              <button type="button" class="btn btn-default btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
              <a href="{{ route('reports.psx_pl', array_merge($qs($tab), ['export' => 'csv'])) }}" class="btn btn-default btn-sm"><i class="fas fa-file-excel"></i> Export CSV (Excel)</a>
            </div>
          </div>
        </form>
      </div>
    </section>

    {{-- Summary: net of commission --}}
    <div class="row mb-1">
      @foreach ([
        ['Shares sold (matched)', number_format($summary['qty']), 'primary', ''],
        ['Cost incl. commission', 'Rs ' . number_format($summary['cost'], 2), 'info', ''],
        ['Proceeds after commission', 'Rs ' . number_format($summary['proceeds'], 2), 'secondary', ''],
        ['Net P/L', 'Rs ' . number_format($summary['net'], 2), 'success', $pl($summary['net'])],
        ['Won / lost', $summary['winning'] . ' / ' . $summary['losing'], 'dark', ''],
        ['Win rate', number_format($winRate, 1) . '%', 'warning', ''],
      ] as [$label, $value, $colour, $cls])
      <div class="col-xl-2 col-md-4 col-6 mb-2">
        <section class="card card-featured-left card-featured-{{ $colour }}"><div class="card-body py-3">
          <small class="text-muted">{{ $label }}</small>
          <h4 class="mb-0 {{ $cls }}">{{ $value }}</h4>
        </div></section>
      </div>
      @endforeach
    </div>
    <p class="text-muted small mb-3">
      Cost includes the buy commission and proceeds are after the sell commission, so every figure is net.
      A same-day (intraday) trade carries one commission, on the sale.
      @can('reports.psx_commission')<a href="{{ route('reports.psx_commission', array_filter(['period' => 'custom', 'from_date' => $filters['from'], 'to_date' => $filters['to'], 'broker_id' => $filters['broker_id']])) }}" class="no-print">See commission paid</a>.@endcan
    </p>

    <div class="tabs">
      <ul class="nav nav-tabs no-print">
        <li class="nav-item"><a class="nav-link {{ $tab === 'TW' ? 'active' : '' }}" href="{{ route('reports.psx_pl', $qs('TW')) }}">Trade-wise</a></li>
        <li class="nav-item"><a class="nav-link {{ $tab === 'SW' ? 'active' : '' }}" href="{{ route('reports.psx_pl', $qs('SW')) }}">Stock-wise</a></li>
      </ul>

      <div class="tab-content">
        @if ($tab === 'SW')
        <div class="tab-pane fade show active">
          <div class="table-responsive">
            <table class="table table-bordered table-striped mb-0">
              <thead>
                <tr>
                  <th>Stock</th>
                  <th class="text-end">Sales</th>
                  <th class="text-end">Shares sold</th>
                  <th class="text-end">Cost incl. commission</th>
                  <th class="text-end">Proceeds after commission</th>
                  <th class="text-end">Net P/L</th>
                  <th class="text-end">Net P/L %</th>
                  <th class="text-end">Won / lost</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($byStock as $s)
                <tr>
                  <td><strong>{{ $s['symbol'] }}</strong></td>
                  <td class="text-end">{{ $s['trades'] }}</td>
                  <td class="text-end">{{ number_format($s['qty']) }}</td>
                  <td class="text-end">{{ number_format($s['cost'], 2) }}</td>
                  <td class="text-end">{{ number_format($s['proceeds'], 2) }}</td>
                  <td class="text-end fw-bold {{ $pl($s['net']) }}">{{ number_format($s['net'], 2) }}</td>
                  <td class="text-end {{ $pl($s['net']) }}">{{ $s['cost'] ? number_format($s['net'] / $s['cost'] * 100, 2) . '%' : '–' }}</td>
                  <td class="text-end">{{ $s['winning'] }} / {{ $s['losing'] }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted">No closed trades for this filter.</td></tr>
                @endforelse
              </tbody>
              <tfoot>
                <tr class="fw-bold">
                  <td>Total</td>
                  <td class="text-end">{{ $summary['trades'] }}</td>
                  <td class="text-end">{{ number_format($summary['qty']) }}</td>
                  <td class="text-end">{{ number_format($summary['cost'], 2) }}</td>
                  <td class="text-end">{{ number_format($summary['proceeds'], 2) }}</td>
                  <td class="text-end {{ $pl($summary['net']) }}">{{ number_format($summary['net'], 2) }}</td>
                  <td class="text-end {{ $pl($summary['net']) }}">{{ $summary['cost'] ? number_format($summary['net'] / $summary['cost'] * 100, 2) . '%' : '–' }}</td>
                  <td class="text-end">{{ $summary['winning'] }} / {{ $summary['losing'] }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
        @else
        <div class="tab-pane fade show active">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover mb-0" id="plTable">
              <thead>
                <tr>
                  <th>Stock</th>
                  <th>Bought</th>
                  <th>Sold</th>
                  <th class="text-end">Shares</th>
                  <th class="text-end">Buy rate<br><small>incl. commission</small></th>
                  <th class="text-end">Cost</th>
                  <th class="text-end">Sell rate<br><small>after commission</small></th>
                  <th class="text-end">Proceeds</th>
                  <th class="text-end">Net P/L</th>
                  <th class="text-end">Net P/L %</th>
                  <th>Type</th>
                  <th>Broker</th>
                  <th>Buy # / Sell #</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($matches as $m)
                <tr>
                  <td><strong>{{ $m['symbol'] }}</strong></td>
                  <td data-order="{{ $m['buy_date'] }}">{{ \Carbon\Carbon::parse($m['buy_date'])->format('d-M-Y') }}</td>
                  <td data-order="{{ $m['sell_date'] }}">{{ \Carbon\Carbon::parse($m['sell_date'])->format('d-M-Y') }}</td>
                  <td class="text-end">{{ number_format($m['qty']) }}</td>
                  <td class="text-end" title="Buy price {{ number_format($m['buy_price'], 4) }}">{{ number_format($m['buy_rate'], 4) }}</td>
                  <td class="text-end">{{ number_format($m['buy_cost'], 2) }}</td>
                  <td class="text-end" title="Sell price {{ number_format($m['sell_price'], 4) }}">{{ number_format($m['sell_rate'], 4) }}</td>
                  <td class="text-end">{{ number_format($m['sell_proceeds'], 2) }}</td>
                  <td class="text-end fw-bold {{ $pl($m['net_pl']) }}">{{ number_format($m['net_pl'], 2) }}</td>
                  <td class="text-end {{ $pl($m['net_pl']) }}">{{ $m['net_pl_percent'] !== null ? number_format($m['net_pl_percent'], 2) . '%' : '-' }}</td>
                  <td>
                    @if($m['is_intraday'])
                      <span class="badge bg-info">Intraday</span>
                    @else
                      <span class="badge bg-secondary">{{ $m['buy_type'] === 'opening' ? 'Opening' : 'Delivery' }}</span>
                      <small class="text-muted">{{ $m['holding_days'] }}d</small>
                    @endif
                  </td>
                  <td><small>{{ $m['broker'] }}</small></td>
                  <td><small>
                    <a href="{{ route('psx_trades.show', $m['buy_trade_id']) }}">{{ $m['buy_trade_no'] }}</a> /
                    <a href="{{ route('psx_trades.show', $m['sell_trade_id']) }}">{{ $m['sell_trade_no'] }}</a>
                  </small></td>
                </tr>
                @endforeach
              </tbody>
              @if (count($matches))
              <tfoot>
                <tr class="fw-bold">
                  <td colspan="3">Total</td>
                  <td class="text-end">{{ number_format($summary['qty']) }}</td>
                  <td></td>
                  <td class="text-end">{{ number_format($summary['cost'], 2) }}</td>
                  <td></td>
                  <td class="text-end">{{ number_format($summary['proceeds'], 2) }}</td>
                  <td class="text-end {{ $pl($summary['net']) }}">{{ number_format($summary['net'], 2) }}</td>
                  <td colspan="4"></td>
                </tr>
              </tfoot>
              @endif
            </table>
          </div>
          <small class="text-muted">Hover a rate to see the plain buy or sell price. The CSV export also has the commission columns.</small>
        </div>
        @endif
      </div>
    </div>
  </div>
</div>
<script>
  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    if ($('#plTable tbody tr').length) {
      $('#plTable').DataTable({ pageLength: 50, order: [[2, 'desc']] });
    }
  });
</script>
@endsection
