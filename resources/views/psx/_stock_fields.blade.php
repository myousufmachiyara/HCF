<div class="row">
  <div class="col-md-4 mb-3">
    <label>Symbol <span class="text-danger">*</span></label>
    <input type="text" class="form-control text-uppercase" name="symbol" maxlength="20" value="{{ $stock->symbol ?? '' }}" placeholder="e.g. TRG" required>
  </div>
  <div class="col-md-8 mb-3">
    <label>Company Name</label>
    <input type="text" class="form-control" name="name" value="{{ $stock->name ?? '' }}" placeholder="e.g. TRG Pakistan Ltd">
  </div>
  <div class="col-md-8 mb-3">
    <label>Sector</label>
    <input type="text" class="form-control" name="sector" value="{{ $stock->sector ?? '' }}" placeholder="e.g. Cement, Refinery, Technology">
  </div>
  <div class="col-md-4 mb-3 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="is_active" value="1" id="stockActive{{ $stock->id ?? 'New' }}" {{ ($stock->is_active ?? true) ? 'checked' : '' }}>
      <label class="form-check-label" for="stockActive{{ $stock->id ?? 'New' }}">Active</label>
    </div>
  </div>
  <div class="col-12">
    <label>Remarks</label>
    <textarea class="form-control" name="remarks" rows="2">{{ $stock->remarks ?? '' }}</textarea>
  </div>
</div>
