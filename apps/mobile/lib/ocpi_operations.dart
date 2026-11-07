import 'package:flutter/material.dart';
import 'api.dart';
import 'ocpi_credentials.dart';
import 'sandbox_roaming.dart';

String partnerOperationsTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'إدارة شبكات التجوال'
    : 'Roaming partner operations';

/// Partner-reported records remain separate from local charging and accounting.
class OcpiOperationsView extends StatefulWidget {
  final EvApi api;
  final bool admin;
  final VoidCallback onExpired;
  const OcpiOperationsView({
    super.key,
    required this.api,
    required this.admin,
    required this.onExpired,
  });
  @override
  State<OcpiOperationsView> createState() => _OcpiOperationsState();
}

class _OcpiOperationsState extends State<OcpiOperationsView> {
  ApiPage<Map<String, dynamic>>? providers, records;
  Map<String, dynamic>? selected;
  String? module, message;
  bool busy = false, uncertain = false;
  int generation = 0;
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    refresh();
  }

  Future<void> refresh({int? page}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      providers = null;
      selected = null;
      records = null;
      module = null;
      message = null;
    });
    try {
      // Permissions are always enforced by the current server-side actor.
      final result = ApiPage<Map<String, dynamic>>.fromJson(
        await widget.api.request(
          'ocpi/providers',
          query: {'page': '${page ?? 1}'},
        ),
        (j) => j,
      );
      if (mounted && epoch == generation)
        setState(() {
          providers = result;
          uncertain = false;
        });
    } catch (e) {
      fail(e, epoch);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  void fail(Object error, int epoch) {
    if (!mounted || epoch != generation) return;
    final expired = error is ApiException && [401, 419].contains(error.status);
    setState(() {
      selected = null;
      records = null;
      if (expired ||
          (error is ApiException && [403, 404].contains(error.status)))
        providers = null;
      message = expired
          ? tr('Sign in again.', 'سجّل الدخول مجددًا.')
          : tr(
              'Operation unavailable. Refresh partner state before another action.',
              'العملية غير متاحة. حدّث حالة الشريك قبل إجراء آخر.',
            );
    });
    if (expired) widget.onExpired();
  }

  Future<void> mutate(
    Map<String, dynamic> provider,
    String action, {
    Map<String, dynamic>? body,
    String method = 'POST',
  }) async {
    if (busy || uncertain) return;
    final agreed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr('Confirm partner operation', 'تأكيد عملية الشريك')),
        content: Text(
          '${provider['name']}\n${tr('This acts on the configured partner. Partner records do not authorize local charging or billing.', 'يؤثر هذا الإجراء على الشريك المهيأ. لا تمنح سجلات الشريك صلاحية الشحن المحلي أو الفوترة.')}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(tr('Cancel', 'إلغاء')),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(tr('Confirm', 'تأكيد')),
          ),
        ],
      ),
    );
    if (!mounted || agreed != true || busy || uncertain) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      selected = null;
      records = null;
      message = null;
    });
    try {
      final result = await widget.api.request(
        'ocpi/providers/${provider['id']}/$action',
        method: method,
        body: body ?? {},
      );
      if (!mounted || epoch != generation) return;
      // Never accept a future endpoint silently granting charging/accounting authority.
      if ((result is Map &&
          (result['commands_enabled'] == true ||
              result['remote_control'] == true ||
              result['billing_eligible'] == true)))
        throw const FormatException('Unexpected partner capability');
      await refresh(page: providers?.current ?? 1);
      if (mounted)
        setState(
          () => message = tr(
            'Operation acknowledged. Partner state refreshed.',
            'تم تأكيد العملية وتحديث حالة الشريك.',
          ),
        );
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => uncertain = true);
        fail(e, epoch);
        if (mounted && !(e is ApiException && [401, 419].contains(e.status)))
          setState(
            () => message = tr(
              'Outcome uncertain. No automatic retry. Refresh partner state before another action.',
              'النتيجة غير مؤكدة. لن تتكرر العملية تلقائيًا. حدّث حالة الشريك قبل إجراء آخر.',
            ),
          );
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> loadRecords(
    Map<String, dynamic> provider,
    String target, {
    int page = 1,
  }) async {
    if (busy) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      records = null;
      message = null;
      selected = provider;
      module = target;
    });
    try {
      final result = ApiPage<Map<String, dynamic>>.fromJson(
        await widget.api.request(
          'ocpi/providers/${provider['id']}/records/$target',
          query: {'page': '$page'},
        ),
        (j) => j,
      );
      if (mounted && epoch == generation) setState(() => records = result);
    } catch (e) {
      fail(e, epoch);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> credentials(Map<String, dynamic> provider) async {
    if (busy || uncertain) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => OcpiCredentialsView(
          api: widget.api,
          providerId: provider['id'] as int,
          onExpired: widget.onExpired,
        ),
      ),
    );
    if (mounted) await refresh(page: providers?.current ?? 1);
  }

  Future<void> provision() async {
    if (busy || uncertain) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) =>
            _ProvisionPartner(api: widget.api, onExpired: widget.onExpired),
      ),
    );
    if (mounted) await refresh(page: providers?.current ?? 1);
  }

  Widget pager(int current, int last, void Function(int) load) => Row(
    children: [
      TextButton(
        onPressed: busy || current <= 1 ? null : () => load(current - 1),
        child: Text(tr('Previous', 'السابق')),
      ),
      Text('$current / $last'),
      TextButton(
        onPressed: busy || current >= last ? null : () => load(current + 1),
        child: Text(tr('Next', 'التالي')),
      ),
    ],
  );

  String moduleTitle(String value) => switch (value) {
    'locations' => tr('Locations', 'المواقع'),
    'tariffs' => tr('Tariffs', 'التعريفات'),
    'sessions' => tr('Sessions', 'الجلسات'),
    'cdrs' => tr('Charge detail records', 'سجلات تفاصيل الشحن'),
    _ => '',
  };

  String reconciliationIssue(Object? issue) => switch (issue) {
    "partner_not_authorized_and_negotiated" => tr(
      "Partner authorization or negotiation is required",
      "يلزم تصريح الشريك والتفاوض معه",
    ),
    "credit_requires_separate_reconciliation" => tr(
      "Credit record needs separate review",
      "يتطلب سجل الرصيد مراجعة منفصلة",
    ),
    "session_reference_missing" => tr(
      "CDR has no session reference",
      "لا يتضمن سجل الشحن مرجع جلسة",
    ),
    "session_not_received" => tr(
      "Referenced partner session has not been received",
      "لم تُستلم جلسة الشريك المشار إليها",
    ),
    "session_not_completed" => tr(
      "Partner session is not completed",
      "جلسة الشريك غير مكتملة",
    ),
    "energy_precision_unsupported" => tr(
      "Energy precision needs review",
      "تتطلب دقة الطاقة المراجعة",
    ),
    "energy_mismatch" => tr(
      "Session and CDR energy differ",
      "تختلف طاقة الجلسة وسجل الشحن",
    ),
    "history_exceeds_comparison_limit" => tr(
      "Partner history exceeds automatic comparison limit",
      "يتجاوز سجل الشريك حد المقارنة التلقائية",
    ),
    "history_contains_unlinked_cdrs" => tr(
      "Partner history contains unlinked CDRs",
      "يتضمن سجل الشريك سجلات شحن غير مرتبطة",
    ),
    "multiple_cdrs_for_session" => tr(
      "Multiple CDRs claim the same session",
      "تشير سجلات شحن متعددة إلى الجلسة نفسها",
    ),
    "currency_mismatch" => tr(
      "Session and CDR currencies differ",
      "تختلف عملة الجلسة وسجل الشحن",
    ),
    "location_id_mismatch" => tr(
      "Session and CDR locations differ",
      "يختلف موقع الجلسة وسجل الشحن",
    ),
    "evse_uid_mismatch" => tr(
      "Session and CDR EVSEs differ",
      "تختلف وحدة شحن الجلسة وسجل الشحن",
    ),
    "connector_id_mismatch" => tr(
      "Session and CDR connectors differ",
      "يختلف موصل الجلسة وسجل الشحن",
    ),
    "start_date_time_mismatch" => tr(
      "Session and CDR start times differ",
      "يختلف وقت بدء الجلسة وسجل الشحن",
    ),
    "end_date_time_mismatch" => tr(
      "Session and CDR end times differ",
      "يختلف وقت انتهاء الجلسة وسجل الشحن",
    ),
    "cost_excl_vat_missing_or_unsupported" => tr(
      "Net cost is missing or needs precision review",
      "التكلفة الصافية مفقودة أو تتطلب مراجعة الدقة",
    ),
    "cost_incl_vat_missing_or_unsupported" => tr(
      "Gross cost is missing or needs precision review",
      "التكلفة الإجمالية مفقودة أو تتطلب مراجعة الدقة",
    ),
    "cost_excl_vat_mismatch" => tr(
      "Session and CDR net costs differ",
      "تختلف التكلفة الصافية للجلسة وسجل الشحن",
    ),
    "cost_incl_vat_mismatch" => tr(
      "Session and CDR gross costs differ",
      "تختلف التكلفة الإجمالية للجلسة وسجل الشحن",
    ),
    _ => tr("Source fields require review", "تتطلب حقول المصدر المراجعة"),
  };

  List<Widget> record(Map<String, dynamic> row) {
    final n = row['normalized'] as Map? ?? {};
    // Render only normalized allowlisted fields, never opaque tokens or source payloads.
    return [
      Text(
        '${row['country_code'] ?? ''} · ${row['party_id'] ?? ''} · ${row['external_id'] ?? ''}',
      ),
      if (n['name'] != null) Text('${n['name']}'),
      if (n['address'] != null) Text('${n['address']} · ${n['city'] ?? ''}'),
      if (n['status'] != null) Text('${n['status']}'),
      if (n['energy_kwh'] != null) Text('${n['energy_kwh']} kWh'),
      if (n['currency'] != null) Text('${n['currency']}'),
      if (n['cost'] is Map)
        Text(
          '${tr('Partner-reported cost', 'التكلفة المبلّغ عنها من الشريك')}: ${(n['cost'] as Map)['excl_vat'] ?? '—'}',
        ),
      for (final element in (n['elements'] as List? ?? []))
        for (final component
            in ((element as Map)['price_components'] as List? ?? []))
          Text(
            '${(component as Map)['type']} · ${component['price']} · ${tr('Step', 'الخطوة')} ${component['step_size']}',
          ),
      if (row['reconciliation'] is Map) ...[
        Text(
          (row['reconciliation'] as Map)['status'] == 'source_consistent'
              ? tr(
                  'Partner session and CDR agree',
                  'تتطابق جلسة الشريك وسجل تفاصيل الشحن',
                )
              : tr(
                  'Partner reconciliation requires review',
                  'تتطلب مطابقة سجلات الشريك المراجعة',
                ),
        ),
        Text(
          tr(
            'Source comparison does not authorize billing',
            'لا تمنح مقارنة المصدر تصريحاً بالفوترة',
          ),
        ),
        for (final issue
            in ((row['reconciliation'] as Map)['issues'] as List? ?? []))
          Text(reconciliationIssue(issue)),
      ],
      if (row['source_updated_at'] != null)
        Text(
          '${tr('Source updated (UTC)', 'تحديث المصدر (UTC)')}: ${row['source_updated_at']}',
        ),
      if (row['received_at'] != null)
        Text(
          '${tr('Received (UTC)', 'الاستلام (UTC)')}: ${row['received_at']}',
        ),
    ];
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(partnerOperationsTitle(context))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          tr(
            'Partner-reported, unverified data. Live availability is unknown. These records cannot start charging, create bookings or issue bills.',
            'بيانات مبلّغ عنها من الشريك وغير متحقق منها. التوفر المباشر غير معروف. لا تسمح هذه السجلات ببدء الشحن أو إنشاء حجوزات أو فواتير.',
          ),
        ),
        TextButton(
          onPressed: busy ? null : () => refresh(page: providers?.current ?? 1),
          child: Text(tr('Refresh partners', 'تحديث الشركاء')),
        ),
        if (widget.admin)
          TextButton(
            onPressed: busy || uncertain ? null : provision,
            child: Text(tr('Add configured partner', 'إضافة شريك مهيأ')),
          ),
        if (busy) const LinearProgressIndicator(),
        if (message != null) Text(message!),
        if (providers != null && selected == null) ...[
          if (providers!.items.isEmpty)
            Text(tr('No configured partners.', 'لا يوجد شركاء مهيؤون.')),
          for (final p in providers!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${p['name']}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    Text(
                      p['authorized'] == true
                          ? tr('Operator authorized', 'مصرح به من المشغّل')
                          : tr(
                              'Operator authorization revoked',
                              'تم إلغاء تصريح المشغّل',
                            ),
                    ),
                    Text(
                      '${p['version'] ?? tr('Not negotiated', 'لم يتم التفاوض')}',
                    ),
                    if (p['last_synced_at'] != null)
                      Text('${p['last_synced_at']}'),
                    TextButton(
                      onPressed: busy || uncertain
                          ? null
                          : () => credentials(p),
                      child: Text(partnerCredentialsTitle(context)),
                    ),
                    TextButton(
                      onPressed: busy
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => SandboxRoamingView(
                                  api: widget.api,
                                  providerId: p['id'] as int,
                                  onExpired: widget.onExpired,
                                ),
                              ),
                            ),
                      child: Text(
                        tr('Sandbox roaming activity', 'نشاط التجوال التجريبي'),
                      ),
                    ),
                    if (widget.admin)
                      TextButton(
                        onPressed: busy || uncertain
                            ? null
                            : () => mutate(
                                p,
                                'authorization',
                                method: 'PATCH',
                                body: {'authorized': p['authorized'] != true},
                              ),
                        child: Text(
                          p['authorized'] == true
                              ? tr('Revoke partner', 'إلغاء تصريح الشريك')
                              : tr(
                                  'Authorize configured partner',
                                  'تصريح الشريك المهيأ',
                                ),
                        ),
                      ),
                    if (p['authorized'] == true)
                      TextButton(
                        onPressed: busy || uncertain
                            ? null
                            : () => mutate(p, 'negotiate'),
                        child: Text(
                          tr('Negotiate OCPI 2.2.1', 'التفاوض على OCPI 2.2.1'),
                        ),
                      ),
                    for (final m in [
                      'locations',
                      'tariffs',
                      'sessions',
                      'cdrs',
                    ]) ...[
                      TextButton(
                        onPressed: busy ? null : () => loadRecords(p, m),
                        child: Text(
                          '${tr('Inspect', 'عرض')} ${moduleTitle(m)}',
                        ),
                      ),
                      if (p['authorized'] == true &&
                          (p['readable_modules'] as List? ?? []).contains(m))
                        TextButton(
                          onPressed: busy || uncertain
                              ? null
                              : () => mutate(p, 'sync/$m'),
                          child: Text(
                            '${tr('Synchronize', 'مزامنة')} ${moduleTitle(m)}',
                          ),
                        ),
                    ],
                  ],
                ),
              ),
            ),
          pager(providers!.current, providers!.last, (p) => refresh(page: p)),
        ],
        if (records != null && selected != null && module != null) ...[
          TextButton(
            onPressed: busy
                ? null
                : () => refresh(page: providers?.current ?? 1),
            child: Text(tr('Back to partners', 'العودة إلى الشركاء')),
          ),
          Text('${selected!['name']} · ${moduleTitle(module!)}'),
          if (records!.items.isEmpty)
            Text(tr('No synchronized records.', 'لا توجد سجلات متزامنة.')),
          for (final row in records!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: record(row),
                ),
              ),
            ),
          pager(
            records!.current,
            records!.last,
            (p) => loadRecords(selected!, module!, page: p),
          ),
        ],
      ],
    ),
  );
}

/// Admin chooses approved server configuration, never arbitrary URLs or credentials.
class _ProvisionPartner extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const _ProvisionPartner({required this.api, required this.onExpired});
  @override
  State<_ProvisionPartner> createState() => _ProvisionPartnerState();
}

class _ProvisionPartnerState extends State<_ProvisionPartner> {
  final name = TextEditingController(), search = TextEditingController();
  List<Map<String, dynamic>> connections = [];
  ApiPage<Map<String, dynamic>>? owners;
  String? connection, message;
  int? owner;
  bool busy = false, uncertain = false;
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    name.dispose();
    search.dispose();
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    if (busy || uncertain) return;
    setState(() {
      busy = true;
      message = null;
      owner = null;
      owners = null;
      connection = null;
      connections = [];
    });
    try {
      final values = await Future.wait([
        widget.api.request('ocpi/connection-options'),
        widget.api.request(
          'ocpi/owner-options',
          query: {'page': '$page', 'q': search.text.trim()},
        ),
      ]);
      final configs = ((values[0] as Map)['data'] as List)
          .map((x) => Map<String, dynamic>.from(x as Map))
          .where(
            (x) => x['configuration_valid'] == true && x['assigned'] == false,
          )
          .toList();
      final people = ApiPage<Map<String, dynamic>>.fromJson(
        values[1],
        (j) => j,
      );
      if (mounted)
        setState(() {
          connections = configs;
          owners = people;
        });
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  void fail(Object error) {
    if (!mounted) return;
    if (error is ApiException && [401, 403, 404, 419].contains(error.status)) {
      setState(() {
        connections = [];
        owners = null;
        owner = null;
        connection = null;
      });
    }
    setState(
      () => message = uncertain
          ? tr(
              'Outcome uncertain. Close and refresh partners before another request. No automatic retry.',
              'النتيجة غير مؤكدة. أغلق وحدّث الشركاء قبل طلب آخر. لن تتكرر العملية تلقائيًا.',
            )
          : tr(
              'Approved partner configuration unavailable.',
              'تهيئة الشريك المعتمدة غير متاحة.',
            ),
    );
    if (error is ApiException && [401, 419].contains(error.status))
      widget.onExpired();
  }

  Future<void> submit() async {
    if (busy ||
        uncertain ||
        connection == null ||
        owner == null ||
        name.text.trim().isEmpty)
      return;
    final body = {
      'owner_id': owner,
      'connection_key': connection,
      'name': name.text.trim(),
      'authorized': false,
    };
    setState(() {
      busy = true;
      message = null;
    });
    try {
      await widget.api.request('ocpi/providers', method: 'POST', body: body);
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      if (mounted) {
        setState(() => uncertain = true);
        fail(e);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(tr('Add configured partner', 'إضافة شريك مهيأ')),
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          tr(
            'Only approved server connections are available. Credentials stay on the server. New partners require a separate authorization decision.',
            'تتوفر اتصالات الخادم المعتمدة فقط. تبقى بيانات الاعتماد على الخادم. يحتاج الشريك الجديد إلى قرار تصريح منفصل.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (message != null) Text(message!),
        TextField(
          controller: name,
          onChanged: (_) => setState(() {}),
          maxLength: 150,
          enabled: !busy && !uncertain,
          decoration: InputDecoration(
            labelText: tr('Partner name', 'اسم الشريك'),
          ),
        ),
        DropdownButtonFormField<String>(
          initialValue: connection,
          key: ValueKey('connection-$connection-${connections.length}'),
          isExpanded: true,
          decoration: InputDecoration(
            labelText: tr('Approved connection', 'الاتصال المعتمد'),
          ),
          items: connections
              .map(
                (c) => DropdownMenuItem(
                  value: c['key'] as String,
                  child: Text('${c['key']}', overflow: TextOverflow.ellipsis),
                ),
              )
              .toList(),
          onChanged: busy || uncertain
              ? null
              : (v) => setState(() => connection = v),
        ),
        if (!busy && connections.isEmpty)
          Text(
            tr(
              'No unassigned approved connections.',
              'لا توجد اتصالات معتمدة غير مخصصة.',
            ),
          ),
        TextField(
          controller: search,
          maxLength: 80,
          enabled: !busy && !uncertain,
          decoration: InputDecoration(
            labelText: tr('Search vendors', 'البحث عن المورّدين'),
          ),
        ),
        TextButton(
          onPressed: busy || uncertain ? null : () => load(),
          child: Text(tr('Search', 'بحث')),
        ),
        DropdownButtonFormField<int>(
          initialValue: owner,
          key: ValueKey('owner-$owner-${owners?.current}'),
          isExpanded: true,
          decoration: InputDecoration(
            labelText: tr('Provider vendor', 'مورّد الشريك'),
          ),
          items: (owners?.items ?? [])
              .map(
                (u) => DropdownMenuItem(
                  value: u['id'] as int,
                  child: Text(
                    '${u['name']} · #${u['id']}',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              )
              .toList(),
          onChanged: busy || uncertain
              ? null
              : (v) => setState(() => owner = v),
        ),
        if (owners != null)
          Row(
            children: [
              TextButton(
                onPressed: busy || uncertain || owners!.current <= 1
                    ? null
                    : () => load(page: owners!.current - 1),
                child: Text(tr('Previous', 'السابق')),
              ),
              Text('${owners!.current} / ${owners!.last}'),
              TextButton(
                onPressed: busy || uncertain || owners!.current >= owners!.last
                    ? null
                    : () => load(page: owners!.current + 1),
                child: Text(tr('Next', 'التالي')),
              ),
            ],
          ),
        FilledButton(
          onPressed:
              busy ||
                  uncertain ||
                  connection == null ||
                  owner == null ||
                  name.text.trim().isEmpty
              ? null
              : submit,
          child: Text(
            tr('Create unauthorized partner', 'إنشاء شريك دون تصريح'),
          ),
        ),
      ],
    ),
  );
}
