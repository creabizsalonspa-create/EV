import 'dart:math';
import 'package:flutter/material.dart';
import 'api.dart';

/// Authenticated data subject requests only; account deletion is never performed here.
class PrivacyRequestsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const PrivacyRequestsView({super.key, required this.api, required this.onExpired});

  @override
  State<PrivacyRequestsView> createState() => _PrivacyRequestsState();
}

class _PrivacyRequestsState extends State<PrivacyRequestsView> {
  final reason = TextEditingController();
  final items = <Map<String, dynamic>>[];
  String kind = 'data_access';
  String? error, success, pendingId;
  Map<String, dynamic>? pendingBody;
  bool confirmed = false, loading = false, sending = false;
  int generation = 0, page = 1, lastPage = 1;

  bool get arabic => Localizations.localeOf(context).languageCode == 'ar';
  String t(String en, String ar) => arabic ? ar : en;
  String status(String value) => switch (value) {
    'submitted' => t('Submitted', 'تم التقديم'),
    'in_review' => t('Under review', 'قيد المراجعة'),
    'closed' => t('Review closed', 'أُغلقت المراجعة'),
    'withdrawn' => t('Withdrawn', 'تم السحب'),
    _ => value,
  };

  @override
  void initState() {super.initState(); refresh();}
  @override
  void dispose() {generation++;reason.dispose();super.dispose();}
  void failure(Object e) {
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(() => error = e is ApiException && e.status == 409
        ? t('Request changed or key conflicts. Refresh before retrying.', 'تغير الطلب أو تعارض المعرف. حدّث قبل إعادة المحاولة.')
        : e.toString());
  }
  Future<void> refresh([int target = 1]) async {
    final ticket = ++generation, fence = widget.api.sessionFence();
    setState(() {loading = true; error = null;});
    try {
      final result = await widget.api.request('privacy-requests', query: {'page': target.toString()});
      final data = ApiPage<Map<String, dynamic>>.fromJson(result, (v) => v);
      if (!mounted || ticket != generation || !fence()) return;
      setState(() {
        page = data.current; lastPage = data.last;
        items..clear()..addAll(data.items);
      });
    } catch (e) {
      if (mounted && ticket == generation) failure(e);
    } finally {
      if (mounted && ticket == generation) setState(() => loading = false);
    }
  }
  String createIdentity() {
    final rng = Random.secure();
    return List.generate(4, (_) => rng.nextInt(1 << 32).toRadixString(16).padLeft(8, '0')).join();
  }
  void newRequest() {
    setState(() {pendingId = null;pendingBody = null;kind = 'data_access';reason.clear();confirmed = false;error = success = null;});
  }
  Future<void> submit() async {
    if (sending || loading || !confirmed) {
      if (!confirmed) setState(() => error = t('Confirm the review notice first.', 'أكد فهمك لبيان المراجعة أولاً.'));
      return;
    }
    final body = <String, dynamic>{'request_type':kind, 'reason':reason.text.trim().isEmpty ? null : reason.text.trim()};
    if (pendingBody != null && (pendingBody!['request_type'] != body['request_type'] || pendingBody!['reason'] != body['reason'])) {
      setState(() => error = t('Retry the same request or start a different request.', 'أعد إرسال الطلب نفسه أو ابدأ طلباً مختلفاً.'));
      return;
    }
    pendingId ??= createIdentity();
    pendingBody ??= body;
    final ticket = generation, fence = widget.api.sessionFence();
    setState(() {sending = true;error = success = null;});
    try {
      await widget.api.request('privacy-requests', method: 'POST', body: {...pendingBody!, 'idempotency_key':pendingId});
      if (!mounted || ticket != generation || !fence()) return;
      newRequest();
      if (mounted) setState(() => sending = false);
      await refresh(1);
      if (mounted) setState(() => success = t('Request recorded for review.', 'تم تسجيل الطلب للمراجعة.'));
    } catch (e) {
      if (mounted && ticket == generation) failure(e);
    } finally {
      if (mounted && ticket == generation) setState(() => sending = false);
    }
  }
  Future<void> withdraw(int id) async {
    if (sending || loading) return;
    final ticket = generation, fence = widget.api.sessionFence();
    setState(() {sending = true;error = success = null;});
    try {
      await widget.api.request('privacy-requests/' + id.toString() + '/withdraw', method: 'POST', body: {});
      if (!mounted || ticket != generation || !fence()) return;
      setState(() => sending = false);
      await refresh(page);
    } catch (e) {
      if (mounted && ticket == generation) failure(e);
    } finally {
      if (mounted && ticket == generation) setState(() => sending = false);
    }
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('Privacy and data requests', 'طلبات الخصوصية والبيانات'))),
    body: SafeArea(child: ListView(padding: const EdgeInsets.all(16), children: [
      Text(t(
        'You can request a copy of your personal data or a review of account deletion. Submitting a request does not delete your account, charging history, invoices or legally retained records.',
        'يمكنك طلب نسخة من بياناتك الشخصية أو مراجعة حذف حسابك. تقديم الطلب لا يحذف الحساب أو سجل الشحن أو الفواتير أو السجلات المحتفظ بها قانونياً.',
      )),
      if (loading) const LinearProgressIndicator(),
      if (error != null) Semantics(liveRegion: true, child: Text(error!, key: const Key('privacy-error'))),
      if (success != null) Semantics(liveRegion: true, child: Text(success!, key: const Key('privacy-success'))),
      const SizedBox(height: 12),
      DropdownButtonFormField<String>(
        key: ValueKey('privacy-kind-' + kind), initialValue: kind,
        decoration: InputDecoration(labelText: t('Request type', 'نوع الطلب')),
        items: [
          DropdownMenuItem(value: 'data_access', child: Text(t('Personal data access', 'الوصول للبيانات الشخصية'))),
          DropdownMenuItem(value: 'account_deletion', child: Text(t('Account deletion review', 'مراجعة حذف الحساب'))),
        ],
        onChanged: sending ? null : (v) => setState(() => kind = v ?? 'data_access'),
      ),
      TextFormField(
        key: const Key('privacy-reason'), controller: reason, maxLength: 500,
        minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: t('Optional explanation', 'توضيح اختياري')),
      ),
      CheckboxListTile(
        key: const Key('privacy-confirm'),
        value: confirmed,
        onChanged: sending ? null : (v) => setState(() => confirmed = v == true),
        title: Text(t('I understand this is a review request, not instant account deletion.', 'أفهم أن هذا طلب مراجعة وليس حذفاً فورياً للحساب.')),
      ),
      FilledButton(
        key: const Key('privacy-submit'), onPressed: sending || loading ? null : submit,
        child: Text(t('Submit privacy request', 'إرسال طلب الخصوصية')),
      ),
      TextButton(
        onPressed: sending ? null : newRequest,
        child: Text(t('Start different request', 'بدء طلب مختلف')),
      ),
      const Divider(),
      Row(children: [
        Expanded(child: Text(t('My requests', 'طلباتي'), style: Theme.of(context).textTheme.titleLarge)),
        IconButton(
          icon: const Icon(Icons.refresh), tooltip: t('Refresh', 'تحديث'),
          onPressed: sending || loading ? null : () => refresh(page),
        ),
      ]),
      if (!loading && items.isEmpty) Text(t('No privacy requests yet.', 'لا توجد طلبات خصوصية بعد.')),
      for (final row in items)
        Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(
          crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(row['request_type'] == 'account_deletion'
                ? t('Account deletion review', 'مراجعة حذف الحساب')
                : t('Personal data access', 'الوصول للبيانات الشخصية')),
            Text(status((row['status'] ?? '').toString())),
            if (row['reason'] is String && (row['reason'] as String).isNotEmpty)
              Text(row['reason'] as String),
            if (row['resolution'] != null) Text(t('Administrative outcome: ', 'النتيجة الإدارية: ') + row['resolution'].toString()),
            if (row['decision_note'] is String) Text(row['decision_note'] as String),
            if (['submitted','in_review'].contains(row['status']))
              TextButton(
                onPressed: sending || loading ? null : () => withdraw(row['id'] as int),
                child: Text(t('Withdraw request', 'سحب الطلب')),
              ),
          ],
        ))),
      Row(children: [
        TextButton(onPressed: loading || sending || page <= 1 ? null : () => refresh(page - 1), child: Text(t('Previous', 'السابق'))),
        Text(page.toString() + ' / ' + lastPage.toString()),
        TextButton(onPressed: loading || sending || page >= lastPage ? null : () => refresh(page + 1), child: Text(t('Next', 'التالي'))),
      ]),
    ])),
  );
}
