{{-- Shared by show and print. Needs: $trade, $waived, $sells, $buys --}}
@php
  $pl = fn ($n) => $n > 0 ? 'text-success' : ($n < 0 ? 'text-danger' : '');
  $totValue = $trade->items->sum('value');
  $totComm  = $trade->items->sum('commission');
  $totWaive = $waived->sum();
  $buyNet   = $trade->items->where('type', '!=', 'sell')->sum('net_amount');
  $sellNet  = $trade->items->where('type', 'sell')->sum('net_amount');
  $net      = $buyNet - $sellNet - $totWaive;
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
      <th class="text-end">Commission</th><th class="text-end">Waived</th><th class="text-end">Other</th>
      <th class="text-end">Net Amount</th><th>Remarks</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($trade->items as $item)
    <tr>
      <td>{{ $loop->iteration }}</td>
      <td>{{ ucfirst($item->type) }}</td>
      <td><strong>{{ $item->stock->symbol }}</strong> <small class="text-muted">{{ $item->stock->name }}</small></td>
      <td class="text-end">{{ number_format($item->qty) }}</td>
      <td class="text-end">{{ number_format($item->price, 4) }}</td>
      <td class="text-end">{{ number_format($item->value, 2) }}</td>
      <td class="text-end">{{ number_format($item->commission, 2) }}</td>
      <td class="text-end text-success">{{ ($waived[$item->id] ?? 0) > 0 ? number_format($waived[$item->id], 2) : '-' }}</td>
      <td class="text-end">{{ $item->other_charges > 0 ? number_format($item->other_charges, 2) : '-' }}</td>
      <td class="text-end">{{ number_format($item->net_amount, 2) }}</td>
      <td><small>{{ $item->remarks }}</small></td>
    </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td colspan="5" class="text-end">Total</td>
      <td class="text-end">{{ number_format($totValue, 2) }}</td>
      <td class="text-end">{{ number_format($totComm, 2) }}</td>
      <td class="text-end text-success">{{ $totWaive > 0 ? number_format($totWaive, 2) : '-' }}</td>
      <td class="text-end">{{ number_format($trade->items->sum('other_charges'), 2) }}</td>
      <td colspan="2"></td>
    </tr>
    <tr class="fw-bold">
      <td colspan="9" class="text-end">{{ $net >= 0 ? 'Net payable to broker' : 'Net receivable from broker' }} (after intraday waiver)</td>
      <td class="text-end">{{ number_format(abs($net), 2) }}</td>
      <td></td>
    </tr>
  </tfoot>
</table>

@if (count($sells))
<h5 class="mt-4">Profit / loss on sales in this trade (FIFO)</h5>
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
