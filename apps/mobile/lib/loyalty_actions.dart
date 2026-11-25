import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'financial_operation_store.dart';

const loyaltyActionEn = {
  'redeem': 'Request reward',
  'earn': 'Claim invoice points',
  'reconcile': 'Reconcile points',
  'invoice': 'Charging invoice ID',
  'confirm': 'Confirm',
  'cancel': 'Cancel',
  'notice':
      'Noncash promotional points only. Eligibility, holds, cost, stock and all points calculations are decided by the server. A pending request is not a delivered reward.',
  'pending':
      'A saved operation is unconfirmed. Refresh your account/history before explicitly resending the same operation. No automatic retry occurs.',
  'done':
      'Server confirmed the operation. Review the authoritative account and reward-request history.',
  'invalid': 'Enter a positive invoice ID.',
  'history': 'Account and request history',
  'refresh': 'Restore the current customer request',
};
const loyaltyActionAr = {
  'redeem': 'طلب المكافأة',
  'earn': 'المطالبة بنقاط الفاتورة',
  'reconcile': 'مطابقة النقاط',
  'invoice': 'معرف فاتورة الشحن',
  'confirm': 'تأكيد',
  'cancel': 'إلغاء',
  'notice':
      'نقاط ترويجية غير نقدية فقط. يحدد الخادم الأهلية والتعليق والتكلفة والمخزون وكل حسابات النقاط. الطلب المعلق لا يعني تسليم المكافأة.',
  'pending':
      'توجد عملية محفوظة غير مؤكدة. حدّث الحساب والسجل قبل إعادة إرسال العملية نفسها صراحةً. لا توجد إعادة محاولة تلقائية.',
  'done': 'أكد الخادم العملية. راجع الحساب الأصلي وسجل طلبات المكافآت.',
  'invalid': 'أدخل معرف فاتورة موجباً.',
  'history': 'الحساب وسجل الطلبات',
  'refresh': 'استعادة طلب العميل الحالي',
};
String loyaltyActionText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? loyaltyActionAr
        : loyaltyActionEn)[k] ??
    k;

class LoyaltyActionView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String action;
  final int? reward;
  final FinancialOperationStore? store;
  const LoyaltyActionView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.action,
    this.reward,
    this.store,
  });
  @override
  State<LoyaltyActionView> createState() => _ActionState();
}

class _ActionState extends State<LoyaltyActionView>
    with WidgetsBindingObserver {
  FinancialOperation? operation;
  final invoice = TextEditingController();
  Object? error;
  bool busy = true, confirmed = false, foreground = true;
  late bool Function() authorized;
  int generation = 0;

  String t(String k) => loyaltyActionText(context, k);
  bool scoped(EvApi api, bool Function() fence) =>
      mounted && foreground && identical(widget.api, api) &&
      identical(authorized, fence) && fence();

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) restore();
  }

  @override
  void didUpdateWidget(covariant LoyaltyActionView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.action != widget.action ||
        oldWidget.reward != widget.reward ||
        !identical(oldWidget.store, widget.store) ||
        !authorized()) {
      generation++;
      authorized = widget.api.sessionFence();
      operation = null;
      invoice.clear();
      error = null;
      busy = true;
      confirmed = false;
      final api = widget.api;
      final fence = authorized;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (scoped(api, fence)) restore();
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    foreground = resumed;
    if (!resumed) {
      generation++;
      setState(() {
        operation = null;
        invoice.clear();
        error = null;
        busy = false;
        confirmed = false;
      });
    } else {
      // A prior consent or receipt must never become actionable on resume.
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    invoice.dispose();
    super.dispose();
  }

  void redactFormerIdentity() {
    if (!mounted) return;
    setState(() {
      operation = null;
      invoice.clear();
      error = null;
      confirmed = false;
      busy = false;
    });
  }

  Future<void> restore() async {
    final epoch = ++generation;
    final api = widget.api;
    final fence = authorized;
    final action = widget.action;
    final reward = widget.reward;
    bool current() => generation == epoch && scoped(api, fence) &&
        widget.action == action && widget.reward == reward;
    if (!current()) return;
    try {
      if (!['earn', 'redeem', 'reconcile'].contains(action) ||
          (action == 'redeem' && (reward ?? 0) < 1)) {
        throw const FormatException('Unsupported loyalty operation.');
      }
      final me = await api.request('me');
      if (!current()) return;
      if (me is! Map || me['id'] is! int || me['id'] < 1 ||
          me['role'] != 'customer') {
        throw const ApiException(403, 'Customer loyalty account required.');
      }
      final pending = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${api.base.origin}:${me['id']}:loyalty:$action:${reward ?? 'account'}',
        isCurrent: () => scoped(api, fence) &&
            widget.action == action && widget.reward == reward,
      );
      await pending.restore();
      if (!current()) return;
      operation = pending;
      if (pending.pending != null && action == 'earn') {
        invoice.text = '${(pending.pending!['body'] as Map)['invoice_id']}';
      }
    } catch (e) {
      if (current()) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
        }
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    final api = widget.api;
    final fence = authorized;
    final action = widget.action;
    final reward = widget.reward;
    final pending = operation;
    if (busy || confirmed || pending == null || !scoped(api, fence)) return;
    final id = decimalMinor(invoice.text, 0, maximum: 2000000000);
    if (action == 'earn' && (id == null || id < 1)) {
      setState(() => error = const ApiException(422, 'Invalid invoice.'));
      return;
    }
    final yes = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t(action)),
        content: Text(t('notice')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: Text(t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: Text(t('confirm')),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    if (identical(api, widget.api) && !fence()) {
      redactFormerIdentity();
      return;
    }
    if (!scoped(api, fence) || widget.action != action ||
        widget.reward != reward || !identical(pending, operation)) return;
    final epoch = ++generation;
    bool current() => generation == epoch && scoped(api, fence) &&
        widget.action == action && widget.reward == reward &&
        identical(operation, pending);
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await pending.prepare({
        if (action == 'earn') 'invoice_id': id,
        if (action == 'redeem') 'reward_id': reward,
      });
      if (!current()) return;
      final path = action == 'redeem'
          ? 'rewards/$reward/redeem'
          : action == 'earn'
          ? 'invoices/$id/earn'
          : 'reconcile';
      final result = await api.request(
        'commercial/loyalty/$path',
        method: 'POST',
        body: body,
      );
      if (!current()) return;
      final account = result is Map ? result['account'] : null;
      if (account is! Map ||
          account['unit'] != 'NON_MONETARY_POINTS' ||
          account['cash_value'] != false ||
          account['transferable'] != false ||
          account['evidence'] != 'TEST_PROMOTIONAL_ECONOMICS') {
        throw const ApiException(502, 'Unconfirmed promotional evidence.');
      }
      if (action == 'redeem' &&
          (result['redemption'] is! Map ||
              result['redemption']['reward_id'] != reward ||
              !['pending', 'fulfilled', 'rejected'].contains(
                result['redemption']['status'],
              ))) {
        throw const ApiException(502, 'Unconfirmed reward receipt.');
      }
      await pending.confirmed();
      if (current()) confirmed = true;
    } catch (e) {
      if (current()) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
        }
      }
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api) &&
          identical(fence, authorized) &&
          widget.action == action && widget.reward == reward) {
        if (!fence()) {
          redactFormerIdentity();
        } else if (foreground) {
          setState(() => busy = false);
        }
      }
    }
  }

  @override
  Widget build(BuildContext c) {
    if (!foreground || !authorized()) return const Scaffold();
    final api = widget.api;
    final fence = authorized;
    final action = widget.action;
    final reward = widget.reward;
    bool same() => scoped(api, fence) &&
        widget.action == action && widget.reward == reward;
    return Scaffold(
      appBar: AppBar(title: Text(t(action))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          if (operation?.pending != null && !confirmed) Text(t('pending')),
          if (confirmed) Text(t('done')),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (busy) const LinearProgressIndicator(),
          if (action == 'earn')
            TextField(
              controller: invoice,
              readOnly: busy || operation?.pending != null || confirmed,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: t('invoice')),
            ),
          if (!busy && operation == null && !confirmed)
            OutlinedButton(
              onPressed: () {
                if (same()) {
                  setState(() => busy = true);
                  restore();
                }
              },
              child: Text(t('refresh')),
            ),
          FilledButton(
            onPressed: busy || confirmed || operation == null
                ? null
                : () {
                    if (same()) submit();
                  },
            child: Text(t('confirm')),
          ),
          OutlinedButton(
            onPressed: busy ? null : () {
              if (same()) Navigator.of(c).pop();
            },
            child: Text(t('history')),
          ),
        ],
      ),
    );
  }
}
