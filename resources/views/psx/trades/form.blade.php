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
          action="{{ $trade ? route('psx_trades.update', $trade->id) : route('psx_trades.store') }}">
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
                <tr><td>Total commission charged</td><td class="text-end" id="totComm">0.00</td></tr>
                <tr class="fw-bold"><td id="netLabel">Net payable to broker</td><td class="text-end" id="totNet">0.00</td></tr>
              </table>
            </div>
          </div>
        </div>

        <footer class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted">
            <i class="fas fa-keyboard"></i> Tab / Enter moves to the next box. Enter on the last box adds a row. <strong>Ctrl+Enter</strong> saves.
            Empty rows are ignored. No vouchers or ledger entries are created yet.
          </small>
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
      <select class="form-control t-stock" data-name="psx_stock_id">
        <option value="">Select stock</option>
        @foreach ($stocks as $s)
          <option value="{{ $s->id }}" data-symbol="{{ $s->symbol }}">{{ $s->symbol }}{{ $s->name ? ' - ' . $s->name : '' }}</option>
        @endforeach
      </select>
      <div class="t-from-box mt-1" style="display:none">
        <select class="form-control form-control-sm t-from" data-name="buy_item_id" aria-label="Sold from purchase">
          <option value="">Sold from: auto (same day, then oldest)</option>
        </select>
      </div>
    </td>
    <td class="text-end t-holding text-muted">-</td>
    <td><input type="number" class="form-control t-qty" data-name="qty" min="1" step="any"></td>
    <td><input type="number" class="form-control t-price" data-name="price" min="0" step="any"></td>
    <td class="text-end t-value">0.00</td>
    <td class="text-end"><span class="t-comm">0.00</span><br><small class="t-intraday text-info"></small></td>
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
  // Open purchases per "brokerId|SYMBOL" (excluding this trade), for "Sold from"
  var LOTS = @json((object) $lots);
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
      row.find('.t-from').data('want', data.buy_item_id ? String(data.buy_item_id) : '');
    }
    $('#tradeRows').append(row);
    initStockSelect(row.find('.t-stock'));
    fillFrom(row);
    recalc();
    return row;
  }

  // "Sold from": the purchases of this stock still held at the chosen broker
  function fillFrom(row) {
    var box = row.find('.t-from-box'), sel = row.find('.t-from');
    var isSell = row.find('.t-type').val() === 'sell';
    var sym = row.find('.t-stock option:selected').data('symbol');
    var want = sel.val() || sel.data('want') || '';
    var lots = (isSell && sym) ? (LOTS[$('#broker').val() + '|' + sym] || []) : [];
    sel.find('option:not(:first)').remove();
    lots.forEach(function (l) { sel.append($('<option>').val(String(l.id)).text('Sold from ' + l.label)); });
    sel.val(lots.some(function (l) { return String(l.id) === want; }) ? want : '');
    sel.data('want', want);
    box.toggle(isSell && lots.length > 0);
  }

  // ── Stock dropdown: opens on Tab, type to search, Enter picks and jumps to Qty ──
  function initStockSelect($sel) {
    $sel.select2({ width: '100%', placeholder: 'Select stock' });
    var $box = $sel.next('.select2-container');
    $box.find('.select2-selection').on('focus', function () {
      if ($sel.data('skipOpen')) { $sel.data('skipOpen', false); return; }
      $sel.select2('open');
    });
    // jQuery 3.6 stops Select2 putting the cursor in its search box, so typing did nothing: focus it ourselves
    $sel.on('select2:open', function () {
      setTimeout(function () {
        var f = document.querySelector('.select2-container--open .select2-search__field');
        if (f) f.focus();
      }, 0);
    });
    $sel.on('select2:closing', function () { $sel.data('skipOpen', true); });
    $sel.on('select2:select', function () {
      setTimeout(function () { $sel.closest('tr').find('.t-qty').trigger('focus'); }, 0);
    });
  }

  // Order of boxes in a row for Enter navigation
  function rowFields(tr) {
    return [tr.find('.t-type'), tr.find('.t-stock'), tr.find('.t-qty'), tr.find('.t-price'), tr.find('.t-other'), tr.find('.t-remarks')];
  }

  // fromKey: called while a key is held down; open the dropdown only after the key is released,
  // otherwise the same Enter lands in the dropdown and closes it again
  function focusField($f, fromKey) {
    if ($f.hasClass('t-stock')) {
      if (fromKey) { $(document).one('keyup', function () { $f.select2('open'); }); } else { $f.select2('open'); }
    } else {
      $f.trigger('focus');
      if ($f.is('input')) $f.select();
    }
  }

  function isBlank(tr) {
    return !tr.find('.t-stock').val() && !tr.find('.t-qty').val() && !tr.find('.t-price').val();
  }

  function recalc() {
    var s = brokerSettings();
    $('#brokerInfo').text(s.id ? 'Rs ' + s.min + '/share min or ' + s.rate + '%' + (s.waiver ? ', one commission on same-day trades' : '') : '');

    // Pass 1: amounts per row; a sell uses today's buys first (day trade), then older holdings
    var pos = {};   // symbol => {older, lots: today's buys [{d, open}]}
    var rows = [];
    var notes = [];

    $('#tradeRows tr').each(function () {
      var r = $(this);
      var d = {
        r: r,
        type: r.find('.t-type').val(),
        sym: r.find('.t-stock option:selected').data('symbol'),
        qty: parseFloat(r.find('.t-qty').val()) || 0,
        price: parseFloat(r.find('.t-price').val()) || 0,
        other: parseFloat(r.find('.t-other').val()) || 0,
        intraday: 0
      };
      d.value = d.qty * d.price;
      d.comm = (d.type === 'opening' || !d.qty) ? 0 : commission(d.qty, d.price, s);
      rows.push(d);

      if (d.sym && !pos[d.sym]) pos[d.sym] = { older: parseFloat(HOLDINGS[s.id + '|' + d.sym] || 0), lots: [] };
      var p = d.sym ? pos[d.sym] : null;
      var held = p ? p.older + p.lots.reduce(function (a, l) { return a + l.open; }, 0) : 0;
      r.find('.t-holding').text(p ? held.toLocaleString() : '-');
      r.removeClass('row-short');
      if (!p || !d.qty) return;

      if (d.type === 'opening') { p.older += d.qty; return; }
      if (d.type === 'buy') { p.lots.push({ d: d, open: d.qty }); return; }

      // sell
      if (d.qty > held) {
        r.addClass('row-short');
        notes.push('<span class="text-danger"><i class="fas fa-ban"></i> ' + d.sym + ': selling ' + d.qty.toLocaleString() +
                   ' but only ' + held.toLocaleString() + ' held at this broker.</span>');
      }
      // today's buys first (day trade: one commission), then older holdings
      var left = d.qty;
      p.lots.forEach(function (l) {
        var take = Math.min(left, l.open);
        if (take > 0) { l.open -= take; left -= take; if (s.waiver) l.d.intraday += take; }
      });
      p.older -= Math.min(left, p.older);
    });

    // Pass 2: show the commission actually charged (a buy sold the same day carries none)
    var totBuy = 0, totSell = 0, totComm = 0;
    rows.forEach(function (d) {
      var charged = d.comm;
      if (d.type === 'buy' && d.intraday > 0) charged = Math.round(d.comm * (1 - d.intraday / d.qty) * 100) / 100;
      var net = d.type === 'sell' ? d.value - charged - d.other : d.value + charged + d.other;

      d.r.find('.t-value').text(fmt(d.value));
      d.r.find('.t-comm').text(fmt(charged));
      d.r.find('.t-intraday').text(d.type === 'buy' && d.intraday > 0 ? 'Intraday: ' + d.intraday.toLocaleString() + ' sold today' : '');
      d.r.find('.t-net').text(fmt(net));

      if (!d.sym || !d.qty) return;
      totComm += charged;
      if (d.type === 'sell') totSell += net;
      else if (d.type === 'buy') totBuy += net;   // opening balances are not paid to the broker
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

  // Empty rows are dropped; partly filled rows must be completed
  function checkBeforeSave() {
    var problems = [];
    $('#tradeRows tr').each(function (n) {
      var tr = $(this);
      tr.removeClass('row-short');
      if (isBlank(tr)) return;
      var missing = [];
      if (!tr.find('.t-stock').val()) missing.push('stock');
      if (!(parseFloat(tr.find('.t-qty').val()) > 0)) missing.push('quantity');
      if (!(parseFloat(tr.find('.t-price').val()) > 0)) missing.push('price');
      if (missing.length) { tr.addClass('row-short'); problems.push('Row ' + (n + 1) + ': enter ' + missing.join(', ') + '.'); }
    });
    var filled = $('#tradeRows tr').filter(function () { return !isBlank($(this)); }).length;
    if (!filled) problems.push('Enter at least one row.');
    if (problems.length) {
      $('#formNotes').html('<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + problems.join('<br>') + '</span>');
      return false;
    }
    $('#tradeRows tr').each(function () { if (isBlank($(this))) $(this).remove(); });
    return true;
  }

  $(document).ready(function () {
    $('#addRow').on('click', function () { focusField(rowFields(addRow())[0]); });
    $('#tradeRows').on('click', '.t-remove', function () {
      if ($('#tradeRows tr').length > 1) { $(this).closest('tr').remove(); recalc(); }
    });
    $('#tradeRows').on('change input', 'input, select', recalc);
    $('#tradeRows').on('change', '.t-type, .t-stock', function () { fillFrom($(this).closest('tr')); });
    $('#broker').on('change', function () { $('#tradeRows tr').each(function () { fillFrom($(this)); }); });
    $('#broker, #tradeDate').on('change', recalc);

    // Enter = next box; Enter on the last box of a row = new row (or next row); Ctrl+Enter / Ctrl+S = save
    $('#tradeForm').on('keydown', function (e) {
      if ((e.key === 'Enter' && (e.ctrlKey || e.metaKey)) || (e.key.toLowerCase() === 's' && (e.ctrlKey || e.metaKey))) {
        e.preventDefault();
        $('#tradeForm').trigger('submit');
        return;
      }
      if (e.key !== 'Enter') return;
      var $t = $(e.target);
      if ($t.is('button, a, textarea')) return;
      e.preventDefault();

      var tr = $t.closest('#tradeRows tr');
      if (!tr.length) {   // header boxes: go to the first row
        focusField(rowFields($('#tradeRows tr').first())[0], true);
        return;
      }
      var fields = rowFields(tr);
      var idx = fields.findIndex(function (f) { return f.is($t); });
      if (idx > -1 && idx < fields.length - 1) { focusField(fields[idx + 1], true); return; }

      var next = tr.next('tr');
      if (!next.length) {
        if (isBlank(tr)) return;
        next = addRow({ type: tr.find('.t-type').val() });
      }
      focusField(rowFields(next)[0], true);
    });

    $('#tradeForm').on('submit', function (e) {
      if (!checkBeforeSave()) { e.preventDefault(); return false; }
      $('#saveBtn').prop('disabled', true);
    });

    if (ITEMS.length) { ITEMS.forEach(addRow); } else { addRow(); }
    if (!ITEMS.length) setTimeout(function () { focusField(rowFields($('#tradeRows tr').first())[0]); }, 50);
  });
</script>
@endsection
