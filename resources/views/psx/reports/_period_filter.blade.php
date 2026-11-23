{{-- Period / broker filter. Needs $action, $filters (period, from, to, broker_id), $brokers; optional $extra (html) --}}
<section class="card no-print mb-3">
  <div class="card-body">
    <form method="GET" action="{{ $action }}" class="row g-3 align-items-end period-filter">
      <div class="col-md-2">
        <label>Period</label>
        <select name="period" class="form-control period-sel">
          @foreach (\App\Support\DashboardPeriod::NAMES as $key => $name)
            <option value="{{ $key }}" {{ $filters['period'] === $key ? 'selected' : '' }}>{{ $name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2 custom-range" @if($filters['period'] !== 'custom') style="display:none" @endif>
        <label>From</label>
        <input type="date" name="from_date" class="form-control" value="{{ $filters['from'] }}">
      </div>
      <div class="col-md-2 custom-range" @if($filters['period'] !== 'custom') style="display:none" @endif>
        <label>To</label>
        <input type="date" name="to_date" class="form-control" value="{{ $filters['to'] }}">
      </div>
      <div class="col-md-2">
        <label>Broker</label>
        <select name="broker_id" class="form-control">
          <option value="">All brokers</option>
          @foreach ($brokers as $b)
            <option value="{{ $b->id }}" {{ $filters['broker_id'] == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
          @endforeach
        </select>
      </div>
      {!! $extra ?? '' !!}
      <div class="col-md-auto">
        <button class="btn btn-primary"><i class="fas fa-filter"></i> Show</button>
        <button type="button" class="btn btn-default" onclick="window.print()"><i class="fas fa-print"></i></button>
      </div>
    </form>
  </div>
</section>
<script>
  document.querySelectorAll('.period-filter .period-sel').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var custom = this.value === 'custom';
      this.form.querySelectorAll('.custom-range').forEach(function (el) { el.style.display = custom ? '' : 'none'; });
      this.form.querySelectorAll('.custom-range input').forEach(function (el) { el.disabled = !custom; });
    });
    sel.dispatchEvent(new Event('change'));
  });
</script>
