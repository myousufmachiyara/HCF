@extends('layouts.app')

@section('title', 'PSX | Import Excel')

@section('content')
<div class="row">
  <div class="col-xl-8">
    @include('psx._alerts')

    <section class="card">
      <header class="card-header"><h2 class="card-title">Import trades from Excel</h2></header>
      <form method="POST" action="{{ route('psx_trades.import.preview') }}" enctype="multipart/form-data">
        @csrf
        <div class="card-body">
          <p>Upload the <strong>PSX Trade Calculator</strong> sheet. You will see a preview before anything is saved.</p>

          <div class="mb-3">
            <label class="form-label">Excel file (.xlsx) <span class="text-danger">*</span></label>
            <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Broker for these trades <span class="text-danger">*</span></label>
            <select name="psx_broker_id" class="form-control" required>
              @foreach ($brokers as $b)
                <option value="{{ $b->id }}" {{ old('psx_broker_id') == $b->id ? 'selected' : '' }}>
                  {{ $b->name }}{{ $b->is_default ? ' (default)' : '' }} · Rs {{ rtrim(rtrim(number_format($b->min_commission_per_share, 4), '0'), '.') }}/share or {{ rtrim(rtrim(number_format($b->commission_rate, 4), '0'), '.') }}%
                </option>
              @endforeach
            </select>
            <small class="text-muted">Commission is worked out from this broker's settings, the same way as when a trade is entered by hand.</small>
          </div>

          <div class="mb-2"><label class="form-label">What to do with the trades already in the system</label></div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="mode" value="replace" id="modeReplace" {{ old('mode', 'replace') === 'replace' ? 'checked' : '' }}>
            <label class="form-check-label" for="modeReplace">
              <strong>Replace</strong>: delete all existing PSX trades, then import the sheet
              <br><small class="text-muted">Use this to start fresh from the client's sheet. Stocks and brokers are kept.</small>
            </label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="mode" value="append" id="modeAppend" {{ old('mode') === 'append' ? 'checked' : '' }}>
            <label class="form-check-label" for="modeAppend">
              <strong>Add</strong>: keep existing trades and add the sheet's trades to them
              <br><small class="text-muted">Only for a sheet with new trades. Importing the same sheet twice would double the trades.</small>
            </label>
          </div>

          @if ($existing)
            <div class="alert alert-warning mb-0">
              <i class="fas fa-exclamation-triangle"></i>
              There are <strong>{{ number_format($existing) }} trades ({{ number_format($rows) }} rows)</strong> in the system now.
              <a href="{{ route('psx_trades.export') }}">Download them as a CSV backup</a> before replacing.
            </div>
          @endif
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('psx_trades.index') }}" class="btn btn-default">Cancel</a>
          <button class="btn btn-primary"><i class="fas fa-search"></i> Check sheet</button>
        </footer>
      </form>
    </section>
  </div>

  <div class="col-xl-4">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Sheet layout</h2></header>
      <div class="card-body small">
        <p class="mb-2">First sheet, headings in row 1, one trade per row from row 2:</p>
        <table class="table table-sm mb-2">
          <tr><td>B</td><td>Stock Name</td></tr>
          <tr><td>C, D, E</td><td>Purchase Date, Price, Qty</td></tr>
          <tr><td>F, G, H</td><td>Selling Date, Price, Qty <span class="text-muted">(blank if not sold yet)</span></td></tr>
        </table>
        <p class="mb-1">Commission, P/L and the other formula columns are ignored; the system works them out again.</p>
        <p class="mb-1">Each row becomes a buy, plus a sell if the selling side is filled. Rows are grouped into one trade per date.</p>
        <p class="mb-0">Stocks not yet in the Stocks list are added automatically.</p>
      </div>
    </section>
  </div>
</div>
@endsection
