@extends('layouts.app')

@section('title', 'PSX | Trade ' . $trade->trade_no)

@section('content')
<div class="row">
  <div class="col">
    @include('psx._alerts')
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Trade {{ $trade->trade_no }}</h2>
        <div>
          <a href="{{ route('psx_trades.index') }}" class="btn btn-default"><i class="fas fa-arrow-left"></i> All Trades</a>
          @can('psx_trades.print')
          <a href="{{ route('psx_trades.print', $trade->id) }}" target="_blank" class="btn btn-success"><i class="fas fa-print"></i> Print</a>
          @endcan
          @can('psx_trades.edit')
          <a href="{{ route('psx_trades.edit', $trade->id) }}" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>
          @endcan
          @can('psx_trades.delete')
          <form action="{{ route('psx_trades.destroy', $trade->id) }}" method="POST" class="d-inline"
                onsubmit="return confirm('Delete trade {{ $trade->trade_no }}?')">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
          </form>
          @endcan
        </div>
      </header>
      <div class="card-body">
        @include('psx.trades._detail')
        <small class="text-muted">
          Entered by {{ $trade->creator->name ?? '-' }} on {{ $trade->created_at->format('d-M-Y h:i A') }}.
          No vouchers are posted for PSX trades yet.
        </small>
      </div>
    </section>
  </div>
</div>
@endsection
