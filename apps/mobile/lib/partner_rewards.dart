import 'package:flutter/material.dart';

import 'api.dart';
import 'financial_operation_store.dart';

String partnerRewardsTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'مكافآت الشركاء'
    : 'Partner rewards';

class PartnerRewardsView extends StatefulWidget {
  const PartnerRewardsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.store,
  });

  final EvApi api;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;

  @override
  State<PartnerRewardsView> createState() => _PartnerRewardsState();
}

class _PartnerRewardsState extends State<PartnerRewardsView>
    with WidgetsBindingObserver {
  static const endpoint = 'commercial/partner-rewards';
  late final bool Function() ownsSession;
  FinancialOperation? operation;
  ApiPage<Map<String, dynamic>>? offers;
  ApiPage<Map<String, dynamic>>? history;
  Map<String, dynamic>? receipt;
  bool emailVerified = false;
  bool busy = true;
  int generation = 0;
  String? message;

  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int epoch) => mounted && generation == epoch && ownsSession();
  int? get pendingReview =>
      (operation?.pending?['body'] as Map?)?['review_id'] as int?;

  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    open();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      open();
    } else {
      setState(() {
        generation++;
        operation = null;
        offers = null;
        history = null;
        receipt = null;
        message = null;
        emailVerified = false;
        busy = true;
      });
    }
  }

  void checkEvidence(Map row) {
    if (row['source'] != 'RETAIL_PARTNER_REWARD' ||
        row['evidence'] != 'SANDBOX_MANUAL_NONCASH' ||
        row['external_fulfillment_verified'] != false ||
        row['cash_value'] != false ||
        row['points_cost'] != 0) {
      throw const FormatException('Invalid partner reward evidence');
    }
  }

  bool validId(Object? value) => value is int && value > 0;

  Map<String, dynamic> parseOffer(Map<String, dynamic> row) {
    checkEvidence(row);
    if (!validId(row['review_id']) ||
        row['name'] is! String ||
        row['name_ar'] is! String ||
        row['partner_name'] is! String ||
        row['partner_name_ar'] is! String ||
        row['description'] is! String ||
        row['description_ar'] is! String ||
        row['ends_at'] is! String) {
      throw const FormatException('Invalid partner reward offer');
    }
    return row;
  }

  Map<String, dynamic> parseClaim(Map<String, dynamic> row) {
    checkEvidence(row);
    if (!validId(row['id']) ||
        !validId(row['review_id']) ||
        !validId(row['redemption_id']) ||
        row['created_at'] is! String ||
        !['pending', 'fulfilled', 'rejected'].contains(row['status'])) {
      throw const FormatException('Invalid partner reward receipt');
    }
    return row;
  }

  Future<void> run(
    Future<void> Function(int) action, {
    required String Function() failed,
  }) async {
    if (!ownsSession()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      message = null;
    });
    try {
      await action(epoch);
    } catch (error) {
      if (!mounted || generation != epoch) return;
      if (error is ApiException && error.endsCurrentSession) {
        widget.onExpired();
      } else if (ownsSession()) {
        setState(() => message = failed());
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> open() => run((epoch) async {
    final actor = await widget.api.request('me');
    if (!current(epoch)) return;
    if (!validId(actor['id']) || actor['role'] != 'customer') {
      throw const FormatException('Invalid customer account');
    }
    final op = FinancialOperation(
      widget.store ?? DeviceFinancialOperationStore(),
      '${widget.api.base.origin}:${actor['id']}:loyalty:partner-reward',
      isCurrent: () => mounted && ownsSession(),
    );
    await op.restore();
    if (!current(epoch)) return;
    if (op.pending != null &&
        !validId((op.pending!['body'] as Map)['review_id'])) {
      throw const FormatException('Invalid saved partner reward request');
    }
    setState(() {
      operation = op;
      emailVerified = actor['email_verified_at'] != null;
    });
    await loadOffers(epoch, 1);
  }, failed: cannotLoadOffers);

  String cannotLoadOffers() => t(
    'Partner reward offers are unavailable. You can still open your claim history.',
    'عروض مكافآت الشركاء غير متاحة. لا يزال بإمكانك فتح سجل مطالباتك.',
  );

  Future<void> loadOffers(int epoch, int page) async {
    setState(() => offers = null);
    final value = await widget.api.request(
      '$endpoint/offers',
      query: {'page': '$page'},
    );
    if (!current(epoch)) return;
    final parsed = ApiPage.fromJson(value, parseOffer);
    setState(() => offers = parsed);
  }

  Future<void> loadHistory(int page) => run(
    (epoch) async {
      setState(() {
        history = null;
        receipt = null;
      });
      final value = await widget.api.request(
        '$endpoint/claims',
        query: {'page': '$page'},
      );
      if (!current(epoch)) return;
      final parsed = ApiPage.fromJson(value, parseClaim);
      setState(() => history = parsed);
    },
    failed: () => t(
      'Could not load partner reward history.',
      'تعذر تحميل سجل مكافآت الشركاء.',
    ),
  );

  Future<void> confirmClaim(Map<String, dynamic> offer) async {
    final epoch = generation;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: Text(t('Claim this reward?', 'هل تريد المطالبة بهذه المكافأة؟')),
        content: Text(t(offer['name'] as String, offer['name_ar'] as String)),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: Text(t('Cancel', 'إلغاء')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialog, true),
            child: Text(t('Claim', 'مطالبة')),
          ),
        ],
      ),
    );
    if (confirmed == true && current(epoch) && !busy && pendingReview == null) {
      await submit(offer['review_id'] as int);
    }
  }

  Future<void> submit(int review) async {
    final op = operation;
    if (busy ||
        op == null ||
        !emailVerified ||
        (pendingReview != null && pendingReview != review))
      return;
    await run(
      (epoch) async {
        final staged = await op.prepare({'review_id': review});
        if (!current(epoch)) return;
        final value = await widget.api.request(
          '$endpoint/offers/$review/claim',
          method: 'POST',
          body: {'idempotency_key': staged['idempotency_key']},
        );
        if (!current(epoch)) return;
        await acceptReceipt(epoch, op, review, value['claim']);
      },
      failed: () => t(
        'The claim could not be confirmed. Check your history or retry the same saved request.',
        'تعذر تأكيد المطالبة. تحقق من سجلك أو أعد محاولة الطلب المحفوظ نفسه.',
      ),
    );
  }

  Future<void> acceptReceipt(
    int epoch,
    FinancialOperation op,
    int review,
    Object? claim,
  ) async {
    final row = parseClaim(Map<String, dynamic>.from(claim as Map));
    if (row['review_id'] != review) {
      throw const FormatException(
        'Partner reward receipt refers to another offer',
      );
    }
    await op.confirmed();
    if (!current(epoch)) return;
    setState(() {
      receipt = row;
      history = null;
    });
  }

  Future<void> inspectSaved() async {
    final op = operation;
    final review = pendingReview;
    final key = op?.pending?['key'];
    if (busy || op == null || review == null || key is! String) return;
    await run(
      (epoch) async {
        final value = await widget.api.request(
          '$endpoint/claim-receipt',
          method: 'POST',
          body: {'idempotency_key': key},
        );
        if (!current(epoch)) return;
        if (value is! Map || !value.containsKey('claim')) {
          throw const FormatException(
            'Invalid saved partner reward receipt response',
          );
        }
        if (value['claim'] == null) {
          setState(
            () => message = t(
              'No receipt has been recorded for this request.',
              'لم يتم تسجيل إيصال لهذا الطلب.',
            ),
          );
          return;
        }
        await acceptReceipt(epoch, op, review, value['claim']);
      },
      failed: () => t(
        'Could not check the saved claim receipt.',
        'تعذر التحقق من إيصال المطالبة المحفوظة.',
      ),
    );
  }

  Future<void> clearSaved() async {
    final op = operation;
    if (op == null || busy) return;
    final epoch = generation;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: Text(
          t('Clear the saved request?', 'هل تريد مسح الطلب المحفوظ؟'),
        ),
        content: Text(
          t(
            'Clearing this device does not cancel a claim already recorded by the server.',
            'مسح الطلب من هذا الجهاز لا يلغي مطالبة سجلها الخادم بالفعل.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: Text(t('Keep request', 'الاحتفاظ بالطلب')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialog, true),
            child: Text(t('Clear', 'مسح')),
          ),
        ],
      ),
    );
    if (confirmed != true || !current(epoch) || busy) return;
    await run(
      (_) => op.confirmed(),
      failed: () =>
          t('Could not clear the saved request.', 'تعذر مسح الطلب المحفوظ.'),
    );
  }

  Widget pager(int page, int last, void Function(int) select) => Wrap(
    children: [
      if (page > 1)
        TextButton(
          onPressed: busy ? null : () => select(page - 1),
          child: Text(t('Previous', 'السابق')),
        ),
      if (page < last)
        TextButton(
          onPressed: busy ? null : () => select(page + 1),
          child: Text(t('Next', 'التالي')),
        ),
    ],
  );

  Widget offerCard(Map<String, dynamic> row) => Card(
    child: Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t(row['name'] as String, row['name_ar'] as String),
            style: Theme.of(context).textTheme.titleMedium,
          ),
          Text(
            t(row['partner_name'] as String, row['partner_name_ar'] as String),
          ),
          Text(
            t(row['description'] as String, row['description_ar'] as String),
          ),
          Text(
            t(
              'Ends ${row['ends_at']} UTC',
              'ينتهي في ${row['ends_at']} بالتوقيت العالمي',
            ),
          ),
          FilledButton(
            onPressed:
                busy ||
                    !emailVerified ||
                    operation == null ||
                    pendingReview != null
                ? null
                : () => confirmClaim(row),
            child: Text(t('Claim reward', 'المطالبة بالمكافأة')),
          ),
        ],
      ),
    ),
  );

  Widget claimCard(Map<String, dynamic> row) => ListTile(
    title: Text(t('Claim #${row['id']}', 'المطالبة رقم ${row['id']}')),
    subtitle: Text(
      [
        t(
          'Offer #${row['review_id']} · Reward request #${row['redemption_id']}',
          'العرض رقم ${row['review_id']} · طلب المكافأة رقم ${row['redemption_id']}',
        ),
        '${row['created_at']} UTC',
        switch (row['status']) {
          'fulfilled' => t(
            'Manual fulfillment recorded',
            'تم تسجيل التسليم اليدوي',
          ),
          'rejected' => t('Rejected', 'مرفوضة'),
          _ => t('Pending manual fulfillment', 'بانتظار التسليم اليدوي'),
        },
      ].join('\n'),
    ),
  );

  @override
  Widget build(BuildContext context) {
    if (!ownsSession()) return const Scaffold();
    final pending = pendingReview;
    return Scaffold(
      appBar: AppBar(title: Text(partnerRewardsTitle(context))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (busy) const LinearProgressIndicator(),
          if (message != null)
            Semantics(liveRegion: true, child: Text(message!)),
          Text(
            t(
              'Test partner rewards have no cash or points cost. External delivery has not been verified.',
              'لا تتطلب مكافآت الشركاء التجريبية دفعاً نقدياً أو نقاطاً. لم يتم التحقق من التسليم الخارجي.',
            ),
          ),
          if (operation != null && !emailVerified)
            Text(
              t(
                'Verify your email to claim a reward.',
                'تحقق من بريدك الإلكتروني للمطالبة بمكافأة.',
              ),
            ),
          if (pending != null) ...[
            Text(
              t(
                'Saved claim for offer #$pending',
                'مطالبة محفوظة للعرض رقم $pending',
              ),
            ),
            TextButton(
              onPressed: busy ? null : inspectSaved,
              child: Text(
                t(
                  'Check saved claim receipt',
                  'التحقق من إيصال المطالبة المحفوظة',
                ),
              ),
            ),
            TextButton(
              onPressed: busy || !emailVerified ? null : () => submit(pending),
              child: Text(
                t('Retry saved claim', 'إعادة محاولة المطالبة المحفوظة'),
              ),
            ),
            TextButton(
              onPressed: busy ? null : clearSaved,
              child: Text(t('Clear saved claim', 'مسح المطالبة المحفوظة')),
            ),
          ],
          if (receipt case final confirmed?) ...[
            Text(t('Claim receipt', 'إيصال المطالبة')),
            claimCard(confirmed),
          ],
          TextButton(
            onPressed: busy ? null : () => loadHistory(1),
            child: Text(t('My claim history', 'سجل مطالباتي')),
          ),
          if (history case final claims?) ...[
            if (claims.items.isEmpty)
              Text(
                t(
                  'No partner reward claims.',
                  'لا توجد مطالبات بمكافآت الشركاء.',
                ),
              ),
            for (final row in claims.items) claimCard(row),
            pager(claims.current, claims.last, loadHistory),
          ],
          const Divider(),
          TextButton(
            onPressed: busy ? null : open,
            child: Text(t('Refresh offers', 'تحديث العروض')),
          ),
          if (offers case final list?) ...[
            if (list.items.isEmpty)
              Text(
                t(
                  'No partner rewards available.',
                  'لا توجد مكافآت شركاء متاحة.',
                ),
              ),
            for (final row in list.items) offerCard(row),
            pager(
              list.current,
              list.last,
              (page) => run(
                (epoch) => loadOffers(epoch, page),
                failed: cannotLoadOffers,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
