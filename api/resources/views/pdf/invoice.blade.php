{{-- GST tax invoice (dompdf: simple CSS, tables for layout). --}}
@php
    $intl = class_exists(\NumberFormatter::class);
    $fmt = $intl ? new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL) : null;
    $fmt?->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
    $fmt?->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
    $n = fn ($v) => $fmt ? $fmt->format($v) : number_format($v, 2);
    $words = null;
    if ($intl) {
        $spell = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
        $r = (int) floor($inv->total);
        $p = (int) round(($inv->total - $r) * 100);
        $words = 'Indian Rupees '.ucwords($spell->format($r)).($p ? ' and '.ucwords($spell->format($p)).' Paise' : '').' Only';
    }
    $title = match ($inv->status) { 'draft' => 'DRAFT INVOICE', 'cancelled' => 'CANCELLED INVOICE', default => 'TAX INVOICE' };
    $half = rtrim(rtrim(number_format($inv->gst_rate / 2, 2), '0'), '.');
    $full = rtrim(rtrim(number_format($inv->gst_rate, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 30px 34px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #16181d; }
  table { border-collapse: collapse; width: 100%; }
  .head td { vertical-align: top; }
  .logo { width: 56px; height: 56px; }
  .co { font-size: 16px; font-weight: bold; }
  .muted { color: #6b7180; }
  .title { text-align: right; }
  .title b { font-size: 17px; letter-spacing: 1px; display: block; color: {{ $inv->status === 'cancelled' ? '#b42318' : '#16181d' }}; }
  .bar { height: 3px; background: #ED8F21; margin: 10px 0 12px; }
  .box td { padding: 8px 10px; border: 1px solid #e7e9ef; vertical-align: top; width: 50%; }
  .lbl { font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #8a90a0; }
  .items { margin-top: 14px; }
  .items th { background: #16181d; color: #fff; padding: 7px 8px; text-align: left; font-size: 10px; }
  .items td { padding: 7px 8px; border-bottom: 1px solid #eceef2; }
  .r { text-align: right; }
  .tot td { padding: 5px 8px; }
  .tot .grand td { font-size: 13px; font-weight: bold; background: #fdf1e3; border-top: 1.5px solid #ED8F21; }
  .words { margin-top: 6px; font-style: italic; color: #4b5060; }
  .pay td { vertical-align: top; padding: 10px; border: 1px solid #e7e9ef; }
  .qr { width: 118px; height: 118px; }
  .paid { color: #11803b; font-weight: bold; }
  .foot { margin-top: 16px; font-size: 9px; color: #8a90a0; text-align: center; }
  .terms { white-space: pre-line; font-size: 9.5px; color: #4b5060; }
</style>
</head>
<body>
  <table class="head">
    <tr>
      <td style="width:64px">@if ($logo)<img class="logo" src="{{ $logo }}">@endif</td>
      <td>
        <div class="co">{{ $c['legal_name'] }}</div>
        <div class="muted">{{ $c['address'] }}@if ($c['address']), @endif{{ $c['city'] }}, {{ $c['state'] }} {{ $c['pincode'] }}</div>
        <div class="muted">@if ($c['gstin'])GSTIN: <b>{{ $c['gstin'] }}</b> · @endif{{ $c['email'] }}@if ($c['phone']) · {{ $c['phone'] }}@endif</div>
      </td>
      <td class="title">
        <b>{{ $title }}</b>
        <div>No: <b>{{ $inv->invoice_no ?? '—' }}</b></div>
        <div class="muted">Date: {{ $inv->issue_date->format('d M Y') }}</div>
        <div class="muted">Due: {{ $inv->due_date->format('d M Y') }}</div>
      </td>
    </tr>
  </table>
  <div class="bar"></div>

  <table class="box">
    <tr>
      <td>
        <div class="lbl">Bill to</div>
        <b>{{ $client->company_name }}</b><br>
        @if ($client->contact_name){{ $client->contact_name }}<br>@endif
        @if ($client->address){{ $client->address }}<br>@endif
        {{ collect([$client->city, $client->state, $client->pincode])->filter()->implode(', ') }}<br>
        @if ($client->gstin)GSTIN: <b>{{ $client->gstin }}</b><br>@endif
        {{ collect([$client->email, $client->phone])->filter()->implode(' · ') }}
      </td>
      <td>
        <div class="lbl">Place of supply</div>
        {{ $inv->place_of_supply }} @if ($inv->gst_type !== 'export' && \App\Services\Billing\Gst::codeFor($inv->place_of_supply)) ({{ \App\Services\Billing\Gst::codeFor($inv->place_of_supply) }}) @endif
        <br><br>
        <div class="lbl">Tax</div>
        @if ($inv->gst_type === 'intra') CGST {{ $half }}% + SGST {{ $half }}%
        @elseif ($inv->gst_type === 'inter') IGST {{ $full }}%
        @else Export of services, zero-rated @if ($c['lut_number']) under LUT {{ $c['lut_number'] }} @endif
        @endif
      </td>
    </tr>
  </table>

  <table class="items">
    <tr><th style="width:24px">#</th><th>Description</th><th style="width:60px">SAC</th><th class="r" style="width:44px">Qty</th><th class="r" style="width:80px">Rate</th><th class="r" style="width:90px">Amount</th></tr>
    @foreach ($inv->items as $i => $it)
      <tr><td>{{ $i + 1 }}</td><td>{{ $it->description }}</td><td>{{ $it->sac }}</td><td class="r">{{ rtrim(rtrim(number_format($it->qty, 2), '0'), '.') }}</td><td class="r">{{ $n($it->rate) }}</td><td class="r">{{ $n($it->amount) }}</td></tr>
    @endforeach
  </table>

  <table class="tot" style="width:46%; margin-left:54%; margin-top:6px">
    <tr><td>Taxable value</td><td class="r">Rs. {{ $n($inv->subtotal) }}</td></tr>
    @if ($inv->gst_type === 'intra')
      <tr><td>CGST @ {{ $half }}%</td><td class="r">Rs. {{ $n($inv->cgst) }}</td></tr>
      <tr><td>SGST @ {{ $half }}%</td><td class="r">Rs. {{ $n($inv->sgst) }}</td></tr>
    @elseif ($inv->gst_type === 'inter')
      <tr><td>IGST @ {{ $full }}%</td><td class="r">Rs. {{ $n($inv->igst) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="r">Rs. {{ $n($inv->total) }}</td></tr>
    @if ($inv->amount_paid > 0)
      <tr><td class="paid">Paid</td><td class="r paid">Rs. {{ $n($inv->amount_paid) }}</td></tr>
      <tr><td><b>Balance due</b></td><td class="r"><b>Rs. {{ $n($inv->balance()) }}</b></td></tr>
    @endif
  </table>
  @if ($words)<div class="words">{{ $words }}</div>@endif

  @if ($inv->status !== 'cancelled' && $inv->balance() > 0)
  <table class="pay" style="margin-top:16px">
    <tr>
      @if ($qr)
      <td style="width:140px; text-align:center">
        <img class="qr" src="{{ $qr }}"><br>
        <b>Scan to pay Rs. {{ $n($inv->balance()) }}</b><br><span class="muted">GPay · PhonePe · Paytm · any UPI app</span>
      </td>
      @endif
      <td>
        <div class="lbl">Pay by UPI</div>
        @if ($c['upi_id'])UPI ID: <b>{{ $c['upi_id'] }}</b><br><br>@endif
        @if ($c['bank_account_number'])
          <div class="lbl">Or bank transfer</div>
          {{ $c['bank_account_name'] ?: $c['legal_name'] }}<br>
          A/c No: <b>{{ $c['bank_account_number'] }}</b> · IFSC: <b>{{ $c['bank_ifsc'] }}</b><br>
          {{ $c['bank_name'] }}@if ($c['bank_branch']), {{ $c['bank_branch'] }}@endif
          @if ($c['swift'])<br>SWIFT: {{ $c['swift'] }}@endif
        @endif
        <br><br><span class="muted">Please mention <b>{{ $inv->invoice_no }}</b> with your payment.</span>
      </td>
    </tr>
  </table>
  @endif

  @if ($inv->notes)<p><b>Notes:</b> {{ $inv->notes }}</p>@endif
  @if ($c['terms'])<p class="terms"><b>Terms</b><br>{{ $c['terms'] }}</p>@endif

  <div class="foot">This is a computer-generated invoice. · {{ $c['legal_name'] }}@if ($c['pan']) · PAN {{ $c['pan'] }}@endif</div>
</body>
</html>
