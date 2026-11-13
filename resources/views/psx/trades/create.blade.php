@extends('layouts.app')

@section('title', 'PSX | New Trade')

@section('content')
<style>
  #tradeRows td { vertical-align: middle; }
  #tradeRows input.form-control, #tradeRows select.form-control { min-width: 90px; }
  .row-short { background: #fdecea !important; }
</style>

<div class="row">
  <div class="col">
    @include('psx._demo_banner', [
      'slot' => 'This is the trade entry screen as it will work. Calculations run live, but the Save button is disabled.',
    ])

    {{-- No action / no route: the form cannot be submitted in the demo --}}
    <form id="tradeForm" onsubmit="return false;" onkeydown="return event.key != 'Enter';" autocomplete="off">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">New Trade</h2>
        </header>

        <div class="card-body">
          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Trade #</label>
              <input type="text" class="form-control" placeholder="Auto" readonly>
            </div>
            <div class="col-md-2 mb-3">
              <label>Trade Date <span class="text-danger">*</span></label>
              <input type="date" id="tradeDate" class="form-control" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-3 mb-3">
              <label>Broker <span class="text-danger">*</span></label>
              <select id="broker" class="form-control">
                @foreach ($brokers as $b)
                  <option value="{{ $b->id }}"
                          data-min="{{ $b->min_commission_per_share }}"
                          data-rate="{{ $b->commission_rate }}"
                          data-waiver="{{ $b->intraday_buy_waiver ? 1 : 0 }}">
                    {{ $b->name }}{{ $b->is_default ? ' (default)' : '' }}
                  </option>
                @endforeach
              </select>
              <small class="text-muted" id="brokerInfo"></small>
            </div>
            <div class="col-md-2 mb-3">
              <label>Contract Note #</label>
              <input type="text" class="form-control" placeholder="Broker's slip no.">
            </div>
            <div class="col-md-3 mb-3">
              <label>Remarks</label>
              <input type="text" class="form-control">
            </div>
          </div>

          @if ($brokers->isEmpty())
            <div class="alert alert-warning">No active broker found. Add one under <a href="{{ route('psx_brokers.index') }}">Brokers &amp; Commission</a>.</div>
          @endif

          <div class="table-responsive">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th style="width:120px">Type</th>
                  <th style="min-width:200px">Stock</th>
                  <th class="text-end">Holding</th>
                  <th>Qty</th>
                  <th>Price (Rs)</th>
                  <th class="text-end">Value</th>
                  <th class="text-end">Commission</th>
                  <th>Other Charges</th>
                  <th class="text-end">Net Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="tradeRows"></tbody>
              <tfoot>
                <tr>
                  <td colspan="10">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addRow"><i class="fas fa-plus"></i> Add Row</button>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="row">
            <div class="col-md-7">
              <div id="formNotes" class="small"></div>
            </div>
            <div class="col-md-5">
              <table class="table table-sm mb-0">
                <tr><td>Total buys (incl. charges)</td><td class="text-end" id="totBuy">0.00</td></tr>
                <tr><td>Total sells (after charges)</td><td class="text-end" id="totSell">0.00</td></tr>
                <tr><td>Total commission</td><td class="text-end" id="totComm">0.00</td></tr>
                <tr class="fw-bold"><td id="netLabel">Net payable to broker</td><td class="text-end" id="totNet">0.00</td></tr>
              </table>
            </div>
          </div>
        </div>

        <footer class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted"><i class="fas fa-lock"></i> Saving is disabled in this demo. No vouchers or ledger entries are made.</small>
          <div>
            <a href="{{ route('psx_trades.index') }}" class="btn btn-default">Back</a>
            <button type="button" class="btn btn-primary" disabled title="Disabled in demo"><i class="fas fa-save"></i> Save Trade</button>
          </div>
        </footer>
      </section>
    </form>
  </div>
</div>

<template id="rowTemplate">
  <tr>
    <td>
      <select class="form-control t-type">
        <option value="buy">Buy</option>
        <option value="sell">Sell</option>
        <option value="opening">Opening</option>
      </select>
    </td>
    <td>
      <select class="form-control t-stock">
        <option value="">Select stock</option>
        @foreach ($stocks as $s)
          <option value="{{ $s->symbol }}">{{ $s->symbol }}{{ $s->name ? ' - ' . $s->name : '' }}</option>
        @endforeach
      </select>
    </td>
    <td class="text-end t-holding text-muted">-</td>
    <td><input type="number" class="form-control t-qty" min="1" step="1"></td>
    <td><input type="number" class="form-control t-price" min="0" step="0.0001"></td>
    <td class="text-end t-value">0.00</td>
    <td class="text-end t-comm">0.00</td>
    <td><input type="number" class="form-control t-other" min="0" step="0.01" value="0"></td>
    <td class="text-end t-net">0.00</td>
    <td><button type="button" class="btn btn-sm btn-danger t-remove" title="Remove"><i class="fas fa-times"></i></button></td>
  </tr>
</template>

<script>
  // Current holdings from the demo data (symbol => qty)
  var HOLDINGS = @json($holdings);

  function fmt(n) {
    var v = Math.round(n * 100) / 100; if (v === 0) v = 0; return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function brokerSettings() {
    var o = $('#broker option:selected');
    return {
      min: parseFloat(o.data('min')) || 0,
      rate: parseFloat(o.data('rate')) || 0,
      waiver: o.data('waiver') == 1
    };
  }

  function commission(qty, price, s) {
    return Math.round(Math.max(s.min * qty, (s.rate / 100) * qty * price) * 100) / 100;
  }

  function addRow() {
    var row = $($('#rowTemplate').html());
    $('#tradeRows').append(row);
    row.find('.t-stock').select2({ width: '100%' });
    recalc();
  }

  function recalc() {
    var s = brokerSettings();
    $('#brokerInfo').text('Rs ' + s.min + '/share min or ' + s.rate + '%' + (s.waiver ? ', intraday waiver on' : ''));

    var totBuy = 0, totSell = 0, totComm = 0;
    // Per stock while walking down the rows (FIFO: older holdings are sold first, then today's buys)
    var pos = {};   // symbol => {older, today, buyQty, buyComm}
    var notes = [];

    $('#tradeRows tr').each(function () {
      var r = $(this);
      var type = r.find('.t-type').val();
      var sym = r.find('.t-stock').val();
      var qty = parseFloat(r.find('.t-qty').val()) || 0;
      var price = parseFloat(r.find('.t-price').val()) || 0;
      var other = parseFloat(r.find('.t-other').val()) || 0;

      if (sym && !pos[sym]) pos[sym] = { older: parseFloat(HOLDINGS[sym] || 0), today: 0, buyQty: 0, buyComm: 0 };
      var p = sym ? pos[sym] : null;
      r.find('.t-holding').text(p ? (p.older + p.today).toLocaleString() : '-');

      var value = qty * price;
      var comm = (type === 'opening' || !qty) ? 0 : commission(qty, price, s);
      var net = type === 'sell' ? value - comm - other : value + comm + other;

      r.find('.t-value').text(fmt(value));
      r.find('.t-comm').text(fmt(comm));
      r.find('.t-net').text(fmt(net));
      r.removeClass('row-short');

      if (!p || !qty) return;
      totComm += comm;

      if (type === 'opening') { p.older += qty; totBuy += net; return; }
      if (type === 'buy') { p.today += qty; p.buyQty += qty; p.buyComm += comm; totBuy += net; return; }

      // sell
      totSell += net;
      var available = p.older + p.today;
      if (qty > available) {
        r.addClass('row-short');
        notes.push('<span class="text-danger"><i class="fas fa-ban"></i> ' + sym + ': selling ' + qty.toLocaleString() +
                   ' but only ' + available.toLocaleString() + ' held. This sale would be blocked.</span>');
      }
      var fromOlder = Math.min(qty, p.older);
      var fromToday = Math.min(qty - fromOlder, p.today);
      p.older -= fromOlder;
      p.today -= fromToday;
      if (fromToday > 0 && s.waiver) {
        notes.push('<span class="text-success"><i class="fas fa-check"></i> ' + sym + ': ' + fromToday.toLocaleString() +
                   ' shares bought and sold today. Buy commission of Rs ' + fmt(p.buyComm * fromToday / p.buyQty) + ' will be waived.</span>');
      }
    });

    var netAmt = totBuy - totSell;
    $('#totBuy').text(fmt(totBuy));
    $('#totSell').text(fmt(totSell));
    $('#totComm').text(fmt(totComm));
    $('#netLabel').text(netAmt >= 0 ? 'Net payable to broker' : 'Net receivable from broker');
    $('#totNet').text(fmt(Math.abs(netAmt)));
    $('#formNotes').html(notes.join('<br>'));
  }

  $(document).ready(function () {
    $('#addRow').on('click', addRow);
    $('#tradeRows').on('click', '.t-remove', function () { $(this).closest('tr').remove(); recalc(); });
    $('#tradeRows').on('change keyup', 'input, select', recalc);
    $('#broker').on('change', recalc);
    addRow();
    addRow();
  });
</script>
@endsection
