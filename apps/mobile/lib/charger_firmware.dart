import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

String firmwareTitle(BuildContext c) =>
    Localizations.localeOf(c).languageCode == 'ar'
    ? 'البرامج الثابتة الموقعة'
    : 'Signed firmware';

class ChargerFirmwareView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final String manufacturer, model;
  final VoidCallback onExpired;
  const ChargerFirmwareView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.manufacturer,
    required this.model,
    required this.onExpired,
  });
  @override
  State<ChargerFirmwareView> createState() => _FirmwareState();
}

class _FirmwareState extends State<ChargerFirmwareView> {
  ApiPage<Map<String, dynamic>>? operations, releases;
  bool busy = false, manager = false, enabled = false;
  Object? error;
  int generation = 0;
  int? selected;
  Map<String, dynamic>? pending;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get base => 'vendor-ops/companies/${widget.companyId}';
  String get path => '$base/chargers/${widget.chargerId}/firmware-operations';
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    pending?.clear();
    super.dispose();
  }

  void fail(Object e) {
    if (!mounted) return;
    setState(() => error = e);
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int? page, int? releasePage}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final values = await Future.wait([
        widget.api.request(
          path,
          query: {'page': '${page ?? operations?.current ?? 1}'},
        ),
        widget.api.request(
          '$base/firmware-releases',
          query: {
            'page': '${releasePage ?? releases?.current ?? 1}',
            'manufacturer': widget.manufacturer,
            'model': widget.model,
            'active': 'true',
          },
        ),
      ]);
      if (mounted && epoch == generation)
        setState(() {
          operations = ApiPage<Map<String, dynamic>>.fromJson(
            values[0],
            (v) => v,
          );
          releases = ApiPage<Map<String, dynamic>>.fromJson(
            values[1],
            (v) => v,
          );
          manager = values[0]['can_manage'] == true;
          enabled = values[0]['adapter_enabled'] == true;
          if (!releases!.items.any((v) => v['id'] == selected))
            selected = releases!.items.isEmpty
                ? null
                : releases!.items.first['id'] as int;
        });
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() {
          operations = null;
          releases = null;
        });
        fail(e);
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<String?> note(String title) => showDialog<String>(
    context: context,
    builder: (_) => _FirmwareNote(title: title),
  );
  Future<void> deploy() async {
    if (pending == null) {
      final id = selected;
      if (id == null) return;
      final value = await note(
        t('Request signed firmware', 'طلب برنامج ثابت موقع'),
      );
      if (!mounted || value == null) return;
      pending = {
        'release_id': id,
        'reason': value,
        'idempotency_key': operationKey(),
      };
    }
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        path,
        method: 'POST',
        body: Map<String, dynamic>.from(pending!),
      );
      pending = null;
      if (mounted) await load(page: 1);
    } catch (e) {
      if (e is ApiException && [409, 422].contains(e.status)) pending = null;
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> review(int id, {bool cancel = false}) async {
    final value = await note(
      cancel
          ? t(
              'Cancel firmware before dispatch',
              'إلغاء البرنامج الثابت قبل الإرسال',
            )
          : t(
              'Review firmware source evidence',
              'مراجعة دليل مصدر البرنامج الثابت',
            ),
    );
    if (!mounted || value == null) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        '$path/$id/${cancel ? 'cancel' : 'review'}',
        method: 'POST',
        body: {'note': value},
      );
      if (mounted) await load();
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  String state(dynamic value) => switch (value) {
    'transport_submitted' => t(
      'Delivery submitted; awaiting source evidence',
      'تم إرسال الطلب؛ بانتظار دليل المصدر',
    ),
    'outcome_unknown' => t(
      'Delivery outcome unknown; charger remains on hold',
      'نتيجة الإرسال مجهولة؛ يبقى الشاحن محجوزاً',
    ),
    'source_installed' => t(
      'Source reports installed; reviewed',
      'المصدر يبلغ عن التثبيت؛ تمت المراجعة',
    ),
    'cancelled_unsent' => t(
      'Cancelled before dispatch',
      'تم الإلغاء قبل الإرسال',
    ),
    'source_failed' => t(
      'Source reports installation failed; reviewed',
      'المصدر يبلغ عن فشل التثبيت؛ تمت المراجعة',
    ),
    _ => t('Firmware request pending', 'طلب البرنامج الثابت قيد الانتظار'),
  };
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(firmwareTitle(context))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            'Local simulator only. Admin must approve independently trusted signed artifacts. Only compatible approved releases are eligible. No unsigned fallback or automatic retry. Starts remain blocked until fresh idle state and correlated terminal source evidence are reviewed. Source reports do not prove physical installation.',
            'للمحاكاة المحلية فقط. يجب أن يعتمد المسؤول ملفات موقعة موثوقة بشكل مستقل. تتاح الإصدارات المعتمدة والمتوافقة فقط. لا يوجد بديل غير موقع أو إعادة تلقائية. يبقى الشحن محظوراً حتى مراجعة حالة خمول حديثة ودليل مصدر نهائي مرتبط. لا تثبت تقارير المصدر التثبيت الفعلي.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
        TextButton(
          onPressed: busy ? null : () => load(),
          child: Text(t('Refresh', 'تحديث')),
        ),
        if (manager && enabled) ...[
          if (releases?.items.isNotEmpty == true)
            DropdownButtonFormField<int>(
              initialValue: selected,
              key: ValueKey('${releases!.current}:$selected'),
              isExpanded: true,
              decoration: InputDecoration(
                labelText: t(
                  'Approved signed release',
                  'الإصدار الموقع المعتمد',
                ),
              ),
              items: [
                for (final r in releases!.items)
                  DropdownMenuItem(
                    value: r['id'] as int,
                    child: Text(
                      '${r['version']}',
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: busy || pending != null
                  ? null
                  : (v) => setState(() => selected = v),
            ),
          if (releases?.items.isEmpty == true)
            Text(
              t(
                'No compatible approved release. Server provisioning and Admin approval are required.',
                'لا يوجد إصدار معتمد ومتوافق. يلزم إعداد الملف على الخادم واعتماد المسؤول.',
              ),
            ),
          if ((releases?.current ?? 1) > 1)
            TextButton(
              onPressed: busy || pending != null
                  ? null
                  : () => load(releasePage: releases!.current - 1),
              child: Text(t('Previous releases', 'الإصدارات السابقة')),
            ),
          if ((releases?.current ?? 1) < (releases?.last ?? 1))
            TextButton(
              onPressed: busy || pending != null
                  ? null
                  : () => load(releasePage: releases!.current + 1),
              child: Text(t('More releases', 'المزيد من الإصدارات')),
            ),
          if (selected != null || pending != null)
            FilledButton(
              onPressed: busy ? null : deploy,
              child: Text(
                pending == null
                    ? t('Request signed firmware', 'طلب برنامج ثابت موقع')
                    : t('Retry saved request', 'إعادة الطلب المحفوظ'),
              ),
            ),
        ],
        for (final op in operations?.items ?? <Map<String, dynamic>>[])
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('#${op['id']} · ${op['version']}'),
                  Text(state(op['status'])),
                  Text('${op['sha256']}', textDirection: TextDirection.ltr),
                  Text('${op['created_at']}'),
                  if (op['reviewed_at'] == null && manager && enabled)
                    TextButton(
                      onPressed: busy
                          ? null
                          : () => review(
                              op['id'] as int,
                              cancel: op['status'] == 'requested',
                            ),
                      child: Text(
                        t(
                          op['status'] == 'requested'
                              ? 'Cancel firmware before dispatch'
                              : 'Review firmware source evidence',
                          op['status'] == 'requested'
                              ? 'إلغاء البرنامج الثابت قبل الإرسال'
                              : 'مراجعة دليل مصدر البرنامج الثابت',
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        if ((operations?.current ?? 1) > 1)
          TextButton(
            onPressed: busy ? null : () => load(page: operations!.current - 1),
            child: Text(t('Previous', 'السابق')),
          ),
        if ((operations?.current ?? 1) < (operations?.last ?? 1))
          TextButton(
            onPressed: busy ? null : () => load(page: operations!.current + 1),
            child: Text(t('Next', 'التالي')),
          ),
      ],
    ),
  );
}

class _FirmwareNote extends StatefulWidget {
  final String title;
  const _FirmwareNote({required this.title});
  @override
  State<_FirmwareNote> createState() => _FirmwareNoteState();
}

class _FirmwareNoteState extends State<_FirmwareNote> {
  final controller = TextEditingController();
  final form = GlobalKey<FormState>();
  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return AlertDialog(
      title: Text(widget.title),
      content: SingleChildScrollView(
        child: Form(
          key: form,
          child: TextFormField(
            controller: controller,
            minLines: 2,
            maxLines: 4,
            maxLength: 2000,
            decoration: InputDecoration(
              labelText: ar
                  ? 'ملاحظة التشغيل (١٠ أحرف على الأقل)'
                  : 'Operational note (at least 10 characters)',
            ),
            validator: (v) => (v?.trim().length ?? 0) < 10
                ? (ar ? 'أدخل ملاحظة مفصلة' : 'Enter a detailed note')
                : null,
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(ar ? 'إلغاء' : 'Cancel'),
        ),
        FilledButton(
          onPressed: () {
            if (form.currentState!.validate())
              Navigator.pop(context, controller.text.trim());
          },
          child: Text(ar ? 'تأكيد' : 'Confirm'),
        ),
      ],
    );
  }
}
