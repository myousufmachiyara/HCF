@extends('layouts.app')

@section('title', 'PSX | Stock Movement')

@section('content')
@php
  $n  = fn ($v, $d = 0) => $v == 0 ? '–' : number_format($v, $d);
  $pl = fn ($v) => $v > 0 ? 'text-success' : ($v < 0 ? 'text-danger' : '');
  $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-M-Y') : null;
  $symbolSelect = '<div class="col-md-2"><label>Stock</label><select name="symbol" class="form-control select2-js"><option value="">All stocks</option>'
    . $symbols->map(fn ($s) => '<option value="' . e($s) . '"' . ($filters['symbol'] === $s ? ' selected' : '') . '>' . e($s) . '</option>')->implode('')
    . '</select></div>';
@endphp
<style>
  @media print { .no-print { display: none !important; } }
  #moveTable td, #moveTable th { white-space: nowrap; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  #moveTable .grp { border-left: 2px solid #dee2e6; }
</style>
<div class="row">
  <div class="col">
    @include('psx.reports._period_filter', ['action' => route('reports.psx_movement'), 'extra' => $symbolSelect])

    <section class="card">
      <header class="card-header">
        <h2 class="card-title">Stock movement · {{ $filters['from'] ? $fmt($filters['from']) : 'start' }} to {{ $fmt($filters['to']) }}</h2>
      </header>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-sm mb-0" id="moveTable">
            <thead>
              <tr>
                <th rowspan="2">Stock</th>
                <th colspan="2" class="text-center grp">Opening{{ $filters['from'] ? ' (' . $fmt(\Carbon\Carbon::parse($filters['from'])->subDay()) . ')' : '' }}</th>
                <th colspan="2" class="text-center grp">Bought</th>
                <th colspan="4" class="text-center grp">Sold</th>
                <th colspan="3" class="text-center grp">Closing ({{ $fmt($filters['to']) }})</th>
              </tr>
              <tr>
                <th class="num grp">Shares</th><th class="num">Cost</th>
                <th class="num grp">Shares</th><th class="num">Cost</th>
                <th class="num grp">Shares</th><th class="num">Cost</th><th class="num">Proceeds</th><th class="num">Net P/L</th>
                <th class="num grp">Shares</th><th class="num">Cost</th><th class="num">Avg cost</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($rows as $r)
              <tr>
                <td><strong>{{ $r['symbol'] }}</strong>@if($r['opening_entries'])<br><small class="text-info">incl. {{ number_format($r['opening_entries']) }} opening balance</small>@endif</td>
                <td class="num grp">{{ $n($r['open_qty']) }}</td><td class="num">{{ $n($r['open_cost'], 2) }}</td>
                <td class="num grp">{{ $n($r['buy_qty']) }}</td><td class="num">{{ $n($r['buy_cost'], 2) }}</td>
                <td class="num grp">{{ $n($r['sell_qty']) }}</td><td class="num">{{ $n($r['sold_cost'], 2) }}</td><td class="num">{{ $n($r['proceeds'], 2) }}</td>
                <td class="num fw-bold {{ $pl($r['net_pl']) }}">{{ $n($r['net_pl'], 2) }}</td>
                <td class="num grp fw-bold">{{ $n($r['close_qty']) }}</td><td class="num">{{ $n($r['close_cost'], 2) }}</td>
                <td class="num">{{ $r['close_qty'] ? number_format($r['close_cost'] / $r['close_qty'], 4) : '–' }}</td>
              </tr>
              @empty
              <tr><td colspan="12" class="text-center text-muted">No positions or trades in this period.</td></tr>
              @endforelse
            </tbody>
            @if (count($rows))
            <tfoot>
              <tr class="fw-bold">
                <td>Total</td>
                <td class="grp"></td><td class="num">{{ number_format($totals['open_cost'], 2) }}</td>
                <td class="grp"></td><td class="num">{{ number_format($totals['buy_cost'], 2) }}</td>
                <td class="grp"></td><td class="num">{{ number_format($totals['sold_cost'], 2) }}</td><td class="num">{{ number_format($totals['proceeds'], 2) }}</td>
                <td class="num {{ $pl($totals['net_pl']) }}">{{ number_format($totals['net_pl'], 2) }}</td>
                <td class="grp"></td><td class="num">{{ number_format($totals['close_cost'], 2) }}</td><td></td>
              </tr>
            </tfoot>
            @endif
          </table>
        </div>
        <small class="text-muted d-block mt-2">
          All costs include the buy commission; proceeds are after the sell commission, so net P/L = proceeds − cost of the shares sold.
          Opening cost + bought − cost of shares sold = closing cost.
          <a href="{{ request()->fullUrlWithQuery(['all' => 1]) }}" class="no-print">Show every stock</a>
        </small>
      </div>
    </section>
  </div>
</div>
<script>$(function () { $('.select2-js').select2({ width: '100%' }); });</script>
@endsection
