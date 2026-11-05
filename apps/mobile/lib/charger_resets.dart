import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

String resetTitle(BuildContext c) =>
    Localizations.localeOf(c).languageCode == 'ar'
    ? 'عمليات إعادة التشغيل الآمنة'
    : 'Safe reset operations';

class ChargerResetsView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final VoidCallback onExpired;
  const ChargerResetsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
  });
  @override
  State<ChargerResetsView> createState() => _ResetState();
}

class _ResetState extends State<ChargerResetsView> {
  ApiPage<Map<String, dynamic>>? data;
  Map<String, dynamic>? pending;
  Object? error;
  bool busy = false, manager = false, enabled = false;
  int generation = 0;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/reset-operations';
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    load();
  }

  void fail(Object e) {
    if (!mounted) return;
    setState(() => error = e);
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final r = await widget.api.request(path, query: {'page': '$page'});
      if (mounted && epoch == generation)
        setState(() {
          data = ApiPage<Map<String, dynamic>>.fromJson(r, (v) => v);
          manager = r['can_manage'] == true;
          enabled = r['adapter_enabled'] == true;
        });
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => data = null);
        fail(e);
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<String?> note(String title) => showDialog<String>(
    context: context,
    builder: (_) => _ResetNote(title: title),
  );
  Future<void> request() async {
    if (pending == null) {
      final reason = await note(
        t('Request safe reset', 'طلب إعادة تشغيل آمنة'),
      );
      if (!mounted || reason == null) return;
      pending = {'reason': reason, 'idempotency_key': operationKey()};
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
      if (mounted) await load();
    } catch (e) {
      if (e is ApiException && [409, 422].contains(e.status)) pending = null;
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> review(int id) async {
    final value = await note(
      t('Review fresh idle source', 'مراجعة مصدر حديث وخامل'),
    );
    if (!mounted || value == null) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        '$path/$id/review',
        method: 'POST',
        body: {'note': value},
      );
      if (mounted) await load(page: data?.current ?? 1);
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    pending?.clear();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(resetTitle(context))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            'Local simulator only. Soft (1.6) or OnIdle (2.0.1). Active sessions and current bookings block resets. Transport submission does not prove execution. Starts remain blocked until fresh idle source evidence is reviewed. No automatic retry or session closure.',
            'للمحاكاة المحلية فقط. يستخدم Soft أو OnIdle. تمنع الجلسات والحجوزات الحالية إعادة التشغيل. لا يثبت إرسال الطلب التنفيذ. يبقى بدء الشحن محظوراً حتى مراجعة دليل مصدر حديث وخامل. لا توجد إعادة تلقائية أو إغلاق للجلسات.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
        TextButton(
          onPressed: busy ? null : () => load(page: data?.current ?? 1),
          child: Text(t('Refresh', 'تحديث')),
        ),
        if (manager && enabled)
          FilledButton(
            onPressed: busy ? null : request,
            child: Text(
              pending == null
                  ? t('Request safe reset', 'طلب إعادة تشغيل آمنة')
                  : t('Retry saved request', 'إعادة الطلب المحفوظ'),
            ),
          ),
        for (final r in data?.items ?? <Map<String, dynamic>>[])
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('#${r['id']} · ${r['reset_type']}'),
                  Text('${r['status']}'),
                  Text('${r['created_at']}'),
                  if (r['reviewed_at'] != null)
                    Text(
                      t(
                        'Fresh idle source reviewed; physical reset execution remains unverified',
                        'تمت مراجعة مصدر حديث وخامل؛ يبقى تنفيذ إعادة التشغيل الفعلي غير مثبت',
                      ),
                    )
                  else if (manager && enabled)
                    TextButton(
                      onPressed: busy ? null : () => review(r['id'] as int),
                      child: Text(
                        t('Review fresh idle source', 'مراجعة مصدر حديث وخامل'),
                      ),
                    ),
                ],
              ),
            ),
          ),
        if ((data?.current ?? 1) > 1)
          TextButton(
            onPressed: busy ? null : () => load(page: data!.current - 1),
            child: Text(t('Previous', 'السابق')),
          ),
        if ((data?.current ?? 1) < (data?.last ?? 1))
          TextButton(
            onPressed: busy ? null : () => load(page: data!.current + 1),
            child: Text(t('Next', 'التالي')),
          ),
      ],
    ),
  );
}

class _ResetNote extends StatefulWidget {
  final String title;
  const _ResetNote({required this.title});
  @override
  State<_ResetNote> createState() => _ResetNoteState();
}

class _ResetNoteState extends State<_ResetNote> {
  final text = TextEditingController();
  final form = GlobalKey<FormState>();
  @override
  void dispose() {
    text.dispose();
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
            controller: text,
            maxLength: 2000,
            minLines: 2,
            maxLines: 4,
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
              Navigator.pop(context, text.text.trim());
          },
          child: Text(ar ? 'تأكيد' : 'Confirm'),
        ),
      ],
    );
  }
}
