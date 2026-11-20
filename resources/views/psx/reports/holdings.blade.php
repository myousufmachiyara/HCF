@extends('layouts.app')

@section('title', 'PSX | Holdings')

@section('content')
<style>
  @media print { .no-print { display: none !important; } }
  .lots-row td { background: #f8f9fa; }
  .mkt-price { max-width: 110px; margin-left: auto; text-align: right; }
</style>
<div class="row">
  <div class="col">
    <section class="card no-print mb-3">
      <div class="card-body">
        <form method="GET" action="{{ route('reports.psx_holdings') }}" class="row g-3 align-items-end">
          <div class="col-md-3">
            <label>Broker</label>
            <select name="broker_id" class="form-control">
              <option value="">All brokers</option>
              @foreach ($brokers as $b)
                <option value="{{ $b->id }}" {{ $filters['broker_id'] == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
              @endforeach
            </select>
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
          <div class="col-md-3">
            <button class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="{{ route('reports.psx_holdings') }}" class="btn btn-default">Reset</a>
          </div>
        </form>
      </div>
    </section>

    <div class="row mb-3">
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-primary"><div class="card-body py-3">
          <small class="text-muted">Stocks held</small><h4 class="mb-0">{{ count(array_unique(array_column($holdings, 'symbol'))) }}</h4>
        </div></section>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-info"><div class="card-body py-3">
          <small class="text-muted">Invested (at cost)</small><h4 class="mb-0">Rs {{ number_format($totals['cost'], 2) }}</h4>
        </div></section>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-secondary"><div class="card-body py-3">
          <small class="text-muted">Market value</small><h4 class="mb-0" id="cardMkt">-</h4>
        </div></section>
      </div>
      <div class="col-md-3 col-6 mb-2">
        <section class="card card-featured-left card-featured-warning"><div class="card-body py-3">
          <small class="text-muted">Unrealised P/L</small><h4 class="mb-0" id="cardUpl">-</h4>
        </div></section>
      </div>
    </div>

    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Current Holdings</h2>
        <div class="no-print">
          <button type="button" class="btn btn-default" id="useLastPrice"><i class="fas fa-magic"></i> Fill last buy price</button>
          <button type="button" class="btn btn-default" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
      </header>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-hover mb-0" id="holdingsTable">
            <thead>
              <tr>
                <th>Stock</th>
                <th>Broker</th>
                <th>Held Since</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Avg Cost</th>
                <th class="text-end">Cost Value</th>
                <th class="text-end">Buy Commission</th>
                <th class="text-end" style="width:130px">Market Price</th>
                <th class="text-end">Market Value</th>
                <th class="text-end">Unrealised P/L</th>
                <th class="text-end">P/L %</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($holdings as $h)
              <tr class="h-row" data-qty="{{ $h['qty'] }}" data-cost="{{ $h['cost_value'] }}" data-last="{{ $h['last_price'] }}">
                <td>
                  <a href="#lots-{{ $loop->index }}" data-bs-toggle="collapse" class="no-print" title="Show lots"><i class="fas fa-caret-right"></i></a>
                  <strong>{{ $h['symbol'] }}</strong>
                  @if($h['stock_name'])<br><small class="text-muted">{{ $h['stock_name'] }}</small>@endif
                </td>
                <td>{{ $h['broker'] }}</td>
                <td>{{ \Carbon\Carbon::parse($h['since'])->format('d-M-Y') }}</td>
                <td class="text-end">{{ number_format($h['qty']) }}</td>
                <td class="text-end">{{ number_format($h['avg_cost'], 4) }}</td>
                <td class="text-end">{{ number_format($h['cost_value'], 2) }}</td>
                <td class="text-end">{{ number_format($h['commission'], 2) }}</td>
                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm mkt-price" placeholder="Price"></td>
                <td class="text-end mkt-value">-</td>
                <td class="text-end mkt-pl">-</td>
                <td class="text-end mkt-pct">-</td>
              </tr>
              <tr id="lots-{{ $loop->index }}" class="collapse lots-row">
                <td colspan="11">
                  <strong>Open lots ({{ count($h['lots']) }})</strong>, oldest first. These are sold first (FIFO), except shares bought and sold on the same day, which are matched together:
                  <table class="table table-sm mb-0 mt-1">
                    <thead><tr><th>Trade #</th><th>Type</th><th>Date</th><th class="text-end">Bought</th><th class="text-end">Still Held</th><th class="text-end">Price</th><th class="text-end">Value</th></tr></thead>
                    <tbody>
                      @foreach ($h['lots'] as $lot)
                      <tr>
                        <td>@if($lot['trade_id'])<a href="{{ route('psx_trades.show', $lot['trade_id']) }}">{{ $lot['trade_no'] }}</a>@else{{ $lot['trade_no'] }}@endif</td>
                        <td>{{ ucfirst($lot['type']) }}</td>
                        <td>{{ \Carbon\Carbon::parse($lot['date'])->format('d-M-Y') }}</td>
                        <td class="text-end">{{ number_format($lot['qty_original']) }}</td>
                        <td class="text-end">{{ number_format($lot['qty_open']) }}</td>
                        <td class="text-end">{{ number_format($lot['price'], 4) }}</td>
                        <td class="text-end">{{ number_format($lot['qty_open'] * $lot['price'], 2) }}</td>
                      </tr>
                      @endforeach
                    </tbody>
                  </table>
                </td>
              </tr>
              @empty
              <tr><td colspan="11" class="text-center text-muted">No open positions.</td></tr>
              @endforelse
            </tbody>
            <tfoot>
              <tr class="fw-bold">
                <td colspan="5" class="text-end">Total</td>
                <td class="text-end">{{ number_format($totals['cost'], 2) }}</td>
                <td class="text-end">{{ number_format($totals['commission'], 2) }}</td>
                <td></td>
                <td class="text-end" id="totMkt">-</td>
                <td class="text-end" id="totPl">-</td>
                <td class="text-end" id="totPct">-</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <small class="text-muted">
          Cost value = shares still held × their buy price. Buy commission is shown separately, as it will be in the accounts.
          Unrealised P/L = market value − cost value, before selling commission.
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
      if (isNaN(price)) {
        r.find('.mkt-value, .mkt-pl, .mkt-pct').text('-').removeClass('text-success text-danger');
        return;
      }
      any = true;
      var mkt = qty * price, pl = Math.round((mkt - cost) * 100) / 100;
      totMkt += mkt; totCost += cost;
      r.find('.mkt-value').text(fmt(mkt));
      r.find('.mkt-pl').text(fmt(pl)); colour(r.find('.mkt-pl'), pl);
      r.find('.mkt-pct').text(fmt(cost ? pl / cost * 100 : 0) + '%'); colour(r.find('.mkt-pct'), pl);
    });
    var pl = Math.round((totMkt - totCost) * 100) / 100;
    $('#totMkt').text(any ? fmt(totMkt) : '-');
    $('#totPl').text(any ? fmt(pl) : '-'); colour('#totPl', any ? pl : 0);
    $('#totPct').text(any && totCost ? fmt(pl / totCost * 100) + '%' : '-');
    $('#cardMkt').text(any ? 'Rs ' + fmt(totMkt) : '-');
    $('#cardUpl').text(any ? 'Rs ' + fmt(pl) : '-'); colour('#cardUpl', any ? pl : 0);
  }

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    $('#holdingsTable').on('input', '.mkt-price', recalcHoldings);
    $('#useLastPrice').on('click', function () {
      $('.h-row').each(function () { $(this).find('.mkt-price').val($(this).data('last')); });
      recalcHoldings();
    });
  });
</script>
@endsection
