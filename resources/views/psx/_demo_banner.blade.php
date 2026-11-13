<div class="alert alert-info d-flex align-items-start mb-3" role="note">
  <i class="fas fa-info-circle me-2 mt-1"></i>
  <div>
    <strong>Demo preview.</strong>
    {{ $slot ?? 'This screen shows sample trades taken from the existing Excel sheet.' }}
    Nothing is saved, and no vouchers or ledger entries are created.
    @isset($broker)
      @if($broker)
        Commission uses the <a href="{{ route('psx_brokers.index') }}">{{ $broker->name }}</a> settings:
        Rs {{ rtrim(rtrim(number_format($broker->min_commission_per_share, 4), '0'), '.') }} per share minimum or {{ rtrim(rtrim(number_format($broker->commission_rate, 4), '0'), '.') }}% of value, whichever is higher{{ $broker->intraday_buy_waiver ? '; no buy commission on same-day sells' : '' }}.
      @endif
    @endisset
  </div>
</div>
