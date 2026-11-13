@extends('layouts.app')

@section('title', 'PSX | Brokers & Commission')

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
        <h2 class="card-title">Brokers &amp; Commission Settings</h2>
        @can('psx_brokers.create')
        <button type="button" class="modal-with-form btn btn-primary" href="#addBrokerModal">
          <i class="fas fa-plus"></i> Add Broker
        </button>
        @endcan
      </header>

      <div class="card-body">
        <p class="text-muted mb-3">
          The <strong>default</strong> broker's commission settings are used in the Trades, Holdings and Profit &amp; Loss screens.
          Change the rates here and those screens recalculate.
        </p>
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="datatable-psx-brokers">
            <thead>
              <tr>
                <th>#</th>
                <th>Broker</th>
                <th>Client Code</th>
                <th>Contact</th>
                <th class="text-end">Min / Share (Rs)</th>
                <th class="text-end">Rate (%)</th>
                <th>Intraday Waiver</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($brokers as $broker)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                  <strong>{{ $broker->name }}</strong>
                  @if($broker->code)<small class="text-muted">({{ $broker->code }})</small>@endif
                  @if($broker->is_default)<span class="badge bg-primary ms-1">Default</span>@endif
                </td>
                <td>{{ $broker->client_code ?? '-' }}</td>
                <td>
                  {{ $broker->contact_person ?? '' }}
                  @if($broker->phone)<br><small>{{ $broker->phone }}</small>@endif
                  @if($broker->email)<br><small>{{ $broker->email }}</small>@endif
                </td>
                <td class="text-end">{{ number_format($broker->min_commission_per_share, 4) }}</td>
                <td class="text-end">{{ number_format($broker->commission_rate, 4) }}</td>
                <td>
                  <span class="badge {{ $broker->intraday_buy_waiver ? 'bg-success' : 'bg-secondary' }}">{{ $broker->intraday_buy_waiver ? 'Yes' : 'No' }}</span>
                </td>
                <td>
                  <span class="badge {{ $broker->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $broker->is_active ? 'Active' : 'Inactive' }}</span>
                </td>
                <td class="text-nowrap">
                  @can('psx_brokers.edit')
                  <a class="text-primary modal-with-form" href="#editBrokerModal{{ $broker->id }}" title="Edit"><i class="fa fa-edit"></i></a>
                  @endcan
                  @can('psx_brokers.delete')
                  <form action="{{ route('psx_brokers.destroy', $broker->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete {{ $broker->name }}?')">
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

    @foreach ($brokers as $broker)
    <div id="editBrokerModal{{ $broker->id }}" class="modal-block modal-block-warning modal-block-lg mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('psx_brokers.update', $broker->id) }}">
          @csrf
          @method('PUT')
          <header class="card-header"><h2 class="card-title">Edit Broker: {{ $broker->name }}</h2></header>
          <div class="card-body">
            @include('psx._broker_fields', ['broker' => $broker])
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-warning">Update</button>
            <button class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endforeach

    <div id="addBrokerModal" class="modal-block modal-block-primary modal-block-lg mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('psx_brokers.store') }}">
          @csrf
          <header class="card-header"><h2 class="card-title">New Broker</h2></header>
          <div class="card-body">
            @include('psx._broker_fields', ['broker' => null])
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
    $('#datatable-psx-brokers').DataTable({ pageLength: 50, order: [] });
  });
</script>
@endsection
