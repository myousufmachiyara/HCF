@extends('layouts.app')

@section('title', 'PSX | Commission')

@section('content')
@php
  $n  = fn ($v, $d = 2) => number_format($v, $d);
  $pl = fn ($v) => $v > 0 ? 'text-success' : ($v < 0 ? 'text-danger' : '');
  $byNames = ['month' => 'Month', 'day' => 'Day', 'stock' => 'Stock', 'broker' => 'Broker'];
  $bySelect = '<div class="col-md-2"><label>Group by</label><select name="by" class="form-control">'
    . collect($byNames)->map(fn ($l, $k) => '<option value="' . $k . '"' . ($filters['by'] === $k ? ' selected' : '') . '>' . $l . '</option>')->implode('')
    . '</select></div>';
@endphp
<style>
  @media print { .no-print { display: none !important; } }
  .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
<div class="row">
  <div class="col">
    @include('psx.reports._period_filter', ['action' => route('reports.psx_commission'), 'extra' => $bySelect])

    <div class="row mb-3">
      @foreach ([
        ['Commission on closed trades (as in Excel)', 'Rs ' . $n($totals['closed_comm']), 'warning'],
        ['Commission paid on all trades', 'Rs ' . $n($totals['total']), 'secondary'],
        ['Not charged (same-day trades)', 'Rs ' . $n($totals['saved']), 'info'],
        ['Gross / net P/L', 'Rs ' . $n($totals['gross_pl'], 0) . ' / ' . $n($totals['net_pl'], 0), 'primary'],
      ] as [$cardLabel, $value, $colour])
      <div class="col-xl-3 col-md-6 mb-2">
        <section class="card card-featured-left card-featured-{{ $colour }}"><div class="card-body py-3">
          <small class="text-muted">{{ $cardLabel }}</small><h4 class="mb-0">{{ $value }}</h4>
        </div></section>
      </div>
      @endforeach
    </div>

    <section class="card">
      <header class="card-header"><h2 class="card-title">Commission by {{ strtolower($byNames[$filters['by']]) }} · {{ strtolower($label) }}</h2></header>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0">
            <thead>
              <tr>
                <th>{{ $byNames[$filters['by']] }}</th>
                <th class="num">Bought (value)</th>
                <th class="num">Sold (value)</th>
                <th class="num">Paid on buys</th>
                <th class="num">Paid on sells</th>
                <th class="num">Total paid</th>
                <th class="num">Not charged<br><small>same-day buys</small></th>
                <th class="num">On closed trades<br><small>as in Excel</small></th>
                <th class="num">Gross P/L</th>
                <th class="num">Net P/L<br><small>gross − commission</small></th>
              </tr>
            </thead>
            <tbody>
              @forelse ($rows as $r)
              <tr>
                <td>{{ $r['label'] }}</td>
                <td class="num">{{ $n($r['buy_value']) }}</td>
                <td class="num">{{ $n($r['sell_value']) }}</td>
                <td class="num">{{ $n($r['buy_comm']) }}</td>
                <td class="num">{{ $n($r['sell_comm']) }}</td>
                <td class="num fw-bold">{{ $n($r['total']) }}</td>
                <td class="num text-muted">{{ $r['saved'] > 0 ? $n($r['saved']) : '–' }}</td>
                <td class="num fw-bold">{{ $n($r['closed_comm']) }}</td>
                <td class="num {{ $pl($r['gross_pl']) }}">{{ $n($r['gross_pl']) }}</td>
                <td class="num fw-bold {{ $pl($r['net_pl']) }}">{{ $n($r['net_pl']) }}</td>
              </tr>
              @empty
              <tr><td colspan="10" class="text-center text-muted">No trades in this period.</td></tr>
              @endforelse
            </tbody>
            @if (count($rows))
            <tfoot>
              <tr class="fw-bold">
                <td>Total</td>
                <td class="num">{{ $n($totals['buy_value']) }}</td>
                <td class="num">{{ $n($totals['sell_value']) }}</td>
                <td class="num">{{ $n($totals['buy_comm']) }}</td>
                <td class="num">{{ $n($totals['sell_comm']) }}</td>
                <td class="num">{{ $n($totals['total']) }}</td>
                <td class="num">{{ $n($totals['saved']) }}</td>
                <td class="num">{{ $n($totals['closed_comm']) }}</td>
                <td class="num {{ $pl($totals['gross_pl']) }}">{{ $n($totals['gross_pl']) }}</td>
                <td class="num {{ $pl($totals['net_pl']) }}">{{ $n($totals['net_pl']) }}</td>
              </tr>
            </tfoot>
            @endif
          </table>
        </div>
        <small class="text-muted d-block mt-2">
          <strong>On closed trades</strong> is what the Excel sheet totals: the buy and sell commission of the shares sold, counted on the sale date. It is the commission inside the P/L.
          <strong>Total paid</strong> counts every buy and sell on its trade date, including buys not sold yet; the difference is commission sitting in the cost of shares still held (see Open Positions).
          A buy sold the same day carries no commission; one commission is charged on the sale.
          Each commission is rounded to the paisa, as on a broker's bill, so totals can differ from Excel by a few paisa.
          @if ($totals['other'] > 0) Other charges entered on trades: Rs {{ $n($totals['other']) }}. @endif
        </small>
      </div>
    </section>
  </div>
</div>
@endsection
