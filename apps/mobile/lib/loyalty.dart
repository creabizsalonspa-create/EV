import 'package:flutter/material.dart';
import 'package:intl/intl.dart' show NumberFormat;
import 'api.dart';
import 'strings.dart';
import 'loyalty_actions.dart';

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
  const LoyaltyView({super.key, required this.api, required this.onExpired});
  @override
  State<LoyaltyView> createState() => _LoyaltyState();
}

class _LoyaltyState extends State<LoyaltyView> {
  String section = 'account';
  bool busy = false;
  Object? error;
  Map<String, dynamic>? account;
  ApiPage<Map<String, dynamic>>? page;
  int generation = 0, target = 1;
  String t(String key) => loyaltyText(context, key);
  String n(int value) => NumberFormat.decimalPattern(
    Localizations.localeOf(context).languageCode,
  ).format(value);
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

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
    final epoch = ++generation;
    target = number;
    setState(() {
      busy = true;
      error = null;
      account = null;
      page = null;
    });
    try {
      final result = await widget.api.request(
        'commercial/loyalty/$section',
        query: section == 'account' ? const {} : {'page': '$number'},
      );
      if (!mounted || epoch != generation) return;
      if (section == 'account') {
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
      if (!mounted || epoch != generation) return;
      error = e;
      account = null;
      page = null;
      if (e is ApiException && [401, 419].contains(e.status)) {
        widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
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
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => LoyaltyActionView(
          api: widget.api,
          onExpired: widget.onExpired,
          action: kind,
          reward: reward,
        ),
      ),
    );
    if (mounted) await load(number: target);
  }

  Widget record(Map<String, dynamic> row) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
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
                    : () => action('redeem', reward: row['id'] as int),
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
          subtitle: Text('${row['created_at'] ?? ''}'),
        ),
      );
    }
    return Card(
      child: ListTile(
        title: Text('#${n(row['id'] as int)} · ${status(row['status'])}'),
        subtitle: Text('${t('cost')}: ${n(row['points_cost'] as int)}'),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => LoyaltyRequestView(
              api: widget.api,
              id: row['id'] as int,
              onExpired: widget.onExpired,
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          initialValue: section,
          isExpanded: true,
          items: [
            for (final key in ['account', 'ledger', 'rewards', 'redemptions'])
              DropdownMenuItem(value: key, child: Text(t(key))),
          ],
          onChanged: busy
              ? null
              : (value) {
                  if (value != null) {
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
                  onPressed: busy ? null : () => action(kind),
                  child: Text(loyaltyActionText(context, kind)),
                ),
            ],
          ),
        OutlinedButton.icon(
          onPressed: busy ? null : () => load(number: target),
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
                    : () => load(number: page!.current - 1),
                child: Text(t('previous')),
              ),
              Text(
                '${n(page!.current)} / ${n(page!.last)} · ${n(page!.total)}',
              ),
              OutlinedButton(
                onPressed: busy || page!.current >= page!.last
                    ? null
                    : () => load(number: page!.current + 1),
                child: Text(t('next')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
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

class _LoyaltyRequestState extends State<LoyaltyRequestView> {
  Map<String, dynamic>? value;
  Object? error;
  bool busy = false;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      value = null;
      error = null;
    });
    try {
      final result = Map<String, dynamic>.from(
        await widget.api.request('commercial/loyalty/redemptions/${widget.id}')
            as Map,
      );
      final redemption = result['redemption'];
      final reward = result['reward'];
      final events = result['events'];
      if (redemption is! Map ||
          redemption['id'] != widget.id ||
          redemption['points_cost'] is! int ||
          reward is! Map ||
          events is! List ||
          events.any(
            (e) =>
                e is! Map || e['action'] is! String || e['reason'] is! String,
          ))
        throw const FormatException('Invalid reward history.');
      if (mounted && epoch == generation) setState(() => value = result);
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final t = (String key) => loyaltyText(c, key);
    final ar = Localizations.localeOf(c).languageCode == 'ar';
    return Scaffold(
      appBar: AppBar(title: Text(t('detail'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          OutlinedButton(
            onPressed: busy ? null : load,
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
                  child: Text('${row['created_at'] ?? ''}\n${row['reason']}'),
                ),
              ),
          ],
        ],
      ),
    );
  }
}
