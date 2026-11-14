import 'dart:convert';
import 'package:flutter/material.dart';
import 'api.dart';

class OcpiSyncPlansView extends StatefulWidget {
  final EvApi api;
  final int providerId;
  final VoidCallback onExpired;
  const OcpiSyncPlansView({
    super.key,
    required this.api,
    required this.providerId,
    required this.onExpired,
  });
  @override
  State<OcpiSyncPlansView> createState() => _SyncPlansState();
}

class _SyncPlansState extends State<OcpiSyncPlansView>
    with WidgetsBindingObserver {
  late bool Function() authorized;
  Map<String, dynamic>? data;
  final intervals = <String, int>{};
  final consent = <String>{};
  int generation = 0, page = 1;
  bool foreground = true, busy = false;
  String? error;
  String get base => 'ocpi/providers/${widget.providerId}/sync-plans';
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int n) =>
      mounted && foreground && authorized() && n == generation;
  void clear() {
    generation++;
    data = null;
    intervals.clear();
    consent.clear();
    busy = false;
    error = null;
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant OcpiSyncPlansView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.providerId != widget.providerId) {
      clear();
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted && !foreground) setState(clear);
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void fail(Object e, int n) {
    if (!current(n)) return;
    setState(() {
      data = null;
      intervals.clear();
      consent.clear();
      error = t(
        'Outcome unavailable. Refresh recorded plans before another change.',
        'النتيجة غير متاحة. حدّث الخطط المسجلة قبل أي تغيير آخر.',
      );
    });
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int number = 1}) async {
    if (!foreground || !authorized() || busy) return;
    final n = ++generation;
    setState(() {
      busy = true;
      data = null;
      intervals.clear();
      consent.clear();
      error = null;
    });
    try {
      final raw = Map<String, dynamic>.from(
        await widget.api.request(base, query: {'page': '$number'}),
      );
      if (!current(n)) return;
      if (raw['read_only_polling'] != true ||
          raw['remote_control'] != false ||
          raw['billing_authority'] != false ||
          raw['plans'] is! List ||
          raw['history'] is! Map)
        throw const FormatException();
      for (final p in raw['plans'] as List) {
        if (p is! Map ||
            ![
              'locations',
              'tariffs',
              'sessions',
              'cdrs',
            ].contains(p['module']) ||
            p['revision'] is! String ||
            !RegExp(r'^(0|[1-9][0-9]{0,18})$').hasMatch(p['revision']))
          throw const FormatException();
        intervals[p['module'] as String] = p['interval_minutes'] as int;
      }
      setState(() {
        data = raw;
        page = number;
      });
    } catch (e) {
      fail(e, n);
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  Future<void> save(Map plan, bool enabled, int displayed) async {
    final module = plan['module'] as String;
    if (!current(displayed) || busy || (enabled && !consent.contains(module)))
      return;
    final target = '$base/$module';
    final body = {
      'enabled': enabled,
      'confirmed': enabled,
      'interval_minutes': intervals[module],
      'expected_revision': plan['revision'],
    };
    final n = ++generation;
    setState(() {
      busy = true;
      consent.clear();
      error = null;
    });
    var success = false;
    try {
      await widget.api.request(target, method: 'PUT', body: body);
      if (current(n)) success = true;
    } catch (e) {
      fail(e, n);
    } finally {
      if (current(n)) setState(() => busy = false);
    }
    if (success && current(n)) await load(number: page);
  }

  String moduleLabel(dynamic value) => switch (value) {
    'locations' => t('Stations', 'المحطات'),
    'tariffs' => t('Tariffs', 'التعرفات'),
    'sessions' => t('Partner sessions', 'جلسات الشريك'),
    'cdrs' => t('Charge detail records', 'سجلات تفاصيل الشحن'),
    _ => '',
  };
  String stateLabel(dynamic value) => switch (value) {
    'claimed' => t('Reading partner', 'قراءة الشريك'),
    'completed' => t('Reports synchronized', 'تمت مزامنة التقارير'),
    'failed' => t('Read failed; review partner', 'فشلت القراءة؛ راجع الشريك'),
    'cancelled' => t(
      'Cancelled after consent or authority changed',
      'ألغي بعد تغيير الموافقة أو الصلاحية',
    ),
    'interrupted' => t(
      'Interrupted; review before enabling',
      'انقطع؛ راجع قبل التفعيل',
    ),
    _ => t('Review required', 'تلزم المراجعة'),
  };
  String stoppedLabel(dynamic value) => switch (value) {
    'operator_disabled' => t('Disabled by an operator', 'عطله المشغّل'),
    'consent_or_partner_changed' => t(
      'Approval or partner configuration changed. Review and renew consent.',
      'تغيرت الموافقة أو إعدادات الشريك. راجع وجدد الموافقة.',
    ),
    'interrupted_poll' => t(
      'Interrupted poll. Review the earlier run before enabling.',
      'انقطعت القراءة. راجع العملية السابقة قبل التفعيل.',
    ),
    _ => t('Review required', 'تلزم المراجعة'),
  };
  String countLabel(dynamic value) {
    if (value == null) return '';
    try {
      final c = value is String ? jsonDecode(value) : value;
      if (c is! Map ||
          ![
            'created',
            'updated',
            'duplicate',
            'stale',
          ].every((k) => c[k] is int && c[k] >= 0))
        return t('Counts unavailable', 'الأعداد غير متاحة');
      return t(
        'Created ${c['created']} · updated ${c['updated']} · unchanged ${c['duplicate']} · stale skipped ${c['stale']}',
        'جديد ${c['created']} · محدث ${c['updated']} · دون تغيير ${c['duplicate']} · قديم متجاوز ${c['stale']}',
      );
    } catch (_) {
      return t('Counts unavailable', 'الأعداد غير متاحة');
    }
  }

  @override
  Widget build(BuildContext context) {
    final raw = data, displayed = generation;
    final history = raw?['history'] as Map?;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t('Scheduled partner synchronization', 'مزامنة الشريك المجدولة'),
        ),
      ),
      body: !authorized()
          ? Center(child: Text(t('Sign in again.', 'سجّل الدخول مجددًا.')))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  t(
                    'Optional bounded read-only polling of negotiated partner modules. Reports stay unverified. No charging commands, reservations, payments or invoice posting. Changed partner credentials or account authority require renewed consent.',
                    'قراءة دورية اختيارية ومحدودة لوحدات الشريك المتفاوض عليها. تبقى التقارير غير موثقة. لا أوامر شحن أو حجوزات أو مدفوعات أو ترحيل فواتير. تغيير بيانات الشريك أو صلاحية الحساب يتطلب موافقة جديدة.',
                  ),
                ),
                if (busy) const LinearProgressIndicator(),
                if (error != null) Text(error!),
                OutlinedButton(
                  onPressed: busy ? null : () => load(number: page),
                  child: Text(t('Refresh', 'تحديث')),
                ),
                if (raw != null && raw['configured'] != true)
                  Text(
                    t(
                      'Background polling is disabled on this server.',
                      'القراءة الدورية معطلة على هذا الخادم.',
                    ),
                  ),
                for (final plan in raw?['plans'] as List? ?? const [])
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(moduleLabel(plan['module'])),
                          Text(
                            plan['consent_current'] == true
                                ? t('Current consent', 'موافقة حالية')
                                : t(
                                    'No current consent',
                                    'لا توجد موافقة حالية',
                                  ),
                          ),
                          if (plan['next_run_at'] != null)
                            Text(
                              '${t('Next eligible poll (UTC)', 'موعد القراءة المتاحة التالي (UTC)')}: ${plan['next_run_at']}',
                            ),
                          if (plan['running'] == true)
                            Text(
                              t(
                                'Poll in progress; an interruption requires review.',
                                'قراءة قيد التنفيذ؛ الانقطاع يتطلب المراجعة.',
                              ),
                            ),
                          if (plan['stopped_reason'] != null)
                            Text(stoppedLabel(plan['stopped_reason'])),
                          if (plan['enabled'] == true)
                            OutlinedButton(
                              onPressed: busy
                                  ? null
                                  : () => save(plan, false, displayed),
                              child: Text(
                                t(
                                  'Disable module polling',
                                  'تعطيل قراءة الوحدة',
                                ),
                              ),
                            ),
                          if (raw!['configured'] == true &&
                              plan['negotiated'] == true &&
                              plan['running'] != true) ...[
                            DropdownButtonFormField<int>(
                              initialValue: intervals[plan['module']],
                              decoration: InputDecoration(
                                labelText: t(
                                  'Polling interval in minutes',
                                  'فاصل القراءة بالدقائق',
                                ),
                              ),
                              items: [
                                for (final minutes in raw['intervals'] as List)
                                  DropdownMenuItem<int>(
                                    value: minutes as int,
                                    child: Text(
                                      '$minutes ${t('minutes', 'دقيقة')}',
                                    ),
                                  ),
                              ],
                              onChanged: busy
                                  ? null
                                  : (value) {
                                      if (!current(displayed) || value == null)
                                        return;
                                      setState(() {
                                        intervals[plan['module'] as String] =
                                            value;
                                        consent.remove(plan['module']);
                                      });
                                    },
                            ),
                            CheckboxListTile(
                              value: consent.contains(plan['module']),
                              title: Text(
                                t(
                                  'I approve this module and polling interval.',
                                  'أوافق على هذه الوحدة وفاصل القراءة.',
                                ),
                              ),
                              onChanged: busy
                                  ? null
                                  : (value) {
                                      if (!current(displayed)) return;
                                      setState(() {
                                        if (value == true)
                                          consent.add(plan['module'] as String);
                                        else
                                          consent.remove(plan['module']);
                                      });
                                    },
                            ),
                            FilledButton(
                              onPressed:
                                  busy || !consent.contains(plan['module'])
                                  ? null
                                  : () => save(plan, true, displayed),
                              child: Text(
                                t(
                                  'Enable reviewed polling',
                                  'تفعيل القراءة المراجعة',
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                Text(t('Private polling history', 'سجل القراءة الخاص')),
                for (final run in history?['data'] as List? ?? const [])
                  ListTile(
                    title: Text(
                      '${moduleLabel(run['module'])} · ${stateLabel(run['state'])}',
                    ),
                    subtitle: Text(
                      '${run['started_at']} UTC · ${countLabel(run['counts'])}',
                    ),
                  ),
                if (page > 1)
                  TextButton(
                    onPressed: busy ? null : () => load(number: page - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (history != null &&
                    (history['current_page'] as int) <
                        (history['last_page'] as int))
                  TextButton(
                    onPressed: busy ? null : () => load(number: page + 1),
                    child: Text(t('Next', 'التالي')),
                  ),
              ],
            ),
    );
  }
}
