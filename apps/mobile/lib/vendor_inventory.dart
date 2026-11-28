import 'dart:async';
import 'station_information.dart';
import 'station_availability_blocks.dart';
import 'package:flutter/material.dart';
import 'api.dart';
import 'operational_analytics.dart';
import 'strings.dart';
import 'vendor_topology.dart';
import 'charger_admission.dart';
import 'charger_diagnostics.dart';
import 'direct_connector_review.dart';
import 'charger_availability.dart';
import 'charger_logs.dart';
import 'charger_source_registration.dart';
import 'charger_resets.dart';
import 'charger_firmware.dart';
import 'maintenance_windows.dart';
import 'topology_strings.dart';
import 'vendor_company.dart';
import 'vendor_session_observations.dart';
import 'vendor_forms.dart';
import 'vendor_operations_strings.dart';

/// Existing company inventory APIs; no hardware command or inferred live state.
class VendorInventoryView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final bool metadataEditor;
  final Map<String, dynamic>? company, site, charger;
  const VendorInventoryView({
    super.key,
    required this.api,
    required this.onExpired,
    this.metadataEditor = false,
    this.company,
    this.site,
    this.charger,
  });
  @override
  State<VendorInventoryView> createState() => _VendorInventoryViewState();
}

bool inventoryMaintenanceReceipt(dynamic value, int connector, int charger,
    bool blocked, String reason) =>
    value is Map && value['id'] is int && value['id'] == connector &&
    value['charger_id'] is int && value['charger_id'] == charger &&
    value['maintenance_blocked'] == blocked &&
    value['maintenance_reason'] == reason &&
    value['availability'] == 'unknown' && value['remote_control_enabled'] == false;

class _VendorInventoryViewState extends State<VendorInventoryView> with WidgetsBindingObserver {
  late bool Function() authorized;
  StreamSubscription<int>? sessionSubscription;
  bool foreground = true, retired = false, confirming = false;
  bool get active => mounted && foreground && !retired && authorized();
  bool current(int epoch) => active && generation == epoch;
  bool get top => ModalRoute.of(context)?.isCurrent ?? true;
  void redact() {
    data = null; error = null; busy = false; uncertain = false; confirming = false;
  }
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false, uncertain = false;
  int generation = 0, target = 1;
  int get level => widget.charger != null
      ? 3
      : widget.site != null
      ? 2
      : widget.company != null
      ? 1
      : 0;
  Map<String, dynamic> get capabilities =>
      Map<String, dynamic>.from(widget.company?['capabilities'] as Map? ?? {});
  bool get writer => capabilities['inventory_write'] == true;
  String tr(String key) => inventoryText(context, key);
  String get endpoint {
    final base = 'vendor-ops/companies';
    if (level == 0) return base;
    final company = '$base/${widget.company!['id']}';
    if (level == 1) return '$company/sites';
    if (level == 2) return '$company/sites/${widget.site!['id']}/chargers';
    return '$company/chargers/${widget.charger!['id']}/connectors';
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    final source = widget.api;
    sessionSubscription = source.sessionEvents.listen((_) {
      if (mounted && identical(source,widget.api) && !authorized()) {
        setState(() { retired = true; redact(); });
      }
    });
    if (active) load();
  }

  @override
  void didUpdateWidget(covariant VendorInventoryView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api,widget.api) ||
        oldWidget.company?['id'] != widget.company?['id'] ||
        oldWidget.site?['id'] != widget.site?['id'] ||
        oldWidget.charger?['id'] != widget.charger?['id'] ||
        oldWidget.metadataEditor != widget.metadataEditor ||
        oldWidget.company?['capabilities'].toString() != widget.company?['capabilities'].toString()) {
      generation++; retired = true; redact();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    final next = state == AppLifecycleState.resumed;
    if (next == foreground) return;
    setState(() { foreground = next; generation++; redact(); });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    super.dispose();
  }

  void handleFailure(Object e,int epoch) {
    if (current(epoch)) { failed(e); }
    else if (mounted && foreground && epoch == generation &&
        e is ApiException && e.endsCurrentSession) {
      widget.onExpired();
      if (mounted) Navigator.of(context).popUntil((route) => route.isFirst);
    }
  }

  void failed(Object e) {
    if (!mounted) return;
    setState(() {
      error = e;
      data = null;
    });
    if (e is ApiException && [401, 419].contains(e.status)) {
      widget.onExpired();
      if (mounted) Navigator.of(context).popUntil((route) => route.isFirst);
    }
  }

  Future<void> load({int page = 1}) async {
    if (!active || page < 1 || page > 100000) return;
    final epoch = ++generation, request = widget.api, path = endpoint;
    target = page;
    setState(() {
      busy = true;
      data = null;
      error = null;
      uncertain = false;
    });
    try {
      final result = await request.request(path,query: {'page': '$page'});
      if (!current(epoch)) return;
      final parsed = ApiPage.fromJson(result, (v) => v);
      final seen = <int>{};
      if (parsed.current != page || parsed.last < 1 || page > parsed.last ||
          parsed.total < 0 || parsed.items.length > 25 ||
          parsed.items.length > parsed.total ||
          parsed.items.any((row) => row['id'] is! int || row['id'] <= 0 ||
              !seen.add(row['id'] as int) ||
              (level == 1 && row['company_id'] != widget.company?['id']) ||
              (level == 2 && (row['company_id'] != widget.company?['id'] ||
                row['site_id'] != widget.site?['id'])) ||
              (level == 3 && row['charger_id'] != widget.charger?['id']))) {
        throw const FormatException('Invalid inventory source page.');
      }
      setState(() => data = parsed);
    } catch (e) {
      handleFailure(e,epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> open(Map<String, dynamic> row) async {
    if (!active || !top || busy || confirming || !(data?.items.contains(row) ?? false)) return;
    final epoch = generation;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => !current(epoch) ? const Scaffold(body:SizedBox.shrink()) : VendorInventoryView(
          api: widget.api,
          onExpired: widget.onExpired,
          metadataEditor: widget.metadataEditor,
          company: level == 0 ? row : widget.company,
          site: level == 1 ? row : widget.site,
          charger: level == 2 ? row : widget.charger,
        ),
      ),
    );
    if (current(epoch) && top) {
      await load(page: data?.current ?? 1);
    }
  }

  Widget sessionObservationsButton(Map<String, dynamic> row) {
    final request = widget.api,
        authorized = request.sessionFence(),
        epoch = generation;
    bool live() =>
        current(epoch) && top &&
        request == widget.api &&
        authorized() && !confirming;
    return TextButton(
      onPressed: busy
          ? null
          : () async {
              if (!live() || row['id'] is! int) return;
              await Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => current(epoch) && authorized()
                      ? VendorSessionObservationsView(
                          api: request,
                          companyId: row['id'] as int,
                          onExpired: widget.onExpired,
                        )
                      : const Scaffold(body: SizedBox.shrink()),
                ),
              );
            },
      child: Text(
        Localizations.localeOf(context).languageCode == 'ar'
            ? 'جلسات الشحن المسجلة'
            : 'Recorded charging sessions',
      ),
    );
  }

  Future<void> maintenance(Map<String, dynamic> row) async {
    if (!active || !top || busy || confirming || !writer || !(data?.items.contains(row) ?? false) || (row['maintenance_blocked'] == true && capabilities['inventory_operational'] != true)) return;
    final reviewed = generation, request = widget.api, path = endpoint;
    final connector = row['id'] as int, charger = widget.charger!['id'] as int;
    setState(() => confirming = true);
    var reason = '';
    final form = GlobalKey<FormState>();
    final blocked = row['maintenance_blocked'] == true;
    String? result;
    try {
    result = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr(blocked ? 'release' : 'block')),
        content: SingleChildScrollView(
          child: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(tr('consent')),
                const SizedBox(height: 16),
                TextFormField(
                  onChanged: (value) => reason = value,
                  maxLength: 1000,
                  minLines: 2,
                  maxLines: 5,
                  decoration: InputDecoration(labelText: tr('reason')),
                  validator: (v) => (v?.trim().isEmpty ?? true)
                      ? EvStrings.of(context).t('required')
                      : null,
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(EvStrings.of(context).t('cancel')),
          ),
          FilledButton(
            onPressed: () {
              if (form.currentState!.validate())
                Navigator.pop(context, reason.trim());
            },
            child: Text(EvStrings.of(context).t('confirm')),
          ),
        ],
      ),
    );
    } finally {
      if (current(reviewed)) setState(() => confirming = false);
    }
    if (result == null || !current(reviewed) || !top || busy || !writer || (blocked && capabilities['inventory_operational'] != true)) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final receipt = await request.request(
        '$path/$connector/maintenance',
        method: 'PATCH',
        body: {'maintenance_blocked': !blocked, 'maintenance_reason': result},
      );
      if (!current(epoch)) return;
      if (!inventoryMaintenanceReceipt(receipt,connector,charger,!blocked,result)) {
        throw const FormatException('Unconfirmed maintenance receipt.');
      }
      await load(page: target);
    } catch (e) {
      if (current(epoch)) uncertain = e is! ApiException || e.status == 0 || e.status >= 500;
      handleFailure(e,epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> createInventory() async {
    if (!active || !top || busy || confirming || data == null || (level != 0 && (!writer || capabilities['inventory_operational'] != true))) return;
    final epoch = generation;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => !current(epoch) ? const Scaffold(body:SizedBox.shrink()) : VendorEditView(
          api: widget.api,
          onExpired: widget.onExpired,
          kind: ['company', 'site', 'charger', 'connector'][level],
          company: widget.company,
          site: widget.site,
          charger: widget.charger,
        ),
      ),
    );
    if (current(epoch) && top) await load(page: target);
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = active ? data : null;
    final title = tr(['companies', 'sites', 'chargers', 'connectors'][level]);
    final rendered = generation;
    bool live() => current(rendered) && top && !busy && !confirming;
    VoidCallback guarded(VoidCallback callback) => () { if (live()) callback(); };
    WidgetBuilder scoped(WidgetBuilder builder) => (c) =>
        current(rendered) ? builder(c) : const Scaffold(body:SizedBox.shrink());
    if (!active) return Scaffold(
      appBar:AppBar(title:Text(title)),
      body:Center(child:Text(foreground ? s.t('expired') : tr('hidden'))),
    );
    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          IconButton(
            tooltip: s.t('refresh'),
            onPressed: busy || confirming ? null : guarded(() => load(page: target)),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          if (live()) await load(page: target);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            Text(tr('disclaimer')),
            if (p != null && level == 1)
              TextButton(
                onPressed: busy
                    ? null
                    : guarded(() => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: scoped((_) => OperationalAnalyticsView(
                            api: widget.api,
                            companyId: widget.company!['id'] as int,
                            onExpired: widget.onExpired,
                          )),
                        ),
                      )),
                child: Text(
                  Localizations.localeOf(context).languageCode == 'ar'
                      ? 'تحليلات تشغيل الشحن'
                      : 'Charging operational analytics',
                ),
              ),

            if (p != null && level == 0) ...[
              if (widget.metadataEditor)
                OutlinedButton.icon(
                  key: const Key('vendor-station-information-open'),
                  icon: const Icon(Icons.info_outline),
                  label: Text(
                    Localizations.localeOf(context).languageCode == 'ar'
                        ? 'ساعات العمل ومرافق المحطة'
                        : 'Station hours & facilities',
                  ),
                  onPressed: busy
                      ? null
                      : guarded(() => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: scoped((_) => VendorStationInformationView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                            )),
                          ),
                        )),
                ),
              FilledButton(
                onPressed: busy ? null : guarded(createInventory),
                child: Text(vendorOpsText(context, 'newCompany')),
              ),
              TextButton(
                onPressed: busy
                    ? null
                    : () async {
                        if (!live()) return;
                        await Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: scoped((_) => VendorRecordsView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              kind: 'invitations',
                            )),
                          ),
                        );
                        if (current(rendered) && top) load();
                      },
                child: Text(vendorOpsText(context, 'invitations')),
              ),
            ] else if (p != null && writer && capabilities['inventory_operational'] == true)
              FilledButton(
                onPressed: busy ? null : guarded(createInventory),
                child: Text(
                  vendorOpsText(
                    context,
                    [
                      'newCompany',
                      'newSite',
                      'newCharger',
                      'newConnector',
                    ][level],
                  ),
                ),
              ),

            if (busy) const LinearProgressIndicator(),
            if (error != null) ...[
              Text(s.t(errorKey(error!))),
              if (uncertain) Text(tr('uncertain')),
              TextButton(
                onPressed: busy || confirming ? null : guarded(() => load(page: target)),
                child: Text(s.t('retry')),
              ),
            ],
            if (p != null && p.items.isEmpty) Text(tr('empty')),
            if (p != null) ...[
              for (final row in p.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          level == 0
                              ? '${row['legal_name']}'
                              : level == 1
                              ? '${row['name']}'
                              : level == 2
                              ? '${row['asset_reference']}'
                              : '${tr('connector')} ${row['connector_number']}',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        if (level == 0)
                          Text(
                            '${row['country']} · ${tr(row['status'] as String)}',
                          ),
                        if (level == 1) ...[
                          Text('${row['address']}'),
                          Text('${row['country']} · ${row['timezone']}'),
                          Text('${row['latitude']}, ${row['longitude']}'),
                          // Only the vendor owner may change a station-wide
                          // booking policy. A staff member's inventory grant
                          // is not a station ownership grant.
                          if (widget.metadataEditor &&
                              capabilities['owner'] == true &&
                              row['station_id'] is int &&
                              (row['station_id'] as int) > 0)
                            OutlinedButton.icon(
                              key: Key('station-blocks-${row['station_id']}'),
                              icon: const Icon(Icons.event_busy_outlined),
                              onPressed: busy
                                  ? null
                                  : () async {
                                      if (!live()) return;
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: scoped((_) => StationAvailabilityBlocksView(
                                            api: widget.api,
                                            stationId: row['station_id'] as int,
                                            onExpired: widget.onExpired,
                                          )),
                                        ),
                                      );
                                      if (current(rendered) && top) {
                                        await load(page: data?.current ?? 1);
                                      }
                                    },
                              label: Text(
                                Localizations.localeOf(context).languageCode == 'ar'
                                    ? 'إدارة فترات حجب المحطة'
                                    : 'Manage station availability blocks',
                              ),
                            ),
                        ],
                        if (level == 2) ...[
                          Text('${row['manufacturer']} · ${row['model']}'),
                          Text(
                            '${tr(row['source'] as String)} · OCPP ${row['ocpp_protocol'] ?? '—'}',
                          ),
                        ],
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    if (!live()) return;
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: scoped((_) => VendorTopologyView(
                                          api: widget.api,
                                          company: widget.company!,
                                          charger: row,
                                          onExpired: widget.onExpired,
                                        )),
                                      ),
                                    );
                                    if (current(rendered) && top)
                                      await load(page: data?.current ?? 1);
                                  },
                            child: Text(topologyText(context, 'title')),
                          ),
                        if (level == 2 &&
                            row['source'] == 'direct_unverified' &&
                            ['1.6', '2.0.1'].contains(row['ocpp_protocol']) &&
                            widget.company?['id'] is int &&
                            row['id'] is int) ...[
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) =>
                                          ChargerSourceRegistrationView(
                                            api: widget.api,
                                            companyId:
                                                widget.company!['id'] as int,
                                            chargerId: row['id'] as int,
                                            onExpired: widget.onExpired,
                                          )),
                                    ),
                                  )),
                            child: Text(
                              EvStrings.of(context).arabic
                                  ? 'التسجيل لدى المصدر'
                                  : 'Source registration',
                            ),
                          ),
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) => ChargerLogsView(
                                        api: widget.api,
                                        companyId: widget.company!['id'] as int,
                                        chargerId: row['id'] as int,
                                        onExpired: widget.onExpired,
                                      )),
                                    ),
                                  )),
                            child: Text(
                              EvStrings.of(context).arabic
                                  ? 'ملفات تشخيص المصدر الخاصة'
                                  : 'Private source diagnostic files',
                            ),
                          ),
                        ],
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    if (!live()) return;
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: scoped((_) => ChargerAdmissionView(
                                          api: widget.api,
                                          company: widget.company!,
                                          charger: row,
                                          onExpired: widget.onExpired,
                                        )),
                                      ),
                                    );
                                    if (current(rendered) && top)
                                      await load(page: data?.current ?? 1);
                                  },
                            child: Text(admissionText(context, 'title')),
                          ),
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) => ChargerDiagnosticsView(
                                        api: widget.api,
                                        companyId: widget.company!['id'] as int,
                                        chargerId: row['id'] as int,
                                        registeredDirectProtocol:
                                            row['source'] == 'direct_unverified'
                                            ? '${row['ocpp_protocol'] ?? ''}'
                                            : null,
                                        onExpired: widget.onExpired,
                                      )),
                                    ),
                                  )),
                            child: Text(diagnosticsTitle(context)),
                          ),
                        if (level == 2 && row['source'] == 'simulator')
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) => ChargerAvailabilityView(
                                        api: widget.api,
                                        companyId: widget.company!['id'] as int,
                                        chargerId: row['id'] as int,
                                        onExpired: widget.onExpired,
                                      )),
                                    ),
                                  )),
                            child: Text(availabilityCommandTitle(context)),
                          ),
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) => ChargerResetsView(
                                        api: widget.api,
                                        companyId: widget.company!['id'] as int,
                                        chargerId: row['id'] as int,
                                        onExpired: widget.onExpired,
                                      )),
                                    ),
                                  )),
                            child: Text(resetTitle(context)),
                          ),
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) => ChargerFirmwareView(
                                        api: widget.api,
                                        companyId: widget.company!['id'] as int,
                                        chargerId: row['id'] as int,
                                        manufacturer: '${row['manufacturer']}',
                                        model: '${row['model']}',
                                        onExpired: widget.onExpired,
                                      )),
                                    ),
                                  )),
                            child: Text(firmwareTitle(context)),
                          ),
                        if (level == 3) ...[
                          if (widget.charger?['source'] == 'direct_unverified' &&
                              ['1.6', '2.0.1'].contains(widget.charger?['ocpp_protocol']))
                            OutlinedButton(
                              onPressed: busy ? null :
                                guarded(() => Navigator.of(context).push(
                                  MaterialPageRoute<void>(
                                    builder: scoped((_) => DirectConnectorReviewView(
                                      api: widget.api,
                                      companyId: widget.company!['id'] as int,
                                      chargerId: widget.charger!['id'] as int,
                                      connectorId: row['id'] as int,
                                      protocol: widget.charger!['ocpp_protocol'] as String,
                                      canReview: capabilities['owner'] == true &&
                                        capabilities['inventory_operational'] == true,
                                      onExpired: widget.onExpired,
                                    )),
                                  ),
                                )),
                              child: Text(Localizations.localeOf(context).languageCode == 'ar'
                                ? 'مراجعة هوية الموصل المباشر' : 'Direct connector identity review'),
                            ),
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : guarded(() => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: scoped((_) => MaintenanceWindowsView(
                                        api: widget.api,
                                        companyId: widget.company!['id'] as int,
                                        chargerId: widget.charger!['id'] as int,
                                        connectorId: row['id'] as int,
                                        canManage: writer,
                                        operational:
                                            capabilities['inventory_operational'] ==
                                            true,
                                        onExpired: widget.onExpired,
                                      )),
                                    ),
                                  )),
                            child: Text(maintenanceTitle(context)),
                          ),
                          Text(
                            '${row['connector_type']} · ${row['max_power_kw']} kW',
                          ),
                          Text(
                            tr(
                              row['maintenance_blocked'] == true
                                  ? 'blocked'
                                  : 'notBlocked',
                            ),
                          ),
                          if ((row['maintenance_reason'] as String?)
                                  ?.isNotEmpty ??
                              false)
                            Text('${row['maintenance_reason']}'),
                          Text(tr('unknown')),
                          if (writer) ...[
                            if (row['maintenance_blocked'] == true &&
                                capabilities['inventory_operational'] != true)
                              Text(tr('approval')),
                            OutlinedButton(
                              onPressed:
                                  busy ||
                                      (row['maintenance_blocked'] == true &&
                                          capabilities['inventory_operational'] !=
                                              true)
                                  ? null
                                  : guarded(() => maintenance(row)),
                              child: Text(
                                tr(
                                  row['maintenance_blocked'] == true
                                      ? 'release'
                                      : 'block',
                                ),
                              ),
                            ),
                          ],
                        ],
                        if (level == 0 &&
                            (row['capabilities'] as Map?)?['company_read'] ==
                                true)
                          TextButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    if (!live()) return;
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: scoped((_) => VendorCompanyView(
                                          api: widget.api,
                                          onExpired: widget.onExpired,
                                          company: row['id'] as int,
                                        )),
                                      ),
                                    );
                                    if (current(rendered) && top) load(page: target);
                                  },
                            child: Text(vendorOpsText(context, 'company')),
                          ),
                        if (level == 0 &&
                            (row['capabilities'] as Map?)?['charging_read'] ==
                                true)
                          sessionObservationsButton(row),
                        if (level < 3 &&
                            (level != 0 ||
                                (row['capabilities']
                                        as Map?)?['inventory_read'] ==
                                    true))
                          FilledButton.tonal(
                            onPressed: busy ? null : guarded(() => open(row)),
                            child: Text(
                              tr(['sites', 'chargers', 'connectors'][level]),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              Text(
                s.t('page', {
                  'page': p.current,
                  'last': p.last,
                  'total': p.total,
                }),
              ),
              Wrap(
                spacing: 12,
                children: [
                  OutlinedButton(
                    onPressed: busy || p.current <= 1
                        ? null
                        : guarded(() => load(page: p.current - 1)),
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: busy || p.current >= p.last
                        ? null
                        : guarded(() => load(page: p.current + 1)),
                    child: Text(s.t('next')),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

String inventoryText(BuildContext context, String key) {
  final ar = Localizations.localeOf(context).languageCode == 'ar';
  return (ar ? inventoryArabic : inventoryEnglish)[key] ?? key;
}

const inventoryEnglish = {
  'draft': 'Draft',
  'submitted': 'Under review',
  'approved': 'Approved',
  'suspended': 'Suspended',
  'changes_requested': 'Changes requested',
  'rejected': 'Rejected',
  'simulator': 'Simulator',
  'discovery': 'Discovery only',
  'direct_unverified': 'Direct connection unverified',
  'partner_unverified': 'Partner connection unverified',
  'companies': 'Company inventory',
  'sites': 'Sites',
  'chargers': 'Chargers',
  'connectors': 'Connectors',
  'connector': 'Connector',
  'disclaimer':
      'Business inventory and maintenance only. Live availability is unknown; these screens do not provision hardware or send charger commands.',
  'block': 'Block new starts',
  'release': 'Remove maintenance block',
  'reason': 'Maintenance reason',
  'consent':
      'This changes business admission for linked simulator inventory. It does not stop an active session or send a hardware command. Record the reason before confirming.',
  'blocked': 'Maintenance block active',
  'notBlocked': 'No maintenance block recorded',
  'unknown': 'Live availability: unknown',
  'approval':
      'Current company approval and license are required to remove a block.',
  'empty': 'No inventory records in this scope.',
  'hidden': 'Private inventory is hidden while the app is inactive.',
  'uncertain':
      'The update may have been saved. Refresh authoritative inventory before submitting again.',
};
const inventoryArabic = {
  'draft': 'مسودة',
  'submitted': 'قيد المراجعة',
  'approved': 'معتمدة',
  'suspended': 'معلّقة',
  'changes_requested': 'تعديلات مطلوبة',
  'rejected': 'مرفوضة',
  'simulator': 'محاكاة',
  'discovery': 'للاستكشاف فقط',
  'direct_unverified': 'اتصال مباشر غير متحقق',
  'partner_unverified': 'اتصال شريك غير متحقق',
  'companies': 'مخزون الشركة',
  'sites': 'المواقع',
  'chargers': 'الشواحن',
  'connectors': 'الموصلات',
  'connector': 'الموصل',
  'disclaimer':
      'مخزون الأعمال والصيانة فقط. التوافر المباشر غير معروف؛ لا تُجهّز هذه الشاشات أجهزة فعلية ولا ترسل أوامر للشواحن.',
  'block': 'حظر جلسات البدء الجديدة',
  'release': 'إزالة حظر الصيانة',
  'reason': 'سبب الصيانة',
  'consent':
      'يغيّر هذا الإجراء قبول الجلسات للمخزون التجريبي المرتبط. لا يوقف جلسة نشطة ولا يرسل أمراً لجهاز فعلي. سجّل السبب قبل التأكيد.',
  'blocked': 'حظر الصيانة مفعّل',
  'notBlocked': 'لا يوجد حظر صيانة مسجّل',
  'unknown': 'التوافر المباشر: غير معروف',
  'approval': 'تتطلب إزالة الحظر موافقة الشركة وصلاحية الرخصة الحالية.',
  'empty': 'لا توجد سجلات مخزون ضمن هذا النطاق.',
  'hidden': 'يُخفى المخزون الخاص عندما يكون التطبيق غير نشط.',
  'uncertain': 'ربما حُفظ التحديث. حدّث المخزون المعتمد قبل إعادة الإرسال.',
};
