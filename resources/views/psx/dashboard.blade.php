@extends('layouts.app')

@section('title', 'PSX | Dashboard')

@section('content')
@php
  $money  = fn ($n, $d = 0) => 'Rs ' . number_format($n, $d);
  $signed = fn ($n, $d = 0) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . 'Rs ' . number_format(abs($n), $d);
  $arrow  = fn ($n) => $n > 0 ? '<i class="fas fa-caret-up psx-up" aria-hidden="true"></i>' : ($n < 0 ? '<i class="fas fa-caret-down psx-down" aria-hidden="true"></i>' : '');
  $winRate = $summary['trades'] ? $summary['winning'] / $summary['trades'] * 100 : null;
  $periodNames = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', '30d' => 'Last 30 days', 'year' => 'This year', 'all' => 'All time', 'custom' => 'Custom'];
  $periodText = $filters['period'] === 'custom'
      ? (($filters['from'] ? \Carbon\Carbon::parse($filters['from'])->format('d M Y') : 'Start') . ' – ' . ($filters['to'] ? \Carbon\Carbon::parse($filters['to'])->format('d M Y') : 'today'))
      : ($periodNames[$filters['period']] ?? 'All time');
  $qs = array_filter(['broker_id' => $filters['broker_id'], 'from_date' => $filters['from'], 'to_date' => $filters['to']]);
@endphp

<style>
  .psx-dash {
    --ink-1: #0b0b0b;
    --ink-2: #52514e;
    --ink-3: #8a8984;
    --rule: rgba(0, 0, 0, .08);
    --pos: #2a78d6;      /* profit / single-series blue */
    --neg: #e34948;      /* loss */
    --other: #b4b2a9;    /* 'Other' group: several stocks added together */
  }
  .psx-dash .tiles { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
  .psx-dash .tiles > * { min-width: 0; }
  .psx-dash .tiles .card { margin-bottom: 0; }
  .psx-dash .kpi { height: 100%; }
  .psx-dash .kpi .card-body { padding: 16px 18px; }
  .psx-dash .kpi-label { color: var(--ink-2); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
  .psx-dash .kpi-value { color: var(--ink-1); font-size: 24px; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; }
  .psx-dash .kpi-sub { color: var(--ink-2); font-size: 12px; margin-top: 6px; }
  .psx-dash .psx-up { color: var(--pos); }
  .psx-dash .psx-down { color: var(--neg); }
  .psx-dash .section-title { display: flex; justify-content: space-between; align-items: baseline; margin: 22px 0 10px; }
  .psx-dash .section-title h3 { font-size: 16px; font-weight: 700; margin: 0; color: var(--ink-1); }
  .psx-dash .section-title a { font-size: 13px; }
  .psx-dash .chart-box { position: relative; height: 280px; }
  .psx-dash .chart-box.tall { height: 320px; }
  .psx-dash .legend { display: flex; gap: 16px; font-size: 12px; color: var(--ink-2); white-space: nowrap; }
  .psx-dash .card-header.has-legend { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 4px 16px; }
  .psx-dash .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: -1px; }
  .psx-dash .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .psx-dash .empty { color: var(--ink-3); text-align: center; padding: 60px 10px; }
  .psx-dash details.table-view summary { cursor: pointer; color: var(--ink-2); font-size: 12px; margin-top: 8px; }
  .psx-dash .module-card { height: 100%; }
  .psx-dash .module-card .card-body { padding: 14px 16px; }
  .psx-dash .module-card .icon { width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: #eef4fc; color: var(--pos); margin-right: 10px; }
  .psx-dash .module-card h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--ink-1); }
  .psx-dash .module-card .figure { font-size: 18px; font-weight: 700; color: var(--ink-1); margin-top: 10px; font-variant-numeric: tabular-nums; }
  .psx-dash .module-card .meta { font-size: 12px; color: var(--ink-2); }
  .psx-dash .table-sm td, .psx-dash .table-sm th { padding: .4rem .5rem; }
</style>

<div class="psx-dash">

  {{-- Header + filters (one row) --}}
  <section class="card mb-3">
    <div class="card-body py-3">
      <form method="GET" action="{{ route('reports.psx_dashboard') }}" id="dashFilters" class="row g-2 align-items-end">
        <div class="col-lg-4 col-12">
          <h2 class="card-title mb-0">PSX Trading Dashboard</h2>
          <small class="text-muted">{{ $periodText }}{{ $filters['broker_id'] ? ' · ' . optional($brokers->firstWhere('id', $filters['broker_id']))->name : ' · All brokers' }}</small>
        </div>
        <div class="col-lg-2 col-6">
          <label class="small text-muted mb-1">Period</label>
          <select name="period" class="form-control" id="periodSel">
            @foreach ($periodNames as $key => $label)
              <option value="{{ $key }}" {{ $filters['period'] === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-2 col-6 custom-range" @if($filters['period'] !== 'custom') style="display:none" @endif>
          <label class="small text-muted mb-1">From</label>
          <input type="date" name="from_date" class="form-control" value="{{ $filters['from'] }}">
        </div>
        <div class="col-lg-2 col-6 custom-range" @if($filters['period'] !== 'custom') style="display:none" @endif>
          <label class="small text-muted mb-1">To</label>
          <input type="date" name="to_date" class="form-control" value="{{ $filters['to'] }}">
        </div>
        <div class="col-lg-2 col-6">
          <label class="small text-muted mb-1">Broker</label>
          <select name="broker_id" class="form-control">
            <option value="">All brokers</option>
            @foreach ($brokers as $b)
              <option value="{{ $b->id }}" {{ $filters['broker_id'] == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-auto custom-range" @if($filters['period'] !== 'custom') style="display:none" @endif>
          <button class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        </div>
      </form>
    </div>
  </section>

  {{-- Headline figures --}}
  <div class="tiles">
    <div>
      <section class="card kpi"><div class="card-body">
        <div class="kpi-label">Realised net P/L</div>
        <div class="kpi-value">{!! $arrow($summary['net']) !!} {{ $signed($summary['net']) }}</div>
        <div class="kpi-sub">Gross {{ $signed($summary['gross']) }} · after commission</div>
      </div></section>
    </div>
    <div>
      <section class="card kpi"><div class="card-body">
        <div class="kpi-label">Invested now (at cost)</div>
        <div class="kpi-value">{{ $money($portfolio['cost']) }}</div>
        <div class="kpi-sub">{{ $portfolio['stocks'] }} {{ \Illuminate\Support\Str::plural('stock', $portfolio['stocks']) }} held · all dates</div>
      </div></section>
    </div>
    <div>
      <section class="card kpi"><div class="card-body">
        <div class="kpi-label">Commission paid</div>
        <div class="kpi-value">{{ $money($activity['commission']) }}</div>
        <div class="kpi-sub">{{ $money($activity['saved']) }} not charged on same-day trades</div>
      </div></section>
    </div>
    <div>
      <section class="card kpi"><div class="card-body">
        <div class="kpi-label">Win rate</div>
        <div class="kpi-value">{{ $winRate === null ? '–' : number_format($winRate, 0) . '%' }}</div>
        <div class="kpi-sub">{{ $summary['winning'] }} won · {{ $summary['losing'] }} lost of {{ $summary['trades'] }} closed</div>
      </div></section>
    </div>
    <div>
      <section class="card kpi"><div class="card-body">
        <div class="kpi-label">Trading activity</div>
        <div class="kpi-value">{{ $activity['buys'] + $activity['sells'] }} <small class="fs-6 fw-normal text-muted">rows</small></div>
        <div class="kpi-sub">{{ $activity['buys'] }} buys {{ $money($activity['bought']) }} · {{ $activity['sells'] }} sells {{ $money($activity['sold']) }}</div>
      </div></section>
    </div>
  </div>

  {{-- PROFIT & LOSS --}}
  <div class="section-title">
    <h3>Profit &amp; Loss</h3>
    @can('reports.psx_pl')
    <a href="{{ route('reports.psx_pl', $qs) }}">Open Profit &amp; Loss report <i class="fas fa-arrow-right"></i></a>
    @endcan
  </div>
  <div class="row g-3">
    <div class="col-xl-8">
      <section class="card h-100">
        <header class="card-header has-legend">
          <h2 class="card-title">Realised net P/L by {{ $monthly ? 'month' : 'selling day' }}</h2>
          <div class="legend"><span><i style="background:var(--pos)"></i>Profit</span><span><i style="background:var(--neg)"></i>Loss</span></div>
        </header>
        <div class="card-body">
          @if (count($plSeries))
            <div class="chart-box"><canvas id="plChart" aria-label="Realised net profit and loss by {{ $monthly ? 'month' : 'day' }}" role="img"></canvas></div>
            <details class="table-view">
              <summary>View as table</summary>
              <table class="table table-sm mt-2 mb-0">
                <thead><tr><th>{{ $monthly ? 'Month' : 'Day' }}</th><th class="num">Net P/L</th><th class="num">Running total</th></tr></thead>
                <tbody>
                  @foreach ($plSeries as $p)
                    <tr><td>{{ $p['label'] }}</td><td class="num">{{ $signed($p['net'], 2) }}</td><td class="num">{{ $signed($p['running'], 2) }}</td></tr>
                  @endforeach
                </tbody>
              </table>
            </details>
          @else
            <div class="empty"><i class="fas fa-chart-bar fa-2x mb-2"></i><br>No shares sold in this period.</div>
          @endif
        </div>
      </section>
    </div>
    <div class="col-xl-4">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Intraday vs delivery</h2></header>
        <div class="card-body">
          <table class="table table-sm mb-3">
            <thead><tr><th></th><th class="num">Intraday</th><th class="num">Delivery</th></tr></thead>
            <tbody>
              <tr><td>Closed trades</td><td class="num">{{ $split['Intraday']['trades'] }}</td><td class="num">{{ $split['Delivery']['trades'] }}</td></tr>
              <tr><td>Gross P/L</td><td class="num">{{ $signed($split['Intraday']['gross']) }}</td><td class="num">{{ $signed($split['Delivery']['gross']) }}</td></tr>
              <tr><td>Commission</td><td class="num">{{ $money($split['Intraday']['commission']) }}</td><td class="num">{{ $money($split['Delivery']['commission']) }}</td></tr>
              <tr class="fw-bold"><td>Net P/L</td><td class="num">{!! $arrow($split['Intraday']['net']) !!} {{ $signed($split['Intraday']['net']) }}</td><td class="num">{!! $arrow($split['Delivery']['net']) !!} {{ $signed($split['Delivery']['net']) }}</td></tr>
              <tr><td>Won / lost</td><td class="num">{{ $split['Intraday']['winning'] }} / {{ $split['Intraday']['losing'] }}</td><td class="num">{{ $split['Delivery']['winning'] }} / {{ $split['Delivery']['losing'] }}</td></tr>
            </tbody>
          </table>
          <p class="small text-muted mb-0">
            Intraday: bought and sold the same day, one commission (on the sell).
            Delivery: held overnight or longer, commission on both sides.
          </p>
        </div>
      </section>
    </div>
  </div>

  <div class="row g-3 mt-0">
    <div class="col-xl-12">
      <section class="card">
        <header class="card-header has-legend">
          <h2 class="card-title">Best and worst stocks (realised net P/L)</h2>
          <div class="legend"><span><i style="background:var(--pos)"></i>Profit</span><span><i style="background:var(--neg)"></i>Loss</span></div>
        </header>
        <div class="card-body">
          @if (count($winners) || count($losers))
            <div class="chart-box" style="height: {{ max(160, 34 * (count($winners) + count($losers)) + 40) }}px"><canvas id="stockChart" role="img" aria-label="Best and worst stocks by realised net profit or loss"></canvas></div>
            <details class="table-view">
              <summary>View as table</summary>
              <table class="table table-sm mt-2 mb-0">
                <thead><tr><th>Stock</th><th class="num">Closed trades</th><th class="num">Net P/L</th></tr></thead>
                <tbody>
                  @foreach (array_merge($winners, $losers) as $s)
                    <tr><td>{{ $s['symbol'] }}</td><td class="num">{{ $s['trades'] }}</td><td class="num">{{ $signed($s['net'], 2) }}</td></tr>
                  @endforeach
                </tbody>
              </table>
            </details>
          @else
            <div class="empty">No shares sold in this period.</div>
          @endif
        </div>
      </section>
    </div>
  </div>

  {{-- HOLDINGS --}}
  <div class="section-title">
    <h3>Holdings <small class="text-muted fw-normal">· current, all dates</small></h3>
    @can('reports.psx_holdings')
    <a href="{{ route('reports.psx_holdings', array_filter(['broker_id' => $filters['broker_id']])) }}">Open Holdings <i class="fas fa-arrow-right"></i></a>
    @endcan
  </div>
  <div class="row g-3">
    <div class="col-xl-8">
      <section class="card h-100">
        <header class="card-header has-legend">
          <h2 class="card-title">Where the money is invested (at cost)</h2>
          @if (count($allocation) && str_starts_with(end($allocation)['symbol'], 'Other'))
            <div class="legend"><span><i style="background:var(--pos)"></i>Stock</span><span><i style="background:var(--other)"></i>Other stocks combined</span></div>
          @endif
        </header>
        <div class="card-body">
          @if (count($allocation))
            <div class="chart-box" style="height: {{ max(160, 34 * count($allocation) + 40) }}px"><canvas id="allocChart" role="img" aria-label="Invested amount at cost by stock"></canvas></div>
            <details class="table-view">
              <summary>View as table</summary>
              <table class="table table-sm mt-2 mb-0">
                <thead><tr><th>Stock</th><th class="num">Shares</th><th class="num">Cost</th><th class="num">Share of portfolio</th></tr></thead>
                <tbody>
                  @foreach ($allocation as $a)
                    <tr><td>{{ $a['symbol'] }}</td><td class="num">{{ number_format($a['qty']) }}</td><td class="num">{{ $money($a['cost']) }}</td><td class="num">{{ $portfolio['cost'] ? number_format($a['cost'] / $portfolio['cost'] * 100, 1) : 0 }}%</td></tr>
                  @endforeach
                </tbody>
              </table>
            </details>
          @else
            <div class="empty">No shares held.</div>
          @endif
        </div>
      </section>
    </div>
    <div class="col-xl-4">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Portfolio at a glance</h2></header>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><td>Stocks held</td><td class="num">{{ $portfolio['stocks'] }}</td></tr>
            <tr><td>Open lots</td><td class="num">{{ $portfolio['lots'] }}</td></tr>
            <tr><td>Invested at cost</td><td class="num">{{ $money($portfolio['cost'], 2) }}</td></tr>
            @if (count($allocation))
              <tr><td>Largest holding</td><td class="num">{{ $allocation[0]['symbol'] }} · {{ $portfolio['cost'] ? number_format($allocation[0]['cost'] / $portfolio['cost'] * 100, 1) : 0 }}%</td></tr>
            @endif
            <tr><td>Realised net P/L, all time</td><td class="num">{!! $arrow($modules['all_net']) !!} {{ $signed($modules['all_net']) }}</td></tr>
          </table>
          <p class="small text-muted mt-3 mb-0">Market value and unrealised P/L are on the Holdings screen, where you enter today's prices.</p>
        </div>
      </section>
    </div>
  </div>

  {{-- TRADES --}}
  <div class="section-title">
    <h3>Trades</h3>
    <div>
      @can('psx_trades.create')
      <a href="{{ route('psx_trades.create') }}" class="btn btn-primary btn-sm me-2"><i class="fas fa-plus"></i> New Trade</a>
      @endcan
      @can('psx_trades.index')
      <a href="{{ route('psx_trades.index') }}">All trades <i class="fas fa-arrow-right"></i></a>
      @endcan
    </div>
  </div>
  <section class="card">
    <div class="card-body">
      @if (count($recent))
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead>
            <tr><th>Trade #</th><th>Date</th><th>Type</th><th>Stock</th><th>Broker</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Value</th><th class="num">Commission</th></tr>
          </thead>
          <tbody>
            @foreach ($recent as $t)
            <tr>
              <td><a href="{{ route('psx_trades.show', $t['trade_id']) }}">{{ $t['trade_no'] }}</a></td>
              <td class="text-nowrap">{{ \Carbon\Carbon::parse($t['date'])->format('d M Y') }}</td>
              <td>{{ ucfirst($t['type']) }}@if($t['intraday_qty'] > 0) <span class="badge bg-info">Intraday</span>@endif</td>
              <td><strong>{{ $t['symbol'] }}</strong></td>
              <td>{{ $t['broker'] }}</td>
              <td class="num">{{ number_format($t['qty']) }}</td>
              <td class="num">{{ number_format($t['price'], 2) }}</td>
              <td class="num">{{ number_format($t['value'], 2) }}</td>
              <td class="num">{{ number_format($t['commission_charged'], 2) }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <small class="text-muted">Latest 8 trade rows, any date.</small>
      @else
        <div class="empty">No trades yet. @can('psx_trades.create')<a href="{{ route('psx_trades.create') }}">Enter the first trade</a>.@endcan</div>
      @endif
    </div>
  </section>

  {{-- MODULES --}}
  <div class="section-title"><h3>Module summary</h3></div>
  <div class="tiles mb-3">
    @php
      $last = $modules['last_trade'] ? \Carbon\Carbon::parse($modules['last_trade'])->format('d M Y') : '–';
      $def  = $modules['default'];
      $cards = [
        ['psx_trades.index',     'psx_trades.index',     'fa-exchange-alt', 'Trades',          number_format($modules['trades']) . ' trades',              'Last trade ' . $last],
        ['reports.psx_holdings', 'reports.psx_holdings', 'fa-briefcase',    'Holdings',        $portfolio['stocks'] . ' stocks',                           $money($portfolio['cost']) . ' at cost'],
        ['reports.psx_pl',       'reports.psx_pl',       'fa-chart-line',   'Profit & Loss',   $signed($modules['all_net']),                               'Realised, all time'],
        ['psx_stocks.index',     'psx_stocks.index',     'fa-tags',         'Stocks',          $modules['stocks_live'] . ' active',                        $modules['stocks'] . ' in the list'],
        ['psx_brokers.index',    'psx_brokers.index',    'fa-handshake',    'Brokers',         $modules['brokers'] . ' active',                            $def ? 'Default: ' . $def->name . ' · Rs ' . rtrim(rtrim(number_format($def->min_commission_per_share, 4), '0'), '.') . '/share or ' . rtrim(rtrim(number_format($def->commission_rate, 4), '0'), '.') . '%' : 'No default broker'],
      ];
    @endphp
    @foreach ($cards as [$perm, $route, $icon, $title, $figure, $meta])
      @can($perm)
      <div>
        <a href="{{ route($route) }}" class="text-decoration-none">
          <section class="card module-card"><div class="card-body">
            <div class="d-flex align-items-center"><span class="icon"><i class="fas {{ $icon }}"></i></span><h4>{{ $title }}</h4></div>
            <div class="figure">{{ $figure }}</div>
            <div class="meta">{{ $meta }}</div>
          </div></section>
        </a>
      </div>
      @endcan
    @endforeach
  </div>
</div>

<script>
  (function () {
    // Period: presets apply at once; "Custom" shows the date boxes
    var form = document.getElementById('dashFilters');
    document.getElementById('periodSel').addEventListener('change', function () {
      var custom = this.value === 'custom';
      document.querySelectorAll('.custom-range').forEach(function (el) { el.style.display = custom ? '' : 'none'; });
      if (!custom) submitPreset();
    });
    function submitPreset() {
      form.querySelectorAll('input[type=date]').forEach(function (el) { el.disabled = true; });
      if (!form.querySelector('select[name=broker_id]').value) form.querySelector('select[name=broker_id]').disabled = true;
      form.submit();
    }
    form.querySelector('select[name=broker_id]').addEventListener('change', function () {
      if (document.getElementById('periodSel').value !== 'custom') submitPreset();
    });

    var PL = @json($plSeries);
    var STOCKS = @json(array_merge($winners, $losers));
    var ALLOC = @json($allocation);
    var TOTAL_COST = {{ (float) $portfolio['cost'] }};

    function rs(n, d) {
      d = d === undefined ? 0 : d;
      var s = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
      return (n < 0 ? '−' : '') + 'Rs ' + s;
    }
    function signed(n, d) { return (n > 0 ? '+' : '') + rs(n, d); }
    function short(n) {
      var a = Math.abs(n), s = n < 0 ? '−' : '';
      if (a >= 1e6) return s + (a / 1e6).toFixed(1) + 'M';
      if (a >= 1e3) return s + (a / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k';
      return s + a.toFixed(0);
    }

    // Charts wait for Chart.js, which the layout loads at the end of the page
    window.addEventListener('load', function () {
      if (typeof Chart === 'undefined') return;
      var css = getComputedStyle(document.querySelector('.psx-dash'));
      var POS = css.getPropertyValue('--pos').trim(), NEG = css.getPropertyValue('--neg').trim();
      var INK2 = css.getPropertyValue('--ink-2').trim(), RULE = css.getPropertyValue('--rule').trim(), OTHER = css.getPropertyValue('--other').trim();

      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.font.size = 12;
      Chart.defaults.color = INK2;

      var tooltip = {
        backgroundColor: '#ffffff', titleColor: '#0b0b0b', bodyColor: '#52514e',
        borderColor: 'rgba(0,0,0,.12)', borderWidth: 1, padding: 10, displayColors: true, boxPadding: 4
      };
      var valueAxis = function (horizontal) {
        return { grid: { color: RULE, drawTicks: false }, border: { display: false }, ticks: { callback: function (v) { return short(v); }, padding: 6 } };
      };
      var catAxis = { grid: { display: false }, border: { color: RULE } };

      // 1. Realised net P/L by day / month
      var plEl = document.getElementById('plChart');
      if (plEl) {
        new Chart(plEl, {
          type: 'bar',
          data: {
            labels: PL.map(function (p) { return p.label; }),
            datasets: [{
              data: PL.map(function (p) { return p.net; }),
              backgroundColor: PL.map(function (p) { return p.net >= 0 ? POS : NEG; }),
              borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, categoryPercentage: .8, barPercentage: .9
            }]
          },
          options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: Object.assign({}, tooltip, {
                callbacks: {
                  label: function (c) { return ' Net P/L ' + signed(c.raw, 2); },
                  afterLabel: function (c) { return ' Running total ' + signed(PL[c.dataIndex].running, 2); }
                }
              })
            },
            scales: { x: catAxis, y: valueAxis() }
          }
        });
      }

      // 2. Best and worst stocks (diverging around zero)
      var stEl = document.getElementById('stockChart');
      if (stEl) {
        new Chart(stEl, {
          type: 'bar',
          data: {
            labels: STOCKS.map(function (s) { return s.symbol; }),
            datasets: [{
              data: STOCKS.map(function (s) { return s.net; }),
              backgroundColor: STOCKS.map(function (s) { return s.net >= 0 ? POS : NEG; }),
              borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18
            }]
          },
          options: {
            indexAxis: 'y', maintainAspectRatio: false,
            interaction: { mode: 'nearest', axis: 'y', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: Object.assign({}, tooltip, {
                callbacks: {
                  label: function (c) { return ' Net P/L ' + signed(c.raw, 2); },
                  afterLabel: function (c) { return ' ' + STOCKS[c.dataIndex].trades + ' closed trade(s)'; }
                }
              })
            },
            scales: { y: Object.assign({}, catAxis, { ticks: { font: { weight: '600' } } }), x: valueAxis(true) }
          }
        });
      }

      // 3. Holdings at cost by stock (one series, one colour)
      var alEl = document.getElementById('allocChart');
      if (alEl) {
        new Chart(alEl, {
          type: 'bar',
          data: {
            labels: ALLOC.map(function (a) { return a.symbol; }),
            datasets: [{ data: ALLOC.map(function (a) { return a.cost; }), backgroundColor: ALLOC.map(function (a) { return a.symbol.indexOf('Other') === 0 ? OTHER : POS; }), borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }]
          },
          options: {
            indexAxis: 'y', maintainAspectRatio: false,
            interaction: { mode: 'nearest', axis: 'y', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: Object.assign({}, tooltip, {
                displayColors: false,
                callbacks: {
                  label: function (c) { return ' Cost ' + rs(c.raw); },
                  afterLabel: function (c) {
                    var a = ALLOC[c.dataIndex];
                    return ' ' + a.qty.toLocaleString() + ' shares · ' + (TOTAL_COST ? (a.cost / TOTAL_COST * 100).toFixed(1) : 0) + '% of portfolio';
                  }
                }
              })
            },
            scales: { y: Object.assign({}, catAxis, { ticks: { font: { weight: '600' } } }), x: valueAxis(true) }
          }
        });
      }
    });
  })();
</script>
@endsection
