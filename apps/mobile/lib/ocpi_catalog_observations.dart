import 'package:flutter/material.dart';
import 'api.dart';

const catalogAgeLabels = {
  'within_1_hour': ['Within1 hour', 'خلال ساعة'],
  'within_24_hours': ['Over1 hour, within24 hours', 'أقدم من ساعة وحتى24 ساعة'],
  'within_7_days': ['Over24 hours, within7 days', 'أقدم من24 ساعة وحتى7 أيام'],
  'older_than_7_days': ['Over7 days', 'أقدم من7 أيام'],
  'unknown': ['Unknown timestamp', 'وقت غير معروف'],
  'clock_ahead': ['Clock ahead of snapshot', 'الساعة متقدمة على اللقطة'],
};
const catalogMetricLabels = {
  'stored_publish_true': [
    'Stored publish flag: true',
    'علامة النشر المخزنة: نعم',
  ],
  'stored_publish_false': [
    'Stored publish flag: false',
    'علامة النشر المخزنة: لا',
  ],
  'stored_publish_unknown': ['Unknown publish flag', 'علامة النشر غير معروفة'],
  'coordinates_usable': [
    'Coordinates usable for a map (accuracy unverified)',
    'إحداثيات قابلة للعرض (الدقة غير متحققة)',
  ],
  'coordinates_unknown_or_invalid': [
    'Missing/invalid coordinates',
    'إحداثيات مفقودة/غير صالحة',
  ],
  'connector_list_present': [
    'Reported connector list present',
    'قائمة موصلات مبلّغ عنها موجودة',
  ],
  'connector_list_empty': [
    'Reported connector list empty',
    'قائمة الموصلات المبلّغ عنها فارغة',
  ],
  'connector_list_unknown': [
    'Reported connector list unknown',
    'قائمة الموصلات المبلّغ عنها غير معروفة',
  ],
  'current_version_retired': [
    'Current tariff version withdrawn',
    'نسخة التعرفة الحالية مسحوبة',
  ],
};

const catalogReportedMarkets = {
  'AE': [
    'UAE (partner-reported location)',
    'الإمارات (موقع مبلّغ عنه من الشريك)',
  ],
  'US': [
    'US (partner-reported location)',
    'الولايات المتحدة (موقع مبلّغ عنه من الشريك)',
  ],
  'CA': [
    'Canada (partner-reported location)',
    'كندا (موقع مبلّغ عنه من الشريك)',
  ],
  'other_or_unknown': [
    'Other or unknown reported location',
    'موقع مبلّغ عنه في دولة أخرى أو غير معروف',
  ],
};
const catalogMarketFacets = {
  'published_by_reported_market': [
    'Stored published reports by location country (not network coverage)',
    'التقارير المنشورة حسب دولة الموقع (ليست تغطية الشبكة)',
  ],
  'published_with_usable_coordinates_by_reported_market': [
    'Published reports with map-usable coordinates (accuracy unverified)',
    'التقارير المنشورة بإحداثيات قابلة للعرض (الدقة غير متحققة)',
  ],
};

/// Validate the same bounded, source-only report for live clients and widgets.
bool _catalogObservationCount(dynamic v) => v is int && v >= 0 && v <= 10000;
bool catalogObservationReportValid(dynamic d, {required int providerId}) {
  if (d is! Map ||
      d['scope'] != 'stored_partner_catalog_observations' ||
      d['provider'] is! Map ||
      d['provider']['id'] != providerId ||
      d['provider']['name'] is! String ||
      d['provider']['authorized'] is! bool ||
      d['as_of'] is! String ||
      d['record_limit'] != 10000)
    return false;
  if (!['all', 'locations', 'tariffs'].contains(d['module_filter']) ||
      d['market_classification'] != 'PARTNER_REPORTED_LOCATION_COUNTRY_ONLY' ||
      d['coverage_exhaustive'] != false ||
      [
        'receipt_refreshes_source_age',
        'live_availability_verified',
        'physical_charging_verified',
        'remote_control',
        'billing_authority',
      ].any((k) => d[k] != false))
    return false;
  final modules = d['modules'];
  final expected = d['module_filter'] == 'all'
      ? ['locations', 'tariffs']
      : [d['module_filter']];
  if (modules is! Map || modules.length != expected.length) return false;
  int combinedTotal = 0;
  for (final key in expected) {
    final m = modules[key];
    if (m is! Map || !_catalogObservationCount(m['total'])) return false;
    combinedTotal += m['total'] as int;
    if (combinedTotal > 10000) return false;
    for (final kind in ['reported_age', 'receipt_age']) {
      final values = m[kind];
      if (values is! Map ||
          values.length != 6 ||
          catalogAgeLabels.keys.any(
            (k) => !_catalogObservationCount(values[k]),
          ) ||
          catalogAgeLabels.keys.fold<int>(
                0,
                (sum, k) => sum + (values[k] as int),
              ) !=
              m['total'])
        return false;
    }
    final metrics = key == 'locations'
        ? catalogMetricLabels.keys
              .where((k) => k != 'current_version_retired')
              .toList()
        : ['current_version_retired'];
    if (m.length != metrics.length + 3 + (key == 'locations' ? 2 : 0) ||
        metrics.any(
          (k) =>
              !_catalogObservationCount(m[k]) ||
              (m[k] as int) > (m['total'] as int),
        ))
      return false;
    if (key == 'locations') {
      final published = m['published_by_reported_market'];
      final usable = m['published_with_usable_coordinates_by_reported_market'];
      bool marketCounts(dynamic values) =>
          values is Map &&
          values.length == catalogReportedMarkets.length &&
          catalogReportedMarkets.keys.every(
            (k) => _catalogObservationCount(values[k]),
          );
      int sum(Map values) => catalogReportedMarkets.keys.fold<int>(
        0,
        (total, k) => total + (values[k] as int),
      );
      if (!marketCounts(published) || !marketCounts(usable)) return false;
      if (sum(published as Map) != m['stored_publish_true'] ||
          sum(usable as Map) > m['coordinates_usable'] ||
          catalogReportedMarkets.keys.any((k) => usable[k] > published[k])) {
        return false;
      }
      for (final group in [
        [
          'stored_publish_true',
          'stored_publish_false',
          'stored_publish_unknown',
        ],
        ['coordinates_usable', 'coordinates_unknown_or_invalid'],
        [
          'connector_list_present',
          'connector_list_empty',
          'connector_list_unknown',
        ],
      ]) {
        if (group.fold<int>(0, (sum, k) => sum + (m[k] as int)) != m['total'])
          return false;
      }
    }
  }
  return true;
}

/// Strict, bounded source-observation history. No record in this shape is
/// evidence of usable chargers, provider geographic completeness or billing.
bool catalogQualityHistoryValid(dynamic d, {required int providerId}) {
  if (d is! Map ||
      d['scope'] != 'stored_partner_catalog_quality_history' ||
      d['provider'] is! Map ||
      d['provider']['id'] != providerId ||
      d['provider']['name'] is! String ||
      d['provider']['authorized'] is! bool ||
      d['period'] != 'hour_utc' ||
      d['sample_limit'] != 30 ||
      d['retention_hours'] != 168 ||
      d['evidence'] != 'STORED_PARTNER_CATALOG_TIMESTAMPS_ONLY' ||
      d['sample_completeness'] != 'STORED_SYNC_AND_OPTIONAL_PERIODIC_SNAPSHOTS' ||
      [
        'source_covers_whole_network',
        'live_availability_verified',
        'physical_charging_verified',
        'remote_control',
        'billing_authority',
      ].any((key) => d[key] != false) ||
      d['samples'] is! List ||
      (d['samples'] as List).length > 30 ||
      d['count'] != (d['samples'] as List).length) {
    return false;
  }
  final iso = RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$');
  DateTime? exactUtc(String text) {
    if (!iso.hasMatch(text)) return null;
    final parsed = DateTime.tryParse(text);
    if (parsed == null || !parsed.isUtc ||
        parsed.year != int.parse(text.substring(0, 4)) ||
        parsed.month != int.parse(text.substring(5, 7)) ||
        parsed.day != int.parse(text.substring(8, 10)) ||
        parsed.hour != int.parse(text.substring(11, 13)) ||
        parsed.minute != int.parse(text.substring(14, 16)) ||
        parsed.second != int.parse(text.substring(17, 19)) ||
        parsed.millisecond * 1000 + parsed.microsecond !=
            int.parse(text.substring(20, 26))) {
      return null;
    }
    return parsed;
  }

  const counts = [
    'published_count',
    'within_policy_count',
    'stale_count',
    'unknown_age_count',
    'future_clock_count',
  ];
  DateTime? previous;
  for (final value in d['samples'] as List) {
    if (value is! Map ||
        value['observed_hour'] is! String ||
        value['sampled_at'] is! String ||
        !iso.hasMatch(value['observed_hour']) ||
        !iso.hasMatch(value['sampled_at'])) {
      return false;
    }
    final hour = exactUtc(value['observed_hour']);
    final sampled = exactUtc(value['sampled_at']);
    if (hour == null ||
        sampled == null ||
        hour.minute != 0 ||
        hour.second != 0 ||
        hour.millisecond != 0 ||
        hour.microsecond != 0 ||
        sampled.isBefore(hour) ||
        !sampled.isBefore(hour.add(const Duration(hours: 1))) ||
        (previous != null && !hour.isBefore(previous))) {
      return false;
    }
    previous = hour;
    if (['within_policy', 'attention', 'empty'].contains(value['state'])) {
      if (value['max_age_seconds'] is! int ||
          value['max_age_seconds'] < 60 ||
          value['max_age_seconds'] > 604800 ||
          counts.any((key) =>
              value[key] is! int || value[key] < 0 || value[key] > 1000000) ||
          value['within_policy_count'] +
                  value['stale_count'] +
                  value['unknown_age_count'] !=
              value['published_count'] ||
          value['future_clock_count'] > value['unknown_age_count'] ||
          value['state'] != (value['published_count'] == 0
              ? 'empty' : (value['stale_count'] + value['unknown_age_count'] > 0
                  ? 'attention' : 'within_policy'))) {
        return false;
      }
    } else if (!['unavailable', 'policy_unavailable']
            .contains(value['state']) ||
        counts.any((key) => value[key] != null)) {
      return false;
    }
  }
  return true;
}

class OcpiCatalogObservationsView extends StatefulWidget {
  final EvApi api;
  final int providerId;
  final VoidCallback onExpired;
  const OcpiCatalogObservationsView({
    super.key,
    required this.api,
    required this.providerId,
    required this.onExpired,
  });
  @override
  State<OcpiCatalogObservationsView> createState() =>
      _CatalogObservationState();
}

class _CatalogObservationState extends State<OcpiCatalogObservationsView>
    with WidgetsBindingObserver {
  late bool Function() authorized;
  bool foreground = true, busy = false;
  String module = 'all';
  int generation = 0;
  Map<String, dynamic>? data;
  Map<String, dynamic>? history;
  String? error;
  bool get ar => Localizations.localeOf(context).languageCode == 'ar';
  String t(String en, String arabic) => ar ? arabic : en;
  String label(List<String> names) => names[ar ? 1 : 0];
  bool current(int n) =>
      mounted && foreground && authorized() && n == generation;
  void clear() {
    generation++;
    data = null;
    history = null;
    error = null;
    busy = false;
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didUpdateWidget(covariant OcpiCatalogObservationsView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api || old.providerId != widget.providerId) {
      clear();
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted && !foreground) setState(clear);
  }

  Future<void> load() async {
    if (!foreground || !authorized()) return;
    final n = ++generation;
    final selected = module;
    setState(() {
      busy = true;
      data = null;
      history = null;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'ocpi/providers/${widget.providerId}/catalog-observations',
        query: {'module': selected},
      );
      if (!current(n)) return;
      if (!catalogObservationReportValid(
            result,
            providerId: widget.providerId,
          ) ||
          result['module_filter'] != selected)
        throw const FormatException('Invalid observation contract');
      setState(() => data = Map<String, dynamic>.from(result));
    } catch (e) {
      if (current(n)) {
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
        if (current(n))
          setState(
            () => error = t(
              'Observations unavailable. Check access or select one module, then read again. No partial counts are shown.',
              'الملاحظات غير متاحة. تحقق من الصلاحية أو اختر وحدة واحدة ثم اقرأ مجدداً. لا تُعرض أعداد جزئية.',
            ),
          );
      }
    } finally {
      if (mounted && n == generation)
        setState(() {
          busy = false;
          if (!authorized()) clear();
        });
    }
  }

  Future<void> loadHistory() async {
    if (!foreground || !authorized()) return;
    final n = ++generation;
    setState(() {
      busy = true;
      data = null;
      history = null;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'ocpi/providers/${widget.providerId}/catalog-quality-history',
      );
      if (!current(n)) return;
      if (!catalogQualityHistoryValid(result, providerId: widget.providerId)) {
        throw const FormatException('Invalid partner quality history');
      }
      setState(() => history = Map<String, dynamic>.from(result));
    } catch (e) {
      if (current(n)) {
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
        }
        if (current(n)) {
          setState(() => error = t(
            'Quality history unavailable. Read again explicitly; no partial samples are shown.',
            'سجل جودة المصدر غير متاح. أعد القراءة يدوياً؛ لا تُعرض عينات جزئية.',
          ));
        }
      }
    } finally {
      if (mounted && n == generation) {
        setState(() {
          busy = false;
          if (!authorized()) clear();
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final permitted = foreground && authorized();
    final report = permitted ? data : null;
    final quality = permitted ? history : null;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t(
            'Stored partner catalog observations',
            'ملاحظات كتالوج الشريك المخزنة',
          ),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Stored partner reports only. Age buckets describe observations, not the configured catalog policy. A recent receipt does not refresh an old source report. Publish flags, coordinates and connector lists do not prove geographic accuracy, live availability or charging compatibility. No upstream request, booking, command or payment is sent.',
              'تقارير شريك مخزنة فقط. فئات العمر تصف الملاحظات وليست قرار سياسة الكتالوج المضبوطة. الاستلام الحديث لا يجعل تقرير المصدر القديم حديثاً. علامات النشر والإحداثيات وقوائم الموصلات لا تثبت دقة الموقع أو التوفر الحي أو توافق الشحن. لا يُرسل طلب للشريك أو حجز أو أمر أو دفع.',
            ),
          ),
          DropdownButtonFormField<String>(
            isExpanded: true,
            initialValue: module,
            decoration: InputDecoration(
              labelText: t('Catalog module', 'وحدة الكتالوج'),
            ),
            items: [
              for (final item in [
                ('all', 'Locations and tariffs', 'المواقع والتعرفة'),
                ('locations', 'Locations', 'المواقع'),
                ('tariffs', 'Tariffs', 'التعرفة'),
              ])
                DropdownMenuItem(
                  value: item.$1,
                  child: Text(t(item.$2, item.$3)),
                ),
            ],
            onChanged: busy
                ? null
                : (v) => setState(() {
                    clear();
                    module = v!;
                  }),
          ),
          FilledButton(
            onPressed: busy ? null : load,
            child: Text(
              t(
                'Read stored catalog observations',
                'قراءة ملاحظات الكتالوج المخزنة',
              ),
            ),
          ),
          OutlinedButton(
            onPressed: busy ? null : loadHistory,
            child: Text(t('Read source quality history', 'قراءة سجل جودة المصدر')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(error!),
          if (quality != null) ...[
            Text(t(
              'Stored hourly source quality only — not live availability or network coverage.',
              'جودة المصدر المخزنة بالساعة فقط؛ ليست توفراً حياً أو تغطية للشبكة.',
            )),
            Text('${quality['provider']['name']}'),
            if ((quality['samples'] as List).isEmpty)
              Text(t(
                'No changed location syncs recorded; no coverage conclusion.',
                'لا توجد عمليات مزامنة مواقع متغيرة مسجلة؛ لا توجد نتيجة عن التغطية.',
              )),
            for (final sample in quality['samples'] as List)
              Card(
                key: ValueKey('catalog-quality-${sample['observed_hour']}'),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${sample['observed_hour']}'),
                      Text(t(
                        'Source quality state: ${sample['state']}',
                        'حالة جودة المصدر: ${sample['state']}',
                      )),
                      if (sample['published_count'] != null)
                        Text(t(
                          'Published: ${sample['published_count']} · Within policy: ${sample['within_policy_count']} · Stale: ${sample['stale_count']} · Unknown: ${sample['unknown_age_count']} · Future clock: ${sample['future_clock_count']}',
                          'منشور: ${sample['published_count']} · ضمن السياسة: ${sample['within_policy_count']} · قديم: ${sample['stale_count']} · غير معروف: ${sample['unknown_age_count']} · ساعة مستقبلية: ${sample['future_clock_count']}',
                        )),
                    ],
                  ),
                ),
              ),
          ],
          if (report != null) ...[
            Text('${report['provider']['name']}'),
            Text('${report['as_of']}'),
            if (report['provider']['authorized'] != true)
              Text(
                t(
                  'Partner authorization is revoked. These are private historical records.',
                  'تصريح الشريك ملغى. هذه سجلات تاريخية خاصة.',
                ),
              ),
            for (final e in (report['modules'] as Map).entries)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        e.key == 'locations'
                            ? t('Stored locations', 'المواقع المخزنة')
                            : t('Stored tariffs', 'التعرفة المخزنة'),
                      ),
                      Text(
                        t(
                          'Records: ${e.value['total']}',
                          'السجلات: ${e.value['total']}',
                        ),
                      ),
                      for (final kind in ['reported_age', 'receipt_age']) ...[
                        Text(
                          kind == 'reported_age'
                              ? t(
                                  'Partner-reported timestamp age',
                                  'عمر الوقت الذي أبلغ عنه الشريك',
                                )
                              : t(
                                  'Local receipt timestamp age',
                                  'عمر وقت الاستلام المحلي',
                                ),
                        ),
                        for (final bucket in catalogAgeLabels.entries)
                          Text(
                            '${label(bucket.value)}: ${e.value[kind][bucket.key]}',
                          ),
                      ],
                      for (final metric in catalogMetricLabels.entries)
                        if ((e.value as Map).containsKey(metric.key))
                          Text(
                            '${label(metric.value)}: ${e.value[metric.key]}',
                          ),
                      if (e.key == 'locations')
                        for (final facet in catalogMarketFacets.entries) ...[
                          Text(
                            label(facet.value),
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          for (final market in catalogReportedMarkets.entries)
                            Text(
                              '${label(market.value)}: ${e.value[facet.key][market.key]}',
                              key: ValueKey(
                                'catalog-market-${facet.key}-${market.key}',
                              ),
                            ),
                        ],
                    ],
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}
