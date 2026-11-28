import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'connector_freshness.dart';
import 'charger_diagnostics.dart';
import 'reservation_times.dart' show serverEventTime;

// A stored report can expire between the row read and completion of
// the server snapshot. Never use the device clock to decide its freshness.
String connectorFleetStateAtSnapshot(dynamic freshness, dynamic asOf) {
  final snapshot = strictUtcConnectorTime(asOf);
  if (freshness is! Map || snapshot == null) return 'unknown';
  final checked = strictUtcConnectorTime(freshness['checked_at']);
  if (checked == null || checked.isAfter(snapshot)) return 'unknown';
  return connectorReportedState(freshness, snapshot.difference(checked));
}

class ConnectorFleetView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const ConnectorFleetView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<ConnectorFleetView> createState() => _ConnectorFleetState();
}

class _ConnectorFleetState extends State<ConnectorFleetView>
    with WidgetsBindingObserver {
  final station = TextEditingController();
  late bool Function() authorized;
  StreamSubscription<int>? sessionChanges;
  bool foreground = true, busy = false;
  int generation = 0;
  String selectedStation = '';
  String? error;
  Map<String, dynamic>? data;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
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
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    bindSession();
    WidgetsBinding.instance.addObserver(this);
  }

  void bindSession() {
    unawaited(sessionChanges?.cancel());
    final source = widget.api;
    authorized = source.sessionFence();
    sessionChanges = source.sessionEvents.listen((_) {
      if (mounted && identical(source, widget.api) && !authorized()) {
        setState(() {
          clear();
          station.clear();
          selectedStation = '';
        });
      }
    });
  }

  @override
  void didUpdateWidget(covariant ConnectorFleetView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api) {
      clear();
      station.clear();
      selectedStation = '';
      bindSession();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground && mounted) setState(clear);
  }

  bool valid(dynamic d, int page) {
    if (d is! Map ||
        d['scope'] != 'recorded_connector_fleet' ||
        d['evidence'] != 'SIMULATED_SOURCE' ||
        strictUtcConnectorTime(d['as_of']) == null ||
        [
          'physical_availability_verified',
          'remote_control',
          'billing_authority',
        ].any((k) => d[k] != false))
      return false;
    final p = d['connectors'];
    if (p is! Map ||
        p['data'] is! List ||
        p['data'].length > 25 ||
        p['current_page'] != page ||
        p['last_page'] is! int ||
        p['last_page'] < page)
      return false;
    final ids = <int>{};
    for (final row in p['data']) {
      if (row is! Map ||
          row['mapping_id'] is! int ||
          row['mapping_id'] <= 0 ||
          !ids.add(row['mapping_id'] as int) ||
          row['station_id'] is! int ||
          row['station_id'] <= 0 ||
          (selectedStation.isNotEmpty && row['station_id'].toString() != selectedStation) ||
          row['station_name'] is! String ||
          ![null, '1.6', '2.0.1'].contains(row['protocol']) ||
          row['inventory_eligible_at_read'] is! bool)
        return false;
      final target = row['operations_target'];
      if (target != null &&
          (target is! Map ||
              ![
                'company_id',
                'charger_id',
              ].every((k) => target[k] is int && target[k] > 0)))
        return false;
      final f = row['status_freshness'];
      if (f is! Map ||
          !['recent', 'stale', 'unknown'].contains(f['state']) ||
          f['evidence'] != 'SIMULATED_SOURCE' ||
          f['physical_availability_verified'] != false ||
          (f['state'] == 'recent' &&
              ![
                'Available',
                'Occupied',
                'Preparing',
                'Charging',
                'SuspendedEV',
                'SuspendedEVSE',
                'Finishing',
                'Reserved',
                'Unavailable',
                'Faulted',
              ].contains(f['reported_status'])))
        return false;
    }
    return true;
  }

  Future<void> load({int page = 1, bool newFilter = false}) async {
    if (busy || !foreground || !authorized() || page < 1 || page > 1000000) return;
    if (newFilter) {
      final value = station.text.trim();
      final id = int.tryParse(value);
      if (value.isNotEmpty &&
          (!RegExp(r'^[1-9][0-9]*$').hasMatch(value) ||
              id == null ||
              id > 9007199254740991)) {
        setState(() {
          data = null;
          error = t('Enter a positive station ID.', 'أدخل معرف محطة موجباً.');
        });
        return;
      }
      selectedStation = value;
    }
    final n = ++generation;
    setState(() {
      busy = true;
      error = null;
      data = null;
    });
    try {
      final result = await widget.api.request(
        'charging-operations/connectors',
        query: {
          'page': '$page',
          if (selectedStation.isNotEmpty) 'station_id': selectedStation,
        },
      );
      if (!current(n)) return;
      if (!valid(result, page))
        throw const FormatException('Invalid source snapshot');
      setState(() => data = Map<String, dynamic>.from(result));
    } catch (e) {
      if (!current(n)) return;
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
      if (current(n))
        setState(
          () => error = t(
            'Connector snapshot unavailable. Refresh explicitly.',
            'لقطة الموصلات غير متاحة. حدّث صراحةً.',
          ),
        );
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    generation++;
    unawaited(sessionChanges?.cancel());
    station.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final visible = foreground && authorized() ? data : null;
    final p = visible?['connectors'];
    final viewEpoch = generation;
    String sourceState(dynamic row) =>
        connectorFleetStateAtSnapshot(row['status_freshness'], visible?['as_of']);
    return Scaffold(
      appBar: AppBar(
        title: Text(t('Recorded connector fleet', 'سجل موصلات الأسطول')),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Snapshot of simulator reports only. No physical availability, connectivity or command authority is verified.',
              'لقطة لتقارير المحاكي فقط. لم يُتحقق من التوفر أو الاتصال الفعلي ولا تمنح صلاحية الأوامر.',
            ),
          ),
          TextField(
            controller: station,
            enabled: foreground && authorized() && !busy,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              labelText: t('Station ID (optional)', 'معرف المحطة (اختياري)'),
            ),
          ),
          FilledButton(
            onPressed: busy || !foreground || !authorized()
                ? null
                : () { if (current(viewEpoch)) load(newFilter: true); },
            child: Text(t('Read connector snapshot', 'قراءة لقطة الموصلات')),
          ),
          if (busy && foreground && authorized()) Semantics(liveRegion:true,
            child:LinearProgressIndicator(semanticsLabel:t('Reading recorded connectors','جارٍ قراءة الموصلات المسجلة'))),
          if (foreground && authorized() && error != null) Semantics(liveRegion:true,child:Text(error!)),
          if (visible != null) ...[
            Text('${t('Snapshot time', 'وقت اللقطة')}: ${serverEventTime(context, visible['as_of'])}'),
            for (final row in p['data'])
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${row['station_name']} · #${row['mapping_id']}'),
                      if (row['operations_target'] != null)
                        TextButton(
                          onPressed: () {
                            if (!current(viewEpoch) || busy || !identical(visible, data)) return;
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => ChargerDiagnosticsView(
                                  api: widget.api,
                                  companyId:
                                      row['operations_target']['company_id']
                                          as int,
                                  chargerId:
                                      row['operations_target']['charger_id']
                                          as int,
                                  onExpired: widget.onExpired,
                                ),
                              ),
                            );
                          },
                          child: Text(
                            t('Open source diagnostics', 'فتح تشخيص المصدر'),
                          ),
                        ),
                      Text(
                        '${t('Protocol', 'البروتوكول')}: ${row['protocol'] ?? t('No qualified source', 'لا يوجد مصدر مؤهل')}',
                      ),
                      Text(
                        row['inventory_eligible_at_read'] != true
                            ? t(
                                'Inventory unavailable or maintenance blocked',
                                'المخزون غير متاح أو محظور للصيانة',
                              )
                            : reportedConnectorStatuses.contains(sourceState(row))
                            ? '${t('Recent report at snapshot', 'تقرير حديث وقت اللقطة')}: ${connectorStatusLabel(sourceState(row), Localizations.localeOf(context).languageCode == 'ar')}'
                            : sourceState(row) == 'stale'
                            ? t(
                                'Stale report; current state unknown',
                                'تقرير قديم؛ الحالة الحالية غير معروفة',
                              )
                            : t(
                                'Source state unknown',
                                'حالة المصدر غير معروفة',
                              ),
                      ),
                      if (row['status_freshness']['observed_at'] != null)
                        Text(
                          '${t('Source timestamp', 'وقت المصدر')}: ${serverEventTime(context, sourceState(row) == 'unknown' ? null : row['status_freshness']['observed_at'])}',
                        ),
                    ],
                  ),
                ),
              ),
            if (p['data'].isEmpty)
              Text(
                t(
                  'No mapped connectors in this scope.',
                  'لا توجد موصلات مرتبطة ضمن هذا النطاق.',
                ),
              ),
            Wrap(
              children: [
                TextButton(
                  onPressed: busy || p['current_page'] <= 1
                      ? null
                      : () { if (current(viewEpoch) && identical(visible,data)) load(page: p['current_page'] - 1); },
                  child: Text(t('Previous', 'السابق')),
                ),
                TextButton(
                  onPressed: busy || p['current_page'] >= p['last_page'] || p['current_page'] >= 1000000
                      ? null
                      : () { if (current(viewEpoch) && identical(visible,data)) load(page: p['current_page'] + 1); },
                  child: Text(t('Next', 'التالي')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
