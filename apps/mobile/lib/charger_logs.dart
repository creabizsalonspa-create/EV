import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:crypto/crypto.dart';
import 'api.dart';
import 'strings.dart';
import 'reservation_times.dart' show serverEventTime;

Map<String, dynamic> parseDiagnosticLogPage(dynamic value) {
  if (value is! Map ||
      value['can_request'] is! bool ||
      value['can_manage'] is! bool ||
      value['maximum_file_bytes'] != 524288 ||
      value['upload_lifetime_seconds'] != 900 ||
      value['file_retention_seconds'] != 86400 ||
      ![
        'SIMULATED_SOURCE',
        'AUTHENTICATED_SOURCE',
      ].contains(value['evidence']) ||
      value['charging_capacity_changed'] != false ||
      value['data'] is! List)
    throw const FormatException('Unexpected log capability');
  for (final row in value['data']) {
    if (row is! Map ||
        row['id'] is! int ||
        row['id'] <= 0 ||
        row['id'] > 9007199254740991 ||
        !['1.6', '2.0.1'].contains(row['protocol']) ||
        ![
          'SIMULATED_SOURCE',
          'AUTHENTICATED_SOURCE',
        ].contains(row['evidence']) ||
        row['physical_device_verified'] != false ||
        row['can_download'] is! bool)
      throw const FormatException('Invalid log receipt');
  }
  return Map<String, dynamic>.from(value);
}

class ChargerLogsView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final int? repairOrderId;
  final VoidCallback onExpired;
  const ChargerLogsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
    this.repairOrderId,
  });
  @override
  State<ChargerLogsView> createState() => _LogState();
}

class _LogState extends State<ChargerLogsView> with WidgetsBindingObserver {
  Map<String, dynamic>? data, pending;
  Object? error;
  bool busy = false, foreground = true;
  late bool Function() owner;
  bool current(int epoch) =>
      mounted && foreground && epoch == generation && owner();
  bool acceptsFailure(Object e, int epoch) =>
      mounted &&
      foreground &&
      epoch == generation &&
      (owner() || e is ApiException && e.endsCurrentSession);
  bool ownsRow(Map row) {
    final rows = data?['data'];
    return rows is List && rows.any((item) => identical(item, row));
  }

  int generation = 0, page = 1;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/diagnostic-logs';
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    owner = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void dispose() {
    generation++;
    data = null;
    pending = null;
    error = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant ChargerLogsView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api ||
        old.companyId != widget.companyId ||
        old.chargerId != widget.chargerId ||
        old.repairOrderId != widget.repairOrderId) {
      generation++;
      owner = widget.api.sessionFence();
      data = null;
      pending = null;
      page = 1;
      load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    generation++;
    if (mounted)
      setState(() {
        foreground = state == AppLifecycleState.resumed;
        data = null;
        busy = false;
        error = null;
      });
  }

  void failed(Object e) {
    busy = false;
    error = e;
    if (e is ApiException && [401, 403, 404, 409, 419].contains(e.status)) {
      data = null;
      if (e.status != 409) pending = null;
      if ([401, 419].contains(e.status)) widget.onExpired();
    }
  }

  Future<void> load() async {
    if (!foreground || !owner()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final v = parseDiagnosticLogPage(
        await widget.api.request(
          path,
          query: {
            'page': '$page',
            if (widget.repairOrderId != null)
              'repair_order_id': '${widget.repairOrderId}',
          },
        ),
      );
      if (current(epoch)) setState(() => data = v);
    } catch (e) {
      if (acceptsFailure(e, epoch)) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> create() async {
    if (busy ||
        !foreground ||
        !owner() ||
        pending != null ||
        data?['can_request'] != true)
      return;
    final epoch = generation;
    final note = await showDialog<String>(
      context: context,
      builder: (_) =>
          _LogReason(ar: Localizations.localeOf(context).languageCode == 'ar'),
    );
    if (!current(epoch) || note == null || busy || pending != null) return;
    pending = {
      'request_key': operationKey(),
      'reason': note,
      if (widget.repairOrderId != null) 'repair_order_id': widget.repairOrderId,
    };
    await send();
  }

  Future<void> send() async {
    if (busy ||
        !foreground ||
        !owner() ||
        pending == null ||
        data?['can_request'] != true)
      return;
    final epoch = ++generation;
    final intent = Map<String, dynamic>.from(pending!);
    setState(() => busy = true);
    try {
      await widget.api.request(path, method: 'POST', body: intent);
      if (!current(epoch)) return;
      pending = null;
    } catch (e) {
      if (acceptsFailure(e, epoch)) setState(() => failed(e));
    } finally {
      if (current(epoch)) {
        setState(() => busy = false);
        await load();
      }
    }
  }

  Future<void> act(Map row, bool cancel) async {
    if (busy ||
        !foreground ||
        !owner() ||
        !ownsRow(row) ||
        data?['can_manage'] != true)
      return;
    final beforeDialog = generation;
    if (cancel) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (dialog) => AlertDialog(
          title: Text(
            tr(
              'Revoke upload and remove stored file?',
              'إلغاء صلاحية الرفع وحذف الملف المخزن؟',
            ),
          ),
          content: Text(
            tr(
              'The device may still finish its original request. No device cancel or charging command is sent.',
              'قد يستكمل الجهاز طلبه الأصلي. لا يُرسل أمر إلغاء للجهاز أو للشحن.',
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialog, false),
              child: Text(tr('Back', 'رجوع')),
            ),
            TextButton(
              onPressed: () => Navigator.pop(dialog, true),
              child: Text(tr('Revoke', 'إلغاء الصلاحية')),
            ),
          ],
        ),
      );
      if (!current(beforeDialog) || ok != true) return;
    }
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      await widget.api.request(
        '$path/${row['id']}${cancel ? '' : '/observe'}',
        method: cancel ? 'DELETE' : 'POST',
        body: cancel ? null : {},
      );
      if (current(epoch)) {
        setState(() => busy = false);
        await load();
      }
    } catch (e) {
      if (acceptsFailure(e, epoch)) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> save(Map row) async {
    if (busy ||
        !foreground ||
        !owner() ||
        !ownsRow(row) ||
        row['can_download'] != true)
      return;
    final expected = row['file_sha256'];
    final size = row['file_size'];
    if (expected is! String ||
        !RegExp(r'^[a-f0-9]{64}$').hasMatch(expected) ||
        size is! int ||
        size < 1 ||
        size > 524288)
      return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      final doc = await widget.api.downloadDiagnosticLog(
        '$path/${row['id']}/download',
      );
      if (doc.mime != 'application/octet-stream' ||
          doc.bytes.length != size ||
          sha256.convert(doc.bytes).toString() != expected)
        throw const FormatException('Diagnostic integrity mismatch');
      if (!current(epoch)) return;
      await FilePicker.platform.saveFile(
        dialogTitle: tr(
          'Save private diagnostic file',
          'حفظ ملف التشخيص الخاص',
        ),
        fileName: 'charger-log-${row['id']}.bin',
        type: FileType.custom,
        allowedExtensions: ['bin'],
        bytes: doc.bytes,
      );
    } catch (e) {
      if (acceptsFailure(e, epoch)) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  String stateLabel(dynamic value) => switch (value) {
    'requested' => tr('Request recorded', 'تم تسجيل الطلب'),
    'dispatching' => tr('Delivery outcome pending', 'بانتظار نتيجة الإرسال'),
    'transport_submitted' => tr(
      'Source transport submitted',
      'تم إرسال الطلب للمصدر',
    ),
    'not_dispatched' => tr('Not dispatched', 'لم يُرسل'),
    'outcome_unknown' => tr(
      'Delivery outcome unknown',
      'نتيجة الإرسال غير معلومة',
    ),
    'device_rejected' => tr('Device rejected request', 'رفض الجهاز الطلب'),
    _ => tr('Source review required', 'يلزم فحص المصدر'),
  };
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(tr('Diagnostic files', 'ملفات التشخيص'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          tr(
            'Private diagnostic files. Each record identifies simulated or authenticated-source evidence; neither proves physical hardware or changes charging capacity. Uploads expire after 15 minutes; stored content after 24 hours. OCPP 1.6 upload-status notifications remain unattributed. Exports are saved under your device control.',
            'ملفات تشخيص خاصة. يحدد كل سجل دليلاً من محاكاة أو مصدر مصادق؛ ولا يثبت أي منهما الأجهزة الفعلية أو يغير سعة الشحن. تنتهي صلاحية الرفع بعد ١٥ دقيقة والمحتوى بعد ٢٤ ساعة. تبقى إشعارات رفع OCPP 1.6 غير منسوبة. تُحفظ الملفات المصدرة تحت تحكم جهازك.',
          ),
        ),
        if (!foreground || !owner())
          Text(
            tr(
              'Private diagnostic history hidden. Return to your inventory to continue.',
              'سجل التشخيص الخاص مخفي. ارجع إلى المخزون للمتابعة.',
            ),
          )
        else ...[
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          TextButton(
            onPressed: busy ? null : load,
            child: Text(tr('Refresh diagnostic history', 'تحديث سجل التشخيص')),
          ),
          if (pending != null) ...[
            Text(
              tr(
                'Request outcome unknown. Refresh history or resolve the original identity; no automatic redispatch.',
                'نتيجة الطلب غير معلومة. حدّث السجل أو تحقّق من المعرّف الأصلي؛ لا إعادة إرسال تلقائية.',
              ),
            ),
            TextButton(
              onPressed: busy || data?['can_request'] != true ? null : send,
              child: Text(
                tr('Resolve diagnostic request', 'التحقق من طلب التشخيص'),
              ),
            ),
            if (data != null)
              TextButton(
                onPressed: busy ? null : () => setState(() => pending = null),
                child: Text(
                  tr(
                    'Clear pending form after reviewing history',
                    'مسح النموذج المعلق بعد مراجعة السجل',
                  ),
                ),
              ),
          ],
          if (data?['can_request'] == true && pending == null)
            TextButton(
              onPressed: busy ? null : create,
              child: Text(tr('Request diagnostic file', 'طلب ملف تشخيص')),
            ),
          for (final row in data?['data'] as List? ?? [])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('#${row['id']} · OCPP ${row['protocol']}'),
                    Text(
                      row['evidence'] == 'AUTHENTICATED_SOURCE'
                          ? tr(
                              'Authenticated source record; hardware unverified',
                              'سجل مصدر مصادق؛ الأجهزة غير متحققة',
                            )
                          : tr('Simulated source record', 'سجل مصدر محاكى'),
                    ),
                    Text(stateLabel(row['dispatch_state'])),
                    Text(
                      '${tr('Upload expires', 'تنتهي صلاحية الرفع')}: ${serverEventTime(context, row['expires_at'])}',
                    ),
                    if (row['expired'] == true)
                      Text(tr('Upload expired', 'انتهت صلاحية الرفع')),
                    if (row['cancelled_at'] != null)
                      Text(
                        tr(
                          'Upload revoked; stored content removed',
                          'أُلغيت صلاحية الرفع وحُذف المحتوى المخزن',
                        ),
                      ),
                    if (row['accepted_at'] != null)
                      Text(
                        tr(
                          'Correlated source acceptance observed',
                          'رُصد قبول منسوب للمصدر',
                        ),
                      ),
                    if (row['device_status'] == 'legacy_status_unattributed')
                      Text(
                        tr(
                          'Legacy upload status is unattributed',
                          'حالة الرفع القديمة غير منسوبة',
                        ),
                      ),
                    if (row['source_status_at'] != null)
                      Text(
                        '${tr('Correlated source status', 'حالة المصدر المنسوبة')}: ${row['device_status']}',
                      ),
                    if (row['file_received_at'] != null) ...[
                      Text(
                        '${tr('File received', 'تم استلام الملف')}: ${serverEventTime(context, row['file_received_at'])}',
                      ),
                      Text(
                        '${tr('File size', 'حجم الملف')}: ${row['file_size']} B',
                      ),
                      Text(
                        '${tr('File retention expires', 'تنتهي مدة الاحتفاظ بالملف')}: ${serverEventTime(context, row['file_expires_at'])}',
                      ),
                    ],
                    if (data?['can_manage'] == true)
                      TextButton(
                        onPressed: busy || row['cancelled_at'] != null
                            ? null
                            : () => act(row, false),
                        child: Text(
                          tr('Inspect diagnostic source', 'فحص مصدر التشخيص'),
                        ),
                      ),
                    if (row['can_download'] == true)
                      TextButton(
                        onPressed: busy ? null : () => save(row),
                        child: Text(
                          tr(
                            'Save private diagnostic file',
                            'حفظ ملف التشخيص الخاص',
                          ),
                        ),
                      ),
                    if (data?['can_manage'] == true &&
                        row['cancelled_at'] == null)
                      TextButton(
                        onPressed: busy ? null : () => act(row, true),
                        child: Text(
                          tr(
                            'Revoke upload and remove file',
                            'إلغاء صلاحية الرفع وحذف الملف',
                          ),
                        ),
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
                  onPressed: busy || page >= data!['last_page']
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
      ],
    ),
  );
}

class _LogReason extends StatefulWidget {
  final bool ar;
  const _LogReason({required this.ar});
  @override
  State<_LogReason> createState() => _ReasonState();
}

class _ReasonState extends State<_LogReason> {
  final input = TextEditingController();
  @override
  void dispose() {
    input.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.ar ? 'سبب التشخيص' : 'Diagnostic reason'),
    content: TextField(
      controller: input,
      maxLength: 500,
      minLines: 2,
      maxLines: 4,
      onChanged: (_) => setState(() {}),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text(widget.ar ? 'رجوع' : 'Back'),
      ),
      TextButton(
        onPressed: input.text.trim().length < 10
            ? null
            : () => Navigator.pop(context, input.text.trim()),
        child: Text(widget.ar ? 'طلب' : 'Request'),
      ),
    ],
  );
}
