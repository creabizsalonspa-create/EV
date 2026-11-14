import 'privacy_copy.dart';
import 'dart:math';
import 'package:flutter/material.dart';
import 'api.dart';

/// Authenticated data subject requests only; account deletion is never performed here.
class PrivacyRequestsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const PrivacyRequestsView({
    super.key,
    required this.api,
    required this.onExpired,
  });

  @override
  State<PrivacyRequestsView> createState() => _PrivacyRequestsState();
}

class _PrivacyRequestsState extends State<PrivacyRequestsView>
    with WidgetsBindingObserver {
  final reason = TextEditingController();
  final items = <Map<String, dynamic>>[];
  String kind = 'access';
  String? error, success, pendingId;
  Map<String, dynamic>? pendingBody;
  bool confirmed = false,
      loading = false,
      sending = false,
      background = false,
      historyLoaded = false;
  int generation = 0, page = 1, lastPage = 1;
  late bool Function() accountFence;

  bool get arabic => Localizations.localeOf(context).languageCode == 'ar';
  String t(String en, String ar) => arabic ? ar : en;
  String status(String value) => switch (value) {
    'pending' => t('Submitted', 'تم التقديم'),
    'reviewing' => t('Under review', 'قيد المراجعة'),
    'closed_review' => t('Review closed', 'أُغلقت المراجعة'),
    'blocked_external' => t('External review required', 'مراجعة خارجية مطلوبة'),
    'withdrawn' => t('Withdrawn', 'تم السحب'),
    _ => value,
  };

  String outcome(String value) => switch (value) {
    'referred' => t('Referred to compliance', 'أُحيل للامتثال'),
    'declined' => t('Declined after review', 'رُفض بعد المراجعة'),
    'review_documented' => t('Review documented', 'المراجعة موثقة'),
    _ => value,
  };

  @override
  void initState() {
    super.initState();
    accountFence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    refresh();
  }

  @override
  void didUpdateWidget(covariant PrivacyRequestsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (identical(oldWidget.api, widget.api) && accountFence()) return;
    // A reused view must not expose another account's private reasons or
    // hold an operation key from a prior authentication context.
    clearPrivate();
    accountFence = widget.api.sessionFence();
    refresh();
  }

  void clearPrivate() {
    generation++;
    reason.clear();
    items.clear();
    kind = 'access';
    confirmed = loading = sending = false;
    pendingId = null;
    pendingBody = null;
    error = success = null;
    page = lastPage = 1;
    historyLoaded = false;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      background = state != AppLifecycleState.resumed;
      clearPrivate();
    });
  }

  bool rejectStale() {
    if (!background && accountFence()) return false;
    if (mounted) setState(clearPrivate);
    return true;
  }

  void finish(int ticket, bool Function() fence, {bool writing = false}) {
    if (!mounted || ticket != generation) return;
    setState(() {
      if (!fence()) {
        clearPrivate();
      } else if (writing) {
        sending = false;
      } else {
        loading = false;
      }
    });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    reason.dispose();
    super.dispose();
  }

  void failure(Object e) {
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted)
      setState(
        () => error = e is ApiException && e.status == 409
            ? t(
                'Request changed or key conflicts. Refresh before retrying.',
                'تغير الطلب أو تعارض المعرف. حدّث قبل إعادة المحاولة.',
              )
            : e.toString(),
      );
  }

  Future<void> refresh([int target = 1]) async {
    if (rejectStale()) return;
    final ticket = ++generation, fence = widget.api.sessionFence();
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'account/privacy-requests',
        query: {'page': target.toString()},
      );
      final data = ApiPage<Map<String, dynamic>>.fromJson(result, (v) => v);
      if (!mounted || ticket != generation || !fence()) return;
      setState(() {
        historyLoaded = true;
        page = data.current;
        lastPage = data.last;
        items
          ..clear()
          ..addAll(data.items);
      });
    } catch (e) {
      if (mounted && ticket == generation && fence()) failure(e);
    } finally {
      finish(ticket, fence);
    }
  }

  String createIdentity() {
    final rng = Random.secure();
    return List.generate(
      4,
      (_) => rng.nextInt(1 << 32).toRadixString(16).padLeft(8, '0'),
    ).join();
  }

  void newRequest() {
    setState(() {
      pendingId = null;
      pendingBody = null;
      kind = 'access';
      reason.clear();
      confirmed = false;
      error = success = null;
    });
  }

  Future<void> submit() async {
    if (rejectStale()) return;
    if (sending || loading || !confirmed) {
      if (!confirmed)
        setState(
          () => error = t(
            'Confirm the review notice first.',
            'أكد فهمك لبيان المراجعة أولاً.',
          ),
        );
      return;
    }
    final explanation = reason.text.trim();
    if (explanation.isNotEmpty && explanation.length < 10) {
      setState(
        () => error = t(
          'Use at least 10 characters for extra details.',
          'اكتب ١٠ أحرف على الأقل للتوضيح الإضافي.',
        ),
      );
      return;
    }
    // This is the explicit right selected and confirmed by the account holder,
    // not a claim that data was exported, corrected or erased.
    final defaults = {
      'access': 'I request access to my personal account data.',
      'correction':
          'I request a review of corrections to my personal account data.',
      'erasure': 'I request a review of deletion of my personal account data.',
    };
    final body = <String, dynamic>{
      'kind': kind,
      'details': explanation.isEmpty ? defaults[kind]! : explanation,
    };
    if (pendingBody != null &&
        (pendingBody!['kind'] != body['kind'] ||
            pendingBody!['details'] != body['details'])) {
      setState(
        () => error = t(
          'Retry the same request or start a different request.',
          'أعد إرسال الطلب نفسه أو ابدأ طلباً مختلفاً.',
        ),
      );
      return;
    }
    pendingId ??= createIdentity();
    pendingBody ??= body;
    final ticket = generation, fence = widget.api.sessionFence();
    setState(() {
      sending = true;
      error = success = null;
    });
    try {
      final posted = await widget.api.request(
        'account/privacy-requests',
        method: 'POST',
        body: {...pendingBody!, 'idempotency_key': pendingId},
      );
      if (!mounted || ticket != generation || !fence()) return;
      // A successful POST followed by a failed GET has an uncertain UI
      // outcome. Keep the SAME idempotency key until the submitted request
      // is visible in the authenticated list, so retry cannot duplicate it.
      final response = await widget.api.request(
        'account/privacy-requests',
        query: {'page': '1'},
      );
      if (!mounted || ticket != generation || !fence()) return;
      final data = ApiPage<Map<String, dynamic>>.fromJson(response, (v) => v);
      final savedId = (posted as Map)['id'];
      if (savedId == null || !data.items.any((item) => item['id'] == savedId)) {
        throw const FormatException(
          'Submitted privacy request not yet visible in history',
        );
      }
      setState(() {
        historyLoaded = true;
        page = data.current;
        lastPage = data.last;
        items
          ..clear()
          ..addAll(data.items);
        pendingId = null;
        pendingBody = null;
        kind = 'access';
        reason.clear();
        confirmed = false;
        success = t('Request recorded for review.', 'تم تسجيل الطلب للمراجعة.');
      });
    } catch (e) {
      if (mounted && ticket == generation && fence()) failure(e);
    } finally {
      finish(ticket, fence, writing: true);
    }
  }

  Future<void> withdraw(int id) async {
    if (rejectStale()) return;
    if (sending || loading) return;
    final ticket = generation, fence = widget.api.sessionFence();
    setState(() {
      sending = true;
      error = success = null;
    });
    try {
      await widget.api.request(
        'account/privacy-requests/' + id.toString() + '/withdraw',
        method: 'POST',
        body: {},
      );
      if (!mounted || ticket != generation || !fence()) return;
      setState(() => sending = false);
      await refresh(page);
    } catch (e) {
      if (mounted && ticket == generation && fence()) failure(e);
    } finally {
      finish(ticket, fence, writing: true);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(t('Privacy and data requests', 'طلبات الخصوصية والبيانات')),
    ),
    body: SafeArea(
      child: background || !accountFence()
          ? Center(
              child: Text(
                t(
                  'Private request details cleared. Return to your account to continue.',
                  'تم مسح تفاصيل الطلب الخاصة. ارجع إلى حسابك للمتابعة.',
                ),
              ),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  t(
                    'You can request a copy of your personal data or a review of account deletion. Submitting a request does not delete your account, charging history, invoices or legally retained records.',
                    'يمكنك طلب نسخة من بياناتك الشخصية أو مراجعة حذف حسابك. تقديم الطلب لا يحذف الحساب أو سجل الشحن أو الفواتير أو السجلات المحتفظ بها قانونياً.',
                  ),
                ),
                if (loading) const LinearProgressIndicator(),
                if (error != null)
                  Semantics(
                    liveRegion: true,
                    child: Text(error!, key: const Key('privacy-error')),
                  ),
                if (success != null)
                  Semantics(
                    liveRegion: true,
                    child: Text(success!, key: const Key('privacy-success')),
                  ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  isExpanded: true,
                  key: ValueKey('privacy-kind-' + kind),
                  initialValue: kind,
                  decoration: InputDecoration(
                    labelText: t('Request type', 'نوع الطلب'),
                  ),
                  items: [
                    DropdownMenuItem(
                      value: 'access',
                      child: Text(
                        t('Personal data access', 'الوصول للبيانات الشخصية'),
                      ),
                    ),
                    DropdownMenuItem(
                      value: 'correction',
                      child: Text(
                        t('Personal data correction', 'تصحيح البيانات الشخصية'),
                      ),
                    ),
                    DropdownMenuItem(
                      value: 'erasure',
                      child: Text(
                        t('Account deletion review', 'مراجعة حذف الحساب'),
                      ),
                    ),
                  ],
                  onChanged: sending
                      ? null
                      : (v) => setState(() => kind = v ?? 'access'),
                ),
                TextFormField(
                  key: const Key('privacy-reason'),
                  controller: reason,
                  maxLength: 500,
                  minLines: 2,
                  maxLines: 4,
                  decoration: InputDecoration(
                    labelText: t(
                      'Optional extra details (10+ characters)',
                      'توضيح إضافي اختياري (١٠ أحرف على الأقل)',
                    ),
                  ),
                ),
                CheckboxListTile(
                  key: const Key('privacy-confirm'),
                  value: confirmed,
                  onChanged: sending
                      ? null
                      : (v) => setState(() => confirmed = v == true),
                  title: Text(
                    t(
                      'I understand this is a review request, not instant account deletion.',
                      'أفهم أن هذا طلب مراجعة وليس حذفاً فورياً للحساب.',
                    ),
                  ),
                ),
                FilledButton(
                  key: const Key('privacy-submit'),
                  onPressed: sending || loading ? null : submit,
                  child: Text(
                    t('Submit privacy request', 'إرسال طلب الخصوصية'),
                  ),
                ),
                TextButton(
                  onPressed: sending ? null : newRequest,
                  child: Text(t('Start different request', 'بدء طلب مختلف')),
                ),
                const Divider(),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        t('My requests', 'طلباتي'),
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.refresh),
                      tooltip: t('Refresh', 'تحديث'),
                      onPressed: sending || loading
                          ? null
                          : () => refresh(page),
                    ),
                  ],
                ),
                if (!loading && historyLoaded && items.isEmpty)
                  Text(
                    t('No privacy requests yet.', 'لا توجد طلبات خصوصية بعد.'),
                  ),
                if (!loading && !historyLoaded)
                  Text(
                    t(
                      'Refresh to load your private request history.',
                      'حدّث لتحميل سجل طلباتك الخاصة.',
                    ),
                  ),
                for (final row in items)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            row['kind'] == 'erasure'
                                ? t(
                                    'Account deletion review',
                                    'مراجعة حذف الحساب',
                                  )
                                : row['kind'] == 'correction'
                                ? t(
                                    'Personal data correction',
                                    'تصحيح البيانات الشخصية',
                                  )
                                : t(
                                    'Personal data access',
                                    'الوصول للبيانات الشخصية',
                                  ),
                          ),
                          Text(status((row['status'] ?? '').toString())),
                          if (row['details'] is String)
                            Text(row['details'] as String),
                          if (row['copy'] is Map &&
                              row['copy']['status'] == 'available')
                            TextButton(
                              onPressed: sending || loading
                                  ? null
                                  : () => Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => PrivacyCopyPage(
                                          api: widget.api,
                                          requestId: row['id'] as int,
                                          onExpired: widget.onExpired,
                                        ),
                                      ),
                                    ),
                              child: Text(
                                t(
                                  'View private account copy',
                                  'عرض نسخة الحساب الخاصة',
                                ),
                              ),
                            ),
                          if ([
                            'pending',
                            'reviewing',
                            'blocked_external',
                          ].contains(row['status']))
                            TextButton(
                              onPressed: sending || loading
                                  ? null
                                  : () => withdraw(row['id'] as int),
                              child: Text(t('Withdraw request', 'سحب الطلب')),
                            ),
                        ],
                      ),
                    ),
                  ),
                Row(
                  children: [
                    TextButton(
                      onPressed: loading || sending || page <= 1
                          ? null
                          : () => refresh(page - 1),
                      child: Text(t('Previous', 'السابق')),
                    ),
                    Text(page.toString() + ' / ' + lastPage.toString()),
                    TextButton(
                      onPressed: loading || sending || page >= lastPage
                          ? null
                          : () => refresh(page + 1),
                      child: Text(t('Next', 'التالي')),
                    ),
                  ],
                ),
              ],
            ),
    ),
  );
}
