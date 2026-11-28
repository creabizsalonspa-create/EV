import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart' show NumberFormat;
import 'api.dart';
import 'financial_operation_store.dart';
import 'reservation_times.dart';
import 'strings.dart';
import 'loyalty_actions.dart';
import 'noncash_capabilities.dart';

const loyaltyEn = {
  'title': 'Points and rewards',
  'notice':
      'Test promotional points only. No cash value, transfer or payment. Pending rewards are not delivered benefits.',
  'account': 'My points',
  'ledger': 'Points history',
  'rewards': 'Reward catalog',
  'redemptions': 'My reward requests',
  'balance': 'Posted points',
  'spendable': 'Available points',
  'pending': 'Pending reconciliation',
  'qualified': 'Qualified lifetime points',
  'hold': 'Redemption is on hold. Review your financial recovery records.',
  'empty': 'No records.',
  'refresh': 'Refresh',
  'previous': 'Previous',
  'next': 'Next',
  'stock': 'Available stock',
  'cost': 'Points required',
  'pendingStatus': 'Pending manual fulfillment',
  'fulfilled': 'Fulfilled',
  'rejected': 'Rejected',
  'unknownStatus': 'Unrecognized status',
  'Silver': 'Silver',
  'Gold': 'Gold',
  'Platinum': 'Platinum',
  'earn': 'Earned',
  'reversal': 'Source reversal',
  'expiry': 'Expired',
  'redemption': 'Reward reservation',
  'release': 'Released',
  'inactive': 'Policy inactive',
  'detail': 'Request history',
  'events': 'Audit events',
  'unknownKind': 'Points adjustment',
};
const loyaltyAr = {
  'title': 'النقاط والمكافآت',
  'notice':
      'نقاط ترويجية تجريبية فقط. لا قيمة نقدية ولا تحويل أو دفع. الطلب المعلق لا يعني تسليم المكافأة.',
  'account': 'نقاطي',
  'ledger': 'سجل النقاط',
  'rewards': 'كتالوج المكافآت',
  'redemptions': 'طلبات مكافآتي',
  'balance': 'النقاط المسجلة',
  'spendable': 'النقاط المتاحة',
  'pending': 'بانتظار المطابقة',
  'qualified': 'النقاط المؤهلة التراكمية',
  'hold': 'الاستبدال معلق. راجع سجلات معالجة الدفع.',
  'empty': 'لا توجد سجلات.',
  'refresh': 'تحديث',
  'previous': 'السابق',
  'next': 'التالي',
  'stock': 'المخزون المتاح',
  'cost': 'النقاط المطلوبة',
  'pendingStatus': 'بانتظار التسليم اليدوي',
  'fulfilled': 'تم التسليم',
  'rejected': 'مرفوض',
  'unknownStatus': 'حالة غير معروفة',
  'Silver': 'فضي',
  'Gold': 'ذهبي',
  'Platinum': 'بلاتيني',
  'earn': 'مكتسبة',
  'reversal': 'عكس من المصدر',
  'expiry': 'منتهية',
  'redemption': 'حجز مكافأة',
  'release': 'محررة',
  'inactive': 'السياسة غير فعالة',
  'detail': 'سجل الطلب',
  'events': 'أحداث التدقيق',
  'unknownKind': 'تعديل النقاط',
};
String loyaltyText(BuildContext c, String key) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? loyaltyAr
        : loyaltyEn)[key] ??
    key;

/// Read-only canonical promotional evidence. Never calculates economics,
/// starts a payment or posts an earning/redemption from a GET/refresh.
class LoyaltyView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final FinancialOperationStore? couponStore;
  const LoyaltyView({
    super.key,
    required this.api,
    required this.onExpired,
    this.couponStore,
  });
  @override
  State<LoyaltyView> createState() => _LoyaltyState();
}

class _LoyaltyState extends State<LoyaltyView>
    with WidgetsBindingObserver {
  String section = 'account';
  bool busy = false, foreground = true;
  late bool Function() authorized;
  Object? error;
  Map<String, dynamic>? account;
  ApiPage<Map<String, dynamic>>? page;
  int generation = 0, target = 1, formEpoch = 0;
  String t(String key) => loyaltyText(context, key);
  String n(int value) => NumberFormat.decimalPattern(
    Localizations.localeOf(context).languageCode,
  ).format(value);
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final state = WidgetsBinding.instance.lifecycleState;
    foreground = state == null || state == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant LoyaltyView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        !identical(oldWidget.couponStore, widget.couponStore) ||
        !authorized()) {
      generation++;
      authorized = widget.api.sessionFence();
      account = null;
      page = null;
      error = null;
      busy = false;
      section = 'account';
      target = 1;
      formEpoch++;
      final api = widget.api;
      final current = authorized;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && foreground && identical(api, widget.api) &&
            identical(current, authorized) && current()) load();
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (resumed == foreground) return;
    foreground = resumed;
    if (!resumed) {
      generation++;
      setState(() {
        account = null;
        page = null;
        error = null;
        busy = false;
      });
    } else {
      // Private points require a fresh, explicit refresh.
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  bool scoped(EvApi api, bool Function() fence) =>
      mounted && foreground && identical(api, widget.api) &&
      identical(fence, authorized) && fence();

  int integer(Map<String, dynamic> row, String key, {bool negative = false}) {
    final value = row[key];
    if (value is! int || (!negative && value < 0))
      throw const FormatException('Invalid points evidence.');
    return value;
  }

  void checkAccount(Map<String, dynamic> row) {
    if (row['unit'] != 'NON_MONETARY_POINTS' ||
        row['cash_value'] != false ||
        row['transferable'] != false ||
        row['evidence'] != 'TEST_PROMOTIONAL_ECONOMICS' ||
        row['redemption_hold'] is! bool ||
        row['tier_by_currency'] is! Map)
      throw const FormatException('Unsupported loyalty evidence.');
    integer(row, 'balance_points', negative: true);
    for (final key in [
      'pending_reconciliation_points',
      'spendable_points',
      'qualified_lifetime_points',
    ]) {
      integer(row, key);
    }
    for (final entry in (row['tier_by_currency'] as Map).entries) {
      if (!RegExp(r'^[A-Z]{3}$').hasMatch('${entry.key}') ||
          entry.value is! Map ||
          !['Silver', 'Gold', 'Platinum'].contains(entry.value['tier']) ||
          entry.value['enabled'] is! bool)
        throw const FormatException('Unsupported tier.');
    }
  }

  void checkRow(Map<String, dynamic> row) {
    integer(row, 'id');
    if (section == 'ledger') {
      integer(row, 'points', negative: true);
    } else if (section == 'rewards') {
      integer(row, 'points_cost');
      integer(row, 'available_stock');
      if (row['cash_value'] != false ||
          row['fulfillment'] != 'MANUAL_NON_MONETARY')
        throw const FormatException('Unsupported reward evidence.');
      for (final key in ['name', 'name_ar', 'description', 'description_ar']) {
        if (row[key] is! String)
          throw const FormatException('Unsupported reward text.');
      }
    } else {
      integer(row, 'points_cost');
      integer(row, 'reward_id');
    }
  }

  Future<void> load({int number = 1}) async {
    final api = widget.api;
    final fence = authorized;
    if (!scoped(api, fence)) return;
    final selected = section;
    final epoch = ++generation;
    bool current() => epoch == generation && scoped(api, fence) &&
        section == selected;
    target = number;
    setState(() {
      busy = true;
      error = null;
      account = null;
      page = null;
    });
    try {
      final result = await api.request(
        'commercial/loyalty/$selected',
        query: selected == 'account' ? const {} : {'page': '$number'},
      );
      if (!current()) return;
      if (selected == 'account') {
        final row = Map<String, dynamic>.from(result as Map);
        checkAccount(row);
        account = row;
      } else {
        final parsed = ApiPage.fromJson(result, (row) => row);
        for (final row in parsed.items) {
          checkRow(row);
        }
        page = parsed;
      }
    } catch (e) {
      if (!current()) return;
      error = e;
      account = null;
      page = null;
      if (e is ApiException && [401, 419].contains(e.status)) {
        widget.onExpired();
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  String status(Object? value) => t(switch (value) {
    'pending' => 'pendingStatus',
    'fulfilled' => 'fulfilled',
    'rejected' => 'rejected',
    _ => 'unknownStatus',
  });
  Widget accountCard(Map<String, dynamic> row) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final entry in {
            'balance': 'balance_points',
            'spendable': 'spendable_points',
            'pending': 'pending_reconciliation_points',
            'qualified': 'qualified_lifetime_points',
          }.entries)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Text('${t(entry.key)}: ${n(row[entry.value] as int)}'),
            ),
          if (row['redemption_hold'] == true)
            Text(
              t('hold'),
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          for (final entry in (row['tier_by_currency'] as Map).entries)
            Text(
              '${entry.key}: ${t(entry.value['tier'] as String)}${entry.value['enabled'] == false ? ' · ${t('inactive')}' : ''}',
            ),
        ],
      ),
    ),
  );
  Future<void> action(String kind, {int? reward}) async {
    final api = widget.api;
    final fence = authorized;
    if (!scoped(api, fence)) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => LoyaltyActionView(
          api: api,
          onExpired: widget.onExpired,
          action: kind,
          reward: reward,
        ),
      ),
    );
    if (scoped(api, fence)) await load(number: target);
  }

  Widget record(Map<String, dynamic> row) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final api = widget.api;
    final fence = authorized;
    if (section == 'rewards')
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                row[ar ? 'name_ar' : 'name'] as String,
                style: Theme.of(context).textTheme.titleMedium,
              ),
              Text(row[ar ? 'description_ar' : 'description'] as String),
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () {
                        if (scoped(api, fence)) {
                          action('redeem', reward: row['id'] as int);
                        }
                      },
                child: Text(loyaltyActionText(context, 'redeem')),
              ),
              Text(
                '${t('cost')}: ${n(row['points_cost'] as int)} · ${t('stock')}: ${n(row['available_stock'] as int)}',
              ),
            ],
          ),
        ),
      );
    if (section == 'ledger') {
      final kind = row['kind'];
      return Card(
        child: ListTile(
          title: Text(
            '${t(['earn', 'reversal', 'expiry', 'redemption', 'release'].contains(kind) ? kind as String : 'unknownKind')}: ${n(row['points'] as int)}',
          ),
          subtitle: Text(serverEventTime(context, row['created_at'])),
        ),
      );
    }
    return Card(
      child: ListTile(
        title: Text('#${n(row['id'] as int)} · ${status(row['status'])}'),
        subtitle: Text(
          '${t('cost')}: ${n(row['points_cost'] as int)}\n'
          '${serverEventTime(context, row['created_at'])}',
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: () {
          if (!scoped(api, fence)) return;
          Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => LoyaltyRequestView(
                api: api,
                id: row['id'] as int,
                onExpired: widget.onExpired,
              ),
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!foreground || !authorized()) return const Scaffold();
    final api = widget.api;
    final fence = authorized;
    bool same() => scoped(api, fence);
    return Scaffold(
    appBar: AppBar(
      title: Text(t('title')),
      actions: [
        TextButton(
          onPressed: () {
            if (!same()) return;
            Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => _CouponView(
                  api: api,
                  onExpired: widget.onExpired,
                  store: widget.couponStore,
                ),
              ),
            );
          },
          child: Text(
            Localizations.localeOf(context).languageCode == 'ar'
                ? 'القسائم'
                : 'Coupons',
          ),
        ),
      ],
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          key: ValueKey('loyalty-section-$formEpoch'),
          initialValue: section,
          isExpanded: true,
          items: [
            for (final key in ['account', 'ledger', 'rewards', 'redemptions'])
              DropdownMenuItem(value: key, child: Text(t(key))),
          ],
          onChanged: busy
              ? null
              : (value) {
                  if (value != null && same()) {
                    section = value;
                    load();
                  }
                },
        ),
        const SizedBox(height: 12),
        if (section == 'account')
          Wrap(
            spacing: 12,
            runSpacing: 8,
            children: [
              for (final kind in ['earn', 'reconcile'])
                OutlinedButton(
                  onPressed: busy ? null : () {
                     if (same()) action(kind);
                   },
                  child: Text(loyaltyActionText(context, kind)),
                ),
            ],
          ),
        OutlinedButton.icon(
          onPressed: busy ? null : () {
             if (same()) load(number: target);
           },
          icon: const Icon(Icons.refresh),
          label: Text(t('refresh')),
        ),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
        if (account != null) accountCard(account!),
        if (page != null && page!.items.isEmpty) Text(t('empty')),
        if (page != null) ...[
          for (final row in page!.items) record(row),
          Wrap(
            spacing: 12,
            runSpacing: 8,
            alignment: WrapAlignment.center,
            children: [
              OutlinedButton(
                onPressed: busy || page!.current <= 1
                    ? null
                    : () { if (same()) load(number: page!.current - 1); },
                child: Text(t('previous')),
              ),
              Text(
                '${n(page!.current)} / ${n(page!.last)} · ${n(page!.total)}',
              ),
              OutlinedButton(
                onPressed: busy || page!.current >= page!.last
                    ? null
                    : () { if (same()) load(number: page!.current + 1); },
                child: Text(t('next')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
  }
}

class LoyaltyRequestView extends StatefulWidget {
  final EvApi api;
  final int id;
  final VoidCallback onExpired;
  const LoyaltyRequestView({
    super.key,
    required this.api,
    required this.id,
    required this.onExpired,
  });
  @override
  State<LoyaltyRequestView> createState() => _LoyaltyRequestState();
}

class _LoyaltyRequestState extends State<LoyaltyRequestView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? value;
  Object? error;
  bool busy = false, foreground = true;
  late bool Function() authorized;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant LoyaltyRequestView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.id != widget.id || !authorized()) {
      generation++;
      authorized = widget.api.sessionFence();
      value = null;
      error = null;
      busy = false;
      final api = widget.api;
      final fence = authorized;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && foreground && identical(api, widget.api) &&
            identical(fence, authorized) && fence()) load();
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (resumed == foreground) return;
    foreground = resumed;
    if (!resumed) {
      generation++;
      setState(() {
        value = null;
        error = null;
        busy = false;
      });
    } else {
      // No implicit reuse of a previously downloaded private reward receipt.
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  bool scoped(EvApi api, bool Function() fence) =>
      mounted && foreground && identical(widget.api, api) &&
      identical(authorized, fence) && fence();

  Future<void> load() async {
    final api = widget.api;
    final fence = authorized;
    if (!scoped(api, fence)) return;
    final id = widget.id;
    final epoch = ++generation;
    bool current() => generation == epoch && scoped(api, fence) &&
        widget.id == id;
    setState(() {
      busy = true;
      value = null;
      error = null;
    });
    try {
      final result = Map<String, dynamic>.from(
        await api.request('commercial/loyalty/redemptions/$id') as Map,
      );
      if (!current()) return;
      final redemption = result['redemption'];
      final reward = result['reward'];
      final events = result['events'];
      if (redemption is! Map ||
          redemption['id'] != id ||
          redemption['points_cost'] is! int ||
          reward is! Map ||
          events is! List ||
          events.any(
            (e) =>
                e is! Map || e['action'] is! String || e['reason'] is! String,
          )) {
        throw const FormatException('Invalid reward history.');
      }
      if (current()) setState(() => value = result);
    } catch (e) {
      if (current()) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
        }
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    if (!foreground || !authorized()) return const Scaffold();
    final t = (String key) => loyaltyText(c, key);
    final ar = Localizations.localeOf(c).languageCode == 'ar';
    final api = widget.api;
    final fence = authorized;
    return Scaffold(
      appBar: AppBar(title: Text(t('detail'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          OutlinedButton(
            onPressed: busy ? null : () {
              if (scoped(api, fence)) load();
            },
            child: Text(t('refresh')),
          ),
          if (busy) const Center(child: CircularProgressIndicator()),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (value != null) ...[
            Text(
              '${value!['reward'][ar ? 'name_ar' : 'name'] ?? ''}',
              style: Theme.of(c).textTheme.titleLarge,
            ),
            Text(
              t(switch (value!['redemption']['status']) {
                'pending' => 'pendingStatus',
                'fulfilled' => 'fulfilled',
                'rejected' => 'rejected',
                _ => 'unknownStatus',
              }),
            ),
            Text(
              '${t('cost')}: ${NumberFormat.decimalPattern(ar ? 'ar' : 'en').format(value!['redemption']['points_cost'])}',
            ),
            Text(t('events'), style: Theme.of(c).textTheme.titleMedium),
            for (final row in value!['events'] as List)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    '${serverEventTime(c, row['created_at'])}\n${row['reason']}',
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _CouponView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;
  const _CouponView({required this.api, required this.onExpired, this.store});

  @override
  State<_CouponView> createState() => _CouponState();
}

class _CouponState extends State<_CouponView> with WidgetsBindingObserver {
  final code = TextEditingController();
  late bool Function() ownsSession;
  FinancialOperation? operation;
  int? owner;
  Map<String, dynamic>? get pending => operation?.pending;
  Map? get draft => pending?['body'] as Map?;
  static String digest(String value) =>
      sha256.convert(utf8.encode(value)).toString();
  List<Map<String, dynamic>> receipts = [];
  List<Map<String, dynamic>> offers = [];
  bool browsing = false;
  bool hasHistory = false;
  int page = 1;
  int last = 1;
  int generation = 0;
  bool busy = false, foreground = true;
  NoncashCapabilities? capability;
  String? message;

  String label(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) loadCapability();
  }

  @override
  void didUpdateWidget(covariant _CouponView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        !identical(oldWidget.store, widget.store) ||
        !ownsSession()) {
      generation++;
      ownsSession = widget.api.sessionFence();
      operation = null;
      owner = null;
      capability = null;
      message = null;
      code.clear();
      clearRecords();
      busy = false;
      final api = widget.api;
      final bound = ownsSession;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && foreground && identical(api, widget.api) &&
            identical(bound, ownsSession) && bound()) {
          loadCapability();
        }
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    foreground = resumed;
    if (resumed) {
      if (ownsSession()) loadCapability();
      return;
    }
    setState(() {
      generation++;
      operation = null;
      owner = null;
      capability = null;
      code.clear();
      clearRecords();
      message = null;
      busy = false;
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    code.dispose();
    super.dispose();
  }

  bool scoped(EvApi api, bool Function() fence) =>
      mounted && foreground && identical(widget.api, api) &&
      identical(ownsSession, fence) && fence();

  Future<void> loadCapability() async {
    final api = widget.api;
    final fence = ownsSession;
    if (!scoped(api, fence)) return;
    final epoch = ++generation;
    bool current() => generation == epoch && scoped(api, fence);
    setState(() => busy = true);
    try {
      final me = await api.request('me');
      if (!current()) return;
      if (me['id'] is! int || me['role'] != 'customer') {
        throw const FormatException('Invalid coupon account');
      }
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${api.base.origin}:${me['id']}:loyalty:coupon',
        isCurrent: () => scoped(api, fence),
      );
      await op.restore();
      if (!current()) return;
      setState(() {
        operation = op;
        owner = me['id'] as int;
      });
      final value = await api.request('commercial/noncash/status');
      if (!current()) return;
      setState(() => capability = NoncashCapabilities.parse(value));
    } catch (error) {
      if (current() &&
          error is ApiException &&
          error.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  void clearRecords() {
    receipts = [];
    offers = [];
    browsing = false;
    hasHistory = false;
    page = 1;
    last = 1;
  }

  Map<String, dynamic> offer(Map<String, dynamic> row) {
    if (row['id'] is! int ||
        row['reward_name'] is! String ||
        row['reward_name_ar'] is! String ||
        row['ends_at'] is! String) {
      throw const FormatException('Invalid eligible coupon');
    }
    return row;
  }

  Map<String, dynamic> receipt(dynamic value) {
    final row = Map<String, dynamic>.from(value as Map);
    if (row['id'] is! int ||
        row['points_cost'] != 0 ||
        row['cash_value'] != false ||
        row['source'] != 'COUPON' ||
        row['delivery_verified'] != false ||
        row['fulfillment'] != 'MANUAL_NON_MONETARY' ||
        !['pending', 'fulfilled', 'rejected'].contains(row['status']) ||
        row['reward_name'] is! String ||
        row['reward_name_ar'] is! String ||
        row['claimed_at'] is! String) {
      throw const FormatException('Invalid coupon receipt');
    }
    return {...row, 'receipt_mode': NoncashReceiptEvidence.parse(row).mode};
  }

  Future<void> run(String action, {int number = 1, int? coupon}) async {
    final op = operation;
    final ownerId = owner;
    final api = widget.api;
    final fence = ownsSession;
    if (busy ||
        !scoped(api, fence) ||
        op == null ||
        ownerId == null ||
        (action == 'claim' && capability?.enabled != true))
      return;
    final entered = code.text.trim().toUpperCase();
    Map<String, dynamic>? intent;
    if (action == 'claim') {
      if (coupon != null) {
        if (pending != null && draft?['coupon'] != coupon) return;
        intent = {'coupon': coupon};
      } else {
        if (!RegExp(r'^[A-Z0-9-]{12,40}$').hasMatch(entered)) {
          setState(
            () => message = label(
              'Enter a valid coupon code.',
              'أدخل رمز قسيمة صالحاً.',
            ),
          );
          return;
        }
        final codeHash = digest(entered);
        if (pending != null && draft?['code_hash'] != codeHash) {
          setState(
            () => message = label(
              'Re-enter the original code or check the saved receipt.',
              'أعد إدخال الرمز الأصلي أو تحقق من الإيصال المحفوظ.',
            ),
          );
          return;
        }
        intent = {'code_hash': codeHash};
      }
    }
    if (action == 'claim-receipt' && pending == null) return;
    final read = action == 'claims' || action == 'eligible';
    final endpoint = coupon == null ? action : 'eligible/$coupon/claim';
    final epoch = ++generation;
    bool current() => generation == epoch && scoped(api, fence);
    setState(() {
      busy = true;
      message = null;
    });
    try {
      final staged = intent == null ? null : await op.prepare(intent);
      if (!current()) return;
      final key = staged?['idempotency_key'] ?? pending?['key'];
      final result = await api.request(
        'commercial/coupons/$endpoint',
        method: read ? 'GET' : 'POST',
        query: read ? {'page': '$number'} : const {},
        body: read
            ? null
            : {
                'idempotency_key': key,
                if (action == 'claim' && coupon == null) 'code': entered,
              },
      );
      if (!current()) return;
      if (action == 'eligible') {
        final parsed = ApiPage.fromJson(result, offer);
        setState(() {
          browsing = true;
          hasHistory = false;
          offers = parsed.items;
          receipts = [];
          page = parsed.current;
          last = parsed.last;
        });
      } else if (action == 'claims') {
        final parsed = ApiPage.fromJson(result, receipt);
        setState(() {
          browsing = false;
          hasHistory = true;
          offers = [];
          receipts = parsed.items;
          page = parsed.current;
          last = parsed.last;
        });
      } else if (result['claim'] != null) {
        final row = receipt(result['claim']);
        if (draft?['coupon'] != null && row['coupon_id'] != draft?['coupon']) {
          throw const FormatException('Coupon receipt identity changed');
        }
        if (draft?['code_hash'] case final String codeHash) {
          final request = digest('coupon-claim:$ownerId:$key');
          if (row['request_binding'] != digest('$codeHash:$request')) {
            throw const FormatException('Coupon receipt binding changed');
          }
        }
        await op.confirmed();
        if (!current()) return;
        setState(() {
          clearRecords();
          receipts = [row];
          code.clear();
        });
      } else if (action == 'claim-receipt' && result['claim'] == null) {
        setState(
          () => message = label(
            'No receipt has been recorded for this request.',
            'لم يتم تسجيل إيصال لهذا الطلب.',
          ),
        );
      } else {
        throw const FormatException('Missing coupon receipt');
      }
    } catch (error) {
      if (!current()) return;
      if (error is ApiException && error.endsCurrentSession) {
        setState(() {
          operation = null;
          owner = null;
          code.clear();
          clearRecords();
          message = null;
        });
        widget.onExpired();
      } else if (current()) {
        setState(() {
          if (read) {
            clearRecords();
            message = label(
              'Could not load coupons. Please retry.',
              'تعذر تحميل القسائم. أعد المحاولة.',
            );
          } else if (action == 'claim' &&
              error is ApiException &&
              [404, 409, 422].contains(error.status)) {
            message = label(
              'Coupon request declined. Check the code, expiry and limits.',
              'رُفض طلب القسيمة. تحقق من الرمز والصلاحية والحدود.',
            );
          } else {
            message = label(
              'Coupon request could not be confirmed. Check the receipt or retry the same request.',
              'تعذر تأكيد طلب القسيمة. تحقق من الإيصال أو أعد الطلب نفسه.',
            );
          }
        });
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  Future<void> clearDraft() async {
    final op = operation;
    final api = widget.api;
    final fence = ownsSession;
    final saved = pending;
    final ownerId = owner;
    final savedBody = draft;
    if (op == null || busy || !scoped(api, fence) ||
        saved == null || ownerId == null || savedBody == null) return;
    final key = saved['key'];
    final coupon = savedBody['coupon'];
    final codeHash = savedBody['code_hash'];
    final entered = code.text.trim().toUpperCase();
    if (key is! String ||
        !(coupon is int && coupon > 0 && codeHash == null ||
            coupon == null && codeHash is String)) return;
    if (coupon == null &&
        (!RegExp(r'^[A-Z0-9-]{12,40}$').hasMatch(entered) ||
            digest(entered) != codeHash)) {
      setState(() => message = label(
        'Re-enter the original code to cancel the saved claim.',
        'أعد إدخال الرمز الأصلي لإلغاء المطالبة المحفوظة.',
      ));
      return;
    }
    final epoch = ++generation;
    bool current() => generation == epoch && scoped(api, fence);
    setState(() {
      busy = true;
      message = null;
    });
    try {
      final request = digest('coupon-claim:$ownerId:$key');
      final binding = digest(coupon == null
          ? '$codeHash:$request'
          : 'coupon-cancel-v1:$coupon:$request');
      final result = await api.request('commercial/coupons/claim-intents/cancel',
        method: 'POST', body: {
          'idempotency_key': key,
          if (coupon == null) 'code': entered else 'coupon_id': coupon,
        });
      if (!current()) return;
      if (result['request_binding'] != binding ||
          (coupon != null && result['coupon_id'] != coupon)) {
        throw const FormatException('Invalid coupon cancellation binding');
      }
      final Map<String, dynamic>? accepted;
      if (result['state'] == 'accepted') {
        accepted = receipt(result['claim']);
        if ((coupon != null && accepted['coupon_id'] != coupon) ||
            (coupon == null && accepted['request_binding'] != binding)) {
          throw const FormatException('Invalid accepted coupon identity');
        }
      } else if (result['state'] == 'cancelled' &&
          result.containsKey('claim') && result['claim'] == null) {
        accepted = null;
      } else {
        throw const FormatException('Invalid coupon cancellation state');
      }
      await op.confirmed();
      if (!current()) return;
      setState(() {
        clearRecords();
        code.clear();
        if (accepted != null) {
          receipts = [accepted];
        } else {
          message = label('Saved claim cancelled by the server.',
              'ألغى الخادم المطالبة المحفوظة.');
        }
      });
    } catch (error) {
      if (current()) {
        if (error is ApiException && error.endsCurrentSession) {
          setState(() {
            operation = null;
            owner = null;
            code.clear();
            clearRecords();
            message = null;
          });
          widget.onExpired();
        } else {
          setState(() => message = label(
            'Cancellation not confirmed. Keep the same saved claim.',
            'لم يتأكد الإلغاء. احتفظ بالمطالبة المحفوظة نفسها.',
          ));
        }
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!foreground || !ownsSession()) return const Scaffold();
    final api = widget.api;
    final fence = ownsSession;
    bool same() => scoped(api, fence);
    void guardedRun(String action, {int number = 1, int? coupon}) {
      if (same()) run(action, number: number, coupon: coupon);
    }
    return Scaffold(
    appBar: AppBar(title: Text(label('Reward coupons', 'قسائم المكافآت'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          label(
            'Enter a code you received to claim a noncash reward.',
            'أدخل الرمز الذي تلقيته للمطالبة بمكافأة غير نقدية.',
          ),
        ),
        if (capability?.enabled == false)
          Text(
            label(
              'New coupon claims are disabled. Existing receipts remain available.',
              'المطالبات الجديدة بالقسائم معطلة. تبقى الإيصالات الموجودة متاحة.',
            ),
          ),
        if (capability == null && !busy)
          TextButton(
            onPressed: () {
              if (same()) loadCapability();
            },
            child: Text(
              label(
                'Retry coupon availability',
                'إعادة التحقق من توفر القسائم',
              ),
            ),
          ),
        TextField(
          controller: code,
          enabled:
              !busy && (capability?.enabled == true || draft?['code_hash'] != null) && draft?['coupon'] == null,
          maxLength: 40,
          textCapitalization: TextCapitalization.characters,
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(
            labelText: label('Coupon code', 'رمز القسيمة'),
          ),
        ),
        FilledButton(
          onPressed:
              busy || capability?.enabled != true || draft?['coupon'] != null
              ? null
              : () => guardedRun('claim'),
          child: Text(label('Claim coupon', 'المطالبة بالقسيمة')),
        ),
        if (pending != null)
          TextButton(
            onPressed: busy ? null : () => guardedRun('claim-receipt'),
            child: Text(
              label('Check last claim receipt', 'التحقق من إيصال المطالبة'),
            ),
          ),
        if (pending != null)
          TextButton(
            onPressed: busy ? null : () {
              if (same()) clearDraft();
            },
            child: Text(label('Clear coupon draft', 'مسح مسودة القسيمة')),
          ),
        TextButton(
          onPressed: busy ? null : () => guardedRun('claims'),
          child: Text(label('Refresh coupon history', 'تحديث سجل القسائم')),
        ),
        TextButton(
          onPressed: busy ? null : () => guardedRun('eligible'),
          child: Text(label('Browse eligible coupons', 'تصفح القسائم المؤهلة')),
        ),
        if (hasHistory && receipts.isEmpty && !busy)
          Text(label('No coupon claims', 'لا توجد مطالبات بالقسائم')),
        if (browsing && offers.isEmpty && !busy)
          Text(label('No eligible coupons', 'لا توجد قسائم مؤهلة')),
        for (final offer in offers)
          ListTile(
            title: Text(
              label('${offer['reward_name']}', '${offer['reward_name_ar']}'),
            ),
            subtitle: Text('${offer['ends_at']} UTC'),
            trailing: TextButton(
              onPressed:
                  busy ||
                      capability?.enabled != true ||
                      offer['id'] is! int ||
                      (pending != null && draft?['coupon'] != offer['id'])
                  ? null
                  : () => guardedRun('claim', coupon: offer['id'] as int),
              child: Text(label('Claim', 'مطالبة')),
            ),
          ),
        if (busy) const LinearProgressIndicator(),
        if (message != null) Semantics(liveRegion: true, child: Text(message!)),
        for (final row in receipts)
          ListTile(
            title: Text(
              label(
                row['reward_name'] as String,
                row['reward_name_ar'] as String,
              ),
            ),
            subtitle: Text(
              [
                '${row['claimed_at']} · #${row['id']}',
                row['receipt_mode'] == 'reviewed_manual'
                    ? label(
                        'Reviewed manual noncash record',
                        'سجل غير نقدي يدوي مراجع',
                      )
                    : label('Sandbox test record', 'سجل تجريبي'),
              ].join('\n'),
            ),
            trailing: Text(switch (row['status']) {
              'fulfilled' => label('Fulfilled', 'تم التسليم'),
              'rejected' => label('Rejected', 'مرفوض'),
              _ => label('Pending fulfillment', 'بانتظار التسليم'),
            }),
          ),
        if (page > 1)
          TextButton(
            onPressed: busy
                ? null
                : () => guardedRun(browsing ? 'eligible' : 'claims', number: page - 1),
            child: Text(label('Previous', 'السابق')),
          ),
        if (page < last)
          TextButton(
            onPressed: busy
                ? null
                : () => guardedRun(browsing ? 'eligible' : 'claims', number: page + 1),
            child: Text(label('Next', 'التالي')),
          ),
      ],
    ),
  );
  }
}
