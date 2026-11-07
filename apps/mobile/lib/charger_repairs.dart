import 'package:flutter/material.dart';
import 'api.dart';
import 'charger_logs.dart';
import 'strings.dart';

class ChargerRepairsView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final int? initialAlertId, initialOrderId;
  final VoidCallback onExpired;
  const ChargerRepairsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
    this.initialAlertId,
    this.initialOrderId,
  });
  @override
  State<ChargerRepairsView> createState() => _RepairsState();
}

class _RepairsState extends State<ChargerRepairsView> {
  Map<String, dynamic>? data, pending;
  Object? error;
  bool busy = false;
  int generation = 0, page = 1;
  bool initialFocusPending = true;
  final alert = TextEditingController();
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/repair-orders';
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String stateLabel(String state) => switch (state) {
    'open' => tr('Repair open', 'الإصلاح مفتوح'),
    'in_progress' => tr('Repair in progress', 'الإصلاح قيد التنفيذ'),
    'review' => tr('Repair awaiting review', 'الإصلاح بانتظار المراجعة'),
    'closed' => tr('Repair review closed', 'أُغلقت مراجعة الإصلاح'),
    _ => tr('Repair state unavailable', 'حالة الإصلاح غير متاحة'),
  };
  @override
  void initState() {
    super.initState();
    alert.text = widget.initialAlertId?.toString() ?? "";
    load();
  }

  @override
  void didUpdateWidget(covariant ChargerRepairsView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api ||
        old.companyId != widget.companyId ||
        old.chargerId != widget.chargerId ||
        old.initialOrderId != widget.initialOrderId ||
        old.initialAlertId != widget.initialAlertId) {
      generation++;
      data = null;
      pending = null;
      alert.clear();
      page = 1;
      initialFocusPending = true;
      load();
    }
  }

  @override
  void dispose() {
    generation++;
    alert.dispose();
    super.dispose();
  }

  void failed(Object e) {
    error = e;
    if (e is ApiException && [401, 403, 404, 419].contains(e.status)) {
      data = null;
      pending = null;
      alert.clear();
      if ([401, 419].contains(e.status)) widget.onExpired();
    }
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final focusId = initialFocusPending ? widget.initialOrderId : null;
      final result = await widget.api.request(path, query: {
        'page': '$page',
        if (focusId != null) 'focus_order_id': '$focusId',
      });
      if (!mounted || epoch != generation) return;
      if (focusId != null) {
        final focused = result['focus_page'];
        if (focused is! int || focused < 1 || result['current_page'] != focused) {
          throw const FormatException('Repair order focus not confirmed');
        }
        page = focused;
      }
      initialFocusPending = false;
      if (result['commands_sent'] != false ||
          result['capacity_changed'] != false ||
          result['physical_repair_verified'] != false ||
          result['can_manage'] is! bool ||
          result['data'] is! List)
        throw const FormatException('Unexpected repair capability');
      setState(() => data = Map<String, dynamic>.from(result));
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<String?> note() async {
    return showDialog<String>(
      context: context,
      builder: (_) =>
          _RepairNote(ar: Localizations.localeOf(context).languageCode == 'ar'),
    );
  }

  Future<void> change(int alertId, int version, String state) async {
    if (busy || pending != null || data?['can_manage'] != true) return;
    final epoch = generation;
    final text = await note();
    if (!mounted ||
        epoch != generation ||
        text == null ||
        busy ||
        pending != null)
      return;
    pending = {
      'request_key': operationKey(),
      'alert_id': alertId,
      'expected_version': version,
      'state': state,
      'note': text,
    };
    await send();
  }

  Future<void> send() async {
    if (busy || pending == null || data?['can_manage'] != true) return;
    final epoch = ++generation;
    final intent = Map<String, dynamic>.from(pending!);
    setState(() => busy = true);
    try {
      await widget.api.request(path, method: 'POST', body: intent);
      if (!mounted || epoch != generation) return;
      pending = null;
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) {
        setState(() => busy = false);
        await load();
      }
    }
  }

  Future<void> history(int id) async {
    final epoch = generation;
    setState(() => busy = true);
    try {
      final result = await widget.api.request(
        '$path/$id/history',
        query: {'page': '1'},
      );
      if (!mounted || epoch != generation) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => _RepairHistory(
            api: widget.api,
            path: '$path/$id/history',
            initial: Map<String, dynamic>.from(result),
            onExpired: widget.onExpired,
          ),
        ),
      );
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final manage = data?['can_manage'] == true && !busy && pending == null;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Repair work orders', 'أوامر الإصلاح'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            tr(
              'Repair documentation does not release charging capacity or verify physical repair.',
              'توثيق الإصلاح لا يحرر سعة الشحن ولا يثبت الإصلاح الفعلي.',
            ),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          TextButton(
            onPressed: busy ? null : load,
            child: Text(tr('Refresh', 'تحديث')),
          ),
          if (pending != null) ...[
            Text(
              tr(
                'Repair update uncertain. Resolve the original request before another update.',
                'تحديث الإصلاح غير مؤكد. تحقّق من الطلب الأصلي قبل تحديث آخر.',
              ),
            ),
            TextButton(
              onPressed: busy || data?['can_manage'] != true ? null : send,
              child: Text(
                tr(
                  'Resolve original repair update',
                  'تحقق من تحديث الإصلاح الأصلي',
                ),
              ),
            ),
            TextButton(
              onPressed: busy || data == null
                  ? null
                  : () => setState(() => pending = null),
              child: Text(
                tr(
                  'Clear pending form after reviewing repair history',
                  'مسح النموذج المعلق بعد مراجعة سجل الإصلاح',
                ),
              ),
            ),
          ],
          if (manage) ...[
            TextField(
              controller: alert,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: tr('Fault alert number', 'رقم تنبيه العطل'),
              ),
            ),
            TextButton(
              onPressed: () {
                final id = int.tryParse(alert.text);
                if (id != null && id > 0) change(id, 0, 'open');
              },
              child: Text(tr('Open repair work order', 'فتح أمر إصلاح')),
            ),
          ],
          for (final row in data?['data'] as List? ?? [])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('#${row['id']} · ${row['alert_id']}'),
                    Text(stateLabel(row['state'] as String)),
                    Text(
                      row['recovery_observation_id'] != null
                          ? tr(
                              'Source recovery observed',
                              'تم رصد تعافي المصدر',
                            )
                          : tr(
                              'Source recovery not observed',
                              'لم يُرصد تعافي المصدر',
                            ),
                    ),
                    TextButton(
                      onPressed: busy ? null : () => history(row['id'] as int),
                      child: Text(tr('Repair history', 'سجل الإصلاح')),
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
                                  repairOrderId: row['id'] as int,
                                  onExpired: widget.onExpired,
                                ),
                              ),
                            ),
                      child: Text(tr('Diagnostic files', 'ملفات التشخيص')),
                    ),
                    if (manage)
                      for (final target in switch (row['state']) {
                        'open' => ['in_progress'],
                        'in_progress' => ['review', 'open'],
                        'review' => [
                          'in_progress',
                          if (row['recovery_observation_id'] != null) 'closed',
                        ],
                        'closed' => ['open'],
                        _ => <String>[],
                      })
                        TextButton(
                          onPressed: () => change(
                            row['alert_id'] as int,
                            row['version'] as int,
                            target,
                          ),
                          child: Text(stateLabel(target)),
                        ),
                  ],
                ),
              ),
            ),
          if (data != null)
            Row(
              children: [
                TextButton(
                  onPressed: busy || page <= 1
                      ? null
                      : () {
                          page--;
                          load();
                        },
                  child: Text(tr('Previous', 'السابق')),
                ),
                Text('$page / ${data!['last_page']}'),
                TextButton(
                  onPressed: busy || page >= (data!['last_page'] as int)
                      ? null
                      : () {
                          page++;
                          load();
                        },
                  child: Text(tr('Next', 'التالي')),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

class _RepairHistory extends StatefulWidget {
  final EvApi api;
  final String path;
  final Map<String, dynamic> initial;
  final VoidCallback onExpired;
  const _RepairHistory({
    required this.api,
    required this.path,
    required this.initial,
    required this.onExpired,
  });
  @override
  State<_RepairHistory> createState() => _HistoryState();
}

class _HistoryState extends State<_RepairHistory> {
  late Map<String, dynamic> data = widget.initial;
  bool busy = false;
  Object? error;
  Future<void> load(int page) async {
    setState(() {
      busy = true;
      data = {};
      error = null;
    });
    try {
      final value = await widget.api.request(
        widget.path,
        query: {'page': '$page'},
      );
      if (mounted) setState(() => data = Map<String, dynamic>.from(value));
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
    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'سجل الإصلاح' : 'Repair history')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          for (final row in data['data'] as List? ?? []) ...[
            Text('#${row['version']} · ${row['created_at']}'),
            Text(row['note'] as String),
          ],
          if (data['current_page'] != null)
            Row(
              children: [
                TextButton(
                  onPressed: busy || data['current_page'] <= 1
                      ? null
                      : () => load((data['current_page'] as int) - 1),
                  child: Text(ar ? 'السابق' : 'Previous'),
                ),
                TextButton(
                  onPressed: busy || data['current_page'] >= data['last_page']
                      ? null
                      : () => load((data['current_page'] as int) + 1),
                  child: Text(ar ? 'التالي' : 'Next'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

class _RepairNote extends StatefulWidget {
  final bool ar;
  const _RepairNote({required this.ar});
  @override
  State<_RepairNote> createState() => _RepairNoteState();
}

class _RepairNoteState extends State<_RepairNote> {
  final input = TextEditingController();
  @override
  void dispose() {
    input.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.ar ? 'ملاحظة الإصلاح' : 'Repair note'),
    content: TextField(
      controller: input,
      maxLength: 2000,
      minLines: 2,
      maxLines: 5,
      onChanged: (_) => setState(() {}),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text(widget.ar ? 'إلغاء' : 'Cancel'),
      ),
      TextButton(
        onPressed: input.text.trim().length < 10
            ? null
            : () => Navigator.pop(context, input.text.trim()),
        child: Text(widget.ar ? 'حفظ' : 'Save'),
      ),
    ],
  );
}
