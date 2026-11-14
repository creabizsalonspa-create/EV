import 'package:flutter/material.dart';
import 'api.dart';

/// Generic notice consent and private handoff history. No client-triggered send.
class NotificationEmailView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const NotificationEmailView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<NotificationEmailView> createState() => _NotificationEmailState();
}

class _NotificationEmailState extends State<NotificationEmailView>
    with WidgetsBindingObserver {
  late bool Function() authorized;
  Map<String, dynamic>? preference, history;
  bool foreground = true, busy = false, consent = false;
  String? error;
  int generation = 0, page = 1;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int n) =>
      mounted && foreground && authorized() && generation == n;
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  void clear() {
    generation++;
    preference = null;
    history = null;
    consent = false;
    busy = false;
    error = null;
  }

  @override
  void didUpdateWidget(covariant NotificationEmailView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) {
      clear();
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted && !foreground) setState(clear);
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void fail(Object e, int n) {
    if (!current(n)) return;
    setState(() {
      preference = null;
      history = null;
      consent = false;
      error = t(
        'Outcome unavailable. Refresh before another change.',
        'النتيجة غير متاحة. حدّث قبل إجراء تغيير آخر.',
      );
    });
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int number = 1}) async {
    if (!foreground || !authorized() || busy) return;
    final n = ++generation;
    setState(() {
      busy = true;
      preference = null;
      history = null;
      consent = false;
      error = null;
    });
    try {
      final results = await Future.wait([
        widget.api.request('notifications/email'),
        widget.api.request(
          'notifications/email/history',
          query: {'page': '$number'},
        ),
      ]);
      if (!current(n)) return;
      final p = Map<String, dynamic>.from(results[0]);
      final h = Map<String, dynamic>.from(results[1]);
      if (p['revision'] is! String ||
          !RegExp(r'^(0|[1-9][0-9]{0,18})$').hasMatch(p['revision']) ||
          h['delivery_confirmed'] != false ||
          h['receipts'] is! Map)
        throw const FormatException();
      setState(() {
        preference = p;
        history = Map<String, dynamic>.from(h['receipts']);
        page = number;
      });
    } catch (e) {
      fail(e, n);
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  Future<void> save(bool enabled) async {
    if (!foreground ||
        !authorized() ||
        busy ||
        preference == null ||
        (enabled && !consent))
      return;
    final revision = preference!['revision'];
    final n = ++generation;
    setState(() {
      busy = true;
      consent = false;
      error = null;
    });
    var success = false;
    try {
      await widget.api.request(
        'notifications/email',
        method: 'PUT',
        body: {
          'enabled': enabled,
          'confirmed': enabled,
          'expected_revision': revision,
        },
      );
      if (!current(n)) return;
      success = true;
    } catch (e) {
      fail(e, n);
    } finally {
      if (current(n)) setState(() => busy = false);
    }
    if (success && current(n)) await load();
  }

  String stateLabel(Object? state) => switch (state) {
    'queued' => t('Queued', 'في الانتظار'),
    'claimed' => t(
      'Claimed; review if interrupted',
      'تم الحجز؛ راجع عند الانقطاع',
    ),
    'handed_off' => t(
      'Transport accepted; delivery unconfirmed',
      'قبله النقل؛ التسليم غير مؤكد',
    ),
    'outcome_unknown' => t(
      'Outcome unknown; no automatic retry',
      'نتيجة غير معروفة؛ لا إعادة تلقائية',
    ),
    'cancelled' => t('Cancelled', 'ملغى'),
    _ => t('Review required', 'تلزم المراجعة'),
  };
  @override
  Widget build(BuildContext context) {
    final p = preference;
    return Scaffold(
      appBar: AppBar(
        title: Text(t('Email activity notices', 'إشعارات النشاط بالبريد')),
      ),
      body: !authorized()
          ? Center(child: Text(t('Sign in again.', 'سجّل الدخول مجددًا.')))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  t(
                    'Optional generic notices to your verified account email. New unread activity only, at most once every 15 minutes. Details stay in your account. Delivery is never confirmed by transport acceptance.',
                    'إشعارات عامة اختيارية لبريد حسابك الموثق للنشاط الجديد غير المقروء فقط، بحد أقصى مرة كل 15 دقيقة. تبقى التفاصيل في الحساب. قبول النقل لا يؤكد التسليم.',
                  ),
                ),
                if (busy) const LinearProgressIndicator(),
                if (error != null) Text(error!),
                OutlinedButton(
                  onPressed: busy ? null : () => load(number: page),
                  child: Text(t('Refresh', 'تحديث')),
                ),
                if (p != null) ...[
                  Text(
                    p['consent_current'] == true
                        ? t(
                            'Enabled with current consent',
                            'مفعّل بالموافقة الحالية',
                          )
                        : t(
                            'No current delivery consent',
                            'لا توجد موافقة إرسال حالية',
                          ),
                  ),
                  if (p['configured'] != true)
                    Text(
                      t(
                        'Server delivery is not configured.',
                        'الإرسال غير مهيأ على الخادم.',
                      ),
                    ),
                  if (p['verified_email_required'] == true)
                    Text(
                      t(
                        'Verify your account email before enabling.',
                        'وثّق بريد حسابك قبل التفعيل.',
                      ),
                    ),
                  if (p['enabled'] == true)
                    OutlinedButton(
                      onPressed: busy ? null : () => save(false),
                      child: Text(
                        t('Disable email notices', 'تعطيل إشعارات البريد'),
                      ),
                    ),
                  if (p['consent_current'] != true &&
                      p['configured'] == true &&
                      p['verified_email_required'] != true) ...[
                    CheckboxListTile(
                      value: consent,
                      onChanged: busy
                          ? null
                          : (value) => setState(() => consent = value == true),
                      title: Text(
                        t(
                          'I consent to generic email activity notices.',
                          'أوافق على إشعارات النشاط العامة بالبريد.',
                        ),
                      ),
                    ),
                    FilledButton(
                      onPressed: busy || !consent ? null : () => save(true),
                      child: Text(t('Enable with consent', 'تفعيل بالموافقة')),
                    ),
                  ],
                ],
                Text(
                  t(
                    'Handoff history — delivery unconfirmed',
                    'سجل النقل — التسليم غير مؤكد',
                  ),
                ),
                for (final row
                    in (history?['data'] as List? ?? const []).whereType<Map>())
                  ListTile(
                    title: Text(stateLabel(row['state'])),
                    subtitle: Text(
                      '${row['created_at']} · ${row['evidence'] ?? ''}',
                    ),
                  ),
                if (page > 1)
                  TextButton(
                    onPressed: busy ? null : () => load(number: page - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (history != null &&
                    (history!['current_page'] as int) <
                        (history!['last_page'] as int))
                  TextButton(
                    onPressed: busy ? null : () => load(number: page + 1),
                    child: Text(t('Next', 'التالي')),
                  ),
              ],
            ),
    );
  }
}
