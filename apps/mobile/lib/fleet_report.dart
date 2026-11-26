import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';

Map<String, dynamic> verifyFleetReport(dynamic raw, int fleetId, Map<String, String> filters) {
  bool count(dynamic v) => v is int && v >= 0 && v <= 9007199254740991;
  if (!count(fleetId) || fleetId == 0 || raw is! Map ||
      raw['basis'] != 'approved_claims_submitted_in_period' ||
      raw['physical_charging_verified'] != false || raw['fleet'] is! Map ||
      !count(raw['fleet']['id']) || raw['fleet']['id'] != fleetId ||
      raw['fleet']['name'] is! String ||
      raw['period'] is! Map || raw['period']['from'] != filters['from'] ||
      raw['period']['through'] != filters['through'] ||
      (filters.containsKey('cost_center_id') &&
        (!count(raw['period']['cost_center_id']) ||
          raw['period']['cost_center_id'] == 0 ||
          raw['period']['cost_center_id'] != int.tryParse(filters['cost_center_id']!))) ||
      (!filters.containsKey('cost_center_id') && raw['period']['cost_center_id'] != null) ||
      raw['period']['timezone'] is! String || raw['as_of'] is! String ||
      DateTime.tryParse(raw['as_of']) == null || raw['current_inventory'] is! Map ||
      !['active_members','invited_members','assigned_vehicles','cost_centers'].every(
        (key) => count(raw['current_inventory'][key])) ||
      !count(raw['approved_claims']) || raw['approved_claims'] > 2500 ||
      raw['groups'] is! List || raw['groups'].length > 2500) {
    throw const FormatException('Invalid fleet report scope.');
  }
  final keys = <(int,String,String,String,int)>{};
  var claims = 0;
  for (final g in raw['groups']) {
    if (g is! Map || !count(g['cost_center_id']) || g['cost_center_id'] == 0 ||
        (filters.containsKey('cost_center_id') &&
          g['cost_center_id'] != int.tryParse(filters['cost_center_id']!)) ||
        g['code'] is! String || g['name'] is! String || g['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(g['currency']) ||
        g['minor_units'] is! int || g['minor_units'] < 0 || g['minor_units'] > 3 ||
        g['amount_minor'] is! String || !RegExp(r'^(0|[1-9][0-9]{0,22})$').hasMatch(g['amount_minor']) ||
        !count(g['claims']) || g['claims'] == 0 ||
        g['claims'] > raw['approved_claims'] - claims ||
        !keys.add((g['cost_center_id'],g['code'],g['name'],g['currency'],g['minor_units']))) {
      throw const FormatException('Invalid fleet report group.');
    }
    claims += g['claims'] as int;
  }
  if (claims != raw['approved_claims']) throw const FormatException('Invalid fleet report total.');
  // Only retain the validated fields consumed by the report and its CSV.
  // Transport maps may be mutable and may include unrelated private fields.
  final fleet = raw['fleet'] as Map;
  final period = raw['period'] as Map;
  final inventory = raw['current_inventory'] as Map;
  return Map<String, dynamic>.unmodifiable({
    'as_of': raw['as_of'],
    'fleet': Map<String, dynamic>.unmodifiable({
      'id': fleet['id'], 'name': fleet['name'],
    }),
    'basis': raw['basis'],
    'physical_charging_verified': false,
    'period': Map<String, dynamic>.unmodifiable({
      for (final k in ['from', 'through', 'timezone', 'cost_center_id'])
        k: period[k],
    }),
    'current_inventory': Map<String, dynamic>.unmodifiable({
      for (final k in ['active_members', 'invited_members', 'assigned_vehicles', 'cost_centers'])
        k: inventory[k],
    }),
    'approved_claims': raw['approved_claims'],
    'groups': List<Map<String, dynamic>>.unmodifiable([
      for (final g in raw['groups'] as List)
        Map<String, dynamic>.unmodifiable({
          for (final k in ['cost_center_id', 'code', 'name', 'currency', 'minor_units', 'claims', 'amount_minor'])
            k: g[k],
        }),
    ]),
  });
}

class FleetReportView extends StatefulWidget {
  final EvApi api;
  final int fleetId;
  final VoidCallback onExpired;
  const FleetReportView({
    super.key,
    required this.api,
    required this.fleetId,
    required this.onExpired,
  });
  @override
  State<FleetReportView> createState() => _FleetReportState();
}

class _FleetReportState extends State<FleetReportView>
    with WidgetsBindingObserver {
  final from = TextEditingController(),
      through = TextEditingController(),
      center = TextEditingController();
  late bool Function() authorized;
  StreamSubscription<int>? sessionEvents;
  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted || !identical(api, widget.api)) return;
      setState(() {
        clear();
        // Do not silently authorize a former fleet screen for a new account.
        authorized = () => false;
      });
    });
  }

  Map<String, dynamic>? report;
  int generation = 0;
  bool busy = false, foreground = true, csv = false;
  String? error;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int n) =>
      mounted && foreground && authorized() && generation == n;
  void clear() {
    generation++;
    report = null;
    error = null;
    busy = false;
    csv = false;
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    observe(widget.api);
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    final today = DateTime.now().toUtc().toIso8601String().substring(0, 10);
    from.text = '${today.substring(0, 8)}01';
    through.text = today;
  }

  @override
  void didUpdateWidget(covariant FleetReportView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.fleetId != widget.fleetId) {
      clear();
      if (!identical(oldWidget.api, widget.api)) observe(widget.api);
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted) setState(() { if (!foreground) clear(); });
  }

  Future<void> load() async {
    if (busy || !foreground || !authorized()) return;
    final n = ++generation;
    final api = widget.api;
    final fleetId = widget.fleetId;
    setState(() {
      report = null;
      csv = false;
      error = null;
      busy = true;
    });
    final filters = {
      'from': from.text.trim(),
      'through': through.text.trim(),
      if (center.text.trim().isNotEmpty) 'cost_center_id': center.text.trim(),
    };
    try {
      final data = await api.request(
        'fleets/$fleetId/report',
        query: filters,
      );
      if (current(n)) {
        final verified = verifyFleetReport(data, fleetId, filters);
        setState(() => report = verified);
      }
    } catch (e) {
      if (mounted && identical(api, widget.api) && e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
        return;
      }
      if (current(n)) {
          setState(
            () => error = t(
              'Report unavailable. Check dates, access and cost center.',
              'التقرير غير متاح. تحقق من التواريخ والصلاحية ومركز التكلفة.',
            ),
          );
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

  String amount(Map g) {
    final digits = g['minor_units'] as int;
    final value = '${g['amount_minor']}'.padLeft(digits + 1, '0');
    return digits == 0
        ? value
        : '${value.substring(0, value.length - digits)}.${value.substring(value.length - digits)}';
  }

  String export(Map d) {
    String cell(Object? v) {
      final s = '$v';
      return '"${RegExp(r'^[\p{Z}\p{Cc}\p{Cf}]*[=+@-]', unicode: true).hasMatch(s) ? "'" : ''}${s.replaceAll('"', '""')}"';
    }

    final p = d['period'] as Map;
    final rows = <List<Object?>>[
      ['Fleet', d['fleet']['name'], d['as_of']],
      ['Approved claims submitted', p['from'], p['through'], p['timezone']],
      [
        'Recorded claims only; not balances, payment or physical charging proof',
      ],
      for (final e in (d['current_inventory'] as Map).entries) [e.key, e.value],
      [
        'Cost center',
        'Historic name',
        'Currency',
        'Minor units',
        'Claims',
        'Amount minor',
      ],
      for (final g in d['groups'])
        [
          g['code'],
          g['name'],
          g['currency'],
          g['minor_units'],
          g['claims'],
          g['amount_minor'],
        ],
    ];
    return rows.map((r) => r.map(cell).join(',')).join('\r\n');
  }

  @override
  Widget build(BuildContext context) {
    final d = authorized() && foreground ? report : null;
    final labels = {
      'active_members': t('Active members', 'الأعضاء النشطون'),
      'invited_members': t('Pending invitations', 'الدعوات المعلقة'),
      'assigned_vehicles': t('Assigned vehicles', 'المركبات المسندة'),
      'cost_centers': t('Cost centers', 'مراكز التكلفة'),
    };
    return Scaffold(
      appBar: AppBar(title: Text(t('Fleet report', 'تقرير الأسطول'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Only approved claims explicitly shared with the fleet. Recorded amounts are not balances, payments, refunds or proof of physical charging. Inventory counts are current across all cost centers. Dates use the fleet timezone.',
              'المطالبات المعتمدة التي تمت مشاركتها صراحة مع الأسطول فقط. المبالغ المسجلة ليست أرصدة أو مدفوعات أو مبالغ مستردة أو دليلاً على الشحن الفعلي. أعداد المخزون حالية لجميع مراكز التكلفة. التواريخ حسب منطقة الأسطول الزمنية.',
            ),
          ),
          for (final pair in [
            (
              from,
              t(
                'From local date (YYYY-MM-DD)',
                'من التاريخ المحلي (YYYY-MM-DD)',
              ),
            ),
            (
              through,
              t(
                'Through local date (YYYY-MM-DD)',
                'حتى التاريخ المحلي (YYYY-MM-DD)',
              ),
            ),
            (
              center,
              t('Cost center ID (optional)', 'معرف مركز التكلفة (اختياري)'),
            ),
          ])
            TextField(
              controller: pair.$1,
              enabled: !busy && foreground && authorized(),
              onChanged: (_) => setState(clear),
              decoration: InputDecoration(labelText: pair.$2),
            ),
          FilledButton(
            onPressed: busy || !foreground || !authorized() ? null : load,
            child: Text(t('Read report', 'قراءة التقرير')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null && foreground && authorized()) Text(error!),
          if (d != null) ...[
            Text('${d['fleet']['name']}'),
            Text(
              '${d['period']['from']} — ${d['period']['through']} · ${d['period']['timezone']} · ${d['as_of']}',
            ),
            for (final key in labels.keys)
              Text('${labels[key]}: ${d['current_inventory'][key]}'),
            Text(
              t(
                'Approved claims: ${d['approved_claims']}',
                'المطالبات المعتمدة: ${d['approved_claims']}',
              ),
            ),
            for (final g in d['groups'])
              Text(
                '${g['code']} · ${g['name']} · ${g['currency']} ${amount(g)} · ${g['claims']}',
              ),
            TextButton(
              onPressed: () {
                if (!foreground || !authorized() || !identical(report, d)) return;
                setState(() => csv = !csv);
              },
              child: Text(
                t('View private CSV snapshot', 'عرض لقطة CSV الخاصة'),
              ),
            ),
            if (csv)
              SelectableText(export(d), key: const Key('fleet-report-csv')),
          ],
        ],
      ),
    );
  }

  @override
  void dispose() {
    sessionEvents?.cancel();
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    from.dispose();
    through.dispose();
    center.dispose();
    super.dispose();
  }
}
