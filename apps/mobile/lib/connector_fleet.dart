import 'package:flutter/material.dart';
import 'api.dart';
import 'connector_freshness.dart';
import 'charger_diagnostics.dart';

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
  bool foreground = true, busy = false;
  int generation = 0;
  String selectedStation = '';
  String? error;
  Map<String, dynamic>? data, directData;
  Map<int, Map<String, dynamic>> directResults = {};
  bool liveRequested = false;
  Map<int, Map<String, dynamic>> liveResults = {};
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int n) =>
      mounted && foreground && authorized() && n == generation;
  void clear() {
    generation++;
    data = null;
    directData = null;
    directResults = {};
    error = null;
    busy = false;
    liveRequested = false;
    liveResults = {};
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didUpdateWidget(covariant ConnectorFleetView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api) {
      clear();
      station.clear();
      selectedStation = '';
      authorized = widget.api.sessionFence();
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
        d['as_of'] is! String ||
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
        p['last_page'] < 1)
      return false;
    final mappingIds = <int>{};
    for (final row in p['data']) {
      if (row is! Map ||
          row['mapping_id'] is! int ||
          row['mapping_id'] <= 0 ||
          !mappingIds.add(row['mapping_id'] as int) ||
          row['station_id'] is! int ||
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

  Future<void> load({int page = 1, bool newFilter = false, bool direct = false}) async {
    if (busy || !foreground || !authorized()) return;
    if (newFilter) {
      final value = station.text.trim();
      final id = int.tryParse(value);
      if (value.isNotEmpty &&
          (!RegExp(r'^[1-9][0-9]*$').hasMatch(value) ||
              id == null ||
              id > 9007199254740991)) {
        setState(() {
          data = null;
          directData = null;
          directResults = {};
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
      directData = null;
      directResults = {};
      liveRequested = false;
      liveResults = {};
    });
    try {
      final result = await widget.api.request(
        direct ? 'charging-operations/registered-chargers' : 'charging-operations/connectors',
        query: {
          'page': '$page',
          if (selectedStation.isNotEmpty) 'station_id': selectedStation,
        },
      );
      if (!current(n)) return;
      if (!(direct ? validDirect(result, page) : valid(result, page))) {
        throw const FormatException('Invalid source snapshot');
      }
      setState(() {
        if (direct) {
          directData = Map<String, dynamic>.from(result);
        } else {
          data = Map<String, dynamic>.from(result);
        }
      });
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

  bool sourceTimestamp(dynamic value) =>
      value is String &&
      value.length <= 50 &&
      RegExp(r'^\d{4}-\d\d-\d\dT').hasMatch(value) &&
      DateTime.tryParse(value) != null;

  bool validDirect(dynamic value, int page) {
    if (value is! Map ||
        value['scope'] != 'direct_charger_fleet' ||
        value['evidence'] != 'INVENTORY_ONLY' ||
        !sourceTimestamp(value['as_of']) ||
        ['physical_availability_verified', 'remote_control_authorized',
          'billing_authority'].any((key) => value[key] != false)) return false;
    final p = value['chargers'];
    if (p is! Map || p['current_page'] != page ||
        p['last_page'] is! int || p['last_page'] < 1 ||
        p['data'] is! List || p['data'].length > 25) return false;
    final ids = <int>{};
    for (final row in p['data']) {
      if (row is! Map ||
          !['charger_id', 'company_id', 'station_id'].every((key) =>
              row[key] is int && row[key] > 0 && row[key] <= 9007199254740991) ||
          !ids.add(row['charger_id'] as int) ||
          !['1.6', '2.0.1'].contains(row['protocol']) ||
          row['station_name'] is! String || row['asset_reference'] is! String ||
          row['state'] != 'not_inspected' ||
          (selectedStation.isNotEmpty && '${row['station_id']}' != selectedStation)) return false;
    }
    return true;
  }

  Map<String, dynamic> validateDirectLive(dynamic reply, Map target) {
    if (reply is! Map || reply['company_id'] != target['company_id'] ||
        reply['charger_id'] != target['charger_id'] ||
        reply['protocol'] != target['protocol'] ||
        reply['evidence'] != 'REGISTERED_DIRECT_SOURCE' ||
        reply['source_identity_rechecked'] != true ||
        reply['source_topology_matches_local'] != false ||
        reply['local_mapping_verified'] != false ||
        reply['source_topology_complete'] is! bool || reply['source_online'] is! bool ||
        ['physical_availability_verified', 'remote_control_authorized',
          'commands_sent'].any((key) => reply[key] != false) ||
        !sourceTimestamp(reply['observed_at']) || reply['connectors'] is! List ||
        reply['connectors'].length > 100 ||
        (reply['source_topology_complete'] == true && reply['connectors'].isEmpty)) {
      throw const FormatException('Invalid registered source');
    }
    final statuses = target['protocol'] == '1.6'
        ? ['Available', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE',
            'Finishing', 'Reserved', 'Unavailable', 'Faulted']
        : ['Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted'];
    final seen = <String>{};
    for (final row in reply['connectors']) {
      if (row is! Map || row['mapping_id'] != null || row['operator_maintenance_blocked'] != null ||
          row['source_evse_number'] is! int ||
          (target['protocol'] == '1.6' ? row['source_evse_number'] != 0 :
            row['source_evse_number'] < 1 || row['source_evse_number'] > 1000) ||
          row['source_connector_number'] is! int || row['source_connector_number'] < 1 ||
          row['source_connector_number'] > 100 ||
          !seen.add('${row['source_evse_number']}:${row['source_connector_number']}') ||
          !['recent', 'stale', 'unknown'].contains(row['state']) ||
          row['physical_available'] != false || row['remote_control_authorized'] != false ||
          (row['state'] == 'recent'
            ? (reply['source_online'] != true || reply['source_topology_complete'] != true ||
                !statuses.contains(row['reported_status']) || !sourceTimestamp(row['source_event_at']))
            : row['reported_status'] != null)) {
        throw const FormatException('Invalid registered source connector');
      }
    }
    return Map<String, dynamic>.from(reply);
  }

  Future<void> refreshDirect() async {
    if (busy || !foreground || !authorized() || directData == null) return;
    final all = List<Map>.from(directData!['chargers']['data'] as List);
    if (all.isEmpty) return;
    final n = ++generation;
    setState(() {
      busy = true;
      error = null;
      liveRequested = true;
      directResults = {};
    });
    final collected = <int, Map<String, dynamic>>{};
    try {
      for (var i = 0; i < all.length; i += 4) {
        if (!current(n)) return;
        await Future.wait(all.skip(i).take(4).map((target) async {
          try {
            final reply = await widget.api.request(
              'vendor-ops/companies/${target['company_id']}/chargers/${target['charger_id']}/live-connectors',
            );
            if (current(n)) {
              collected[target['charger_id'] as int] = validateDirectLive(reply, target);
            }
          } catch (e) {
            if (e is ApiException && [401, 403, 419].contains(e.status)) rethrow;
          }
        }));
      }
      if (current(n)) setState(() => directResults = collected);
    } catch (e) {
      if (e is ApiException && [401, 419].contains(e.status) &&
          mounted && foreground && n == generation &&
          (authorized() || e.endsCurrentSession)) {
        setState(clear);
        widget.onExpired();
      } else if (current(n)) {
        setState(() {
          clear();
          error = t('Registered source unavailable. Refresh explicitly.',
            'المصدر المسجل غير متاح. حدّث صراحةً.');
        });
      }
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  Map<int, Map<String, dynamic>> validateLive(
    dynamic reply,
    List<Map<String, dynamic>> group,
  ) {
    final target = group.first['operations_target'];
    if (reply is! Map ||
        reply['company_id'] != target['company_id'] ||
        reply['charger_id'] != target['charger_id'] ||
        !['1.6', '2.0.1'].contains(reply['protocol']) ||
        group.any((row) => row['protocol'] != null &&
            row['protocol'] != reply['protocol']) ||
        reply['evidence'] != 'SIMULATED_CSMS_SOURCE' ||
        reply['source_identity_rechecked'] != true ||
        reply['source_online'] is! bool ||
        reply['source_topology_matches_local'] is! bool ||
        ['physical_availability_verified', 'remote_control_authorized',
          'commands_sent'].any((key) => reply[key] != false) ||
        !sourceTimestamp(reply['observed_at']) ||
        reply['connectors'] is! List ||
        (reply['connectors'] as List).isEmpty ||
        (reply['connectors'] as List).length > 100) {
      throw const FormatException('Invalid live source');
    }
    final statuses = reply['protocol'] == '1.6'
        ? ['Available', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE',
            'Finishing', 'Reserved', 'Unavailable', 'Faulted']
        : ['Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted'];
    final byId = <int, Map<String, dynamic>>{};
    for (final row in reply['connectors']) {
      if (row is! Map ||
          row['mapping_id'] is! int ||
          row['mapping_id'] <= 0 ||
          byId.containsKey(row['mapping_id']) ||
          !['recent', 'stale', 'unknown'].contains(row['state']) ||
          row['physical_available'] != false ||
          row['remote_control_authorized'] != false ||
          (row['state'] == 'recent'
              ? (reply['source_online'] != true ||
                  reply['source_topology_matches_local'] != true ||
                  !statuses.contains(row['reported_status']) ||
                  !sourceTimestamp(row['source_event_at']))
              : row['reported_status'] != null)) {
        throw const FormatException('Invalid live connector');
      }
      byId[row['mapping_id'] as int] = {
        'state': row['state'],
        'reported_status': row['reported_status'],
        'observed_at': reply['observed_at'],
      };
    }
    final result = <int, Map<String, dynamic>>{};
    for (final row in group) {
      final id = row['mapping_id'] as int;
      if (!byId.containsKey(id)) {
        throw const FormatException('Missing fleet mapping');
      }
      result[id] = byId[id]!;
    }
    return result;
  }

  Future<void> refreshLive() async {
    if (busy || !foreground || !authorized() || data == null) return;
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final raw in data!['connectors']['data']) {
      final row = Map<String, dynamic>.from(raw as Map);
      final target = row['operations_target'];
      if (target == null) continue;
      final key = '${target['company_id']}:${target['charger_id']}';
      (groups[key] ??= []).add(row);
    }
    if (groups.isEmpty) return;
    final n = ++generation;
    setState(() {
      busy = true;
      error = null;
      liveRequested = true;
      liveResults = {};
    });
    final all = groups.values.toList();
    final collected = <int, Map<String, dynamic>>{};
    try {
      // An explicit page refresh, no polling or commands. Four concurrent
      // independently scoped requests maximum, one per unique charger.
      for (var i = 0; i < all.length; i += 4) {
        if (!current(n)) return;
        final batch = await Future.wait(all.skip(i).take(4).map((group) async {
          try {
            final target = group.first['operations_target'];
            final reply = await widget.api.request(
              'vendor-ops/companies/${target['company_id']}/chargers/${target['charger_id']}/live-connectors',
            );
            if (!current(n)) return <int, Map<String, dynamic>>{};
            return validateLive(reply, group);
          } catch (e) {
            if (e is ApiException && [401, 403, 419].contains(e.status)) rethrow;
            return <int, Map<String, dynamic>>{};
          }
        }));
        if (!current(n)) return;
        for (final result in batch) {
          collected.addAll(result);
        }
      }
      if (current(n)) setState(() => liveResults = collected);
    } catch (e) {
      if (e is ApiException &&
          [401, 419].contains(e.status) &&
          mounted && foreground && n == generation &&
          (authorized() || e.endsCurrentSession)) {
        setState(clear);
        widget.onExpired();
      } else if (current(n)) {
        setState(() {
          clear();
          error = t('Live source unavailable. Refresh explicitly.',
              'المصدر المباشر غير متاح. حدّث صراحةً.');
        });
      }
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  String liveLabel(int id) {
    if (busy) return t('Reading live source…', 'جارٍ قراءة المصدر المباشر…');
    final result = liveResults[id];
    if (result == null) {
      return t('Live source unavailable or unqualified; state unknown.',
          'المصدر المباشر غير متاح أو غير مؤهل؛ الحالة غير معروفة.');
    }
    final label = result['state'] == 'recent'
        ? '${t('Live source report at read', 'تقرير المصدر المباشر وقت القراءة')}: ${connectorStatusLabel(result['reported_status'] as String, Localizations.localeOf(context).languageCode == 'ar')}'
        : t('Live source state unknown or stale', 'حالة المصدر المباشر غير معروفة أو قديمة');
    return '$label · ${result['observed_at']}';
  }

  @override
  void dispose() {
    generation++;
    station.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final visible = foreground && authorized() ? data : null;
    final p = visible?['connectors'];
    final directPage = foreground && authorized() ? directData?['chargers'] : null;
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
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              labelText: t('Station ID (optional)', 'معرف المحطة (اختياري)'),
            ),
          ),
          FilledButton(
            onPressed: busy || !foreground || !authorized()
                ? null
                : () => load(newFilter: true),
            child: Text(t('Read connector snapshot', 'قراءة لقطة الموصلات')),
          ),
          FilledButton(
            onPressed: busy || !foreground || !authorized() ? null :
              () => load(newFilter: true, direct: true),
            child: Text(t('Read direct charger inventory', 'قراءة مخزون الشواحن المباشرة')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (foreground && authorized() && error != null) Text(error!),
          if (directPage != null) ...[
            Text(t('Direct charger fleet inventory', 'مخزون أسطول الشواحن المباشرة')),
            Text(t('Inventory only; registration, physical availability and command authority are not verified.',
              'المخزون فقط؛ لم يتم التحقق من التسجيل أو التوفر الفعلي أو صلاحية الأوامر.')),
            if ((directPage['data'] as List).isNotEmpty)
              FilledButton(
                onPressed: busy ? null : refreshDirect,
                child: Text(t('Read registered sources for this page', 'قراءة المصادر المسجلة لهذه الصفحة')),
              ),
            for (final row in directPage['data'])
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${row['station_name']} · ${row['asset_reference']}'),
                      Text('OCPP ${row['protocol']}'),
                      if (!liveRequested)
                        Text(t('Source not inspected', 'لم يُفحص المصدر'))
                      else if (busy)
                        Text(t('Reading live source…', 'جارٍ قراءة المصدر المباشر…'))
                      else if (directResults[row['charger_id']] == null)
                        Text(t('Registered source unavailable or unverified; status unknown.',
                          'المصدر المسجل غير متاح أو غير متحقق؛ الحالة مجهولة.'))
                      else ...[
                        Text(directResults[row['charger_id']]!['source_topology_complete'] == true
                          ? t('Source topology reported; local mappings not verified', 'أبلغ المصدر عن البنية؛ الربط المحلي غير متحقق')
                          : t('Source topology incomplete; live status unknown', 'بنية المصدر غير مكتملة؛ الحالة مجهولة')),
                        Text('${directResults[row['charger_id']]!['observed_at']}'),
                        for (final connector in directResults[row['charger_id']]!['connectors'])
                          Text('${connector['source_evse_number']}/${connector['source_connector_number']} · ${connector['state'] == 'recent' ? connectorStatusLabel(connector['reported_status'] as String, Localizations.localeOf(context).languageCode == 'ar') : t('Unknown or stale', 'مجهول أو قديم')}'),
                      ],
                    ],
                  ),
                ),
              ),
            if ((directPage['data'] as List).isEmpty)
              Text(t('No direct chargers in this scope.', 'لا توجد شواحن مباشرة في هذا النطاق.')),
            Row(children: [
              TextButton(
                onPressed: busy || directPage['current_page'] <= 1 ? null :
                  () => load(page: directPage['current_page'] - 1, direct: true),
                child: Text(t('Previous', 'السابق')),
              ),
              TextButton(
                onPressed: busy || directPage['current_page'] >= directPage['last_page'] ? null :
                  () => load(page: directPage['current_page'] + 1, direct: true),
                child: Text(t('Next', 'التالي')),
              ),
            ]),
          ],
          if (visible != null) ...[
            Text('${t('Snapshot time', 'وقت اللقطة')}: ${visible['as_of']}'),
            if ((p['data'] as List).any((row) => row['operations_target'] != null)) ...[
              FilledButton(
                onPressed: busy || !foreground || !authorized() ? null : refreshLive,
                child: Text(t('Read live sources for this page',
                    'قراءة المصادر المباشرة لهذه الصفحة')),
              ),
              Text(t('Live reads are separate simulator-source snapshots; no physical or command authority.',
                  'القراءات المباشرة لقطات مستقلة من المحاكي؛ لا تثبت التوفر الفعلي ولا تمنح صلاحية الأوامر.')),
            ],
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
                            if (!foreground || !authorized()) return;
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
                            : row['status_freshness']['state'] == 'recent'
                            ? '${t('Recent report at snapshot', 'تقرير حديث وقت اللقطة')}: ${connectorStatusLabel(row['status_freshness']['reported_status'] as String, Localizations.localeOf(context).languageCode == 'ar')}'
                            : row['status_freshness']['state'] == 'stale'
                            ? t(
                                'Stale report; current state unknown',
                                'تقرير قديم؛ الحالة الحالية غير معروفة',
                              )
                            : t(
                                'Source state unknown',
                                'حالة المصدر غير معروفة',
                              ),
                      ),
                      if (liveRequested) Text(liveLabel(row['mapping_id'] as int)),
                      if (row['status_freshness']['observed_at'] != null)
                        Text(
                          '${t('Source timestamp', 'وقت المصدر')}: ${row['status_freshness']['observed_at']}',
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
            Row(
              children: [
                TextButton(
                  onPressed: busy || p['current_page'] <= 1
                      ? null
                      : () => load(page: p['current_page'] - 1),
                  child: Text(t('Previous', 'السابق')),
                ),
                TextButton(
                  onPressed: busy || p['current_page'] >= p['last_page']
                      ? null
                      : () => load(page: p['current_page'] + 1),
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
