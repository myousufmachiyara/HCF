@extends('layouts.app')

@section('title', 'PSX | Profit & Loss')

@section('content')
@php
  $pl  = fn ($n) => $n > 0 ? 'text-success' : ($n < 0 ? 'text-danger' : '');
  $qs  = fn ($tab) => array_merge(['tab' => $tab], array_filter([
            'from_date' => $filters['from'], 'to_date' => $filters['to'],
            'symbol' => $filters['symbol'], 'intraday' => $filters['intraday'],
         ]));
  $winRate = $summary['trades'] ? $summary['winning'] / $summary['trades'] * 100 : 0;
@endphp
<style>
  @media print { .no-print { display: none !important; } }
  #plTable td, #plTable th { white-space: nowrap; }
</style>
<div class="row">
  <div class="col">
    <div class="no-print">
      @include('psx._demo_banner', [
        'broker' => $broker,
        'slot'   => 'Realised profit and loss on the sample trades. Each sell is matched against the oldest buy (FIFO), so partly sold buys are handled automatically.',
      ])
    </div>

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
            <div class="col-md-3">
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
            <div class="col-md-3">
              <button class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
              <a href="{{ route('reports.psx_pl', ['tab' => $tab]) }}" class="btn btn-default">Reset</a>
              <button type="button" class="btn btn-default" onclick="window.print()"><i class="fas fa-print"></i></button>
            </div>
          </div>
        </form>
      </div>
    </section>

    {{-- Portfolio summary (same figures as the Excel summary block) --}}
    <div class="row mb-1">
      @foreach ([
        ['Closed trades', number_format($summary['trades']), 'primary', ''],
        ['Gross P/L', 'Rs ' . number_format($summary['gross'], 2), 'info', $pl($summary['gross'])],
        ['Total commission', 'Rs ' . number_format($summary['commission'], 2), 'warning', ''],
        ['Net P/L', 'Rs ' . number_format($summary['net'], 2), 'secondary', $pl($summary['net'])],
        ['Winning / Losing', $summary['winning'] . ' / ' . $summary['losing'], 'success', ''],
        ['Win rate', number_format($winRate, 1) . '%', 'dark', ''],
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
      Turnover (selling value) Rs {{ number_format($summary['turnover'], 2) }}.
      {{ $summary['intraday'] }} intraday matches; Rs {{ number_format($summary['waived'], 2) }} buy commission waived.
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
                  <th class="text-end">Trades</th>
                  <th class="text-end">Qty Sold</th>
                  <th class="text-end">Selling Value</th>
                  <th class="text-end">Gross P/L</th>
                  <th class="text-end">Commission</th>
                  <th class="text-end">Net P/L</th>
                  <th class="text-end">Won / Lost</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($byStock as $s)
                <tr>
                  <td><strong>{{ $s['symbol'] }}</strong></td>
                  <td class="text-end">{{ $s['trades'] }}</td>
                  <td class="text-end">{{ number_format($s['qty']) }}</td>
                  <td class="text-end">{{ number_format($s['turnover'], 2) }}</td>
                  <td class="text-end {{ $pl($s['gross']) }}">{{ number_format($s['gross'], 2) }}</td>
                  <td class="text-end">{{ number_format($s['commission'], 2) }}</td>
                  <td class="text-end fw-bold {{ $pl($s['net']) }}">{{ number_format($s['net'], 2) }}</td>
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
                  <td class="text-end">{{ number_format($summary['turnover'], 2) }}</td>
                  <td class="text-end {{ $pl($summary['gross']) }}">{{ number_format($summary['gross'], 2) }}</td>
                  <td class="text-end">{{ number_format($summary['commission'], 2) }}</td>
                  <td class="text-end {{ $pl($summary['net']) }}">{{ number_format($summary['net'], 2) }}</td>
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
                  <th>Purchase Date</th>
                  <th class="text-end">Purchase Price</th>
                  <th>Selling Date</th>
                  <th class="text-end">Selling Price</th>
                  <th class="text-end">Matched Qty</th>
                  <th class="text-end">Purchase Value</th>
                  <th class="text-end">Selling Value</th>
                  <th class="text-end">Buy Comm.</th>
                  <th class="text-end">Sell Comm.</th>
                  <th class="text-end">Total Comm.</th>
                  <th class="text-end">Gross P/L</th>
                  <th class="text-end">Net P/L</th>
                  <th class="text-end">Net P/L %</th>
                  <th>Type</th>
                  <th>Buy # / Sell #</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($matches as $m)
                <tr>
                  <td><strong>{{ $m['symbol'] }}</strong></td>
                  <td data-order="{{ $m['buy_date'] }}">{{ \Carbon\Carbon::parse($m['buy_date'])->format('d-M-Y') }}</td>
                  <td class="text-end">{{ number_format($m['buy_price'], 2) }}</td>
                  <td data-order="{{ $m['sell_date'] }}">{{ \Carbon\Carbon::parse($m['sell_date'])->format('d-M-Y') }}</td>
                  <td class="text-end">{{ number_format($m['sell_price'], 2) }}</td>
                  <td class="text-end">{{ number_format($m['qty']) }}</td>
                  <td class="text-end">{{ number_format($m['purchase_value'], 2) }}</td>
                  <td class="text-end">{{ number_format($m['selling_value'], 2) }}</td>
                  <td class="text-end">
                    {{ number_format($m['buy_commission'], 2) }}
                    @if($m['buy_waived'] > 0)<br><small class="text-success" title="Intraday waiver">−{{ number_format($m['buy_waived'], 2) }} waived</small>@endif
                  </td>
                  <td class="text-end">{{ number_format($m['sell_commission'], 2) }}</td>
                  <td class="text-end">{{ number_format($m['total_commission'], 2) }}</td>
                  <td class="text-end {{ $pl($m['gross_pl']) }}">{{ number_format($m['gross_pl'], 2) }}</td>
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
                  <td><small>{{ $m['buy_trade_no'] }} / {{ $m['sell_trade_no'] }}</small></td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
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
      $('#plTable').DataTable({ pageLength: 50, order: [[3, 'desc']] });
    }
  });
</script>
@endsection
