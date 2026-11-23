{{-- Shared by show and print. Needs: $trade, $calc (matcher rows keyed by item id), $sells, $buys --}}
@php
  $pl   = fn ($n) => $n > 0 ? 'text-success' : ($n < 0 ? 'text-danger' : '');
  $row  = fn ($item) => $calc[$item->id] ?? ['commission_charged' => $item->commission, 'net_charged' => $item->net_amount, 'intraday_qty' => 0];
  $buys_  = $trade->items->where('type', 'buy');
  $sells_ = $trade->items->where('type', 'sell');
  $boughtTotal = $buys_->sum(fn ($i) => $row($i)['net_charged']);
  $soldTotal   = $sells_->sum(fn ($i) => $row($i)['net_charged']);
  $net = $boughtTotal - $soldTotal;
@endphp
<div class="row mb-3">
  <div class="col-sm-3"><small class="text-muted d-block">Trade #</small><strong>{{ $trade->trade_no }}</strong></div>
  <div class="col-sm-3"><small class="text-muted d-block">Date</small>{{ $trade->trade_date->format('d-M-Y') }}</div>
  <div class="col-sm-3"><small class="text-muted d-block">Broker</small>{{ $trade->broker->name ?? '-' }}</div>
  <div class="col-sm-3"><small class="text-muted d-block">Contract Note #</small>{{ $trade->contract_note_no ?: '-' }}</div>
  @if ($trade->remarks)
  <div class="col-12 mt-2"><small class="text-muted d-block">Remarks</small>{{ $trade->remarks }}</div>
  @endif
</div>

<table class="table table-bordered table-sm">
  <thead>
    <tr>
      <th>#</th><th>Type</th><th>Stock</th>
      <th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Value</th>
      <th class="text-end">Commission</th><th class="text-end">Other</th>
      <th class="text-end">Net Amount</th><th>Remarks</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($trade->items as $item)
    @php $r = $row($item); @endphp
    <tr>
      <td>{{ $loop->iteration }}</td>
      <td>{{ ucfirst($item->type) }}</td>
      <td>
        <strong>{{ $item->stock->symbol }}</strong> <small class="text-muted">{{ $item->stock->name }}</small>
        @if ($item->type === 'sell')
          <br><small class="text-muted">Sold from:
            @if ($item->buyItem && $item->buyItem->trade)
              <a href="{{ route('psx_trades.show', $item->buyItem->psx_trade_id) }}">{{ $item->buyItem->trade->trade_no }}</a>
              ({{ $item->buyItem->trade->trade_date->format('d-M-Y') }}, {{ number_format($item->buyItem->qty) }} @ {{ rtrim(rtrim(number_format($item->buyItem->price, 4), '0'), '.') }})
            @else
              auto (same day, then oldest)
            @endif
          </small>
        @endif
      </td>
      <td class="text-end">{{ number_format($item->qty) }}</td>
      <td class="text-end">{{ number_format($item->price, 4) }}</td>
      <td class="text-end">{{ number_format($item->value, 2) }}</td>
      <td class="text-end">
        {{ number_format($r['commission_charged'], 2) }}
        @if ($r['intraday_qty'] > 0)
          <br><small class="text-info">Intraday: no buy commission on {{ number_format($r['intraday_qty']) }} sold same day</small>
        @endif
      </td>
      <td class="text-end">{{ $item->other_charges > 0 ? number_format($item->other_charges, 2) : '-' }}</td>
      <td class="text-end">{{ number_format($r['net_charged'], 2) }}</td>
      <td><small>{{ $item->remarks }}</small></td>
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td colspan="5" class="text-end">Total</td>
      <td class="text-end">{{ number_format($trade->items->sum('value'), 2) }}</td>
      <td class="text-end">{{ number_format($trade->items->sum(fn ($i) => $row($i)['commission_charged']), 2) }}</td>
      <td class="text-end">{{ number_format($trade->items->sum('other_charges'), 2) }}</td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>

<div class="row justify-content-end">
  <div class="col-md-5">
    <table class="table table-sm">
      <tr><td>Shares bought (value + commission)</td><td class="text-end">{{ number_format($boughtTotal, 2) }}</td></tr>
      <tr><td>Shares sold (value − commission)</td><td class="text-end">{{ number_format($soldTotal, 2) }}</td></tr>
      <tr class="fw-bold">
        <td>{{ $net >= 0 ? 'Net payable to broker' : 'Net receivable from broker' }}</td>
        <td class="text-end">{{ number_format(abs($net), 2) }}</td>
      </tr>
    </table>
    @if ($trade->items->where('type', 'opening')->count())
      <small class="text-muted d-block mb-2">Opening balances are shares already held, so they are not part of the broker settlement.</small>
    @endif
  </div>
</div>

@if (count($sells))
<h5 class="mt-4">Profit / loss on sales in this trade</h5>
<table class="table table-bordered table-sm">
  <thead>
    <tr>
      <th>Stock</th><th>Bought In</th><th>Buy Date</th><th class="text-end">Buy Price</th><th class="text-end">Sell Price</th>
      <th class="text-end">Qty</th><th class="text-end">Gross P/L</th><th class="text-end">Commission</th><th class="text-end">Net P/L</th><th>Type</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($sells as $m)
    <tr>
      <td>{{ $m['symbol'] }}</td>
      <td>{{ $m['buy_trade_no'] }}</td>
      <td>{{ \Carbon\Carbon::parse($m['buy_date'])->format('d-M-Y') }}</td>
      <td class="text-end">{{ number_format($m['buy_price'], 4) }}</td>
      <td class="text-end">{{ number_format($m['sell_price'], 4) }}</td>
      <td class="text-end">{{ number_format($m['qty']) }}</td>
      <td class="text-end {{ $pl($m['gross_pl']) }}">{{ number_format($m['gross_pl'], 2) }}</td>
      <td class="text-end">{{ number_format($m['total_commission'], 2) }}</td>
      <td class="text-end fw-bold {{ $pl($m['net_pl']) }}">{{ number_format($m['net_pl'], 2) }}</td>
      <td>{{ $m['is_intraday'] ? 'Intraday' : ($m['buy_type'] === 'opening' ? 'Opening' : 'Delivery') }}</td>
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td colspan="5" class="text-end">Total</td>
      <td class="text-end">{{ number_format(array_sum(array_column($sells, 'qty'))) }}</td>
      @php $g = array_sum(array_column($sells, 'gross_pl')); $n = array_sum(array_column($sells, 'net_pl')); @endphp
      <td class="text-end {{ $pl($g) }}">{{ number_format($g, 2) }}</td>
      <td class="text-end">{{ number_format(array_sum(array_column($sells, 'total_commission')), 2) }}</td>
      <td class="text-end {{ $pl($n) }}">{{ number_format($n, 2) }}</td>
      <td></td>
    </tr>
  </tfoot>
</table>
@endif

@if (count($buys))
<h5 class="mt-4">Shares from this trade sold later</h5>
<table class="table table-bordered table-sm">
  <thead>
    <tr><th>Stock</th><th>Sold In</th><th>Sell Date</th><th class="text-end">Qty</th><th class="text-end">Sell Price</th><th class="text-end">Net P/L</th></tr>
  </thead>
  <tbody>
    @foreach ($buys as $m)
    <tr>
      <td>{{ $m['symbol'] }}</td>
      <td>{{ $m['sell_trade_no'] }}</td>
      <td>{{ \Carbon\Carbon::parse($m['sell_date'])->format('d-M-Y') }}</td>
      <td class="text-end">{{ number_format($m['qty']) }}</td>
      <td class="text-end">{{ number_format($m['sell_price'], 4) }}</td>
      <td class="text-end {{ $pl($m['net_pl']) }}">{{ number_format($m['net_pl'], 2) }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
