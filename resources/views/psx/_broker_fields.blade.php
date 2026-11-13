@php $key = $broker->id ?? 'New'; @endphp
<h5 class="mb-2">Broker details</h5>
<div class="row">
  <div class="col-md-6 mb-3">
    <label>Broker Name <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="name" value="{{ $broker->name ?? '' }}" required>
  </div>
  <div class="col-md-3 mb-3">
    <label>Broker Code</label>
    <input type="text" class="form-control" name="code" value="{{ $broker->code ?? '' }}">
  </div>
  <div class="col-md-3 mb-3">
    <label>Client Code / UIN</label>
    <input type="text" class="form-control" name="client_code" value="{{ $broker->client_code ?? '' }}">
  </div>
  <div class="col-md-4 mb-3">
    <label>Contact Person</label>
    <input type="text" class="form-control" name="contact_person" value="{{ $broker->contact_person ?? '' }}">
  </div>
  <div class="col-md-4 mb-3">
    <label>Phone</label>
    <input type="text" class="form-control" name="phone" value="{{ $broker->phone ?? '' }}">
  </div>
  <div class="col-md-4 mb-3">
    <label>Email</label>
    <input type="email" class="form-control" name="email" value="{{ $broker->email ?? '' }}">
  </div>
</div>

<h5 class="mb-2 mt-1">Commission settings</h5>
<div class="row">
  <div class="col-md-6 mb-3">
    <label>Minimum commission per share (Rs) <span class="text-danger">*</span></label>
    <input type="number" step="0.0001" min="0" class="form-control" name="min_commission_per_share" value="{{ $broker->min_commission_per_share ?? 0.05 }}" required>
  </div>
  <div class="col-md-6 mb-3">
    <label>Commission rate (% of trade value) <span class="text-danger">*</span></label>
    <input type="number" step="0.0001" min="0" max="100" class="form-control" name="commission_rate" value="{{ $broker->commission_rate ?? 0.15 }}" required>
  </div>
  <div class="col-12 mb-2">
    <small class="text-muted">Commission per trade = the higher of (minimum per share × quantity) or (rate × trade value).</small>
  </div>
  <div class="col-12 mb-3">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="intraday_buy_waiver" value="1" id="waiver{{ $key }}" {{ ($broker->intraday_buy_waiver ?? true) ? 'checked' : '' }}>
      <label class="form-check-label" for="waiver{{ $key }}">No buy commission when shares are sold the same day (intraday). Waived on the same-day quantity only.</label>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-md-6 mb-3">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="is_default" value="1" id="default{{ $key }}" {{ ($broker->is_default ?? false) ? 'checked' : '' }}>
      <label class="form-check-label" for="default{{ $key }}">Default broker</label>
    </div>
  </div>
  <div class="col-md-6 mb-3">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active{{ $key }}" {{ ($broker->is_active ?? true) ? 'checked' : '' }}>
      <label class="form-check-label" for="active{{ $key }}">Active</label>
    </div>
  </div>
  <div class="col-12">
    <label>Remarks</label>
    <textarea class="form-control" name="remarks" rows="2">{{ $broker->remarks ?? '' }}</textarea>
  </div>
</div>
