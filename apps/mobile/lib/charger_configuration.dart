import 'package:flutter/material.dart';
import 'api.dart';

String configurationTitle(BuildContext c) =>
    Localizations.localeOf(c).languageCode == 'ar'
    ? 'إعدادات تشغيل الشاحن'
    : 'Device operating settings';

class ChargerConfigurationView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final VoidCallback onExpired;
  const ChargerConfigurationView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
  });
  @override
  State<ChargerConfigurationView> createState() => _ConfigurationState();
}

class _ConfigurationState extends State<ChargerConfigurationView> {
  ApiPage<Map<String, dynamic>>? data;
  bool busy = false, canRequest = false;
  int generation = 0;
  String? message;
  Map<String, dynamic>? pending;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/configuration-reads';
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void didUpdateWidget(covariant ChargerConfigurationView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.companyId != widget.companyId ||
        oldWidget.chargerId != widget.chargerId) {
      pending = null;
      load();
    }
  }

  void check(Map<String, dynamic> row) {
    if (row['evidence'] != 'SIMULATED_SOURCE' ||
        row['settings_changed'] != false ||
        row['physical_charging_verified'] != false)
      throw const FormatException('Unexpected settings capability');
  }

  void fail(Object error, int epoch) {
    if (!mounted || epoch != generation) return;
    setState(() {
      data = null;
      canRequest = false;
      message = tr(
        'Read unavailable. Refresh source history before another action.',
        'القراءة غير متاحة. حدّث سجل المصدر قبل إجراء آخر.',
      );
    });
    if (error is ApiException && [401, 403, 404, 419].contains(error.status))
      pending = null;
    if (error is ApiException && [401, 419].contains(error.status))
      widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      message = null;
      canRequest = false;
    });
    try {
      final result = await widget.api.request(path, query: {'page': '$page'});
      if (result['settings_writes_enabled'] != false)
        throw const FormatException('Unexpected settings capability');
      final parsed = ApiPage<Map<String, dynamic>>.fromJson(result, (j) {
        check(j);
        return j;
      });
      if (mounted && epoch == generation)
        setState(() {
          data = parsed;
          canRequest = result['can_request'] == true;
        });
    } catch (error) {
      fail(error, epoch);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> request() async {
    if (busy || !canRequest) return;
    pending ??= {
      'idempotency_key': operationKey(),
      'reason': 'Inspect device operating settings',
    };
    final body = Map<String, dynamic>.from(pending!), epoch = generation;
    setState(() {
      busy = true;
      message = null;
    });
    try {
      final result = await widget.api.request(path, method: 'POST', body: body);
      check(Map<String, dynamic>.from(result));
      if (mounted && epoch == generation) {
        pending = null;
        await load();
      }
    } catch (error) {
      if (error is ApiException && [409, 422].contains(error.status))
        pending = null;
      fail(error, epoch);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> observe(int id) async {
    if (busy) return;
    final epoch = generation;
    setState(() {
      busy = true;
      message = null;
    });
    try {
      final result = await widget.api.request(
        '$path/$id/observe',
        method: 'POST',
        body: {},
      );
      check(Map<String, dynamic>.from(result));
      if (mounted && epoch == generation) await load(page: data?.current ?? 1);
    } catch (error) {
      fail(error, epoch);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  String? label(String key) => switch (key) {
    'HeartbeatInterval' || 'OCPPCommCtrlr.HeartbeatInterval' => tr(
      'Heartbeat interval (seconds)',
      'فاصل النبضات (ثوانٍ)',
    ),
    'MeterValueSampleInterval' => tr(
      'Meter sample interval (seconds)',
      'فاصل عينات العداد (ثوانٍ)',
    ),
    'NumberOfConnectors' => tr('Connector count', 'عدد الموصلات'),
    'SampledDataCtrlr.TxUpdatedInterval' => tr(
      'Transaction update interval (seconds)',
      'فاصل تحديث المعاملة (ثوانٍ)',
    ),
    'ClockCtrlr.TimeZone' => tr('Time zone', 'المنطقة الزمنية'),
    _ => null,
  };
  String value(Map row) {
    final raw = row['value'];
    if (row['status'] != 'observed' || raw is! String) return '—';
    if (row['key'] == 'ClockCtrlr.TimeZone')
      return raw == 'UTC' ||
              (raw.length <= 64 &&
                  RegExp(r'^[A-Za-z_+-]+/[A-Za-z_+/-]+$').hasMatch(raw))
          ? raw
          : '—';
    return RegExp(r'^[0-9]{1,5}$').hasMatch(raw) && int.parse(raw) <= 86400
        ? raw
        : '—';
  }

  String status(String? state) => switch (state) {
    'source_observed' => tr('Device reply observed', 'تم رصد رد الشاحن'),
    'device_rejected' => tr('Device rejected the read', 'رفض الشاحن القراءة'),
    'not_dispatched' => tr('Read was not sent', 'لم يُرسل طلب القراءة'),
    'invalid_source_response' => tr(
      'Invalid device reply',
      'رد الشاحن غير صالح',
    ),
    'transport_submitted' => tr('Awaiting device reply', 'بانتظار رد الشاحن'),
    'observed' => tr('Observed', 'مرصود'),
    'unsupported' => tr('Not supported', 'غير مدعوم'),
    'invalid_value' => tr('Invalid device value', 'قيمة الشاحن غير صالحة'),
    _ => tr('Outcome uncertain', 'النتيجة غير مؤكدة'),
  };
  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(configurationTitle(context))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          tr(
            'Explicit read requests only. Device settings and charging state are unchanged. Simulator evidence is not physical acceptance.',
            'طلبات قراءة صريحة فقط. لا تتغير إعدادات الشاحن أو حالة الشحن. دليل المحاكي ليس قبولاً للأجهزة الفعلية.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (message != null) Text(message!),
        if (pending != null)
          Text(
            tr(
              'Resolve the previous request before a new read. No automatic retry.',
              'احسم الطلب السابق قبل قراءة جديدة. لا توجد إعادة تلقائية.',
            ),
          ),
        TextButton(
          onPressed: busy ? null : () => load(page: data?.current ?? 1),
          child: Text(tr('Refresh source history', 'تحديث سجل المصدر')),
        ),
        if (canRequest)
          TextButton(
            onPressed: busy ? null : request,
            child: Text(
              pending == null
                  ? tr('Read device settings', 'قراءة إعدادات الشاحن')
                  : tr('Resolve read request', 'حسم طلب القراءة'),
            ),
          ),
        if (data != null) ...[
          if (data!.items.isEmpty)
            Text(tr('No settings reads yet.', 'لا توجد قراءات إعدادات بعد.')),
          for (final row in data!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('OCPP ${row['protocol']}'),
                    Text(status(row['status'] as String?)),
                    if (row['observed_at'] != null)
                      Text('${row['observed_at']} UTC'),
                    for (final setting in row['snapshot'] as List? ?? [])
                      if (setting is Map && label('${setting['key']}') != null)
                        Text(
                          '${label('${setting['key']}')}: ${value(setting)} · ${status(setting['status'] as String?)}',
                        ),
                    if ([
                      'transport_submitted',
                      'outcome_unknown',
                      'dispatching',
                    ].contains(row['status']))
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => observe(row['id'] as int),
                        child: Text(tr('Check device reply', 'فحص رد الشاحن')),
                      ),
                  ],
                ),
              ),
            ),
          Row(
            children: [
              TextButton(
                onPressed: busy || data!.current <= 1
                    ? null
                    : () => load(page: data!.current - 1),
                child: Text(tr('Previous', 'السابق')),
              ),
              Text('${data!.current} / ${data!.last}'),
              TextButton(
                onPressed: busy || data!.current >= data!.last
                    ? null
                    : () => load(page: data!.current + 1),
                child: Text(tr('Next', 'التالي')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}
