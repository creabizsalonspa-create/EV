import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'vendor_company.dart' show VendorDocumentPreview;

const operationalLabels = {
  'mapped_evses': ['Mapped EVSEs', 'وحدات EVSE المرتبطة'],
  'source_started': ['Source starts', 'بدايات المصدر'],
  'source_completed': ['Completed source sessions', 'جلسات المصدر المكتملة'],
  'pending_source': [
    'Awaiting source start at snapshot',
    'بانتظار بداية المصدر وقت اللقطة',
  ],
  'completed_energy_wh': [
    'Completed energy (Wh)',
    'الطاقة المكتملة (واط ساعة)',
  ],
  'observed_busy_seconds': [
    'Observed EVSE busy seconds',
    'ثواني انشغال EVSE المرصودة',
  ],
  'possible_evse_seconds': [
    'Current EVSE window seconds',
    'ثواني الفترة لوحدات EVSE الحالية',
  ],
  'status_known_seconds': [
    'Fresh status evidence seconds',
    'ثواني دليل الحالة الحديث',
  ],
  'status_unknown_seconds': [
    'Unknown connector seconds',
    'ثواني الموصل المجهولة',
  ],
  'reported_fault_seconds': [
    'Reported fault seconds',
    'ثواني الأعطال المبلغ عنها',
  ],
  'maximum_observed_site_interval_average_w': [
    'Maximum sum of observed interval averages (W), not grid peak',
    'أقصى مجموع لمتوسطات الفترات المرصودة (واط)، وليس ذروة الشبكة',
  ],
  'demand_observed_seconds': [
    'Seconds with usable demand intervals',
    'ثوانٍ مع فترات طلب قابلة للاستخدام',
  ],
  'demand_ambiguous_evse_seconds': [
    'Excluded overlapping EVSE seconds',
    'ثواني تداخل EVSE المستبعدة',
  ],
  'maximum_session_interval_average_w': [
    'Maximum observed session interval average (W)',
    'أقصى متوسط فترة جلسة مرصودة (واط)',
  ],
  'excluded_sessions': ['Sessions requiring review', 'جلسات تحتاج مراجعة'],
};
const operationalNotice = [
  'Simulator source observations. Missing telemetry is unknown, not uptime or physical availability. Energy covers sessions ending in the period; busy time covers observed intervals across current EVSE inventory. Power is a session interval average, not simultaneous site demand. Invoice references are not cash or settlement.',
  'ملاحظات محاكي المصدر. غياب القياسات مجهول وليس وقت تشغيل أو توفر فعلياً. الطاقة للجلسات المنتهية في الفترة ووقت الانشغال للفترة المرصودة ضمن مخزون EVSE الحالي. القدرة متوسط فترة جلسة وليست طلب الموقع المتزامن. الفواتير ليست مقبوضات أو تسويات.',
];

class OperationalAnalyticsView extends StatefulWidget {
  final EvApi api;
  final int companyId;
  final VoidCallback onExpired;
  const OperationalAnalyticsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.onExpired,
  });
  @override
  State<OperationalAnalyticsView> createState() => _OperationalState();
}

class _OperationalState extends State<OperationalAnalyticsView>
    with WidgetsBindingObserver {
  final from = TextEditingController(),
      to = TextEditingController(),
      site = TextEditingController();
  Map<String, dynamic>? report;
  Map<String, String>? applied;
  Object? error;
  bool busy = false, hidden = false;
  int generation = 0;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/operational-analytics';
  bool get ar => Localizations.localeOf(context).languageCode == 'ar';
  String tr(String en, String arabic) => ar ? arabic : en;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final now = DateTime.now().toUtc();
    from.text = now
        .subtract(const Duration(days: 6))
        .toIso8601String()
        .substring(0, 10);
    to.text = now.toIso8601String().substring(0, 10);
    load();
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    from.dispose();
    to.dispose();
    site.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant OperationalAnalyticsView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api || old.companyId != widget.companyId) {
      generation++;
      report = null;
      applied = null;
      site.clear();
      load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      generation++;
      setState(() {
        report = null;
        applied = null;
        busy = false;
        hidden = true;
        error = null;
      });
    }
  }

  void failed(Object e) {
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  bool exact(dynamic v) => v is int && v >= 0 && v <= 9007199254740991;
  Map<String, dynamic> parse(dynamic result) {
    if (result is! Map ||
        result['company_id'] != widget.companyId ||
        result['evidence'] != 'SIMULATED_SOURCE' ||
        result['physical_uptime_verified'] != false ||
        result['physical_site_demand_verified'] != false ||
        result['billing_authority'] != false ||
        result['financial_visible'] is! bool ||
        result['sites'] is! List ||
        result['invoice_facts'] is! List ||
        result['sites'].length > 500)
      throw const FormatException('Unexpected report authority');
    for (final row in result['sites']) {
      if (row is! Map || row['name'] is! String || !exact(row['site_id']))
        throw const FormatException('Invalid site');
      for (final key in operationalLabels.keys) {
        if (key == 'maximum_observed_site_interval_average_w' &&
            row.containsKey(key) &&
            row[key] == null) {
          continue;
        }
        if (!exact(row[key]))
          throw const FormatException('Inexact source metric');
      }
    }
    for (final row in result['invoice_facts']) {
      if (result['financial_visible'] != true ||
          row is! Map ||
          row['currency'] is! String ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(row['currency']) ||
          !exact(row['minor_units']) ||
          row['minor_units'] > 3 ||
          !exact(row['invoiced_minor']) ||
          !exact(row['vendor_allocated_minor']) ||
          row['evidence'] != 'SIMULATED')
        throw const FormatException('Invalid invoice fact');
    }
    if (result['feedback_groups'] != null) {
      if (result['feedback_groups'] is! List ||
          result['feedback_physical_charging_verified'] != false ||
          result['feedback_scope'] !=
              'currently_published_revisions_created_in_window') {
        throw const FormatException('Unexpected feedback authority');
      }
      for (final row in result['feedback_groups']) {
        if (row is! Map ||
            !exact(row['site_id']) ||
            !result['sites'].any((s) => s['site_id'] == row['site_id']) ||
            ![
              'SIMULATED_SOURCE_OBSERVED',
              'SIMULATED_APP_ONLY',
            ].contains(row['evidence']) ||
            !exact(row['ratings']) ||
            row['ratings'] < 1 ||
            !exact(row['rating_sum']) ||
            row['rating_sum'] < row['ratings'] ||
            row['rating_sum'] > row['ratings'] * 5) {
          throw const FormatException('Invalid feedback aggregate');
        }
      }
    }
    return Map<String, dynamic>.from(result);
  }

  Future<void> load() async {
    final query = {
      'from': from.text.trim(),
      'to': to.text.trim(),
      if (site.text.trim().isNotEmpty) 'site_id': site.text.trim(),
    };
    final epoch = ++generation;
    setState(() {
      busy = true;
      report = null;
      applied = null;
      error = null;
      hidden = false;
    });
    try {
      final value = parse(await widget.api.request(path, query: query));
      if (mounted && epoch == generation)
        setState(() {
          report = value;
          applied = query;
        });
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> pdf() async {
    if (busy || applied == null) return;
    final epoch = generation;
    final filters = {...applied!, 'locale': ar ? 'ar' : 'en'};
    setState(() => busy = true);
    try {
      final doc = await widget.api.downloadOperationalAnalytics(
        '$path/pdf',
        filters,
      );
      if (doc.mime != 'application/pdf' ||
          doc.bytes.length < 5 ||
          String.fromCharCodes(doc.bytes.take(5)) != '%PDF-')
        throw const FormatException('Invalid report PDF');
      if (!mounted || epoch != generation) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => VendorDocumentPreview(
            document: doc,
            title: tr('Charging operational analytics', 'تحليلات تشغيل الشحن'),
            notice: operationalNotice[ar ? 1 : 0],
          ),
        ),
      );
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(tr('Charging operational analytics', 'تحليلات تشغيل الشحن')),
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(operationalNotice[ar ? 1 : 0]),
        Text(
          tr(
            'Report and PDF use applied UTC filters, at most 31 days.',
            'يستخدم التقرير وPDF مرشحات UTC المطبقة لمدة ٣١ يوماً كحد أقصى.',
          ),
        ),
        for (final pair in [
          (from, tr('From (UTC)', 'من (UTC)')),
          (to, tr('To (UTC)', 'إلى (UTC)')),
          (site, tr('Site number (optional)', 'رقم الموقع (اختياري)')),
        ])
          TextField(
            controller: pair.$1,
            enabled: !busy,
            decoration: InputDecoration(labelText: pair.$2),
          ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
        if (hidden)
          Text(
            tr(
              'Private report cleared while inactive. Refresh to reload.',
              'مُسح التقرير الخاص عند مغادرة التطبيق. حدّث لإعادة تحميله.',
            ),
          ),
        TextButton(
          onPressed: busy ? null : load,
          child: Text(tr('Apply and refresh', 'تطبيق وتحديث')),
        ),
        if (report != null) ...[
          Text('${report!['from']} — ${report!['to']} UTC'),
          Text('${report!['as_of']}'),
          Text(tr(
            'Demand sums floored accepted meter interval averages wholly inside the window. Overlapping sessions on the same EVSE are excluded. Coverage is partial; this is not grid peak or instantaneous power.',
            'الطلب يجمع متوسطات فترات العدادات المقبولة داخل الفترة، بعد التقريب لأسفل. تُستبعد فترات تداخل الجلسات على EVSE نفسها. التغطية جزئية ولا تمثل ذروة الشبكة أو قياساً لحظياً.',
          )),
          TextButton(
            onPressed: busy ? null : pdf,
            child: Text(tr('View private report PDF', 'عرض التقرير الخاص PDF')),
          ),
          for (final row in report!['sites'])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${row['name']} · #${row['site_id']}'),
                    for (final label in operationalLabels.entries)
                      Text(
                        '${label.value[ar ? 1 : 0]}: ${row[label.key] ?? tr('Unknown', 'غير معروف')}',
                      ),
                  ],
                ),
              ),
            ),
          if (report!['feedback_groups'] != null) ...[
            Text(tr('Moderated charging feedback', 'ملاحظات الشحن المعتمدة')),
            Text(
              tr(
                'Currently published feedback only, by creation date of its current revision within the UTC window. Physical charging is unverified. No identities or private comments are included.',
                'الملاحظات المنشورة حالياً فقط، حسب تاريخ إنشاء النسخة الحالية ضمن الفترة بالتوقيت العالمي. لا يُستنتج تحقق من الشحن الفعلي. لا تتضمن هوية أو نصوصاً خاصة.',
              ),
            ),
            if ((report!['feedback_groups'] as List).isEmpty)
              Text(
                tr(
                  'No approved feedback in report window',
                  'لا توجد ملاحظات معتمدة ضمن فترة التقرير',
                ),
              ),
            for (final group in report!['feedback_groups']) ...[
              Text(
                group['evidence'] == 'SIMULATED_SOURCE_OBSERVED'
                    ? tr(
                        'Observed simulator feedback',
                        'ملاحظات محاكاة مرصودة من المصدر',
                      )
                    : tr(
                        'App-only simulator feedback',
                        'ملاحظات محاكاة التطبيق فقط',
                      ),
              ),
              Text(
                '${tr('Ratings', 'التقييمات')}: ${group['ratings']} · ${tr('Rating sum', 'مجموع النقاط')}: ${group['rating_sum']} · ${tr('Site', 'الموقع')}: ${group['site_id']}',
              ),
            ],
          ],
          if (report!['financial_visible'] == true)
            Text(
              tr(
                'Invoice references, not cash or settlements',
                'فواتير مرجعية وليست مقبوضات أو تسويات',
              ),
            ),
          for (final row in report!['invoice_facts'])
            Text(
              "${tr('Invoiced', 'المفوتر')}: ${row['currency']} ${minorDecimal(row['invoiced_minor'] as int, row['minor_units'] as int)} · ${row['evidence']}\n${tr('Vendor allocated', 'تخصيص المورد')}: ${row['currency']} ${minorDecimal(row['vendor_allocated_minor'] as int, row['minor_units'] as int)}",
            ),
        ],
      ],
    ),
  );
}
