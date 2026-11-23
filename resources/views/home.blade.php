@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
  $money  = fn ($n, $d = 0) => ($n < 0 ? '−' : '') . 'Rs ' . number_format(abs($n), $d);
  $signed = fn ($n, $d = 0) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . 'Rs ' . number_format(abs($n), $d);
  $arrow  = fn ($n) => $n > 0 ? '<i class="fas fa-caret-up d-up" aria-hidden="true"></i>' : ($n < 0 ? '<i class="fas fa-caret-down d-down" aria-hidden="true"></i>' : '');
  $date   = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d M Y') : '';
  $s = $sales ?? null; $p = $purchases ?? null; $a = $accounts ?? null; $inv = $inventory ?? null; $r = $returns ?? null;
  $anything = collect($can)->filter()->isNotEmpty();
@endphp

<style>
  .home-dash {
    --ink-1: #0b0b0b; --ink-2: #52514e; --ink-3: #8a8984; --rule: rgba(0, 0, 0, .08);
    --s1: #2a78d6;   /* sales / profit */
    --s2: #eb6834;   /* purchases */
    --neg: #e34948;  /* loss */
  }
  .home-dash .tiles { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 165px), 1fr)); }
  .home-dash .tiles > * { min-width: 0; }
  .home-dash .tiles .card { margin-bottom: 0; height: 100%; }
  .home-dash .kpi .card-body { padding: 16px 18px; }
  .home-dash .kpi-label { color: var(--ink-2); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
  .home-dash .kpi-value { color: var(--ink-1); font-size: 23px; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; }
  .home-dash .kpi-sub { color: var(--ink-2); font-size: 12px; margin-top: 6px; }
  .home-dash .d-up { color: var(--s1); } .home-dash .d-down { color: var(--neg); }
  .home-dash .section-title { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 6px; margin: 22px 0 10px; }
  .home-dash .section-title h3 { font-size: 16px; font-weight: 700; margin: 0; color: var(--ink-1); }
  .home-dash .section-title a { font-size: 13px; }
  .home-dash .chart-box { position: relative; height: 290px; }
  .home-dash .legend { display: flex; gap: 16px; font-size: 12px; color: var(--ink-2); white-space: nowrap; }
  .home-dash .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: -1px; }
  .home-dash .card-header.has-legend { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 4px 16px; }
  .home-dash .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .home-dash .empty { color: var(--ink-3); text-align: center; padding: 40px 10px; }
  .home-dash details.table-view summary { cursor: pointer; color: var(--ink-2); font-size: 12px; margin-top: 8px; }
  .home-dash .table-sm td, .home-dash .table-sm th { padding: .4rem .5rem; }
  .home-dash .module-card .card-body { padding: 14px 16px; }
  .home-dash .module-card .icon { width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: #eef4fc; color: var(--s1); margin-right: 10px; }
  .home-dash .module-card h4 { font-size: 14px; font-weight: 700; margin: 0; color: var(--ink-1); }
  .home-dash .module-card .figure { font-size: 18px; font-weight: 700; color: var(--ink-1); margin-top: 10px; font-variant-numeric: tabular-nums; }
  .home-dash .module-card .meta { font-size: 12px; color: var(--ink-2); }
  .home-dash .split { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: #f0efec; margin: 8px 0 4px; gap: 2px; }
  .home-dash .split span { display: block; height: 100%; }
</style>

<div class="home-dash">

  {{-- Header + period --}}
  <section class="card mb-3">
    <div class="card-body py-3">
      <form method="GET" action="{{ route('dashboard') }}" id="dashFilters" class="row g-2 align-items-end">
        <div class="col-lg-5 col-12">
          <h2 class="card-title mb-0">Welcome, {{ auth()->user()->name }}</h2>
          <small class="text-muted">{{ now()->format('l, d F Y') }} · showing {{ strtolower($label) }}</small>
        </div>
        <div class="col-lg-2 col-6">
          <label class="small text-muted mb-1">Period</label>
          <select name="period" class="form-control" id="periodSel">
            @foreach (\App\Support\DashboardPeriod::NAMES as $key => $name)
              <option value="{{ $key }}" {{ $filters['period'] === $key ? 'selected' : '' }}>{{ $name }}</option>
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
        <div class="col-auto custom-range" @if($filters['period'] !== 'custom') style="display:none" @endif>
          <button class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        </div>
      </form>
    </div>
  </section>

  @if (!$anything)
    <section class="card"><div class="card-body empty">
      <i class="fas fa-th-large fa-2x mb-2"></i><br>
      Your role has no modules on the dashboard yet. Use the menu to open the screens you can access.
    </div></section>
  @else

  {{-- Headline figures --}}
  <div class="tiles">
    @if ($s)
    <div><section class="card kpi"><div class="card-body">
      <div class="kpi-label">Net sales</div>
      <div class="kpi-value">{{ $money($s['net']) }}</div>
      <div class="kpi-sub">{{ number_format($s['count']) }} {{ \Illuminate\Support\Str::plural('invoice', $s['count']) }}@if($s['discount'] > 0) · {{ $money($s['discount']) }} discount @endif</div>
    </div></section></div>
    @endif
    @if ($p)
    <div><section class="card kpi"><div class="card-body">
      <div class="kpi-label">Purchases</div>
      <div class="kpi-value">{{ $money($p['total']) }}</div>
      <div class="kpi-sub">{{ number_format($p['count']) }} {{ \Illuminate\Support\Str::plural('invoice', $p['count']) }}</div>
    </div></section></div>
    @endif
    @if ($a)
    <div><section class="card kpi"><div class="card-body">
      <div class="kpi-label">Net profit</div>
      <div class="kpi-value">{!! $arrow($a['net']) !!} {{ $money($a['net']) }}</div>
      <div class="kpi-sub">Gross profit {{ $money($a['gross']) }} · expenses {{ $money($a['expenses']) }}</div>
    </div></section></div>
    <div><section class="card kpi"><div class="card-body">
      <div class="kpi-label">Cash &amp; bank</div>
      <div class="kpi-value">{{ $money($a['cash'] + $a['bank']) }}</div>
      <div class="kpi-sub">Cash {{ $money($a['cash']) }} · bank {{ $money($a['bank']) }} · today</div>
    </div></section></div>
    <div><section class="card kpi"><div class="card-body">
      <div class="kpi-label">Receivables</div>
      <div class="kpi-value">{{ $money($a['receivables']) }}</div>
      <div class="kpi-sub">{{ $a['receivable_n'] }} {{ \Illuminate\Support\Str::plural('party', $a['receivable_n']) }} owe you · today</div>
    </div></section></div>
    <div><section class="card kpi"><div class="card-body">
      <div class="kpi-label">Payables</div>
      <div class="kpi-value">{{ $money($a['payables']) }}</div>
      <div class="kpi-sub">You owe {{ $a['payable_n'] }} {{ \Illuminate\Support\Str::plural('party', $a['payable_n']) }} · today</div>
    </div></section></div>
    @endif
  </div>

  {{-- SALES & PURCHASES --}}
  @if ($s || $p)
  <div class="section-title">
    <h3>{{ $s && $p ? 'Sales & purchases' : ($s ? 'Sales' : 'Purchases') }}</h3>
    <div>
      @can('reports.sales')<a href="{{ route('reports.sale') }}" class="me-3">Sales report <i class="fas fa-arrow-right"></i></a>@endcan
      @can('reports.purchase')<a href="{{ route('reports.purchase') }}">Purchase report <i class="fas fa-arrow-right"></i></a>@endcan
    </div>
  </div>
  <div class="row g-3">
    <div class="{{ $s ? 'col-xl-8' : 'col-12' }}">
      <section class="card h-100">
        <header class="card-header has-legend">
          <h2 class="card-title">{{ $s && $p ? 'Sales vs purchases' : ($s ? 'Sales' : 'Purchases') }} by {{ $trend['monthly'] ? 'month' : 'day' }}</h2>
          @if ($s && $p)
            <div class="legend"><span><i style="background:var(--s1)"></i>Sales</span><span><i style="background:var(--s2)"></i>Purchases</span></div>
          @endif
        </header>
        <div class="card-body">
          @if (count($trend['series']))
            <div class="chart-box"><canvas id="trendChart" role="img" aria-label="Sales and purchases over time"></canvas></div>
            <details class="table-view">
              <summary>View as table</summary>
              <table class="table table-sm mt-2 mb-0">
                <thead><tr><th>{{ $trend['monthly'] ? 'Month' : 'Day' }}</th>@if($s)<th class="num">Sales</th>@endif @if($p)<th class="num">Purchases</th>@endif</tr></thead>
                <tbody>
                  @foreach ($trend['series'] as $row)
                    <tr><td>{{ $row['label'] }}</td>@if($s)<td class="num">{{ $money($row['sales']) }}</td>@endif @if($p)<td class="num">{{ $money($row['purchases']) }}</td>@endif</tr>
                  @endforeach
                </tbody>
              </table>
            </details>
          @else
            <div class="empty"><i class="fas fa-chart-bar fa-2x mb-2"></i><br>No {{ $s ? 'sales' : '' }}{{ $s && $p ? ' or ' : '' }}{{ $p ? 'purchases' : '' }} in this period.</div>
          @endif
        </div>
      </section>
    </div>
    @if ($s)
    <div class="col-xl-4">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Cash and credit sales</h2></header>
        <div class="card-body">
          @php $tot = max($s['cash'] + $s['credit'], 0.01); @endphp
          <div class="split" role="img" aria-label="Cash {{ round($s['cash'] / $tot * 100) }} percent, credit {{ round($s['credit'] / $tot * 100) }} percent">
            @if ($s['cash'] > 0)<span style="width: {{ $s['cash'] / $tot * 100 }}%; background: var(--s1)"></span>@endif
            @if ($s['credit'] > 0)<span style="width: {{ $s['credit'] / $tot * 100 }}%; background: #86b6ef"></span>@endif
          </div>
          <table class="table table-sm mb-3">
            <tr><td><i class="fas fa-square" style="color:var(--s1)"></i> Cash sales</td><td class="num">{{ $money($s['cash']) }}</td></tr>
            <tr><td><i class="fas fa-square" style="color:#86b6ef"></i> Credit sales</td><td class="num">{{ $money($s['credit']) }}</td></tr>
          </table>
          <h6 class="fw-bold mb-2">Top customers</h6>
          @if (count($s['customers']))
            <table class="table table-sm mb-0">
              @foreach ($s['customers'] as $c)
                <tr><td>{{ $c['name'] }} <small class="text-muted">· {{ $c['count'] }} {{ \Illuminate\Support\Str::plural('invoice', $c['count']) }}</small></td><td class="num">{{ $money($c['total']) }}</td></tr>
              @endforeach
            </table>
          @else
            <div class="text-muted small">No sales in this period.</div>
          @endif
        </div>
      </section>
    </div>
    @endif
  </div>

  <div class="row g-3 mt-0">
    @if ($s)
    <div class="col-xl-6">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Best-selling products (by value)</h2></header>
        <div class="card-body">
          @if (count($s['products']))
            <div class="chart-box" style="height: {{ 40 * count($s['products']) + 40 }}px"><canvas id="productChart" role="img" aria-label="Best-selling products by sales value"></canvas></div>
            <details class="table-view">
              <summary>View as table</summary>
              <table class="table table-sm mt-2 mb-0">
                <thead><tr><th>Product</th><th class="num">Qty sold</th><th class="num">Value</th></tr></thead>
                <tbody>@foreach ($s['products'] as $pr)<tr><td>{{ $pr->name }}</td><td class="num">{{ number_format($pr->qty) }}</td><td class="num">{{ $money($pr->total) }}</td></tr>@endforeach</tbody>
              </table>
            </details>
          @else
            <div class="empty">No sales in this period.</div>
          @endif
        </div>
      </section>
    </div>
    @endif
    @if ($p)
    <div class="col-xl-6">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Top vendors (purchases)</h2></header>
        <div class="card-body">
          @if (count($p['vendors']))
            <table class="table table-sm mb-0">
              <thead><tr><th>Vendor</th><th class="num">Invoices</th><th class="num">Amount</th></tr></thead>
              <tbody>
                @foreach ($p['vendors'] as $v)
                  <tr><td>{{ $v['name'] }}</td><td class="num">{{ $v['count'] }}</td><td class="num">{{ $money($v['total']) }}</td></tr>
                @endforeach
              </tbody>
            </table>
          @else
            <div class="empty">No purchases in this period.</div>
          @endif
        </div>
      </section>
    </div>
    @endif
  </div>

  {{-- Recent invoices --}}
  <div class="row g-3 mt-0">
    @if ($s)
    <div class="col-xl-6">
      <section class="card h-100">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Latest sale invoices</h2>
          <div>
            @can('sale_invoices.create')<a href="{{ route('sale_invoices.create') }}" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New</a>@endcan
            @can('sale_invoices.index')<a href="{{ route('sale_invoices.index') }}" class="btn btn-default btn-sm">All</a>@endcan
          </div>
        </header>
        <div class="card-body">
          @if (count($s['recent']))
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>#</th><th>Date</th><th>Customer</th><th>Type</th><th class="num">Amount</th></tr></thead>
            <tbody>
              @foreach ($s['recent'] as $i)
              <tr>
                <td>@can('sale_invoices.index')<a href="{{ route('sale_invoices.show', $i->id) }}">{{ $i->invoice_no }}</a>@else{{ $i->invoice_no }}@endcan</td>
                <td class="text-nowrap">{{ $date($i->date) }}</td>
                <td>{{ $i->party }}</td>
                <td>{{ ucfirst($i->type) }}</td>
                <td class="num">{{ $money($i->total) }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
          @else
            <div class="empty">No sale invoices yet.</div>
          @endif
        </div>
      </section>
    </div>
    @endif
    @if ($p)
    <div class="col-xl-6">
      <section class="card h-100">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Latest purchase invoices</h2>
          <div>
            @can('purchase_invoices.create')<a href="{{ route('purchase_invoices.create') }}" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New</a>@endcan
            @can('purchase_invoices.index')<a href="{{ route('purchase_invoices.index') }}" class="btn btn-default btn-sm">All</a>@endcan
          </div>
        </header>
        <div class="card-body">
          @if (count($p['recent']))
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>#</th><th>Date</th><th>Vendor</th><th>Bill #</th><th class="num">Amount</th></tr></thead>
            <tbody>
              @foreach ($p['recent'] as $i)
              <tr>
                <td>@can('purchase_invoices.index')<a href="{{ route('purchase_invoices.show', $i->id) }}">{{ $i->invoice_no }}</a>@else{{ $i->invoice_no }}@endcan</td>
                <td class="text-nowrap">{{ $date($i->date) }}</td>
                <td>{{ $i->party }}</td>
                <td>{{ $i->bill_no ?: '—' }}</td>
                <td class="num">{{ $money($i->total) }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
          @else
            <div class="empty">No purchase invoices yet.</div>
          @endif
        </div>
      </section>
    </div>
    @endif
  </div>
  @endif

  {{-- ACCOUNTS --}}
  @if ($a)
  <div class="section-title">
    <h3>Accounts <small class="text-muted fw-normal">· balances as of today</small></h3>
    @can('reports.accounts')<a href="{{ route('reports.accounts') }}">Accounts reports <i class="fas fa-arrow-right"></i></a>@endcan
  </div>
  <div class="row g-3">
    <div class="col-xl-4 col-md-6">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Who owes you</h2></header>
        <div class="card-body">
          @if (count($a['top_receive']))
            <table class="table table-sm mb-0">
              @foreach ($a['top_receive'] as $x)<tr><td>{{ $x['name'] }}</td><td class="num">{{ $money($x['amount']) }}</td></tr>@endforeach
              @if ($a['receivable_n'] > 5)<tr class="text-muted"><td>{{ $a['receivable_n'] - 5 }} more</td><td class="num">{{ $money($a['receivables'] - collect($a['top_receive'])->sum('amount')) }}</td></tr>@endif
            </table>
          @else
            <div class="empty">Nothing outstanding.</div>
          @endif
        </div>
      </section>
    </div>
    <div class="col-xl-4 col-md-6">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Whom you owe</h2></header>
        <div class="card-body">
          @if (count($a['top_pay']))
            <table class="table table-sm mb-0">
              @foreach ($a['top_pay'] as $x)<tr><td>{{ $x['name'] }}</td><td class="num">{{ $money($x['amount']) }}</td></tr>@endforeach
              @if ($a['payable_n'] > 5)<tr class="text-muted"><td>{{ $a['payable_n'] - 5 }} more</td><td class="num">{{ $money($a['payables'] - collect($a['top_pay'])->sum('amount')) }}</td></tr>@endif
            </table>
          @else
            <div class="empty">Nothing outstanding.</div>
          @endif
        </div>
      </section>
    </div>
    <div class="col-xl-4 col-md-12">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Profit &amp; loss · {{ strtolower($label) }}</h2></header>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><td>Revenue</td><td class="num">{{ $money($a['revenue']) }}</td></tr>
            <tr><td>Cost of goods sold</td><td class="num">{{ $a['cogs'] > 0 ? '−' : '' }}{{ $money($a['cogs']) }}</td></tr>
            <tr class="fw-bold"><td>Gross profit</td><td class="num">{{ $money($a['gross']) }}</td></tr>
            <tr><td>Expenses</td><td class="num">{{ $a['expenses'] > 0 ? '−' : '' }}{{ $money($a['expenses']) }}</td></tr>
            <tr class="fw-bold"><td>Net profit</td><td class="num">{!! $arrow($a['net']) !!} {{ $money($a['net']) }}</td></tr>
          </table>
          <small class="text-muted d-block mt-2">From vouchers posted in the period, as in the Profit &amp; Loss report.</small>
        </div>
      </section>
    </div>
  </div>
  @endif

  {{-- INVENTORY + RETURNS --}}
  @if ($inv || $r)
  <div class="section-title">
    <h3>{{ $inv && $r ? 'Inventory & returns' : ($inv ? 'Inventory' : 'Returns') }}</h3>
    @can('reports.inventory')<a href="{{ route('reports.inventory') }}">Inventory report <i class="fas fa-arrow-right"></i></a>@endcan
  </div>
  <div class="row g-3">
    @if ($inv)
    <div class="col-xl-4 col-md-6">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Stock</h2></header>
        <div class="card-body">
          <table class="table table-sm mb-0">
            @if ($inv['value'] !== null)<tr><td>Stock value (Stock in Hand account)</td><td class="num fw-bold">{{ $money($inv['value']) }}</td></tr>@endif
            <tr><td>Active products</td><td class="num">{{ number_format($inv['active']) }}</td></tr>
            <tr><td>In stock</td><td class="num">{{ number_format($inv['in_stock']) }}</td></tr>
            <tr><td>Out of stock</td><td class="num">@if(count($inv['out_of_stock']))<i class="fas fa-exclamation-triangle" style="color:#fab219" aria-hidden="true"></i> @endif{{ count($inv['out_of_stock']) }}</td></tr>
            <tr><td>Units in stock</td><td class="num">{{ number_format($inv['units']) }}</td></tr>
          </table>
        </div>
      </section>
    </div>
    <div class="col-xl-4 col-md-6">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Out of stock</h2></header>
        <div class="card-body">
          @if (count($inv['out_of_stock']))
            <table class="table table-sm mb-0">
              @foreach ($inv['out_of_stock']->take(6) as $o)
                <tr><td>{{ $o->name }}</td><td class="num">{{ number_format($o->qty) }} {{ $o->unit }}</td></tr>
              @endforeach
              @if (count($inv['out_of_stock']) > 6)<tr class="text-muted"><td colspan="2">and {{ count($inv['out_of_stock']) - 6 }} more</td></tr>@endif
            </table>
          @else
            <div class="empty"><i class="fas fa-check" aria-hidden="true"></i> Every active product is in stock.</div>
          @endif
        </div>
      </section>
    </div>
    @endif
    @if ($r)
    <div class="col-xl-4 col-md-12">
      <section class="card h-100">
        <header class="card-header"><h2 class="card-title">Returns · {{ strtolower($label) }}</h2></header>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <thead><tr><th></th><th class="num">Count</th><th class="num">Value</th></tr></thead>
            <tr>
              <td>@can('sale_return.index')<a href="{{ route('sale_return.index') }}">Sale returns</a>@else Sale returns @endcan</td>
              <td class="num">{{ $r['sale']['count'] }}</td><td class="num">{{ $money($r['sale']['total']) }}</td>
            </tr>
            <tr>
              <td>@can('purchase_return.index')<a href="{{ route('purchase_return.index') }}">Purchase returns</a>@else Purchase returns @endcan</td>
              <td class="num">{{ $r['purchase']['count'] }}</td><td class="num">{{ $money($r['purchase']['total']) }}</td>
            </tr>
          </table>
          @if ($s && $s['net'] > 0)
            <small class="text-muted d-block mt-2">Sale returns are {{ number_format($r['sale']['total'] / $s['net'] * 100, 1) }}% of net sales in this period.</small>
          @endif
        </div>
      </section>
    </div>
    @endif
  </div>
  @endif

  {{-- MODULE SUMMARY --}}
  <div class="section-title"><h3>Modules</h3></div>
  @php
    $cards = [];
    if ($s) $cards[] = ['sale_invoices.index', route('sale_invoices.index'), 'fa-cash-register', 'Sales', $money($s['net']), $s['count'] . ' invoices · ' . strtolower($label)];
    if ($p) $cards[] = ['purchase_invoices.index', route('purchase_invoices.index'), 'fa-shopping-cart', 'Purchases', $money($p['total']), $p['count'] . ' invoices · ' . strtolower($label)];
    if ($r) $cards[] = ['sale_return.index', route('sale_return.index'), 'fa-undo', 'Returns', $r['sale']['count'] + $r['purchase']['count'] . ' returns', 'Sale ' . $money($r['sale']['total']) . ' · purchase ' . $money($r['purchase']['total'])];
    if ($inv) $cards[] = ['products.index', route('products.index'), 'fa-boxes', 'Products', number_format($inv['active']) . ' active', $inv['in_stock'] . ' in stock · ' . count($inv['out_of_stock']) . ' out of stock'];
    if ($a) $cards[] = ['coa.index', route('coa.index'), 'fa-book', 'Chart of accounts', number_format($a['coa']) . ' accounts', 'Receivable ' . $money($a['receivables']) . ' · payable ' . $money($a['payables'])];
    if (isset($vouchers)) $cards[] = ['vouchers.index', route('vouchers.index', 'journal'), 'fa-file-invoice', 'Vouchers', number_format($vouchers->sum('n')) . ' vouchers', collect(['journal', 'payment', 'receipt'])->map(fn ($t) => ucfirst($t) . ' ' . ($vouchers[$t]->n ?? 0))->implode(' · ')];
    if (isset($psx)) $cards[] = ['reports.psx_dashboard', route('reports.psx_dashboard', $filters['period'] === 'custom' ? ['period' => 'custom', 'from_date' => $filters['from'], 'to_date' => $filters['to']] : ['period' => $filters['period']]), 'fa-chart-line', 'PSX Trading', $signed($psx['net']) . ' realised', $psx['stocks'] . ' stocks held · ' . $money($psx['invested']) . ' cost'];
  @endphp
  <div class="tiles mb-3">
    @foreach ($cards as [$perm, $url, $icon, $title, $figure, $meta])
      <div>
        @php $canOpen = auth()->user()->can($perm); @endphp
        <a href="{{ $canOpen ? $url : '#' }}" class="text-decoration-none" @if(!$canOpen) tabindex="-1" aria-disabled="true" @endif>
          <section class="card module-card"><div class="card-body">
            <div class="d-flex align-items-center"><span class="icon"><i class="fas {{ $icon }}"></i></span><h4>{{ $title }}</h4></div>
            <div class="figure">{{ $figure }}</div>
            <div class="meta">{{ $meta }}</div>
          </div></section>
        </a>
      </div>
    @endforeach
  </div>
  @endif
</div>

<script>
  (function () {
    var form = document.getElementById('dashFilters');
    function submitPreset() {
      form.querySelectorAll('input[type=date]').forEach(function (el) { el.disabled = true; });
      form.submit();
    }
    document.getElementById('periodSel').addEventListener('change', function () {
      var custom = this.value === 'custom';
      document.querySelectorAll('.custom-range').forEach(function (el) { el.style.display = custom ? '' : 'none'; });
      if (!custom) submitPreset();
    });

    var TREND = @json($trend['series'] ?? []);
    var SHOW_SALES = {{ $s ? 'true' : 'false' }}, SHOW_PURCHASES = {{ $p ? 'true' : 'false' }};
    var PRODUCTS = @json(isset($s) && $s ? $s['products'] : []);

    function rs(n) { return (n < 0 ? '−' : '') + 'Rs ' + Math.abs(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
    function short(n) {
      var a = Math.abs(n), s = n < 0 ? '−' : '';
      if (a >= 1e6) return s + (a / 1e6).toFixed(1) + 'M';
      if (a >= 1e3) return s + (a / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k';
      return s + a.toFixed(0);
    }

    window.addEventListener('load', function () {
      if (typeof Chart === 'undefined') return;
      var css = getComputedStyle(document.querySelector('.home-dash'));
      var S1 = css.getPropertyValue('--s1').trim(), S2 = css.getPropertyValue('--s2').trim();
      var INK2 = css.getPropertyValue('--ink-2').trim(), RULE = css.getPropertyValue('--rule').trim();
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.font.size = 12;
      Chart.defaults.color = INK2;

      var tooltip = { backgroundColor: '#ffffff', titleColor: '#0b0b0b', bodyColor: '#52514e', borderColor: 'rgba(0,0,0,.12)', borderWidth: 1, padding: 10, boxPadding: 4 };
      var valueAxis = { grid: { color: RULE, drawTicks: false }, border: { display: false }, ticks: { callback: function (v) { return short(v); }, padding: 6 }, beginAtZero: true };
      var catAxis = { grid: { display: false }, border: { color: RULE } };

      var tEl = document.getElementById('trendChart');
      if (tEl) {
        var sets = [];
        if (SHOW_SALES) sets.push({ label: 'Sales', data: TREND.map(function (r) { return r.sales; }), backgroundColor: S1, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 });
        if (SHOW_PURCHASES) sets.push({ label: 'Purchases', data: TREND.map(function (r) { return r.purchases; }), backgroundColor: S2, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 });
        new Chart(tEl, {
          type: 'bar',
          data: { labels: TREND.map(function (r) { return r.label; }), datasets: sets },
          options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            datasets: { bar: { categoryPercentage: .75, barPercentage: .92 } },
            plugins: {
              legend: { display: false },
              tooltip: Object.assign({}, tooltip, { callbacks: { label: function (c) { return ' ' + c.dataset.label + ' ' + rs(c.raw); } } })
            },
            scales: { x: catAxis, y: valueAxis }
          }
        });
      }

      var pEl = document.getElementById('productChart');
      if (pEl) {
        new Chart(pEl, {
          type: 'bar',
          data: {
            labels: PRODUCTS.map(function (p) { return p.name; }),
            datasets: [{ data: PRODUCTS.map(function (p) { return +p.total; }), backgroundColor: S1, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }]
          },
          options: {
            indexAxis: 'y', maintainAspectRatio: false,
            interaction: { mode: 'nearest', axis: 'y', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: Object.assign({}, tooltip, { displayColors: false, callbacks: {
                label: function (c) { return ' Sales ' + rs(c.raw); },
                afterLabel: function (c) { return ' ' + Number(PRODUCTS[c.dataIndex].qty).toLocaleString() + ' sold'; }
              } })
            },
            scales: { y: Object.assign({}, catAxis, { ticks: { font: { weight: '600' } } }), x: valueAxis }
          }
        });
      }
    });
  })();
</script>
@endsection
