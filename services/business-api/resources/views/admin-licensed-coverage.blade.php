<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $locale === 'ar' ? 'تغطية الاكتشاف المرخص' : 'Licensed discovery coverage' }}</title>
<style>
body{font:16px/1.55 system-ui,sans-serif;max-width:76rem;margin:2rem auto;padding:0 1rem;background:#f4f6f8;color:#18232d}
main{background:#fff;border:1px solid #cbd4dc;border-radius:12px;padding:1.5rem}a{color:#075985}
.table-scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}th,td{border:1px solid #cbd4dc;padding:.65rem;text-align:start}th{background:#e8eef4}
dt{font-weight:700}dd{margin-block-end:.7rem}caption{text-align:start;font-weight:700;margin-block:.7rem}
</style>
</head>
<body>
@php
    $tr = fn ($en, $ar) => $locale === 'ar' ? $ar : $en;
    $columns = [
        'configured_areas' => ['Configured areas', 'المناطق المحددة'],
        'observed_areas' => ['Observed areas', 'المناطق المرصودة'],
        'areas_with_reports' => ['Areas with reports', 'مناطق بها تقارير'],
        'areas_empty' => ['Observed empty areas', 'مناطق مرصودة بلا تقارير'],
        'unobserved_areas' => ['Unobserved areas', 'المناطق غير المرصودة'],
        'unverified_areas' => ['Unverified areas', 'المناطق غير المتحقق منها'],
        'returned_record_observations' => ['Record observations', 'السجلات المرصودة'],
        'unknown_time_observations' => ['Unknown or future source times', 'أوقات مصدر مجهولة أو مستقبلية'],
        'older_than_24h_observations' => ['Source age over 24h', 'عمر المصدر أكثر من ٢٤ ساعة'],
        'unknown_operating_observations' => ['Unknown operating report', 'حالة تشغيل غير معروفة'],
    ];
@endphp
<nav><a href="/">{{ $tr('Back to platform', 'العودة إلى المنصة') }}</a> · <a href="/admin/licensed-coverage">{{ $tr('Refresh report', 'تحديث التقرير') }}</a></nav>
<main>
<h1>{{ $tr('Licensed discovery coverage', 'تغطية الاكتشاف المرخص') }}</h1>
<p>Open Charge Map · {{ $tr('Approved public benchmark cache samples only; not a geographic coverage percentage or live availability verification.', 'عينات ذاكرة التخزين المؤقت لمناطق مرجعية عامة معتمدة فقط؛ لا تمثل نسبة تغطية جغرافية أو تحققاً من التوافر المباشر.') }}</p>
<p>{{ $tr('No provider request or sample capture is initiated by this page.', 'لا تبدأ هذه الصفحة طلباً للمزود أو تسجيل عينة.') }}</p>
@if (!$report['enabled'])
<p role="status">{{ $report['reason'] === 'invalid_benchmarks' ? $tr('Benchmark configuration is invalid.', 'إعداد المناطق المرجعية غير صالح.') : $tr('Monitoring is not configured or approved.', 'المراقبة غير معدة أو غير معتمدة.') }}</p>
@else
<dl>
<dt>{{ $tr('Window (UTC hours)', 'الفترة (ساعات بالتوقيت العالمي)') }}</dt><dd>{{ $report['window_hours'] }}</dd>
<dt>{{ $tr('Configured areas', 'المناطق المحددة') }}</dt><dd>{{ $report['configured_areas'] }}</dd>
<dt>{{ $tr('Observed hours', 'الساعات المرصودة') }}</dt><dd data-testid="observed-hours">{{ $report['observed_hours'] }}</dd>
<dt>{{ $tr('Unverified hours', 'الساعات غير المتحقق منها') }}</dt><dd data-testid="unverified-hours">{{ $report['unverified_hours'] }}</dd>
<dt>{{ $tr('Missing hours', 'الساعات المفقودة') }}</dt><dd data-testid="missing-hours">{{ $report['window_hours'] - $report['observed_hours'] - $report['unverified_hours'] }}</dd>
</dl>
<p>{{ $tr('Missing observations do not mean zero coverage. Counts describe samples, not unique stations.', 'غياب الرصد لا يعني انعدام التغطية. الأعداد تصف العينات لا المحطات الفريدة.') }}</p>
@if (count($report['samples']) === 0)
<p role="status">{{ $tr('No validated hourly observations.', 'لا توجد مشاهدات ساعية متحقق منها.') }}</p>
@else
<div class="table-scroll" tabindex="0">
<table>
<caption>{{ $tr('Validated hourly observations', 'المشاهدات الساعية المتحقق منها') }}</caption>
<thead><tr><th scope="col">{{ $tr('Hour (UTC)', 'الساعة (التوقيت العالمي)') }}</th>@foreach ($columns as $labels)<th scope="col">{{ $tr($labels[0], $labels[1]) }}</th>@endforeach</tr></thead>
<tbody>@foreach (array_reverse($report['samples']) as $sample)<tr><th scope="row"><bdi>{{ $sample['hour_utc'] }}</bdi></th>@foreach ($columns as $key => $labels)<td>{{ $sample[$key] }}</td>@endforeach</tr>@endforeach</tbody>
</table>
</div>
@endif
@endif
</main>
</body>
</html>
