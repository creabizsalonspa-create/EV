import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';
import 'strings.dart';
import 'vendor_company.dart' show VendorDocumentPreview;

const analyticsEn = {
  'title': 'Financial insights',
  'notice':
      'Your authorized source records. Categories describe separate facts; do not add them into one revenue total. TEST payments are not verified real money. Company statements reference existing charging invoices.',
  'applied':
      'Report and PDF use the last applied filters. Apply edits to refresh.',
  'allCurrencies': 'All currencies, kept separate',
  'from': 'From (UTC)',
  'to': 'To (UTC)',
  'currency': 'Currency (optional)',
  'refresh': 'Apply and refresh',
  'pdf': 'View private management PDF',
  'empty': 'No source records in this period.',
  'current': 'Selected period',
  'previous': 'Previous equal period',
  'asOf': 'Source snapshot',
  'points': 'Noncash points',
  'pointsNotice':
      'Promotional points have no cash value and are never added to money.',
  'cycles': 'Own paid subscription cycle equivalents',
  'cycleNotice':
      'Current paid, non-cancelling cycle costs net of posted credits. Not recognized revenue, guaranteed collection or historical recurring revenue.',
  'monthly': 'Monthly equivalent',
  'annual': 'Annual equivalent',
  'records': 'Source records',
  'pdfNotice':
      'Management report, not a legal tax invoice. Generated from current source using the displayed report filters. Private files close on leaving the foreground.',
  'invalid':
      'Select valid UTC dates (1–366 days, ending today or earlier) and an optional three-letter currency.',
  'hidden':
      'Private report cleared while the app was inactive. Refresh to reload.',
  'earn': 'Earned',
  'redeem': 'Redeemed',
  'release': 'Released',
  'expiry': 'Expired',
  'reversal': 'Reversed',
};
const analyticsAr = {
  'title': 'الرؤى المالية',
  'notice':
      'سجلات المصدر المصرح بها لحسابك. تمثل الفئات حقائق منفصلة فلا تجمعها كإيراد واحد. مدفوعات الاختبار ليست أموالاً حقيقية متحققاً منها. تشير كشوف الشركات لفواتير شحن موجودة.',
  'applied':
      'يستخدم التقرير وPDF المرشحات المطبقة أخيراً. طبّق التعديلات للتحديث.',
  'allCurrencies': 'جميع العملات مع فصل كل عملة',
  'from': 'من (UTC)',
  'to': 'إلى (UTC)',
  'currency': 'العملة (اختياري)',
  'refresh': 'تطبيق وتحديث',
  'pdf': 'عرض التقرير الإداري الخاص PDF',
  'empty': 'لا توجد سجلات مصدر في الفترة.',
  'current': 'الفترة المختارة',
  'previous': 'الفترة السابقة المساوية',
  'asOf': 'وقت لقطة المصدر',
  'points': 'نقاط غير نقدية',
  'pointsNotice': 'النقاط الترويجية بلا قيمة نقدية ولا تضاف إلى الأموال.',
  'cycles': 'مكافئات دورات اشتراكاتك المدفوعة',
  'cycleNotice':
      'تكلفة الدورات الحالية المدفوعة وغير الملغاة بعد الإشعارات الدائنة. ليست إيراداً معترفاً به أو تحصيلاً مضموناً أو إيراداً دورياً تاريخياً.',
  'monthly': 'المكافئ الشهري',
  'annual': 'المكافئ السنوي',
  'records': 'سجلات المصدر',
  'pdfNotice':
      'تقرير إداري وليس فاتورة ضريبية قانونية. يُنشأ من المصدر الحالي باستخدام مرشحات التقرير المعروضة. تغلق الملفات الخاصة عند مغادرة الواجهة.',
  'invalid':
      'اختر تواريخ UTC صحيحة لفترة ١–٣٦٦ يوماً تنتهي اليوم أو قبله وعملة اختيارية من ثلاثة أحرف.',
  'hidden': 'مُسح التقرير الخاص عند مغادرة التطبيق. حدّث لإعادة تحميله.',
  'earn': 'مكتسب',
  'redeem': 'مستبدل',
  'release': 'محرر',
  'expiry': 'منتهٍ',
  'reversal': 'معكوس',
};
String analyticsText(BuildContext c, String key) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? analyticsAr
        : analyticsEn)[key] ??
    key;

const maxAnalyticsInteger = 9007199254740991;
int _exact(dynamic v, {bool signed = false}) {
  if (v is! int ||
      v > maxAnalyticsInteger ||
      v < (signed ? -maxAnalyticsInteger : 0))
    throw const FormatException('Inexact financial source.');
  return v;
}

String _date(dynamic v) {
  if (v is! String ||
      !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v) ||
      DateTime.tryParse('${v}T00:00:00Z')?.toIso8601String().substring(0, 10) !=
          v)
    throw const FormatException('Invalid UTC period.');
  return v;
}

Map<String, dynamic> _map(dynamic v) => Map<String, dynamic>.from(v as Map);
List<Map<String, dynamic>> _rows(dynamic v) {
  if (v is! List || v.length > 2500)
    throw const FormatException('Invalid report groups.');
  return v.map(_map).toList(growable: false);
}

void _money(Map<String, dynamic> r, Iterable<String> amounts) {
  if (r['currency'] is! String ||
      !RegExp(r'^[A-Z]{3}$').hasMatch(r['currency']) ||
      r['minor_units'] is! int ||
      r['minor_units'] < 0 ||
      r['minor_units'] > 3 ||
      r['evidence'] is! String)
    throw const FormatException('Invalid currency source.');
  for (final k in amounts) {
    _exact(r[k]);
  }
}

class FinancialReport {
  final Map<String, dynamic> source;
  late final List<Map<String, dynamic>> groups, points, cycles;
  FinancialReport(dynamic raw) : source = _map(raw) {
    if (source['real_money_verified'] != false ||
        source['scope'] != 'own_charging_and_own_billing')
      throw const FormatException('Unsupported financial scope.');
    final period = _map(source['period']);
    for (final k in ['from', 'to', 'previous_from', 'previous_to']) {
      _date(period[k]);
    }
    if (period['timezone'] != 'UTC' ||
        source['as_of'] is! String ||
        DateTime.tryParse(source['as_of']) == null)
      throw const FormatException('Invalid source time.');
    groups = _rows(source['groups']);
    for (final r in groups) {
      _money(r, ['amount_minor', 'record_count']);
      if (!['current', 'previous'].contains(r['period']) ||
          r['category'] is! String ||
          r['state'] is! String)
        throw const FormatException('Invalid financial group.');
    }
    final p = _map(source['points']);
    if (p['cash_value'] != false || p['unit'] != 'NON_MONETARY_POINTS')
      throw const FormatException('Unsupported points value.');
    points = _rows(p['groups']);
    for (final r in points) {
      _exact(r['points'], signed: true);
      _exact(r['entries']);
      if (!['current', 'previous'].contains(r['period']) ||
          ![
            'earn',
            'redeem',
            'release',
            'expiry',
            'reversal',
          ].contains(r['kind']))
        throw const FormatException('Invalid points group.');
    }
    cycles = _rows(source['recurring_run_rates']);
    for (final r in cycles) {
      _money(r, [
        'subscriptions',
        'monthly_equivalent_minor',
        'annual_equivalent_minor',
      ]);
    }
    for (final key in ['category_labels', 'state_labels']) {
      final labels = _map(source[key]);
      for (final v in labels.values) {
        if (v is! List || v.length != 2 || v.any((label) => label is! String))
          throw const FormatException('Invalid source labels.');
      }
    }
  }
  String label(String kind, String key, bool ar) {
    final labels = _map(source[kind]);
    return labels[key] == null
        ? key
        : (labels[key] as List)[ar ? 1 : 0] as String;
  }
}

class FinancialAnalyticsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const FinancialAnalyticsView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<FinancialAnalyticsView> createState() => _AnalyticsState();
}

class _AnalyticsState extends State<FinancialAnalyticsView>
    with WidgetsBindingObserver {
  final from = TextEditingController(),
      to = TextEditingController(),
      currency = TextEditingController();
  FinancialReport? report;
  Map<String, String>? loadedFilters;
  Object? error;
  bool busy = false, hidden = false;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final now = DateUtils.dateOnly(DateTime.now().toUtc());
    from.text = now
        .subtract(const Duration(days: 29))
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
    currency.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted)
      setState(() {
        generation++;
        report = null;
        loadedFilters = null;
        error = null;
        busy = false;
        hidden = true;
      });
  }

  Map<String, String> filters() {
    final a = _date(from.text.trim()),
        b = _date(to.text.trim()),
        c = currency.text.trim().toUpperCase();
    final start = DateTime.parse('${a}T00:00:00Z'),
        end = DateTime.parse('${b}T00:00:00Z');
    final days = end.difference(start).inDays + 1;
    final today = DateTime.now().toUtc().toIso8601String().substring(0, 10);
    if (days < 1 ||
        days > 366 ||
        b.compareTo(today) > 0 ||
        (c.isNotEmpty && !RegExp(r'^[A-Z]{3}$').hasMatch(c)))
      throw const FormatException('Invalid filters.');
    return {'from': a, 'to': b, if (c.isNotEmpty) 'currency': c};
  }

  void failed(Object e) {
    error = e;
    report = null;
    loadedFilters = null;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load() async {
    final epoch = ++generation, current = widget.api.sessionFence();
    setState(() {
      busy = true;
      error = null;
      report = null;
      loadedFilters = null;
      hidden = false;
    });
    try {
      final query = filters();
      final data = FinancialReport(
        await widget.api.request('financial/analytics', query: query),
      );
      if (!mounted || epoch != generation || !current()) return;
      final period = _map(data.source['period']);
      if (period['from'] != query['from'] ||
          period['to'] != query['to'] ||
          (query['currency'] != null &&
              [
                ...data.groups,
                ...data.cycles,
              ].any((g) => g['currency'] != query['currency'])))
        throw const FormatException('Report filters changed.');
      report = data;
      loadedFilters = Map.unmodifiable(query);
    } catch (e) {
      if (mounted && epoch == generation && current()) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> pdf() async {
    if (busy || loadedFilters == null) return;
    final epoch = generation, current = widget.api.sessionFence();
    final query = {
      ...loadedFilters!,
      'locale': Localizations.localeOf(context).languageCode,
    };
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final document = await widget.api.downloadFinancialAnalytics(query);
      if (document.mime != 'application/pdf' ||
          document.bytes.length < 5 ||
          String.fromCharCodes(document.bytes.take(5)) != '%PDF-')
        throw const FormatException('Invalid management PDF.');
      if (!mounted || epoch != generation || !current()) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => VendorDocumentPreview(
            document: document,
            title: analyticsText(context, 'title'),
            notice: analyticsText(context, 'pdfNotice'),
          ),
        ),
      );
    } catch (e) {
      if (mounted && epoch == generation && current()) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => analyticsText(c, k);
    final ar = Localizations.localeOf(c).languageCode == 'ar';
    final data = report;
    Widget rowCard(String title, List<Widget> children) => Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: Theme.of(c).textTheme.titleMedium),
            const SizedBox(height: 8),
            ...children,
          ],
        ),
      ),
    );
    String amount(Map<String, dynamic> r, String k) =>
        '${r['currency']} ${minorDecimal(r[k] as int, r['minor_units'] as int)}';
    return Scaffold(
      appBar: AppBar(title: Text(t('title'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          const SizedBox(height: 16),
          for (final field in [
            (from, 'from'),
            (to, 'to'),
            (currency, 'currency'),
          ])
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: TextField(
                key: Key('analytics-${field.$2}'),
                controller: field.$1,
                enabled: !busy,
                maxLength: field.$2 == 'currency' ? 3 : 10,
                decoration: InputDecoration(labelText: t(field.$2)),
                textDirection: TextDirection.ltr,
              ),
            ),
          FilledButton(
            key: const Key('analytics-refresh'),
            onPressed: busy ? null : load,
            child: Text(t('refresh')),
          ),
          if (busy) const Center(child: CircularProgressIndicator()),
          if (error != null)
            Text(
              error is FormatException
                  ? t('invalid')
                  : EvStrings.of(c).t(errorKey(error!)),
              key: const Key('analytics-error'),
            ),
          if (hidden) Text(t('hidden')),
          if (data != null) ...[
            const SizedBox(height: 16),
            Text(t('applied')),
            Text(loadedFilters?['currency'] ?? t('allCurrencies')),
            Text('${t('asOf')}: ${data.source['as_of']}'),
            Text(
              '${t('current')}: ${data.source['period']['from']} — ${data.source['period']['to']} UTC',
            ),
            Text(
              '${t('previous')}: ${data.source['period']['previous_from']} — ${data.source['period']['previous_to']} UTC',
            ),
            OutlinedButton(
              key: const Key('analytics-pdf'),
              onPressed: busy ? null : pdf,
              child: Text(t('pdf')),
            ),
            if (data.groups.isEmpty) Text(t('empty')),
            for (final r in data.groups)
              rowCard(data.label('category_labels', r['category'], ar), [
                Text(t(r['period'])),
                Text(
                  amount(r, 'amount_minor'),
                  style: Theme.of(c).textTheme.headlineSmall,
                  textDirection: TextDirection.ltr,
                ),
                Text(data.label('state_labels', r['state'], ar)),
                Text('${t('records')}: ${r['record_count']}'),
                Text('${r['evidence']}'),
              ]),
            rowCard(t('points'), [
              Text(t('pointsNotice')),
              for (final r in data.points)
                Text(
                  '${t(r['period'])} · ${t(r['kind'])}: ${r['points']} (${r['entries']})',
                ),
            ]),
            rowCard(t('cycles'), [
              Text(t('cycleNotice')),
              for (final r in data.cycles) ...[
                const Divider(),
                Text(
                  '${t('monthly')}: ${amount(r, 'monthly_equivalent_minor')}',
                ),
                Text('${t('annual')}: ${amount(r, 'annual_equivalent_minor')}'),
                Text('${r['subscriptions']} · ${r['evidence']}'),
              ],
            ]),
          ],
        ],
      ),
    );
  }
}
