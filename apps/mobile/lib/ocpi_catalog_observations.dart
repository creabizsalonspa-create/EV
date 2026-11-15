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
  String? error;
  bool get ar => Localizations.localeOf(context).languageCode == 'ar';
  String t(String en, String arabic) => ar ? arabic : en;
  String label(List<String> names) => names[ar ? 1 : 0];
  bool current(int n) =>
      mounted && foreground && authorized() && n == generation;
  void clear() {
    generation++;
    data = null;
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

  bool count(dynamic v) => v is int && v >= 0 && v <= 10000;
  bool valid(dynamic d) {
    if (d is! Map ||
        d['scope'] != 'stored_partner_catalog_observations' ||
        d['provider'] is! Map ||
        d['provider']['id'] != widget.providerId ||
        d['provider']['name'] is! String ||
        d['provider']['authorized'] is! bool ||
        d['as_of'] is! String ||
        d['record_limit'] != 10000)
      return false;
    if (!['all', 'locations', 'tariffs'].contains(d['module_filter']) ||
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
    for (final key in expected) {
      final m = modules[key];
      if (m is! Map || !count(m['total'])) return false;
      for (final kind in ['reported_age', 'receipt_age']) {
        final values = m[kind];
        if (values is! Map ||
            values.length != 6 ||
            catalogAgeLabels.keys.any((k) => !count(values[k])) ||
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
      if (m.length != metrics.length + 3 ||
          metrics.any(
            (k) => !count(m[k]) || (m[k] as int) > (m['total'] as int),
          ))
        return false;
      if (key == 'locations')
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
    return true;
  }

  Future<void> load() async {
    if (!foreground || !authorized()) return;
    final n = ++generation;
    final selected = module;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'ocpi/providers/${widget.providerId}/catalog-observations',
        query: {'module': selected},
      );
      if (!current(n)) return;
      if (!valid(result) || result['module_filter'] != selected)
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

  @override
  Widget build(BuildContext context) {
    final report = foreground && authorized() ? data : null;
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
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(error!),
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
