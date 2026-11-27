import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';

int _exact(dynamic raw, {bool positive = false, bool string = false}) {
  final value = string && raw is String &&
          RegExp(r'^[1-9][0-9]{0,15}$').hasMatch(raw)
      ? int.tryParse(raw) : raw;
  if (value is! int || value < (positive ? 1 : 0) ||
      value > 9007199254740991) {
    throw const FormatException('Invalid campaign source integer.');
  }
  return value;
}

const _targets = {'approve': 'approved', 'reject': 'rejected',
  'suspend': 'suspended', 'reinstate': 'approved'};

class AdminCampaignRecord {
  final int id, vendor, invoice, station, revision, budget, delivered, clicks;
  final String status, title, titleAr, description, descriptionAr, country,
      placement, starts, ends;
  final String? region;
  AdminCampaignRecord._(this.id, this.vendor, this.invoice, this.station,
    this.revision, this.budget, this.delivered, this.clicks, this.status,
    this.title, this.titleAr, this.description, this.descriptionAr, this.country,
    this.placement, this.starts, this.ends, this.region);

  factory AdminCampaignRecord.parse(dynamic raw, {int? id}) {
    if (raw is! Map || raw.containsKey('request_key') ||
        raw.containsKey('fingerprint') || raw['sponsored'] != true ||
        raw['unit'] != 'PREPAID_PACKAGE_DELIVERY_UNIT' ||
        raw['additional_cash_charge'] != false ||
        raw['human_impressions_verified'] != false ||
        !['draft', 'submitted', 'approved', 'rejected', 'paused',
          'suspended', 'cancelled'].contains(raw['status']) ||
        !['discovery_banner', 'station_detail'].contains(raw['placement']) ||
        raw['country'] is! String ||
        !RegExp(r'^[A-Z]{2}$').hasMatch(raw['country']) ||
        (raw['region'] != null && (raw['region'] is! String ||
          (raw['region'] as String).length > 100))) {
      throw const FormatException('Untrusted campaign evidence.');
    }
    String creative(String key, int limit) {
      final v = raw[key];
      if (v is! String || v.trim().isEmpty || v.length > limit) {
        throw const FormatException('Invalid campaign creative.');
      }
      return v;
    }
    DateTime date(String key) {
      final v = raw[key];
      if (v is! String) throw const FormatException('Invalid campaign date.');
      return DateTime.parse(v.contains('T') ? v : '${v.replaceFirst(' ', 'T')}Z').toUtc();
    }
    final sourceId = _exact(raw['id'], positive: true);
    final budget = _exact(raw['budget_units'], positive: true);
    final delivered = _exact(raw['delivered_units']);
    final clicks = _exact(raw['verified_server_clicks']);
    if ((id != null && id != sourceId) || budget > 1000000 ||
        delivered > budget || clicks > delivered ||
        !date('ends_at').isAfter(date('starts_at'))) {
      throw const FormatException('Invalid campaign budget or identity.');
    }
    return AdminCampaignRecord._(sourceId, _exact(raw['vendor_id'], positive: true),
      _exact(raw['invoice_id'], positive: true), _exact(raw['station_id'], positive: true),
      _exact(raw['control_revision'], positive: true, string: true), budget,
      delivered, clicks, raw['status'], creative('title', 120),
      creative('title_ar', 120), creative('description', 500),
      creative('description_ar', 500), raw['country'], raw['placement'],
      raw['starts_at'], raw['ends_at'], raw['region']);
  }

  List<String> get actions => switch (status) {
    'submitted' => const ['approve', 'reject'],
    'approved' => const ['suspend'],
    'suspended' => const ['reinstate'],
    _ => const [],
  };

  bool sameTerms(AdminCampaignRecord other) =>
      id == other.id && vendor == other.vendor && invoice == other.invoice &&
      station == other.station && budget == other.budget &&
      title == other.title && titleAr == other.titleAr &&
      description == other.description && descriptionAr == other.descriptionAr &&
      country == other.country && placement == other.placement &&
      starts == other.starts && ends == other.ends && region == other.region;

  void verifyDecision(AdminCampaignRecord receipt, String action) {
    if (!actions.contains(action) || !sameTerms(receipt) ||
        receipt.status != _targets[action] || receipt.revision <= revision ||
        receipt.delivered < delivered || receipt.clicks < clicks) {
      throw const FormatException('Unconfirmed campaign decision.');
    }
  }
}

class AdminCampaignEvent {
  final int id, actor;
  final String action, reason, createdAt;
  const AdminCampaignEvent(this.id, this.actor, this.action, this.reason, this.createdAt);

  static List<AdminCampaignEvent> parseHistory(dynamic raw, AdminCampaignRecord record) {
    if (raw is! List || raw.isEmpty) throw const FormatException('Missing campaign history.');
    final result = <AdminCampaignEvent>[];
    String? state;
    const transitions = <String, (String, String)>{
      'submit': ('draft', 'submitted'), 'approve': ('submitted', 'approved'),
      'reject': ('submitted', 'rejected'), 'pause': ('approved', 'paused'),
      'resume': ('paused', 'approved'), 'suspend': ('approved', 'suspended'),
      'reinstate': ('suspended', 'approved'),
    };
    for (final event in raw) {
      if (event is! Map || _exact(event['campaign_id'], positive: true) != record.id ||
          !['created', 'submit', 'approve', 'reject', 'pause', 'resume',
            'cancel', 'suspend', 'reinstate'].contains(event['action']) ||
          event['reason'] is! String || (event['reason'] as String).length > 500 ||
          event['created_at'] is! String || DateTime.tryParse(event['created_at']) == null) {
        throw const FormatException('Invalid private campaign audit.');
      }
      final id = _exact(event['id'], positive: true);
      if (result.isNotEmpty && result.last.id >= id) {
        throw const FormatException('Invalid campaign audit order.');
      }
      final actor = _exact(event['actor_id'], positive: true);
      final action = event['action'] as String;
      // Replays do not append events. A plausible terminal status cannot
      // hide an invalid path through the canonical control state machine.
      if (state == null) {
        if (action != 'created' || actor != record.vendor) {
          throw const FormatException('Invalid campaign creation authority.');
        }
        state = 'draft';
      } else if (action == 'cancel') {
        if (!['draft', 'submitted', 'approved', 'paused', 'suspended'].contains(state) ||
            actor != record.vendor) {
          throw const FormatException('Invalid campaign cancellation history.');
        }
        state = 'cancelled';
      } else {
        final transition = transitions[action];
        if (transition == null || transition.$1 != state ||
            (['submit', 'pause', 'resume'].contains(action) && actor != record.vendor)) {
          throw const FormatException('Invalid campaign control history.');
        }
        state = transition.$2;
      }
      result.add(AdminCampaignEvent(id, actor,
        action, event['reason'], event['created_at']));
    }
    if (result.last.id != record.revision || state != record.status) {
      throw const FormatException('Campaign revision does not match history.');
    }
    return List.unmodifiable(result);
  }
}

const _reviewFields = ['status', 'country', 'vendor_id', 'station_id', 'invoice_id'];
const _reviewStates = ['draft', 'submitted', 'approved', 'rejected', 'paused', 'suspended', 'cancelled'];

class AdminCampaignPage {
  final List<AdminCampaignRecord> rows;
  final int number, last;
  final bool enabled;
  AdminCampaignPage._(this.rows, this.number, this.last, this.enabled);

  static void verifyScope(AdminCampaignRecord row, Map<String, String> scope) {
    if ((scope['status'] != null && row.status != scope['status']) ||
        (scope['country'] != null && row.country != scope['country']) ||
        (scope['vendor_id'] != null && '${row.vendor}' != scope['vendor_id']) ||
        (scope['station_id'] != null && '${row.station}' != scope['station_id']) ||
        (scope['invoice_id'] != null && '${row.invoice}' != scope['invoice_id'])) {
      throw const FormatException('Campaign outside requested filter.');
    }
  }

  factory AdminCampaignPage.parse(dynamic raw, int requested,
      {Map<String, String> scope = const {}}) {
    if (raw is! Map || raw['data'] is! List || raw['adapter_enabled'] is! bool) {
      throw const FormatException('Invalid campaign page.');
    }
    final current = _exact(raw['current_page'], positive: true);
    final last = _exact(raw['last_page'], positive: true);
    final total = _exact(raw['total']);
    final size = _exact(raw['per_page'], positive: true);
    if (current != requested || current > 100000 || size != 25 ||
        last != (total == 0 ? 1 : (total - 1) ~/ size + 1)) {
      throw const FormatException('Invalid campaign pagination.');
    }
    final remaining = total - (current - 1) * size;
    final expected = remaining < 0 ? 0 : remaining > size ? size : remaining;
    final rows = (raw['data'] as List).map(AdminCampaignRecord.parse).toList();
    if (rows.length != expected || rows.map((r) => r.id).toSet().length != rows.length) {
      throw const FormatException('Invalid campaign page scope.');
    }
    final echo = raw['scope'];
    if (scope.isNotEmpty || echo != null) {
      if (echo is! Map || echo.length != _reviewFields.length ||
          !_reviewFields.every(echo.containsKey)) {
        throw const FormatException('Invalid campaign filter authority.');
      }
      for (final key in _reviewFields) {
        final expected = scope[key];
        final value = expected == null ? null :
            key.endsWith('_id') ? int.parse(expected) : expected;
        if (echo[key] != value || (value is int && echo[key] is! int)) {
          throw const FormatException('Invalid campaign filter authority.');
        }
      }
      for (final row in rows) { verifyScope(row, scope); }
    }
    return AdminCampaignPage._(List.unmodifiable(rows), current, last, raw['adapter_enabled']);
  }
}

class AdminCampaignReviewView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const AdminCampaignReviewView({super.key, required this.api, required this.onExpired});
  @override
  State<AdminCampaignReviewView> createState() => _AdminCampaignState();
}

class _AdminCampaignState extends State<AdminCampaignReviewView>
    with WidgetsBindingObserver {
  final reason = TextEditingController();
  final filters = {for (final key in _reviewFields.skip(1)) key: TextEditingController()};
  String filterStatus = '';
  bool filtersOpen = false, filtersDirty = false;
  Map<String, String> applied = const {};
  final scroll = ScrollController();
  late final bool Function() ownsSession;
  StreamSubscription<int>? events;
  AdminCampaignPage? page;
  AdminCampaignRecord? selected;
  List<AdminCampaignEvent> history = const [];
  bool eligible = false, foreground = true, revoked = false, busy = false,
      failed = false, confirmed = false;
  int generation = 0, number = 1;
  int? actor;
  bool get visible => foreground && !revoked && ownsSession();
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    events = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() { revoked = true; clear(); });
    });
    if (foreground) load();
  }

  void clear() {
    generation++;
    page = null; selected = null; history = const []; eligible = false; busy = false;
    failed = false; confirmed = false; reason.clear();
    filterStatus = ''; applied = const {}; number = 1;
    filtersOpen = false; filtersDirty = false;
    for (final input in filters.values) { input.clear(); }
  }

  @override
  void didUpdateWidget(covariant AdminCampaignReviewView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api)) { revoked = true; clear(); }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) clear();
    });
  }

  @override
  void dispose() {
    generation++;
    events?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    scroll.dispose();
    reason.dispose();
    for (final input in filters.values) { input.dispose(); }
    super.dispose();
  }

  bool current(int epoch) => mounted && visible && generation == epoch;

  Future<void> requireAdmin(int epoch) async {
    final me = await widget.api.request('me');
    if (!current(epoch)) throw const FormatException('Session changed.');
    if (me is! Map || me['role'] != 'admin') {
      throw const ApiException(403, 'Administrator required.');
    }
    final id = _exact(me['id'], positive: true);
    if (actor != null && actor != id) {
      setState(() { revoked = true; clear(); });
      throw const FormatException('Account changed.');
    }
    actor = id;
  }

  void failure(Object error, int epoch, EvApi requestApi) {
    // EvApi synchronously invalidates the old fence before throwing an
    // authentic expiry. Only its current-generation signal can sign out.
    if (mounted && identical(requestApi, widget.api) &&
        error is ApiException && error.endsCurrentSession) {
      widget.onExpired();
      return;
    }
    if (!current(epoch)) return;
    setState(() { selected = null; page = null; history = const []; eligible = false; failed = true; reason.clear(); });
  }

  void editFilters() {
    if (!visible || busy) return;
    setState(() { generation++; page = null; selected = null; history = const []; eligible = false; failed = false; confirmed = false; number = 1; filtersDirty = true; });
  }

  void applyFilters() {
    if (!visible || busy) return;
    final scope = <String, String>{if (filterStatus.isNotEmpty) 'status': filterStatus};
    for (final entry in filters.entries) {
      final value = entry.value.text.trim();
      if (value.isEmpty) continue;
      final valid = entry.key == 'country' ? RegExp(r'^[A-Z]{2}$').hasMatch(value)
          : RegExp(r'^[1-9][0-9]{0,15}$').hasMatch(value) &&
              (int.tryParse(value) ?? 9007199254740992) <= 9007199254740991;
      if (!valid) { editFilters(); setState(() => failed = true); return; }
      scope[entry.key] = value;
    }
    applied = Map.unmodifiable(scope);
    setState(() { filtersDirty = false; filtersOpen = false; });
    load();
  }

  Future<void> load({int next = 1, AdminCampaignRecord? detail}) async {
    if (!visible || busy || filtersDirty ||
        (detail != null && !(page?.rows.any((row) => identical(row, detail)) ?? false))) return;
    if (scroll.hasClients) scroll.jumpTo(0);
    final epoch = ++generation;
    final requestApi = widget.api;
    final scope = applied;
    setState(() {
      busy = true; failed = false; confirmed = false; selected = null; page = null;
      eligible = false; history = const []; reason.clear(); number = next;
    });
    try {
      await requireAdmin(epoch);
      if (!current(epoch)) return;
      final data = AdminCampaignPage.parse(await widget.api.request(
        'commercial/campaigns', query: {'page': '$next', ...scope}), next, scope: scope);
      if (!current(epoch)) return;
      AdminCampaignRecord? record;
      bool canServe = false;
      List<AdminCampaignEvent> audit = const [];
      if (detail != null) {
        final raw = await widget.api.request('commercial/campaigns/${detail.id}');
        if (!current(epoch)) return;
        if (raw is! Map || raw['currently_eligible'] is! bool) {
          throw const FormatException('Invalid campaign eligibility.');
        }
        record = AdminCampaignRecord.parse(raw['campaign'], id: detail.id);
        AdminCampaignPage.verifyScope(record, scope);
        if (!detail.sameTerms(record) || record.revision < detail.revision ||
            record.delivered < detail.delivered || record.clicks < detail.clicks) {
          throw const FormatException('Campaign source regressed or terms changed.');
        }
        audit = AdminCampaignEvent.parseHistory(raw['events'], record);
        canServe = raw['currently_eligible'] as bool;
        if (canServe && (record.status != 'approved' || record.delivered >= record.budget)) {
          throw const FormatException('Invalid serving authority.');
        }
      }
      setState(() { page = data; selected = record; history = audit; eligible = canServe; });
    } catch (error) { failure(error, epoch, requestApi); }
    finally { if (current(epoch)) setState(() => busy = false); }
  }

  Future<void> decide(String action) async {
    final row = selected;
    final note = reason.text.trim();
    if (!visible || busy || row == null || page?.enabled != true ||
        !row.actions.contains(action) || note.length < 5 || note.length > 500) return;
    final epoch = generation;
    final requestApi = widget.api;
    final answer = await showDialog<bool>(context: context, builder: (dialog) =>
      AlertDialog(
        title: Text(label(action)),
        content: Text(t('Confirm this exact campaign revision? No new payment or verified human impression is created.',
          'تأكيد هذه المراجعة المحددة للحملة؟ لا تنشأ دفعة جديدة أو مشاهدة بشرية مثبتة.')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialog, false), child: Text(t('Back', 'رجوع'))),
          FilledButton(onPressed: () => Navigator.pop(dialog, true), child: Text(t('Confirm review', 'تأكيد المراجعة'))),
        ],
      ));
    if (answer != true || !current(epoch) || busy || !identical(row, selected)) return;
    setState(() { busy = true; failed = false; confirmed = false; reason.clear(); });
    try {
      await requireAdmin(epoch);
      if (!current(epoch)) return;
      final raw = await widget.api.request('commercial/campaigns/${row.id}/$action',
        method: 'POST', body: {'reason': note, 'expected_control_revision': row.revision});
      if (!current(epoch)) return;
      if (raw is! Map) throw const FormatException('Unconfirmed decision.');
      final receipt = AdminCampaignRecord.parse(raw['campaign'], id: row.id);
      row.verifyDecision(receipt, action);
      // Never infer serving eligibility from an accepted moderation transition.
      setState(() { selected = null; page = null; history = const []; eligible = false; confirmed = true; });
    } catch (error) { failure(error, epoch, requestApi); }
    finally { if (current(epoch)) setState(() => busy = false); }
  }

  String label(String action) => switch (action) {
    'approve' => t('Approve campaign', 'اعتماد الحملة'),
    'reject' => t('Reject campaign', 'رفض الحملة'),
    'suspend' => t('Suspend campaign', 'تعليق الحملة'),
    _ => t('Reinstate campaign', 'إعادة تفعيل الحملة'),
  };
  String status(String value) => switch (value) {
    'draft' => t('Draft', 'مسودة'),
    'submitted' => t('Submitted', 'مقدمة'),
    'approved' => t('Approved', 'معتمدة'),
    'rejected' => t('Rejected', 'مرفوضة'),
    'paused' => t('Paused', 'متوقفة مؤقتاً'),
    'suspended' => t('Suspended', 'معلقة'),
    _ => t('Cancelled', 'ملغاة'),
  };

  @override
  Widget build(BuildContext context) {
    final data = visible ? page : null, row = visible ? selected : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Campaign administration', 'إدارة الحملات'))),
      body: ListView(controller: scroll, padding: const EdgeInsets.all(16), children: [
        Text(t('Review paid-package delivery units only. Approval is not payment, station capability, or human-impression proof.',
          'مراجعة وحدات تسليم الباقة المدفوعة فقط. الاعتماد ليس إثباتاً للدفع أو قدرات المحطة أو المشاهدات البشرية.')),
        if (!visible) Text(t('Private review cleared. Reopen from the account menu.', 'مُسحت المراجعة الخاصة. أعد فتحها من قائمة الحساب.')),
        if (visible) ...[
          if (row == null)
            TextButton(onPressed: busy ? null : () => setState(() => filtersOpen = !filtersOpen), child: Text(t('Campaign filters', 'مرشحات الحملات'))),
          if (row == null && filtersOpen) ...[
            DropdownButton<String>(value: filterStatus,
              key: const Key('campaign-filter-status'),
              items: [DropdownMenuItem(value: '', child: Text(t('All campaign statuses', 'كل حالات الحملات'))),
                for (final value in _reviewStates) DropdownMenuItem(value: value, child: Text(status(value)))],
              onChanged: busy ? null : (value) { filterStatus = value ?? ''; editFilters(); }),
            for (final entry in filters.entries) TextField(
              key: Key('campaign-filter-${entry.key}'), controller: entry.value,
              enabled: !busy, onChanged: (_) => editFilters(),
              decoration: InputDecoration(labelText: switch (entry.key) {
                'country' => t('Country code', 'رمز الدولة'),
                'vendor_id' => t('Vendor ID', 'رقم المورد'),
                'station_id' => t('Station ID', 'رقم المحطة'),
                _ => t('Invoice ID', 'رقم الفاتورة'),
              })),
            TextButton(onPressed: busy ? null : applyFilters, child: Text(t('Apply campaign filters', 'تطبيق مرشحات الحملات'))),
          ],
          OutlinedButton(onPressed: busy ? null : () => filtersDirty ? applyFilters() : load(next: number), child: Text(t('Refresh campaign history', 'تحديث سجل الحملات'))),
          if (busy) const LinearProgressIndicator(),
          if (failed) Text(t('Review not confirmed. Refresh authoritative history before another decision.', 'لم تتأكد المراجعة. حدّث السجل الأصلي قبل قرار آخر.')),
          if (confirmed) Text(t('Decision confirmed. Refresh authoritative history.', 'تم تأكيد القرار. حدّث السجل الأصلي.')),
          if (data != null && row == null) ...[
            if (!data.enabled) Text(t('Campaign controls disabled', 'ضوابط الحملات معطلة')),
            if (data.rows.isEmpty) Text(t('No campaigns on this page.', 'لا توجد حملات في هذه الصفحة.')),
            for (final item in data.rows) Card(child: ListTile(
              title: Text(t(item.title, item.titleAr)),
              subtitle: Text('#${item.id} · ${status(item.status)}'),
              onTap: busy ? null : () => load(next: number, detail: item),
            )),
            Wrap(spacing: 12, children: [
              TextButton(onPressed: busy || number <= 1 ? null : () => load(next: number - 1), child: Text(t('Previous', 'السابق'))),
              Text('$number / ${data.last}'),
              TextButton(onPressed: busy || number >= data.last || number >= 100000 ? null : () => load(next: number + 1), child: Text(t('Next', 'التالي'))),
            ]),
          ],
          if (row != null) ...[
            Text(t(row.title, row.titleAr), style: Theme.of(context).textTheme.titleLarge),
            Text(t(row.description, row.descriptionAr)),
            Text(t('Other language creative', 'المحتوى باللغة الأخرى')),
            Text(t(row.titleAr, row.title)),
            Text(t(row.descriptionAr, row.description)),
            Text('#${row.id} · ${status(row.status)}'),
            Text('${t('Vendor', 'المورد')} #${row.vendor} · ${t('Invoice', 'الفاتورة')} #${row.invoice} · ${t('Station', 'المحطة')} #${row.station}'),
            Text('${row.country} · ${row.placement} · ${row.region ?? ""}'),
            Text('${row.starts} — ${row.ends}'),
            Text('${t('Control revision', 'مراجعة التحكم')}: ${row.revision}'),
            Text('${t('Server deliveries / budget', 'تسليمات الخادم / الميزانية')}: ${row.delivered} / ${row.budget}'),
            Text('${t('Verified server clicks', 'نقرات الخادم المثبتة')}: ${row.clicks}'),
            Text(eligible ? t('Currently eligible for serving', 'مؤهلة حالياً للعرض') : t('Not currently eligible for serving', 'غير مؤهلة حالياً للعرض')),
            if (data?.enabled == true && row.actions.isNotEmpty) ...[
              TextField(controller: reason, enabled: !busy, maxLength: 500,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: t('Decision reason (5–500 characters)', 'سبب القرار (٥–٥٠٠ حرف)'))),
              for (final action in row.actions)
                OutlinedButton(onPressed: busy || reason.text.trim().length < 5 ? null : () => decide(action), child: Text(label(action))),
            ],
            Text(t('Immutable control history', 'سجل التحكم الثابت')),
            for (final event in history.reversed)
              Card(child: ListTile(
                title: Text('#${event.id} · ${event.action} · ${t('Actor', 'المنفذ')} #${event.actor}'),
                subtitle: Text('${event.reason}\n${event.createdAt}'),
              )),
          ],
        ],
      ]),
    );
  }
}
