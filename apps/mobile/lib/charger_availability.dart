import 'package:flutter/material.dart';

import 'api.dart';
import 'strings.dart';

String availabilityCommandTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'أوامر إتاحة الشاحن'
    : 'Charger availability commands';

class ChargerAvailabilityView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final VoidCallback onExpired;

  const ChargerAvailabilityView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
  });

  @override
  State<ChargerAvailabilityView> createState() => _ChargerAvailabilityState();
}

class _ChargerAvailabilityState extends State<ChargerAvailabilityView>
    with WidgetsBindingObserver {
  final reason = TextEditingController();
  late bool Function() authorized;
  int epoch = 0;
  bool foreground = true, busy = false, canRequest = false;
  ApiPage<Map<String, dynamic>>? operations;
  List<Map<String, dynamic>> connectors = [];
  Map<String, dynamic>? pending;
  Object? error;
  int target = 0;
  String requestedState = 'Inoperative';

  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/availability-operations';

  String t(String english, String arabic) =>
      Localizations.localeOf(context).languageCode == 'ar' ? arabic : english;

  bool current(int generation) =>
      mounted && foreground && authorized() && generation == epoch;

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  void clear() {
    epoch++;
    reason.clear();
    operations = null;
    connectors = [];
    pending = null;
    error = null;
    target = 0;
    busy = false;
    canRequest = false;
  }

  @override
  void didUpdateWidget(covariant ChargerAvailabilityView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.companyId != widget.companyId ||
        oldWidget.chargerId != widget.chargerId) {
      clear();
      authorized = widget.api.sessionFence();
      load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground) {
      setState(clear);
    } else {
      load();
    }
  }

  @override
  void dispose() {
    epoch++;
    WidgetsBinding.instance.removeObserver(this);
    reason.dispose();
    super.dispose();
  }

  Future<void> load([int page = 1]) => run((_) => read(page));

  Future<void> read(int page) async {
    final generation = epoch;
    final result = await widget.api.request(path, query: {'page': '$page'});
    if (!current(generation)) return;
    setState(() {
      operations = ApiPage<Map<String, dynamic>>.fromJson(result, (row) => row);
      canRequest = result['can_request'] == true;
      connectors = (result['connectors'] as List? ?? [])
          .whereType<Map<String, dynamic>>()
          .where((row) => row['id'] is int && row['connector_number'] is int)
          .toList();
      if (!connectors.any((row) => row['id'] == target)) target = 0;
    });
  }

  Future<void> request() async {
    if (!canRequest || (pending == null && reason.text.trim().length < 10))
      return;
    await run((generation) async {
      pending ??= {
        'connector_id': target == 0 ? null : target,
        'requested_state': requestedState,
        'reason': reason.text.trim(),
        'idempotency_key': operationKey(),
      };
      try {
        await widget.api.request(path, method: 'POST', body: {...pending!});
      } on ApiException catch (e) {
        if (current(generation) && [409, 422].contains(e.status))
          pending = null;
        rethrow;
      }
      if (!current(generation)) return;
      pending = null;
      reason.clear();
      await read(1);
    });
  }

  Future<void> observe(int id) => run((generation) async {
    await widget.api.request('$path/$id/observe', method: 'POST', body: {});
    if (current(generation)) await read(operations?.current ?? 1);
  });

  Future<void> run(Future<void> Function(int generation) action) async {
    if (!foreground || !authorized() || busy) return;
    final generation = ++epoch;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await action(generation);
    } catch (e) {
      if (current(generation)) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
        }
      }
    } finally {
      if (current(generation)) setState(() => busy = false);
    }
  }

  String status(Object? value) => switch (value) {
    'pending' || 'dispatching' => t('Pending', 'قيد الانتظار'),
    'transport_submitted' => t(
      'Awaiting simulator response',
      'بانتظار رد المحاكي',
    ),
    'accepted' => t('Simulator accepted', 'قبله المحاكي'),
    'rejected' => t('Simulator rejected', 'رفضه المحاكي'),
    'scheduled' => t('Simulator scheduled', 'جدوله المحاكي'),
    'not_dispatched' => t('Command was not sent', 'لم يُرسل الأمر'),
    _ => t('Outcome unknown', 'النتيجة غير معروفة'),
  };

  @override
  Widget build(BuildContext context) {
    final page = operations;
    final locked = busy || pending != null;

    return Scaffold(
      appBar: AppBar(title: Text(availabilityCommandTitle(context))),
      body: !foreground || !authorized()
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  t(
                    'Simulator responses do not verify physical charger availability or change station notices.',
                    'لا تؤكد ردود المحاكي إتاحة الشاحن الفعلية ولا تغيّر إشعارات المحطة.',
                  ),
                ),
                if (busy) const LinearProgressIndicator(),
                if (error != null)
                  Text(EvStrings.of(context).t(errorKey(error!))),
                TextButton(
                  onPressed: busy ? null : () => load(page?.current ?? 1),
                  child: Text(t('Refresh', 'تحديث')),
                ),
                if (canRequest) ...[
                  DropdownButton<int>(
                    value: target,
                    isExpanded: true,
                    items: [
                      DropdownMenuItem(
                        value: 0,
                        child: Text(t('Entire charger', 'الشاحن بالكامل')),
                      ),
                      for (final row in connectors)
                        DropdownMenuItem(
                          value: row['id'] as int,
                          child: Text(
                            t(
                              'Connector ${row['connector_number']}',
                              'الموصل ${row['connector_number']}',
                            ),
                          ),
                        ),
                    ],
                    onChanged: locked
                        ? null
                        : (value) => setState(() => target = value ?? 0),
                  ),
                  DropdownButton<String>(
                    value: requestedState,
                    isExpanded: true,
                    items: [
                      DropdownMenuItem(
                        value: 'Inoperative',
                        child: Text(t('Inoperative', 'إيقاف')),
                      ),
                      DropdownMenuItem(
                        value: 'Operative',
                        child: Text(t('Operative', 'تشغيل')),
                      ),
                    ],
                    onChanged: locked
                        ? null
                        : (value) => setState(
                            () => requestedState = value ?? 'Inoperative',
                          ),
                  ),
                  TextField(
                    controller: reason,
                    enabled: !locked,
                    maxLength: 500,
                    decoration: InputDecoration(
                      labelText: t('Reason', 'السبب'),
                      helperText: t(
                        'At least 10 characters',
                        '10 أحرف على الأقل',
                      ),
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  FilledButton(
                    onPressed:
                        busy ||
                            (pending == null && reason.text.trim().length < 10)
                        ? null
                        : request,
                    child: Text(
                      pending == null
                          ? t(
                              'Request simulated availability change',
                              'طلب تغيير الإتاحة في المحاكي',
                            )
                          : t('Retry saved request', 'إعادة الطلب المحفوظ'),
                    ),
                  ),
                ],
                for (final row in page?.items ?? <Map<String, dynamic>>[])
                  if (row['evidence'] == 'SIMULATED_SOURCE' &&
                      row['physical_availability_verified'] == false)
                    ListTile(
                      title: Text('#${row['id']} · ${status(row['status'])}'),
                      subtitle: Text('${row['created_at']}'),
                      trailing: row['can_observe'] == true && row['id'] is int
                          ? TextButton(
                              onPressed: busy
                                  ? null
                                  : () => observe(row['id'] as int),
                              child: Text(t('Check response', 'تحقق من الرد')),
                            )
                          : null,
                    ),
                if (page != null && page.current > 1)
                  TextButton(
                    onPressed: busy ? null : () => load(page.current - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (page != null && page.current < page.last)
                  TextButton(
                    onPressed: busy ? null : () => load(page.current + 1),
                    child: Text(t('Next', 'التالي')),
                  ),
              ],
            ),
    );
  }
}
