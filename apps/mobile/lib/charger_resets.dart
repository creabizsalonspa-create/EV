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

class _ResetState extends State<ChargerResetsView> with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? data;
  Map<String, dynamic>? pending;
  Object? error;
  bool busy = false, manager = false, enabled = false;
  int generation = 0;
  bool foreground = true, prompting = false;
  late bool Function() authorized;
  bool current(int epoch) =>
      mounted && foreground && authorized() && epoch == generation;
  void clearPrivateState() {
    generation++;
    pending?.clear();
    pending = null;
    error = null;
    busy = false;
    manager = false;
    enabled = false;
    data = null;
  }

  @override
  void didUpdateWidget(covariant ChargerResetsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.companyId != widget.companyId ||
        oldWidget.chargerId != widget.chargerId) {
      clearPrivateState();
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted)
      setState(() {
        if (!foreground) clearPrivateState();
      });
  }

  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/reset-operations';
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  void fail(Object e) {
    if (!mounted || !foreground || !authorized()) return;
    setState(() => error = e);
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final r = await widget.api.request(path, query: {'page': '$page'});
      if (current(epoch))
        setState(() {
          data = ApiPage<Map<String, dynamic>>.fromJson(r, (v) => v);
          manager = r['can_manage'] == true;
          enabled = r['adapter_enabled'] == true;
        });
    } catch (e) {
      if (current(epoch)) {
        setState(() => data = null);
        fail(e);
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<String?> note(String title, int epoch) async {
    setState(() => prompting = true);
    try {
      return await showDialog<String>(
        context: context,
        builder: (_) =>
            _ResetNote(title: title, authorized: () => current(epoch)),
      );
    } finally {
      if (mounted) setState(() => prompting = false);
    }
  }

  Future<void> request() async {
    if (!foreground || !authorized() || busy || !manager || !enabled) return;
    final epoch = ++generation;
    final target = path;
    final api = widget.api;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      if (pending == null) {
        final reason = await note(
          t('Request safe reset', 'طلب إعادة تشغيل آمنة'),
          epoch,
        );
        if (!current(epoch) || reason == null) return;
        pending = {'reason': reason, 'idempotency_key': operationKey()};
      }
      if (!current(epoch)) return;
      final body = Map<String, dynamic>.from(pending!);
      await api.request(target, method: 'POST', body: body);
      if (!current(epoch)) return;
      pending = null;
      await load();
    } catch (e) {
      if (current(epoch)) {
        if (e is ApiException && [409, 422].contains(e.status)) pending = null;
        fail(e);
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> review(int id) async {
    if (!foreground || !authorized() || busy || !manager || !enabled) return;
    final epoch = ++generation;
    final target = path;
    final api = widget.api;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final value = await note(
        t('Review fresh idle source', 'مراجعة مصدر حديث وخامل'),
        epoch,
      );
      if (!current(epoch) || value == null) return;
      await api.request(
        '$target/$id/review',
        method: 'POST',
        body: {'note': value},
      );
      if (current(epoch)) await load();
    } catch (e) {
      if (current(epoch)) fail(e);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    clearPrivateState();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(resetTitle(context))),
    body: !authorized()
        ? Center(child: Text(t('Sign in again.', 'سجّل الدخول مجددًا.')))
        : ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(
                t(
                  'Local simulator only. Soft (1.6) or OnIdle (2.0.1). Active sessions and current bookings block resets. Transport submission does not prove execution. Starts remain blocked until fresh idle source evidence is reviewed. No automatic retry or session closure.',
                  'للمحاكاة المحلية فقط. يستخدم Soft أو OnIdle. تمنع الجلسات والحجوزات الحالية إعادة التشغيل. لا يثبت إرسال الطلب التنفيذ. يبقى بدء الشحن محظوراً حتى مراجعة دليل مصدر حديث وخامل. لا توجد إعادة تلقائية أو إغلاق للجلسات.',
                ),
              ),
              if (busy && !prompting) const LinearProgressIndicator(),
              if (error != null)
                Text(EvStrings.of(context).t(errorKey(error!))),
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
                            onPressed: busy
                                ? null
                                : () => review(r['id'] as int),
                            child: Text(
                              t(
                                'Review fresh idle source',
                                'مراجعة مصدر حديث وخامل',
                              ),
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
  final bool Function() authorized;
  const _ResetNote({required this.title, required this.authorized});
  @override
  State<_ResetNote> createState() => _ResetNoteState();
}

class _ResetNoteState extends State<_ResetNote> with WidgetsBindingObserver {
  final text = TextEditingController();
  final form = GlobalKey<FormState>();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      FocusScope.of(context).unfocus();
      setState(text.clear);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
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
            if (widget.authorized() && form.currentState!.validate())
              Navigator.pop(context, text.text.trim());
          },
          child: Text(ar ? 'تأكيد' : 'Confirm'),
        ),
      ],
    );
  }
}
