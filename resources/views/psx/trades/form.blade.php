@extends('layouts.app')

@section('title', $trade ? 'PSX | Edit Trade' : 'PSX | New Trade')

@section('content')
<style>
  #tradeRows td { vertical-align: middle; }
  #tradeRows input.form-control, #tradeRows select.form-control { min-width: 90px; }
  .row-short { background: #fdecea !important; }
</style>

<div class="row">
  <div class="col">
    @include('psx._alerts')

    <form id="tradeForm" method="POST" autocomplete="off"
          action="{{ $trade ? route('psx_trades.update', $trade->id) : route('psx_trades.store') }}"
          onkeydown="return event.key != 'Enter' || event.target.tagName == 'TEXTAREA';">
      @csrf
      @if ($trade) @method('PUT') @endif

      <section class="card">
        <header class="card-header">
          <h2 class="card-title">{{ $trade ? 'Edit Trade ' . $trade->trade_no : 'New Trade' }}</h2>
        </header>

        <div class="card-body">
          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Trade #</label>
              <input type="text" class="form-control" value="{{ $trade->trade_no ?? '' }}" placeholder="Auto" readonly>
            </div>
            <div class="col-md-2 mb-3">
              <label>Trade Date <span class="text-danger">*</span></label>
              <input type="date" name="trade_date" id="tradeDate" class="form-control" required
                     value="{{ old('trade_date', $trade ? $trade->trade_date->format('Y-m-d') : date('Y-m-d')) }}">
            </div>
            <div class="col-md-3 mb-3">
              <label>Broker <span class="text-danger">*</span></label>
              <select name="psx_broker_id" id="broker" class="form-control" required>
                @foreach ($brokers as $b)
                  <option value="{{ $b->id }}"
                          data-min="{{ $b->min_commission_per_share }}"
                          data-rate="{{ $b->commission_rate }}"
                          data-waiver="{{ $b->intraday_buy_waiver ? 1 : 0 }}"
                          {{ old('psx_broker_id', $trade->psx_broker_id ?? null) == $b->id ? 'selected' : '' }}>
                    {{ $b->name }}{{ $b->is_default ? ' (default)' : '' }}
                  </option>
                @endforeach
              </select>
              <small class="text-muted" id="brokerInfo"></small>
            </div>
            <div class="col-md-2 mb-3">
              <label>Contract Note #</label>
              <input type="text" name="contract_note_no" class="form-control" maxlength="100" placeholder="Broker's slip no."
                     value="{{ old('contract_note_no', $trade->contract_note_no ?? '') }}">
            </div>
            <div class="col-md-3 mb-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" value="{{ old('remarks', $trade->remarks ?? '') }}">
            </div>
          </div>

          @if ($brokers->isEmpty())
            <div class="alert alert-warning">No active broker found. Add one under <a href="{{ route('psx_brokers.index') }}">Brokers &amp; Commission</a>.</div>
          @endif
          @if ($stocks->isEmpty())
            <div class="alert alert-warning">No active stock found. Add stocks under <a href="{{ route('psx_stocks.index') }}">Stocks</a>.</div>
          @endif

          <div class="table-responsive">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th style="width:120px">Type</th>
                  <th style="min-width:220px">Stock</th>
                  <th class="text-end" title="Shares currently held at this broker (excluding this trade)">Held Now</th>
                  <th>Qty</th>
                  <th>Price (Rs)</th>
                  <th class="text-end">Value</th>
                  <th class="text-end">Commission</th>
                  <th>Other Charges</th>
                  <th class="text-end">Net Amount</th>
                  <th>Row Remarks</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="tradeRows"></tbody>
              <tfoot>
                <tr>
                  <td colspan="11">
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
          <small class="text-muted">Commission is worked out from the broker's settings when you save. No vouchers or ledger entries are created yet.</small>
          <div>
            <a href="{{ $trade ? route('psx_trades.show', $trade->id) : route('psx_trades.index') }}" class="btn btn-default">Cancel</a>
            <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> {{ $trade ? 'Update Trade' : 'Save Trade' }}</button>
          </div>
        </footer>
      </section>
    </form>
  </div>
</div>

<template id="rowTemplate">
  <tr>
    <td>
      <select class="form-control t-type" data-name="type">
        <option value="buy">Buy</option>
        <option value="sell">Sell</option>
        <option value="opening">Opening</option>
      </select>
    </td>
    <td>
      <select class="form-control t-stock" data-name="psx_stock_id" required>
        <option value="">Select stock</option>
        @foreach ($stocks as $s)
          <option value="{{ $s->id }}" data-symbol="{{ $s->symbol }}">{{ $s->symbol }}{{ $s->name ? ' - ' . $s->name : '' }}</option>
        @endforeach
      </select>
    </td>
    <td class="text-end t-holding text-muted">-</td>
    <td><input type="number" class="form-control t-qty" data-name="qty" min="1" step="any" required></td>
    <td><input type="number" class="form-control t-price" data-name="price" min="0" step="any" required></td>
    <td class="text-end t-value">0.00</td>
    <td class="text-end t-comm">0.00</td>
    <td><input type="number" class="form-control t-other" data-name="other_charges" min="0" step="0.01" value="0"></td>
    <td class="text-end t-net">0.00</td>
    <td><input type="text" class="form-control t-remarks" data-name="remarks" maxlength="255"></td>
    <td><button type="button" class="btn btn-sm btn-danger t-remove" title="Remove"><i class="fas fa-times"></i></button></td>
  </tr>
</template>

<script>
  // Shares held now, excluding this trade: "brokerId|SYMBOL" => qty
  var HOLDINGS = @json((object) $holdings);
  var ITEMS = @json($items);
  var rowSeq = 0;

  function fmt(n) {
    var v = Math.round(n * 100) / 100; if (v === 0) v = 0;
    return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function brokerSettings() {
    var o = $('#broker option:selected');
    return {
      id: $('#broker').val(),
      min: parseFloat(o.data('min')) || 0,
      rate: parseFloat(o.data('rate')) || 0,
      waiver: o.data('waiver') == 1
    };
  }

  function commission(qty, price, s) {
    return Math.round(Math.max(s.min * qty, (s.rate / 100) * qty * price) * 100) / 100;
  }

  function addRow(data) {
    var row = $($('#rowTemplate').html());
    var i = rowSeq++;
    row.find('[data-name]').each(function () {
      $(this).attr('name', 'items[' + i + '][' + $(this).data('name') + ']');
    });
    if (data) {
      row.find('.t-type').val(data.type || 'buy');
      row.find('.t-stock').val(data.psx_stock_id || '');
      row.find('.t-qty').val(data.qty);
      row.find('.t-price').val(data.price);
      row.find('.t-other').val(data.other_charges || 0);
      row.find('.t-remarks').val(data.remarks || '');
    }
    $('#tradeRows').append(row);
    row.find('.t-stock').select2({ width: '100%' });
    recalc();
  }

  function recalc() {
    var s = brokerSettings();
    $('#brokerInfo').text(s.id ? 'Rs ' + s.min + '/share min or ' + s.rate + '%' + (s.waiver ? ', intraday waiver on' : '') : '');

    var totBuy = 0, totSell = 0, totComm = 0;
    var pos = {};   // symbol => {older, today, buyQty, buyComm}; FIFO: older shares are sold first, then today's buys
    var notes = [];

    $('#tradeRows tr').each(function () {
      var r = $(this);
      var type = r.find('.t-type').val();
      var sym = r.find('.t-stock option:selected').data('symbol');
      var qty = parseFloat(r.find('.t-qty').val()) || 0;
      var price = parseFloat(r.find('.t-price').val()) || 0;
      var other = parseFloat(r.find('.t-other').val()) || 0;

      if (sym && !pos[sym]) pos[sym] = { older: parseFloat(HOLDINGS[s.id + '|' + sym] || 0), today: 0, buyQty: 0, buyComm: 0 };
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

      totSell += net;
      var available = p.older + p.today;
      if (qty > available) {
        r.addClass('row-short');
        notes.push('<span class="text-danger"><i class="fas fa-ban"></i> ' + sym + ': selling ' + qty.toLocaleString() +
                   ' but only ' + available.toLocaleString() + ' held at this broker.</span>');
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

    if ($('#tradeDate').val() && $('#tradeDate').val() < '{{ date('Y-m-d') }}') {
      notes.push('<span class="text-muted"><i class="fas fa-info-circle"></i> Back-dated trade: "Held Now" shows today\'s holding. Sales are checked against the holding on the trade date when you save.</span>');
    }

    var netAmt = totBuy - totSell;
    $('#totBuy').text(fmt(totBuy));
    $('#totSell').text(fmt(totSell));
    $('#totComm').text(fmt(totComm));
    $('#netLabel').text(netAmt >= 0 ? 'Net payable to broker' : 'Net receivable from broker');
    $('#totNet').text(fmt(Math.abs(netAmt)));
    $('#formNotes').html(notes.join('<br>'));
  }

  $(document).ready(function () {
    $('#addRow').on('click', function () { addRow(); });
    $('#tradeRows').on('click', '.t-remove', function () {
      if ($('#tradeRows tr').length > 1) { $(this).closest('tr').remove(); recalc(); }
    });
    $('#tradeRows').on('change keyup', 'input, select', recalc);
    $('#broker, #tradeDate').on('change', recalc);
    $('#tradeForm').on('submit', function () { $('#saveBtn').prop('disabled', true); });

    if (ITEMS.length) { ITEMS.forEach(addRow); } else { addRow(); addRow(); }
  });
</script>
@endsection
