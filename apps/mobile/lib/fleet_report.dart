import 'package:flutter/material.dart';
import 'api.dart';

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
      final data = await widget.api.request(
        'fleets/${widget.fleetId}/report',
        query: filters,
      );
      if (current(n)) setState(() => report = Map<String, dynamic>.from(data));
    } catch (e) {
      if (current(n)) {
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
        if (current(n))
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
      return '"${RegExp(r'^[\s\uFEFF]*[=+@-]').hasMatch(s) ? "'" : ''}${s.replaceAll('"', '""')}"';
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
              enabled: !busy,
              decoration: InputDecoration(labelText: pair.$2),
            ),
          FilledButton(
            onPressed: busy ? null : load,
            child: Text(t('Read report', 'قراءة التقرير')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(error!),
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
              onPressed: () => setState(() => csv = !csv),
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
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    from.dispose();
    through.dispose();
    center.dispose();
    super.dispose();
  }
}
