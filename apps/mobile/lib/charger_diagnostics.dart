import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'charger_configuration.dart';
import 'charger_logs.dart';
import 'charger_repairs.dart';

String diagnosticsTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'تشخيص المصدر'
    : 'Source diagnostics';

class ChargerDiagnosticsView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final VoidCallback onExpired;
  final bool openFaultAlerts;
  const ChargerDiagnosticsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
    this.openFaultAlerts = false,
  });
  @override
  State<ChargerDiagnosticsView> createState() => _DiagnosticsState();
}

class _DiagnosticsState extends State<ChargerDiagnosticsView> {
  Map<String, dynamic>? data;
  Map<String, dynamic>? alerts;
  int? inspectionCursor;
  Object? error;
  bool busy = false;
  int generation = 0;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}';
  @override
  void initState() {
    super.initState();
    if (widget.openFaultAlerts) {
      // Sequential loads prevent a later alert query from invalidating the
      // diagnostic response's request generation. No POST/PATCH is performed.
      () async {
        await load();
        if (mounted) await loadAlerts();
      }();
    } else {
      load();
    }
  }

  Future<void> load({int? before}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final value = await widget.api.request(
        'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/source-diagnostics',
        query: {if (before != null) 'before': '$before'},
      );
      if (mounted && epoch == generation) {
        setState(() => data = Map<String, dynamic>.from(value));
      }
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> loadAlerts({int page = 1}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      alerts = null;
    });
    try {
      final value = await widget.api.request(
        '$path/fault-alerts',
        query: {'page': '$page'},
      );
      if (mounted && epoch == generation)
        setState(() => alerts = Map<String, dynamic>.from(value));
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> inspect({bool older = false}) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        '$path/fault-alerts/inspect',
        method: 'POST',
        body: {},
        query: {
          if (older && inspectionCursor != null) 'before': '$inspectionCursor',
        },
      );
      if (!mounted) return;
      inspectionCursor = result['next_before'] as int?;
      await loadAlerts();
    } catch (e) {
      if (mounted) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> acknowledge(int id) async {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _FaultNoteDialog(ar: ar),
    );
    if (!mounted || note == null) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        '$path/fault-alerts/$id/acknowledge',
        method: 'PATCH',
        body: {'note': note},
      );
      if (mounted) await loadAlerts(page: alerts?['current_page'] as int? ?? 1);
    } catch (e) {
      if (mounted) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    String tr(String en, String arabic) => ar ? arabic : en;
    final value = data;
    return Scaffold(
      appBar: AppBar(title: Text(diagnosticsTitle(context))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            tr(
              'Authenticated simulator observations, not connector availability or physical acceptance. No commands are sent.',
              'ملاحظات محاكي موثقة وليست توفر الموصل أو قبول الأجهزة الفعلية. لا تُرسل أوامر.',
            ),
          ),
          TextButton(
            onPressed: busy
                ? null
                : () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ChargerConfigurationView(
                        api: widget.api,
                        companyId: widget.companyId,
                        chargerId: widget.chargerId,
                        onExpired: widget.onExpired,
                      ),
                    ),
                  ),
            child: Text(configurationTitle(context)),
          ),
          TextButton(
            onPressed: busy
                ? null
                : () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ChargerRepairsView(
                        api: widget.api,
                        companyId: widget.companyId,
                        chargerId: widget.chargerId,
                        onExpired: widget.onExpired,
                      ),
                    ),
                  ),
            child: Text(tr('Repair work orders', 'أوامر الإصلاح')),
          ),
          TextButton(
            onPressed: busy
                ? null
                : () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => ChargerLogsView(
                        api: widget.api,
                        companyId: widget.companyId,
                        chargerId: widget.chargerId,
                        onExpired: widget.onExpired,
                      ),
                    ),
                  ),
            child: Text(tr('Diagnostic files', 'ملفات التشخيص')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          TextButton(
            onPressed: busy ? null : () => load(),
            child: Text(tr('Refresh', 'تحديث')),
          ),
          TextButton(
            onPressed: busy ? null : () => loadAlerts(),
            child: Text(tr('Fault alerts', 'تنبيهات الأعطال')),
          ),
          if (value?['can_manage_faults'] == true) ...[
            TextButton(
              onPressed: busy ? null : () => inspect(),
              child: Text(tr('Inspect source faults', 'فحص أعطال المصدر')),
            ),
            if (inspectionCursor != null)
              TextButton(
                onPressed: busy ? null : () => inspect(older: true),
                child: Text(
                  tr('Inspect older source faults', 'فحص أعطال مصدر أقدم'),
                ),
              ),
          ],
          if (alerts != null) ...[
            Text(
              tr(
                'Acknowledgment records review only. Source recovery is historical evidence, not physical repair or release of charging capacity.',
                'الإقرار يسجل المراجعة فقط. تعافي المصدر دليل تاريخي وليس إصلاحاً فعلياً أو تحرير سعة الشحن.',
              ),
            ),
            for (final alert in alerts!['data'] as List)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    children: [
                      Text('#${alert['id']}'),
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => Navigator.of(context).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => ChargerRepairsView(
                                    api: widget.api,
                                    companyId: widget.companyId,
                                    chargerId: widget.chargerId,
                                    initialAlertId: alert['id'] as int,
                                    onExpired: widget.onExpired,
                                  ),
                                ),
                              ),
                        child: Text(tr('Repair work orders', 'أوامر الإصلاح')),
                      ),
                      Text(
                        '${alert['evse_number']}/${alert['connector_number']} · ${alert['status']} · ${alert['error_code'] ?? '—'}',
                      ),
                      Text('${alert['occurred_at']}'),
                      Text(
                        alert['recovery_observed_at'] != null
                            ? tr(
                                'Recovery source observed',
                                'رُصد دليل تعافٍ من المصدر',
                              )
                            : tr(
                                'No recovery source observed',
                                'لم يُرصد دليل تعافٍ من المصدر',
                              ),
                      ),
                      if (alert['acknowledged_at'] != null)
                        Text(tr('Fault acknowledged', 'تم الإقرار بالعطل'))
                      else if (alerts!['can_manage_faults'] == true)
                        TextButton(
                          onPressed: busy
                              ? null
                              : () => acknowledge(alert['id'] as int),
                          child: Text(
                            tr('Acknowledge fault', 'الإقرار بالعطل'),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            if ((alerts!['current_page'] as int) > 1)
              TextButton(
                onPressed: busy
                    ? null
                    : () => loadAlerts(
                        page: (alerts!['current_page'] as int) - 1,
                      ),
                child: Text(tr('Previous', 'السابق')),
              ),
            if ((alerts!['current_page'] as int) <
                (alerts!['last_page'] as int))
              TextButton(
                onPressed: busy
                    ? null
                    : () => loadAlerts(
                        page: (alerts!['current_page'] as int) + 1,
                      ),
                child: Text(tr('Next', 'التالي')),
              ),
          ],
          if (value != null) ...[
            Text('${value['protocol']}'),
            Text(
              tr(
                'Automatic fault monitoring',
                'المراقبة التلقائية لأعطال الشاحن',
              ),
            ),
            Text(switch ((value['automatic_monitor'] as Map?)?['state']) {
              'caught_up' => tr(
                'Source events caught up at last check',
                'اكتملت متابعة أحداث المصدر عند آخر فحص',
              ),
              'catching_up' => tr(
                'Catching up source history',
                'جارٍ استكمال سجل المصدر',
              ),
              'requires_review' => tr(
                'Source monitoring requires review',
                'تتطلب مراقبة المصدر مراجعة',
              ),
              'not_observed' => tr(
                'Awaiting registered source',
                'في انتظار تسجيل المصدر',
              ),
              _ => tr(
                'Automatic monitor not observed',
                'لم تُرصد المراقبة التلقائية',
              ),
            }),
            if ((value['automatic_monitor'] as Map?)?['checked_at'] != null)
              Text('${(value['automatic_monitor'] as Map)['checked_at']}'),
            Text(
              value['source_registered'] == true
                  ? tr('Source registered', 'المصدر مسجل')
                  : tr('Source not observed', 'لم يُرصد المصدر'),
            ),
            if (value['source_registered'] == true) ...[
              Text(
                value['source_online'] == true
                    ? tr('Source reports online', 'المصدر يبلغ عن الاتصال')
                    : tr(
                        'Source reports offline',
                        'المصدر يبلغ عن انقطاع الاتصال',
                      ),
              ),
              Text(
                value['recent_message_observed'] == true
                    ? tr('Recent source message', 'رسالة مصدر حديثة')
                    : tr(
                        'No recent source message',
                        'لا توجد رسالة مصدر حديثة',
                      ),
              ),
              Text('${value['last_message_at'] ?? '—'}'),
              Text('${value['firmware_version'] ?? '—'}'),
            ],
            for (final event in value['events'] as List)
              ListTile(
                title: Text(
                  '${event['connectorStatus']} · ${event['errorCode'] ?? '—'}',
                ),
                subtitle: Text(
                  '${event['evseId'] ?? '—'}/${event['connectorId'] ?? '—'} · ${event['timestamp']}',
                ),
              ),
            if (value['next_before'] != null)
              TextButton(
                onPressed: busy
                    ? null
                    : () => load(before: value['next_before'] as int),
                child: Text(tr('Next', 'التالي')),
              ),
          ],
        ],
      ),
    );
  }
}

class _FaultNoteDialog extends StatefulWidget {
  final bool ar;
  const _FaultNoteDialog({required this.ar});
  @override
  State<_FaultNoteDialog> createState() => _FaultNoteState();
}

class _FaultNoteState extends State<_FaultNoteDialog> {
  final controller = TextEditingController();
  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    String tr(String en, String ar) => widget.ar ? ar : en;
    return AlertDialog(
      title: Text(tr('Acknowledge fault', 'الإقرار بالعطل')),
      content: TextField(
        controller: controller,
        maxLength: 2000,
        minLines: 2,
        maxLines: 4,
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(
          labelText: tr(
            'Operator notes (10–2000 characters)',
            'ملاحظات المشغل (١٠–٢٠٠٠ حرف)',
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(tr('Cancel', 'إلغاء')),
        ),
        FilledButton(
          onPressed: controller.text.trim().length < 10
              ? null
              : () => Navigator.pop(context, controller.text.trim()),
          child: Text(tr('Save', 'حفظ')),
        ),
      ],
    );
  }
}
