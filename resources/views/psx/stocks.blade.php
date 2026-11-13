@extends('layouts.app')

@section('title', 'PSX | Stocks')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif
      @if ($errors->any())
        <div class="alert alert-danger mb-0">
          <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">PSX Stocks</h2>
        @can('psx_stocks.create')
        <button type="button" class="modal-with-form btn btn-primary" href="#addStockModal">
          <i class="fas fa-plus"></i> Add Stock
        </button>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="datatable-psx-stocks">
            <thead>
              <tr>
                <th>#</th>
                <th>Symbol</th>
                <th>Company Name</th>
                <th>Sector</th>
                <th>Status</th>
                <th>Remarks</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($stocks as $stock)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td><strong>{{ $stock->symbol }}</strong></td>
                <td>{{ $stock->name ?? '-' }}</td>
                <td>{{ $stock->sector ?? '-' }}</td>
                <td>
                  <span class="badge {{ $stock->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $stock->is_active ? 'Active' : 'Inactive' }}</span>
                </td>
                <td>{{ $stock->remarks }}</td>
                <td class="text-nowrap">
                  @can('psx_stocks.edit')
                  <a class="text-primary modal-with-form" href="#editStockModal{{ $stock->id }}" title="Edit"><i class="fa fa-edit"></i></a>
                  @endcan
                  @can('psx_stocks.delete')
                  <form action="{{ route('psx_stocks.destroy', $stock->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete {{ $stock->symbol }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 m-0 text-danger" title="Delete"><i class="fas fa-trash-alt"></i></button>
                  </form>
                  @endcan
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{-- Edit modals (outside the table so DataTables doesn't touch them) --}}
    @foreach ($stocks as $stock)
    <div id="editStockModal{{ $stock->id }}" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('psx_stocks.update', $stock->id) }}">
          @csrf
          @method('PUT')
          <header class="card-header"><h2 class="card-title">Edit Stock: {{ $stock->symbol }}</h2></header>
          <div class="card-body">
            @include('psx._stock_fields', ['stock' => $stock])
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-warning">Update</button>
            <button class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endforeach

    {{-- Add modal --}}
    <div id="addStockModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('psx_stocks.store') }}">
          @csrf
          <header class="card-header"><h2 class="card-title">New Stock</h2></header>
          <div class="card-body">
            @include('psx._stock_fields', ['stock' => null])
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Create</button>
            <button class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
  </div>
</div>
<script>
  $(document).ready(function () {
    $('#datatable-psx-stocks').DataTable({ pageLength: 100 });
  });
</script>
@endsection
