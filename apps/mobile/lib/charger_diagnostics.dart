import 'source_status_durations.dart';
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
  final String? registeredDirectProtocol;
  const ChargerDiagnosticsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
    this.openFaultAlerts = false,
    this.registeredDirectProtocol,
  });
  @override
  State<ChargerDiagnosticsView> createState() => _DiagnosticsState();
}

class _DiagnosticsState extends State<ChargerDiagnosticsView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? data;
  Map<String, dynamic>? alerts;
  Map<String, dynamic>? liveConnectorData;
  String? liveConnectorError;
  int? inspectionCursor;
  Object? error;
  bool busy = false;
  int generation = 0;
  bool foreground = true;
  bool get direct => widget.registeredDirectProtocol != null;
  late bool Function() authorized;
  bool current(int epoch) =>
      mounted && foreground && authorized() && epoch == generation;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}';
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    if (direct) return;
    if (widget.openFaultAlerts) {
      // Sequential loads prevent a later alert query from invalidating the
      // diagnostic response's request generation. No POST/PATCH is performed.
      () async {
        final initialRead = generation + 1;
        await load();
        if (current(initialRead)) await loadAlerts();
      }();
    } else {
      load();
    }
  }

  void clearPrivateState() {
    generation++;
    data = null;
    alerts = null;
    liveConnectorData = null;
    liveConnectorError = null;
    inspectionCursor = null;
    error = null;
    busy = false;
  }

  @override
  void didUpdateWidget(covariant ChargerDiagnosticsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.companyId != widget.companyId ||
        oldWidget.chargerId != widget.chargerId ||
        oldWidget.registeredDirectProtocol != widget.registeredDirectProtocol) {
      clearPrivateState();
      authorized = widget.api.sessionFence();
      // A changed target requires an explicit read, never an automatic write.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted)
      setState(() {
        if (!foreground) clearPrivateState();
      });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int? before}) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      liveConnectorData = null;
      liveConnectorError = null;
      error = null;
    });
    try {
      final value = await widget.api.request(
        'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/source-diagnostics',
        query: {if (before != null) 'before': '$before'},
      );
      if (current(epoch)) {
        setState(() => data = Map<String, dynamic>.from(value));
      }
    } catch (e) {
      if (current(epoch)) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> readLiveConnectors() async {
    if (!foreground || !authorized() || busy) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      liveConnectorData = null;
      liveConnectorError = null;
    });
    try {
      final reply = await widget.api.request('$path/live-connectors');
      if (!current(epoch)) return;
      if (reply is! Map ||
          reply['company_id'] != widget.companyId ||
          reply['charger_id'] != widget.chargerId ||
          !['1.6', '2.0.1'].contains(reply['protocol']) ||
          reply['evidence'] != (direct ? 'REGISTERED_DIRECT_SOURCE' : 'SIMULATED_CSMS_SOURCE') ||
          (direct && (reply['protocol'] != widget.registeredDirectProtocol ||
              reply['local_mapping_verified'] != false ||
              reply['source_topology_matches_local'] != false ||
              reply['source_topology_complete'] is! bool)) ||
          reply['source_online'] is! bool ||
          reply['source_topology_matches_local'] is! bool ||
          reply['source_identity_rechecked'] != true ||
          reply['source_last_message_at'] is! String ||
          reply['observed_at'] is! String ||
          reply['physical_availability_verified'] != false ||
          reply['remote_control_authorized'] != false ||
          reply['commands_sent'] != false ||
          reply['connectors'] is! List ||
          ((!direct || reply['source_topology_complete'] == true) &&
              (reply['connectors'] as List).isEmpty) ||
          (reply['connectors'] as List).length > 100) {
        throw const FormatException('Untrusted connector source');
      }
      const statuses = [
        'Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV',
        'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted',
      ];
      final rows = <Map<String, dynamic>>[];
      final targets = <String>{};
      for (final entry in reply['connectors'] as List) {
        if (entry is! Map ||
            (direct ? entry['mapping_id'] != null :
                entry['mapping_id'] is! int || entry['mapping_id'] <= 0) ||
            entry['source_evse_number'] is! int ||
            entry['source_evse_number'] < 0 ||
            (reply['protocol'] == '2.0.1' && entry['source_evse_number'] == 0) ||
            entry['source_connector_number'] is! int ||
            entry['source_connector_number'] <= 0 ||
            (direct ? entry['operator_maintenance_blocked'] != null :
                entry['operator_maintenance_blocked'] is! bool) ||
            (direct && (entry['source_connector_number'] > 100 ||
                (reply['protocol'] == '1.6' ? entry['source_evse_number'] != 0 :
                    entry['source_evse_number'] > 1000))) ||
            !['recent', 'stale', 'unknown'].contains(entry['state']) ||
            (entry['state'] == 'recent'
                ? (!statuses.contains(entry['reported_status']) ||
                    reply['source_online'] != true ||
                    (direct ? (reply['source_topology_complete'] != true ||
                        (reply['protocol'] == '1.6' ? entry['reported_status'] == 'Occupied' :
                            !['Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted'].contains(entry['reported_status']))) :
                        reply['source_topology_matches_local'] != true) ||
                    entry['source_event_at'] is! String)
                : entry['reported_status'] != null) ||
            (entry['error_code'] != null &&
                (entry['error_code'] is! String ||
                    (entry['error_code'] as String).length > 80 ||
                    (!['', 'NoError'].contains(entry['error_code']) &&
                        entry['reported_status'] != 'Faulted'))) ||
            (entry['source_event_at'] != null &&
                entry['source_event_at'] is! String) ||
            entry['physical_available'] != false ||
            entry['remote_control_authorized'] != false) {
          throw const FormatException('Untrusted connector source row');
        }
        if (direct && !targets.add('${entry['source_evse_number']}:${entry['source_connector_number']}')) {
          throw const FormatException('Duplicate direct connector target');
        }
        rows.add(Map<String, dynamic>.from(entry));
      }
      if (current(epoch)) {
        setState(() => liveConnectorData = <String, dynamic>{
          ...Map<String, dynamic>.from(reply),
          'connectors': rows,
        });
      }
    } catch (e) {
      if (!current(epoch)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(() {
          liveConnectorData = null;
          liveConnectorError = null;
        });
        widget.onExpired();
      } else {
        setState(() => liveConnectorError =
            'Live connector source unavailable; no command is authorized.');
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> loadAlerts({int page = 1}) async {
    if (!foreground || !authorized()) return;
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
      if (current(epoch))
        setState(() => alerts = Map<String, dynamic>.from(value));
    } catch (e) {
      if (current(epoch)) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> inspect({bool older = false}) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation;
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
      if (!current(epoch)) return;
      inspectionCursor = result['next_before'] as int?;
      await loadAlerts();
    } catch (e) {
      if (current(epoch)) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> acknowledge(int id) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation;
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final note = await showDialog<String>(
      context: context,
      builder: (_) =>
          _FaultNoteDialog(ar: ar, authorized: () => current(epoch)),
    );
    if (!current(epoch) || note == null) return;
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
      if (current(epoch))
        await loadAlerts(page: alerts?['current_page'] as int? ?? 1);
    } catch (e) {
      if (current(epoch)) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    String tr(String en, String arabic) => ar ? arabic : en;
    if (!foreground || !authorized()) {
      return Scaffold(appBar: AppBar(title: Text(diagnosticsTitle(context))));
    }
    if (direct) {
      return Scaffold(
        appBar: AppBar(title: Text(tr('Registered direct source inspection',
            'فحص المصدر المباشر المسجل'))),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(tr('Registered source observations only; local mapping, physical availability and control are not verified.',
                'ملاحظات المصدر المسجل فقط؛ لم يتم التحقق من الربط المحلي أو التوفر الفعلي أو التحكم.')),
            if (busy) const LinearProgressIndicator(),
            TextButton(
              key: const Key('read-live-connector-source'),
              onPressed: busy ? null : readLiveConnectors,
              child: Text(tr('Read live connector source',
                  'قراءة حالة موصلات المصدر المباشر')),
            ),
            if (liveConnectorError != null)
              Text(tr('Registered source unavailable; no control is authorized.',
                  'المصدر المسجل غير متاح؛ لا يوجد تصريح بالتحكم.')),
            if (liveConnectorData != null) ...[
              Text(tr('Registered direct source snapshot', 'لقطة المصدر المباشر المسجل')),
              Text(liveConnectorData!['source_topology_complete'] == true
                  ? tr('Source topology reported; local mappings not verified',
                      'أبلغ المصدر عن بنية الموصلات؛ لم يتم التحقق من الربط المحلي')
                  : tr('Source topology incomplete; live status unknown',
                      'بنية موصلات المصدر غير مكتملة؛ الحالة المباشرة مجهولة')),
              for (final row in liveConnectorData!['connectors'] as List)
                Card(
                  child: ListTile(
                    title: Text('${row['source_evse_number']}/${row['source_connector_number']}'),
                    subtitle: Text(row['state'] == 'recent'
                        ? '${tr('Recent CSMS report', 'تقرير حديث من نظام الشحن')}: ${row['reported_status']}'
                        : row['state'] == 'stale'
                            ? tr('Stale source; live status unknown', 'المصدر قديم؛ الحالة المباشرة مجهولة')
                            : tr('Connector status unknown', 'حالة الموصل مجهولة')),
                  ),
                ),
            ],
          ],
        ),
      );
    }
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
            key: const Key('read-live-connector-source'),
            onPressed: busy ? null : readLiveConnectors,
            child: Text(tr('Read live connector source',
                'قراءة حالة موصلات المصدر المباشر')),
          ),
          if (liveConnectorError != null)
            Text(tr('Live connector source unavailable; no command is authorized.',
                'مصدر الموصلات المباشر غير متاح؛ لا يُسمح بأي أمر.')),
          if (liveConnectorData != null) ...[
            Text(tr(
                'Current CSMS simulator source only; no physical connector availability is verified.',
                'بيانات محاكي إدارة الشحن فقط؛ لم يُتحقق من توفر الموصلات فعلياً.')),
            Text(liveConnectorData!['source_topology_matches_local'] == true
                ? tr('Source matches owned connector mappings',
                    'المصدر يطابق موصلات المخزون المملوك')
                : tr('Connector topology requires review',
                    'تحتاج موصلات المصدر إلى مراجعة')),
            for (final row in liveConnectorData!['connectors'] as List)
              Card(child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('#${row['mapping_id']} · ${row['source_evse_number']}/${row['source_connector_number']}'),
                    Text(row['state'] == 'recent'
                        ? '${tr('Recent CSMS report', 'تقرير حديث من نظام الشحن')}: ${row['reported_status']}'
                        : row['state'] == 'stale'
                            ? tr('Stale source; live status unknown',
                                'المصدر قديم؛ الحالة المباشرة مجهولة')
                            : tr('Connector status unknown',
                                'حالة الموصل مجهولة')),
                    if (row['operator_maintenance_blocked'] == true)
                      Text(tr('Operator maintenance block active',
                          'حظر الصيانة من المشغل مفعّل')),
                  ],
                ),
              )),
          ],
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
                            : () async {
                                final page = alerts!['current_page'] as int;
                                await Navigator.of(context).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => ChargerRepairsView(
                                      api: widget.api,
                                      companyId: widget.companyId,
                                      chargerId: widget.chargerId,
                                      initialAlertId: alert['id'] as int,
                                      onExpired: widget.onExpired,
                                    ),
                                  ),
                                );
                                if (mounted &&
                                    ModalRoute.of(context)?.isCurrent == true) {
                                  await loadAlerts(page: page);
                                }
                              },
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
                      if (alert['repair_review_closed_at'] != null)
                        Text(
                          tr('Repair review closed', 'أُغلقت مراجعة الإصلاح'),
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
            if (value['status_durations'] != null)
              SourceStatusDurationsView(report: value['status_durations']),
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
  final bool Function() authorized;
  const _FaultNoteDialog({required this.ar, required this.authorized});
  @override
  State<_FaultNoteDialog> createState() => _FaultNoteState();
}

class _FaultNoteState extends State<_FaultNoteDialog>
    with WidgetsBindingObserver {
  final controller = TextEditingController();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      FocusScope.of(context).unfocus();
      setState(controller.clear);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
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
          onPressed: !widget.authorized() || controller.text.trim().length < 10
              ? null
              : () => Navigator.pop(context, controller.text.trim()),
          child: Text(tr('Save', 'حفظ')),
        ),
      ],
    );
  }
}
