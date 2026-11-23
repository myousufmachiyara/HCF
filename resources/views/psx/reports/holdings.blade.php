@extends('layouts.app')

@section('title', 'PSX | Open Positions')

@section('content')
@php
  $date = fn ($d) => \Carbon\Carbon::parse($d)->format('d-M-Y');
  $byBroker = $filters['view'] === 'broker';
  $cols = $byBroker ? 12 : 11;
@endphp
<style>
  @media print { .no-print { display: none !important; } .collapse { display: table-row !important; } }
  .lots-row > td { background: #f8f9fa; }
  .mkt-price { max-width: 110px; margin-left: auto; text-align: right; }
  #holdingsTable td, #holdingsTable th { white-space: nowrap; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .kpi-tiles { display: grid; gap: 0 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); }
</style>
<div class="row">
  <div class="col">
    <section class="card no-print mb-3">
      <div class="card-body">
        <form method="GET" action="{{ route('reports.psx_holdings') }}" class="row g-3 align-items-end">
          <div class="col-md-2">
            <label>As of</label>
            <input type="date" name="as_of" class="form-control" value="{{ $filters['as_of'] }}">
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
            <label>Show</label>
            <select name="view" class="form-control">
              <option value="stock" {{ !$byBroker ? 'selected' : '' }}>One line per stock</option>
              <option value="broker" {{ $byBroker ? 'selected' : '' }}>Per stock and broker</option>
            </select>
          </div>
          <div class="col-md-4">
            <button class="btn btn-primary"><i class="fas fa-filter"></i> Show</button>
            <a href="{{ route('reports.psx_holdings') }}" class="btn btn-default">Today</a>
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-default"><i class="fas fa-file-excel"></i> CSV</a>
            <button type="button" class="btn btn-default" onclick="window.print()"><i class="fas fa-print"></i></button>
          </div>
        </form>
      </div>
    </section>

    <div class="kpi-tiles mb-3">
      @foreach ([
        ['Stocks held', count($holdings), 'primary', null],
        ['Shares held', number_format($totals['qty']), 'info', null],
        ['Cost incl. commission', 'Rs ' . number_format($totals['cost'], 2), 'secondary', null],
        ['Market value', '–', 'success', 'cardMkt'],
        ['Unrealised P/L', '–', 'warning', 'cardUpl'],
      ] as [$label, $value, $colour, $id])
      <div class="mb-2">
        <section class="card card-featured-left card-featured-{{ $colour }}"><div class="card-body py-3">
          <small class="text-muted">{{ $label }}</small><h4 class="mb-0" @if($id) id="{{ $id }}" @endif>{{ $value }}</h4>
        </div></section>
      </div>
      @endforeach
    </div>

    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="card-title">Open positions as of {{ $date($filters['as_of']) }}</h2>
        <div class="no-print">
          <button type="button" class="btn btn-default btn-sm" id="expandAll"><i class="fas fa-list"></i> Show all purchases</button>
          <button type="button" class="btn btn-default btn-sm" id="useLastPrice"><i class="fas fa-magic"></i> Fill last buy price</button>
        </div>
      </header>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-hover mb-0" id="holdingsTable">
            <thead>
              <tr>
                <th>Stock</th>
                @if ($byBroker)<th>Broker</th>@endif
                <th>Held since</th>
                <th class="num">Shares</th>
                <th class="num">Avg buy price</th>
                <th class="num">Avg cost<br><small>incl. commission</small></th>
                <th class="num">Cost<br><small>incl. commission</small></th>
                <th class="num no-print" style="width:120px">Market price</th>
                <th class="num">Market value</th>
                <th class="num">Unrealised P/L</th>
                <th class="num">P/L %</th>
                <th class="num">Purchases</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($holdings as $i => $h)
              <tr class="h-row" data-qty="{{ $h['qty'] }}" data-cost="{{ $h['cost_value'] }}" data-last="{{ $h['last_price'] }}">
                <td>
                  <a href="#lots-{{ $i }}" data-bs-toggle="collapse" class="no-print text-decoration-none" title="Show purchases"><i class="fas fa-caret-right"></i></a>
                  <strong>{{ $h['symbol'] }}</strong>
                  @if($h['stock_name'])<br><small class="text-muted">{{ $h['stock_name'] }}</small>@endif
                </td>
                @if ($byBroker)<td>{{ $h['broker'] }}</td>@endif
                <td>{{ $date($h['since']) }}</td>
                <td class="num fw-bold">{{ number_format($h['qty']) }}</td>
                <td class="num">{{ number_format($h['avg_price'], 4) }}</td>
                <td class="num fw-bold">{{ number_format($h['avg_cost'], 4) }}</td>
                <td class="num">{{ number_format($h['cost_value'], 2) }}</td>
                <td class="no-print"><input type="number" step="0.01" min="0" class="form-control form-control-sm mkt-price" placeholder="Price" aria-label="Market price for {{ $h['symbol'] }}"></td>
                <td class="num mkt-value">–</td>
                <td class="num mkt-pl">–</td>
                <td class="num mkt-pct">–</td>
                <td class="num">{{ count($h['lots']) }}</td>
              </tr>
              <tr id="lots-{{ $i }}" class="collapse lots-row">
                <td colspan="{{ $cols }}">
                  <strong>{{ $h['symbol'] }}: {{ count($h['lots']) }} {{ \Illuminate\Support\Str::plural('purchase', count($h['lots'])) }} still held</strong>
                  <span class="text-muted">(oldest first; these are sold first, except shares bought and sold on the same day)</span>
                  <table class="table table-sm mb-0 mt-1">
                    <thead>
                      <tr><th>Trade #</th><th>Date</th><th>Type</th>@if(!$byBroker)<th>Broker</th>@endif<th class="num">Bought</th><th class="num">Still held</th>
                        <th class="num">Buy price</th><th class="num">Commission</th><th class="num">Cost incl. commission</th><th class="num">Cost per share</th></tr>
                    </thead>
                    <tbody>
                      @foreach ($h['lots'] as $lot)
                      <tr>
                        <td>@if($lot['trade_id'])<a href="{{ route('psx_trades.show', $lot['trade_id']) }}">{{ $lot['trade_no'] }}</a>@else{{ $lot['trade_no'] }}@endif</td>
                        <td>{{ $date($lot['date']) }}</td>
                        <td>{{ ucfirst($lot['type']) }}</td>
                        @if(!$byBroker)<td>{{ $lot['broker'] }}</td>@endif
                        <td class="num">{{ number_format($lot['qty_original']) }}</td>
                        <td class="num">{{ number_format($lot['qty_open']) }}</td>
                        <td class="num">{{ number_format($lot['price'], 4) }}</td>
                        <td class="num">{{ number_format($lot['commission_open'], 2) }}</td>
                        <td class="num">{{ number_format($lot['cost'], 2) }}</td>
                        <td class="num">{{ number_format($lot['rate'], 4) }}</td>
                      </tr>
                      @endforeach
                    </tbody>
                    <tfoot>
                      <tr class="fw-bold">
                        <td colspan="{{ $byBroker ? 4 : 5 }}" class="text-end">Merged</td>
                        <td class="num">{{ number_format($h['qty']) }}</td>
                        <td class="num">{{ number_format($h['avg_price'], 4) }}</td>
                        <td class="num">{{ number_format($h['commission'], 2) }}</td>
                        <td class="num">{{ number_format($h['cost_value'], 2) }}</td>
                        <td class="num">{{ number_format($h['avg_cost'], 4) }}</td>
                      </tr>
                    </tfoot>
                  </table>
                </td>
              </tr>
              @empty
              <tr><td colspan="{{ $cols }}" class="text-center text-muted">No open positions on this date.</td></tr>
              @endforelse
            </tbody>
            @if (count($holdings))
            <tfoot>
              <tr class="fw-bold">
                <td colspan="{{ $byBroker ? 3 : 2 }}" class="text-end">Total</td>
                <td class="num">{{ number_format($totals['qty']) }}</td>
                <td></td><td></td>
                <td class="num">{{ number_format($totals['cost'], 2) }}</td>
                <td class="no-print"></td>
                <td class="num" id="totMkt">–</td>
                <td class="num" id="totPl">–</td>
                <td class="num" id="totPct">–</td>
                <td class="num">{{ $totals['lots'] }}</td>
              </tr>
            </tfoot>
            @endif
          </table>
        </div>
        <small class="text-muted d-block mt-2">
          Avg cost = (shares × buy price + buy commission) ÷ shares, across every purchase still held.
          Unrealised P/L = market value − cost incl. commission, before the selling commission.
          Market prices you type are not saved.
        </small>
      </div>
    </section>
  </div>
</div>
<script>
  function fmt(n) { var v = Math.round(n * 100) / 100; if (v === 0) v = 0; return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function colour(el, n) { $(el).removeClass('text-success text-danger').addClass(n > 0 ? 'text-success' : (n < 0 ? 'text-danger' : '')); }

  function recalcHoldings() {
    var totMkt = 0, totCost = 0, any = false;
    $('.h-row').each(function () {
      var r = $(this), qty = +r.data('qty'), cost = +r.data('cost');
      var price = parseFloat(r.find('.mkt-price').val());
      if (isNaN(price)) { r.find('.mkt-value, .mkt-pl, .mkt-pct').text('–').removeClass('text-success text-danger'); return; }
      any = true;
      var mkt = qty * price, pl = Math.round((mkt - cost) * 100) / 100;
      totMkt += mkt; totCost += cost;
      r.find('.mkt-value').text(fmt(mkt));
      r.find('.mkt-pl').text(fmt(pl)); colour(r.find('.mkt-pl'), pl);
      r.find('.mkt-pct').text(fmt(cost ? pl / cost * 100 : 0) + '%'); colour(r.find('.mkt-pct'), pl);
    });
    var pl = Math.round((totMkt - totCost) * 100) / 100;
    $('#totMkt').text(any ? fmt(totMkt) : '–');
    $('#totPl').text(any ? fmt(pl) : '–'); colour('#totPl', any ? pl : 0);
    $('#totPct').text(any && totCost ? fmt(pl / totCost * 100) + '%' : '–');
    $('#cardMkt').text(any ? 'Rs ' + fmt(totMkt) : '–');
    $('#cardUpl').text(any ? 'Rs ' + fmt(pl) : '–'); colour('#cardUpl', any ? pl : 0);
  }

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    $('#holdingsTable').on('input', '.mkt-price', recalcHoldings);
    $('#useLastPrice').on('click', function () {
      $('.h-row').each(function () { $(this).find('.mkt-price').val($(this).data('last')); });
      recalcHoldings();
    });
    var open = false;
    $('#expandAll').on('click', function () {
      open = !open;
      $('.lots-row').toggleClass('show', open);
      $(this).html(open ? '<i class="fas fa-list"></i> Hide purchases' : '<i class="fas fa-list"></i> Show all purchases');
    });
  });
</script>
@endsection
