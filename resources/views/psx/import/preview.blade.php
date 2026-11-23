@extends('layouts.app')

@section('title', 'PSX | Import preview')

@section('content')
@php
  $signed = fn ($n) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . 'Rs ' . number_format(abs($n));
  $date   = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d M Y') : '–';
  $ok     = !count($errors);
@endphp
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Import preview: {{ $name }}</h2>
        <a href="{{ route('psx_trades.import') }}" class="btn btn-default btn-sm"><i class="fas fa-arrow-left"></i> Upload a different file</a>
      </header>
      <div class="card-body">

        @if (!$ok)
          <div class="alert alert-danger">
            <strong><i class="fas fa-times-circle"></i> {{ count($errors) }} {{ \Illuminate\Support\Str::plural('problem', count($errors)) }} found. Nothing can be imported until the sheet is corrected.</strong>
            <ul class="mb-0 mt-2">@foreach (array_slice($errors, 0, 50) as $e)<li>{{ $e }}</li>@endforeach</ul>
            @if (count($errors) > 50)<div class="mt-1">and {{ count($errors) - 50 }} more.</div>@endif
          </div>
        @else
          <div class="alert alert-success"><i class="fas fa-check-circle"></i> The sheet is ready to import. Check the figures below, then confirm.</div>
        @endif

        @if (count($parsed['warnings']))
          <div class="alert alert-warning">
            <strong>{{ count($parsed['warnings']) }} {{ \Illuminate\Support\Str::plural('note', count($parsed['warnings'])) }}</strong> (the import can still go ahead):
            <ul class="mb-0 mt-1">@foreach ($parsed['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
          </div>
        @endif

        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <table class="table table-sm mb-0">
              <tr><th colspan="2">From the sheet</th></tr>
              <tr><td>Rows with data</td><td class="text-end">{{ number_format($parsed['excel_rows']) }}</td></tr>
              <tr><td>Buys / sells</td><td class="text-end">{{ number_format($parsed['buys']) }} / {{ number_format($parsed['sells']) }}</td></tr>
              <tr><td>Dates</td><td class="text-end">{{ $date($parsed['from']) }} – {{ $date($parsed['to']) }}</td></tr>
              <tr><td>Stocks</td><td class="text-end">{{ count($parsed['symbols']) }}</td></tr>
              <tr>
                <td>New stocks to add</td>
                <td class="text-end">{{ count($parsed['new_symbols']) }}</td>
              </tr>
              @if (count($parsed['new_symbols']))
                <tr><td colspan="2"><small class="text-muted">{{ implode(', ', $parsed['new_symbols']) }}</small></td></tr>
              @endif
            </table>
          </div>
          <div class="col-lg-6">
            <table class="table table-sm mb-0">
              <tr><th colspan="2">After import (broker: {{ $broker->name }})</th></tr>
              @if ($check)
                <tr><td>Closed (sold) trades</td><td class="text-end">{{ number_format($check['summary']['trades']) }}</td></tr>
                <tr><td>Gross P/L</td><td class="text-end">{{ $signed($check['summary']['gross']) }}</td></tr>
                <tr><td>Commission</td><td class="text-end">Rs {{ number_format($check['summary']['commission']) }}</td></tr>
                <tr class="fw-bold"><td>Realised net P/L</td><td class="text-end">{{ $signed($check['summary']['net']) }}</td></tr>
                <tr><td>Stocks still held / cost incl. commission</td><td class="text-end">{{ $holdings }} / Rs {{ number_format($invested) }}</td></tr>
                @if ($mode === 'append')
                  <tr><td colspan="2"><small class="text-muted">Includes the {{ number_format($existing) }} trades already saved for this broker.</small></td></tr>
                @endif
              @else
                <tr><td colspan="2" class="text-muted">Shown once the problems are fixed.</td></tr>
              @endif
            </table>
          </div>
        </div>

        @if (count($parsed['rows']))
        <h5>First rows as they will be saved</h5>
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead><tr><th>Excel row</th><th>Date</th><th>Type</th><th>Stock</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Value</th></tr></thead>
            <tbody>
              @foreach (array_slice($parsed['rows'], 0, 12) as $r)
                <tr>
                  <td>{{ $r['excel_row'] }}</td><td>{{ $date($r['date']) }}</td><td>{{ ucfirst($r['type']) }}</td><td>{{ $r['symbol'] }}</td>
                  <td class="text-end">{{ number_format($r['qty']) }}</td><td class="text-end">{{ number_format($r['price'], 2) }}</td>
                  <td class="text-end">{{ number_format($r['qty'] * $r['price'], 2) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @if (count($parsed['rows']) > 12)<small class="text-muted">and {{ number_format(count($parsed['rows']) - 12) }} more rows.</small>@endif
        @endif
      </div>

      @if ($ok)
      <form method="POST" action="{{ route('psx_trades.import.confirm') }}" id="confirmForm">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="ext" value="{{ $ext }}">
        <input type="hidden" name="psx_broker_id" value="{{ $broker->id }}">
        <input type="hidden" name="mode" value="{{ $mode }}">
        <footer class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            @if ($mode === 'replace')
              @if ($existing)
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirmBox" required>
                  <label class="form-check-label" for="confirmBox">
                    Delete all <strong>{{ number_format($existing) }} existing PSX trades</strong> and replace them with this sheet.
                    <a href="{{ route('psx_trades.export') }}">Download a backup first</a>.
                  </label>
                </div>
              @else
                <input type="hidden" name="confirm" value="1">
                <span class="text-muted">There are no PSX trades yet, so nothing will be deleted.</span>
              @endif
            @else
              <span class="text-muted">The sheet's trades will be added to the {{ number_format($existing) }} existing trades.</span>
            @endif
          </div>
          <div>
            <a href="{{ route('psx_trades.import') }}" class="btn btn-default">Cancel</a>
            <button class="btn btn-primary" id="importBtn"><i class="fas fa-file-import"></i> Import {{ number_format(count($parsed['rows'])) }} rows</button>
          </div>
        </footer>
      </form>
      <script>
        document.getElementById('confirmForm').addEventListener('submit', function () {
          var b = document.getElementById('importBtn'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing…';
        });
      </script>
      @endif
    </section>
  </div>
</div>
@endsection
