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

class _ActionState extends State<LoyaltyActionView> {
  FinancialOperation? operation;
  final invoice = TextEditingController();
  Object? error;
  bool busy = true, confirmed = false;
  int generation = 0;
  String t(String k) => loyaltyActionText(context, k);
  @override
  void initState() {
    super.initState();
    restore();
  }

  @override
  void dispose() {
    generation++;
    invoice.dispose();
    super.dispose();
  }

  Future<void> restore() async {
    final epoch = ++generation;
    try {
      if (!['earn', 'redeem', 'reconcile'].contains(widget.action) ||
          (widget.action == 'redeem' && (widget.reward ?? 0) < 1))
        throw const FormatException('Unsupported loyalty operation.');
      final me = await widget.api.request('me');
      if (me['id'] is! int || (me['id'] as int) < 1)
        throw const ApiException(401, 'Sign in required.');
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:${me['id']}:loyalty:${widget.action}:${widget.reward ?? 'account'}',
      );
      await op.restore();
      if (!mounted || generation != epoch) return;
      operation = op;
      if (op.pending != null && widget.action == 'earn')
        invoice.text = '${(op.pending!['body'] as Map)['invoice_id']}';
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    final id = decimalMinor(invoice.text, 0, maximum: 2000000000);
    if (widget.action == 'earn' && (id == null || id < 1)) {
      setState(() => error = const ApiException(422, 'Invalid invoice.'));
      return;
    }
    final yes = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t(widget.action)),
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
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await operation!.prepare({
        if (widget.action == 'earn') 'invoice_id': id,
        if (widget.action == 'redeem') 'reward_id': widget.reward,
      });
      if (!mounted || generation != epoch) return;
      final path = widget.action == 'redeem'
          ? 'rewards/${widget.reward}/redeem'
          : widget.action == 'earn'
          ? 'invoices/$id/earn'
          : 'reconcile';
      final result = await widget.api.request(
        'commercial/loyalty/$path',
        method: 'POST',
        body: body,
      );
      if (!mounted || generation != epoch) return;
      final account = result is Map ? result['account'] : null;
      if (account is! Map ||
          account['unit'] != 'NON_MONETARY_POINTS' ||
          account['cash_value'] != false ||
          account['transferable'] != false ||
          account['evidence'] != 'TEST_PROMOTIONAL_ECONOMICS')
        throw const ApiException(502, 'Unconfirmed promotional evidence.');
      if (widget.action == 'redeem' &&
          (result['redemption'] is! Map ||
              result['redemption']['reward_id'] != widget.reward ||
              ![
                'pending',
                'fulfilled',
                'rejected',
              ].contains(result['redemption']['status'])))
        throw const ApiException(502, 'Unconfirmed reward receipt.');
      confirmed = true;
      await operation!.confirmed();
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t(widget.action))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (operation?.pending != null && !confirmed) Text(t('pending')),
        if (confirmed) Text(t('done')),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (busy) const LinearProgressIndicator(),
        if (widget.action == 'earn')
          TextField(
            controller: invoice,
            readOnly: busy || operation?.pending != null || confirmed,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: t('invoice')),
          ),
        FilledButton(
          onPressed: busy || confirmed || operation == null ? null : submit,
          child: Text(t('confirm')),
        ),
        OutlinedButton(
          onPressed: busy ? null : () => Navigator.of(c).pop(),
          child: Text(t('history')),
        ),
      ],
    ),
  );
}
