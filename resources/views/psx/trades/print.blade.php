<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Trade {{ $trade->trade_no }}</title>
  <link rel="stylesheet" href="{{ asset('assets/vendor/bootstrap/css/bootstrap.css') }}">
  <style>
    body { font-size: 12px; padding: 20px; color: #000; }
    h2 { font-size: 18px; } h5 { font-size: 14px; }
    .table { font-size: 11px; }
    .text-success { color: #000 !important; } .text-danger { color: #000 !important; }
    @media print { .no-print { display: none; } body { padding: 0; } }
  </style>
</head>
<body onload="window.print()">
  <div class="d-flex justify-content-between align-items-end border-bottom mb-3 pb-2">
    <div>
      <h2 class="mb-0">{{ config('app.name') }}</h2>
      <div>PSX Trade Slip</div>
    </div>
    <div class="text-end">Printed {{ now()->format('d-M-Y h:i A') }}</div>
  </div>
  @include('psx.trades._detail')
  <div class="d-flex justify-content-between mt-5 pt-4">
    <div class="border-top pt-1 text-center" style="width:30%">Prepared by</div>
    <div class="border-top pt-1 text-center" style="width:30%">Approved by</div>
  </div>
  <button class="btn btn-primary no-print mt-3" onclick="window.print()">Print</button>
</body>
</html>
