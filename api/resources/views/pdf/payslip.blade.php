{{-- Payslip PDF (rendered by dompdf: keep CSS simple, tables for layout). --}}
@php
    // Indian grouping (1,23,456.00) and amount in words with paise.
    $intl = class_exists(\NumberFormatter::class);
    $fmt = $intl ? new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL) : null;
    $fmt?->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
    $fmt?->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
    $inr = fn ($n) => 'Rs. '.($fmt ? $fmt->format($n) : number_format($n, 2));
    $rows = max(count($p->earnings), count($p->deductions));
    $earn = array_map(null, array_keys($p->earnings), array_values($p->earnings));
    $ded = array_map(null, array_keys($p->deductions), array_values($p->deductions));
    $words = null;
    if ($intl) {
        $spell = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
        $rupees = (int) floor($p->net);
        $paise = (int) round(($p->net - $rupees) * 100);
        $words = 'Rupees '.ucwords($spell->format($rupees)).($paise ? ' and '.ucwords($spell->format($paise)).' Paise' : '').' only';
    }
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 32px 36px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #16181d; }
  .head { width: 100%; border-bottom: 3px solid #ED8F21; padding-bottom: 10px; }
  .head td { vertical-align: middle; }
  .logo { width: 54px; height: 54px; }
  .co { font-size: 17px; font-weight: bold; letter-spacing: .5px; }
  .muted { color: #6b7180; }
  .title { text-align: right; }
  .title b { font-size: 15px; display: block; }
  .info { width: 100%; margin-top: 14px; border-collapse: collapse; }
  .info td { padding: 5px 8px; border: 1px solid #e7e9ef; }
  .info td.k { background: #f6f7f9; color: #4b5060; width: 22%; }
  table.pay { width: 100%; margin-top: 16px; border-collapse: collapse; }
  .pay th { background: #16181d; color: #fff; text-align: left; padding: 7px 8px; font-size: 11px; }
  .pay td { padding: 6px 8px; border-bottom: 1px solid #eceef2; }
  .pay td.n { text-align: right; }
  .pay tr.tot td { font-weight: bold; background: #fdf1e3; border-top: 1px solid #ED8F21; }
  .net { margin-top: 16px; width: 100%; border-collapse: collapse; }
  .net td { padding: 12px; background: #16181d; color: #fff; font-size: 13px; }
  .net td.amt { text-align: right; font-size: 17px; font-weight: bold; color: #ED8F21; }
  .words { margin-top: 6px; color: #4b5060; }
  .foot { margin-top: 28px; color: #8a90a0; font-size: 9.5px; text-align: center; }
</style>
</head>
<body>
  <table class="head">
    <tr>
      <td style="width:64px">@if ($logo)<img class="logo" src="{{ $logo }}">@endif</td>
      <td><div class="co">{{ $company['name'] }}</div><div class="muted">{{ $company['address'] }} · {{ $company['email'] }}</div></td>
      <td class="title"><b>PAYSLIP</b><span class="muted">{{ $p->periodLabel() }}</span></td>
    </tr>
  </table>

  <table class="info">
    <tr><td class="k">Employee name</td><td>{{ $e->name }}</td><td class="k">Employee ID</td><td>{{ $e->emp_code }}</td></tr>
    <tr><td class="k">Designation</td><td>{{ $e->designation ?: '—' }}</td><td class="k">Department</td><td>{{ $e->department ?: '—' }}</td></tr>
    <tr><td class="k">Date of joining</td><td>{{ $e->doj?->format('d M Y') ?: '—' }}</td><td class="k">Bank account</td><td>{{ $accountLast4 ? 'XXXX'.$accountLast4 : '—' }}</td></tr>
    <tr><td class="k">Days in month</td><td>{{ $p->days_in_month }}</td><td class="k">Paid days</td><td>{{ rtrim(rtrim(number_format($p->days_in_month - $p->lop_days, 1), '0'), '.') }} @if ($p->lop_days > 0)<span class="muted">(loss of pay: {{ rtrim(rtrim(number_format($p->lop_days, 1), '0'), '.') }})</span>@endif</td></tr>
  </table>

  <table class="pay">
    <tr><th>Earnings</th><th style="text-align:right">Amount</th><th>Deductions</th><th style="text-align:right">Amount</th></tr>
    @for ($i = 0; $i < $rows; $i++)
      <tr>
        <td>{{ $earn[$i][0] ?? '' }}</td><td class="n">{{ isset($earn[$i]) ? $inr($earn[$i][1]) : '' }}</td>
        <td>{{ $ded[$i][0] ?? '' }}</td><td class="n">{{ isset($ded[$i]) ? $inr($ded[$i][1]) : '' }}</td>
      </tr>
    @endfor
    <tr class="tot"><td>Gross earnings</td><td class="n">{{ $inr($p->gross) }}</td><td>Total deductions</td><td class="n">{{ $inr($p->total_deductions) }}</td></tr>
  </table>

  <table class="net"><tr><td>Net pay</td><td class="amt">{{ $inr($p->net) }}</td></tr></table>
  @if ($words)<div class="words">{{ $words }}</div>@endif

  <div class="foot">This is a computer-generated payslip and does not need a signature.</div>
</body>
</html>
