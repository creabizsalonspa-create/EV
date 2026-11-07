<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}"><body style="font-family:dejavusans;color:#152d3b;font-size:10pt;">
<h1 style="color:#0d6659;font-size:23pt;">{{ $ar ? 'كريا بيز — مستند مالي تجريبي' : 'Crea-Biz — Test financial document' }}</h1>
<p style="color:#8c3429;font-weight:bold;">{{ $ar ? 'مستند تجريبي — ليس فاتورة ضريبية معتمدة قانونياً' : 'TEST DOCUMENT — NOT A LEGALLY APPROVED TAX INVOICE' }}</p>
<table cellpadding="5" cellspacing="0" border="0">
<tr><td width="35%">{{ $ar ? 'رقم المستند' : 'Document number' }}</td><td>{{ $d['number'] }}</td></tr>
<tr><td>{{ $ar ? 'المرجع الأصلي' : 'Original reference' }}</td><td>{{ $d['source']['original_number'] }}</td></tr>
<tr><td>{{ $ar ? 'مرجع الفاتورة' : 'Invoice reference' }}</td><td>{{ $d['source']['invoice_reference'] }}</td></tr>
<tr><td>{{ $ar ? 'تاريخ المصدر — UTC' : 'Source issued at — UTC' }}</td><td>{{ $d['source']['issued_at'] }}</td></tr>
<tr><td>{{ $ar ? 'دليل المصدر' : 'Source evidence' }}</td><td>{{ $d['source']['evidence'] }}</td></tr>
@if(isset($d['source']['period']))
<tr><td>{{ $ar ? 'فترة الفوترة' : 'Billing period' }}</td><td>{{ $d['source']['period'] }}</td></tr>
<tr><td>{{ $ar ? 'تاريخ الاستحقاق — UTC' : 'Due at — UTC' }}</td><td>{{ $d['source']['due_at'] }}</td></tr>
@endif
</table>
<h2>{{ $ar ? 'الجهة المصدرة والمستلم' : 'Issuer and recipient' }}</h2>
<p><b>{{ $d['issuer'][$ar ? 'legal_name_ar' : 'legal_name'] }}</b><br>{{ $d['issuer'][$ar ? 'address_ar' : 'address'] }}<br>{{ $d['issuer']['country'] }}</p>
@if($d['issuer']['tax_registration'])<p>{{ $ar ? 'رقم التسجيل الضريبي المصرح به إدارياً' : 'Administrator-disclosed tax registration' }}: {{ $d['issuer']['tax_registration'] }}</p>@endif
<p>{{ $ar ? 'المستلم' : 'Recipient' }}: {{ $d['source']['recipient']['name'] }}<br>{{ $d['source']['recipient']['email'] }}</p>
<h2>{{ $d['source'][$ar ? 'description_ar' : 'description_en'] }}</h2>
@if(isset($d['source']['status_notice_en']))<p>{{ $d['source'][$ar ? 'status_notice_ar' : 'status_notice_en'] }}</p>@endif
@if(isset($d['source']['lines']))
<table cellpadding="6" cellspacing="0" border="1">
<tr style="background-color:#e8f4f1;"><th width="65%">{{ $ar ? 'مرجع المصدر — المبلغ المخصص' : 'Source reference — allocated amount' }}</th><th width="35%">{{ $d['source']['currency'] }}</th></tr>
@foreach($d['source']['lines'] as $line)
<tr><td>{{ $line['reference'] }}@if($line['reason'])<br>{{ $line['reason'] }}@endif</td><td>{{ \App\Services\Commercial\Documents\DocumentRenderer::signedMoney($line['amount_minor'], $d['source']['minor_units']) }}</td></tr>
@endforeach
</table>
@endif
<table cellpadding="8" cellspacing="0" border="1">
<tr style="background-color:#e8f4f1;"><th width="65%">{{ $ar ? 'الوصف' : 'Description' }}</th><th width="35%">{{ $d['source']['currency'] }}</th></tr>
@if($d['source']['source_kind'] !== 'vendor_settlement')
<tr><td>{{ $ar ? 'المجموع الفرعي' : 'Subtotal' }}</td><td>{{ \App\Services\Commercial\Documents\DocumentRenderer::money($d['source']['subtotal_minor'], $d['source']['minor_units']) }}</td></tr>
<tr><td>{{ $ar ? 'الضريبة حسب سجل المصدر' : 'Tax from source record' }}</td><td>{{ \App\Services\Commercial\Documents\DocumentRenderer::money($d['source']['tax_minor'], $d['source']['minor_units']) }}</td></tr>
@endif
<tr style="background-color:#e8f4f1;"><td><b>{{ $ar ? 'الإجمالي' : 'Total' }}</b></td><td><b>{{ \App\Services\Commercial\Documents\DocumentRenderer::money($d['source']['total_minor'], $d['source']['minor_units']) }}</b></td></tr>
</table>
@if($d['source']['reason'])<p>{{ $ar ? 'السبب' : 'Reason' }}: {{ $d['source']['reason'] }}</p>@endif
<p style="font-size:8pt;color:#52636d;">{{ $ar ? 'هذا المستند ينسخ مبالغ سجل المصدر دون تعديل الدفتر المالي. بيانات الجهة المصدرة والتسجيل الضريبي إفصاحات إدارية لم تتحقق قانونياً. لا يثبت هذا المستند دفعاً فعلياً أو قبولاً من جهة ضريبية.' : 'This document copies source amounts without changing the ledger. Issuer and tax registration are unverified administrative disclosures. This document does not prove real payment or acceptance by a tax authority.' }}</p>
<p style="font-size:7pt;color:#52636d;">{{ $ar ? 'نسخة بيانات الجهة المصدرة' : 'Issuer version' }}: {{ $d['issuer']['version'] }} · {{ $ar ? 'تم التقاط البيانات في — UTC' : 'Snapshot captured at — UTC' }}</p>
<p dir="ltr" style="font-size:7pt;color:#52636d;">{{ \Carbon\CarbonImmutable::parse($d['captured_at'])->utc()->format('Y/m/d H:i:s') }} · {{ $d['template_version'] }}</p>
</body></html>
