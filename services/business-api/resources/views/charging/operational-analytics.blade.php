<h1>{{ $ar ? 'تحليلات تشغيل الشحن' : 'Charging operational analytics' }}</h1>
<p>{{ $ar ? 'ملاحظات محاكي المصدر. غياب القياسات مجهول وليس وقت تشغيل أو توفر فعلياً. الطاقة للجلسات المنتهية في الفترة، ووقت الانشغال للفترة المرصودة فقط ضمن مخزون وحدات EVSE الحالي. لا يمثل هذا قبول الأجهزة أو دفعاً أو تسوية.' : 'Simulator source observations. Missing telemetry is unknown, not uptime or physical availability. Energy covers sessions ending in the period; busy time covers observed intervals across current EVSE inventory. This does not establish hardware acceptance, payment or settlement.' }}</p>
<p>{{ $report['from'] }} — {{ $report['to'] }} UTC · {{ $report['as_of'] }}</p>
@foreach($report['sites'] as $site)
<h2>{{ $site['name'] }}</h2>
<table cellpadding="4" border="1">
@foreach(['mapped_evses'=>['Mapped EVSEs','وحدات EVSE المرتبطة'],'source_started'=>['Source starts','بدايات المصدر'],'source_completed'=>['Completed source sessions','جلسات المصدر المكتملة'],'completed_energy_wh'=>['Completed energy (Wh)','الطاقة المكتملة (واط ساعة)'],'observed_busy_seconds'=>['Observed EVSE busy seconds','ثواني انشغال EVSE المرصودة'],'possible_evse_seconds'=>['Current EVSE window seconds','ثواني الفترة لوحدات EVSE الحالية'],'status_known_seconds'=>['Fresh status evidence seconds','ثواني دليل الحالة الحديث'],'status_unknown_seconds'=>['Unknown connector seconds','ثواني الموصل المجهولة'],'reported_fault_seconds'=>['Reported fault seconds','ثواني الأعطال المبلغ عنها'],'maximum_session_interval_average_w'=>['Maximum observed session interval average (W)','أقصى متوسط فترة جلسة مرصودة (واط)'],'excluded_sessions'=>['Sessions requiring review','جلسات تحتاج مراجعة']] as $key=>$title)
<tr><td>{{ $title[$ar?1:0] }}</td><td>{{ $site[$key] }}</td></tr>
@endforeach
</table>
@endforeach
@if($report['financial_visible'])
<h2>{{ $ar ? 'فواتير مرجعية وليست مقبوضات أو تسويات' : 'Invoice references, not cash or settlements' }}</h2>
@foreach($report['invoice_facts'] as $fact)
<p>{{ $fact['currency'] }} · {{ $fact['evidence'] }} · {{ $ar ? 'المفوتر' : 'Invoiced' }}: {{ \App\Services\Commercial\Analytics\AnalyticsPresentation::amount($fact['invoiced_minor'], $fact['minor_units']) }} · {{ $ar ? 'تخصيص المورد' : 'Vendor allocated' }}: {{ \App\Services\Commercial\Analytics\AnalyticsPresentation::amount($fact['vendor_allocated_minor'], $fact['minor_units']) }}</p>
@endforeach
@endif
