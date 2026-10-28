import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'vendor_topology.dart';
import 'charger_admission.dart';
import 'topology_strings.dart';
import 'vendor_company.dart';
import 'vendor_forms.dart';
import 'vendor_operations_strings.dart';

/// Existing company inventory APIs; no hardware command or inferred live state.
class VendorInventoryView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final Map<String, dynamic>? company, site, charger;
  const VendorInventoryView({
    super.key,
    required this.api,
    required this.onExpired,
    this.company,
    this.site,
    this.charger,
  });
  @override
  State<VendorInventoryView> createState() => _VendorInventoryViewState();
}

class _VendorInventoryViewState extends State<VendorInventoryView> {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false, uncertain = false;
  int generation = 0, target = 1;
  int get level => widget.charger != null
      ? 3
      : widget.site != null
      ? 2
      : widget.company != null
      ? 1
      : 0;
  Map<String, dynamic> get capabilities =>
      Map<String, dynamic>.from(widget.company?['capabilities'] as Map? ?? {});
  bool get writer => capabilities['inventory_write'] == true;
  String tr(String key) => inventoryText(context, key);
  String get endpoint {
    final base = 'vendor-ops/companies';
    if (level == 0) return base;
    final company = '$base/${widget.company!['id']}';
    if (level == 1) return '$company/sites';
    if (level == 2) return '$company/sites/${widget.site!['id']}/chargers';
    return '$company/chargers/${widget.charger!['id']}/connectors';
  }

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

  void failed(Object e) {
    if (!mounted) return;
    setState(() {
      error = e;
      data = null;
    });
    if (e is ApiException && [401, 419].contains(e.status)) {
      widget.onExpired();
      Navigator.of(context).popUntil((route) => route.isFirst);
    }
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    target = page;
    setState(() {
      busy = true;
      data = null;
      error = null;
      uncertain = false;
    });
    try {
      final result = await widget.api.request(
        endpoint,
        query: {'page': '$page'},
      );
      if (!mounted || epoch != generation) return;
      setState(() => data = ApiPage.fromJson(result, (v) => v));
    } catch (e) {
      if (mounted && epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> open(Map<String, dynamic> row) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => VendorInventoryView(
          api: widget.api,
          onExpired: widget.onExpired,
          company: level == 0 ? row : widget.company,
          site: level == 1 ? row : widget.site,
          charger: level == 2 ? row : widget.charger,
        ),
      ),
    );
    if (mounted && ModalRoute.of(context)?.isCurrent == true) {
      await load(page: data?.current ?? 1);
    }
  }

  Future<void> maintenance(Map<String, dynamic> row) async {
    var reason = '';
    final form = GlobalKey<FormState>();
    final blocked = row['maintenance_blocked'] == true;
    final result = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr(blocked ? 'release' : 'block')),
        content: SingleChildScrollView(
          child: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(tr('consent')),
                const SizedBox(height: 16),
                TextFormField(
                  onChanged: (value) => reason = value,
                  maxLength: 1000,
                  minLines: 2,
                  maxLines: 5,
                  decoration: InputDecoration(labelText: tr('reason')),
                  validator: (v) => (v?.trim().isEmpty ?? true)
                      ? EvStrings.of(context).t('required')
                      : null,
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(EvStrings.of(context).t('cancel')),
          ),
          FilledButton(
            onPressed: () {
              if (form.currentState!.validate())
                Navigator.pop(context, reason.trim());
            },
            child: Text(EvStrings.of(context).t('confirm')),
          ),
        ],
      ),
    );
    if (result == null || !mounted) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      await widget.api.request(
        '$endpoint/${row['id']}/maintenance',
        method: 'PATCH',
        body: {'maintenance_blocked': !blocked, 'maintenance_reason': result},
      );
      if (mounted && epoch == generation) await load(page: target);
    } catch (e) {
      if (mounted && epoch == generation) {
        uncertain = e is! ApiException || e.status == 0 || e.status >= 500;
        failed(e);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> createInventory() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => VendorEditView(
          api: widget.api,
          onExpired: widget.onExpired,
          kind: ['company', 'site', 'charger', 'connector'][level],
          company: widget.company,
          site: widget.site,
          charger: widget.charger,
        ),
      ),
    );
    if (mounted) await load(page: target);
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = data;
    final title = tr(['companies', 'sites', 'chargers', 'connectors'][level]);
    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          IconButton(
            tooltip: s.t('refresh'),
            onPressed: busy ? null : () => load(page: target),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          if (!busy) await load(page: target);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            Text(tr('disclaimer')),
            if (level == 0) ...[
              FilledButton(
                onPressed: busy ? null : createInventory,
                child: Text(vendorOpsText(context, 'newCompany')),
              ),
              TextButton(
                onPressed: busy
                    ? null
                    : () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => VendorRecordsView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              kind: 'invitations',
                            ),
                          ),
                        );
                        if (mounted) load();
                      },
                child: Text(vendorOpsText(context, 'invitations')),
              ),
            ] else if (writer && capabilities['inventory_operational'] == true)
              FilledButton(
                onPressed: busy ? null : createInventory,
                child: Text(
                  vendorOpsText(
                    context,
                    [
                      'newCompany',
                      'newSite',
                      'newCharger',
                      'newConnector',
                    ][level],
                  ),
                ),
              ),

            if (busy) const LinearProgressIndicator(),
            if (error != null) ...[
              Text(s.t(errorKey(error!))),
              if (uncertain) Text(tr('uncertain')),
              TextButton(
                onPressed: busy ? null : () => load(page: target),
                child: Text(s.t('retry')),
              ),
            ],
            if (p != null && p.items.isEmpty) Text(tr('empty')),
            if (p != null) ...[
              for (final row in p.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          level == 0
                              ? '${row['legal_name']}'
                              : level == 1
                              ? '${row['name']}'
                              : level == 2
                              ? '${row['asset_reference']}'
                              : '${tr('connector')} ${row['connector_number']}',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        if (level == 0)
                          Text(
                            '${row['country']} · ${tr(row['status'] as String)}',
                          ),
                        if (level == 1) ...[
                          Text('${row['address']}'),
                          Text('${row['country']} · ${row['timezone']}'),
                          Text('${row['latitude']}, ${row['longitude']}'),
                        ],
                        if (level == 2) ...[
                          Text('${row['manufacturer']} · ${row['model']}'),
                          Text(
                            '${tr(row['source'] as String)} · OCPP ${row['ocpp_protocol'] ?? '—'}',
                          ),
                        ],
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => VendorTopologyView(
                                          api: widget.api,
                                          company: widget.company!,
                                          charger: row,
                                          onExpired: widget.onExpired,
                                        ),
                                      ),
                                    );
                                    if (mounted &&
                                        ModalRoute.of(context)?.isCurrent ==
                                            true)
                                      await load(page: data?.current ?? 1);
                                  },
                            child: Text(topologyText(context, 'title')),
                          ),
                        if (level == 2)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => ChargerAdmissionView(
                                          api: widget.api,
                                          company: widget.company!,
                                          charger: row,
                                          onExpired: widget.onExpired,
                                        ),
                                      ),
                                    );
                                    if (mounted &&
                                        ModalRoute.of(context)?.isCurrent ==
                                            true)
                                      await load(page: data?.current ?? 1);
                                  },
                            child: Text(admissionText(context, 'title')),
                          ),
                        if (level == 3) ...[
                          Text(
                            '${row['connector_type']} · ${row['max_power_kw']} kW',
                          ),
                          Text(
                            tr(
                              row['maintenance_blocked'] == true
                                  ? 'blocked'
                                  : 'notBlocked',
                            ),
                          ),
                          if ((row['maintenance_reason'] as String?)
                                  ?.isNotEmpty ??
                              false)
                            Text('${row['maintenance_reason']}'),
                          Text(tr('unknown')),
                          if (writer) ...[
                            if (row['maintenance_blocked'] == true &&
                                capabilities['inventory_operational'] != true)
                              Text(tr('approval')),
                            OutlinedButton(
                              onPressed:
                                  busy ||
                                      (row['maintenance_blocked'] == true &&
                                          capabilities['inventory_operational'] !=
                                              true)
                                  ? null
                                  : () => maintenance(row),
                              child: Text(
                                tr(
                                  row['maintenance_blocked'] == true
                                      ? 'release'
                                      : 'block',
                                ),
                              ),
                            ),
                          ],
                        ],
                        if (level == 0 &&
                            (row['capabilities'] as Map?)?['company_read'] ==
                                true)
                          TextButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => VendorCompanyView(
                                          api: widget.api,
                                          onExpired: widget.onExpired,
                                          company: row['id'] as int,
                                        ),
                                      ),
                                    );
                                    if (mounted) load(page: target);
                                  },
                            child: Text(vendorOpsText(context, 'company')),
                          ),
                        if (level < 3 &&
                            (level != 0 ||
                                (row['capabilities']
                                        as Map?)?['inventory_read'] ==
                                    true))
                          FilledButton.tonal(
                            onPressed: busy ? null : () => open(row),
                            child: Text(
                              tr(['sites', 'chargers', 'connectors'][level]),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              Text(
                s.t('page', {
                  'page': p.current,
                  'last': p.last,
                  'total': p.total,
                }),
              ),
              Wrap(
                spacing: 12,
                children: [
                  OutlinedButton(
                    onPressed: busy || p.current <= 1
                        ? null
                        : () => load(page: p.current - 1),
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: busy || p.current >= p.last
                        ? null
                        : () => load(page: p.current + 1),
                    child: Text(s.t('next')),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

String inventoryText(BuildContext context, String key) {
  final ar = Localizations.localeOf(context).languageCode == 'ar';
  return (ar ? inventoryArabic : inventoryEnglish)[key] ?? key;
}

const inventoryEnglish = {
  'draft': 'Draft',
  'submitted': 'Under review',
  'approved': 'Approved',
  'suspended': 'Suspended',
  'changes_requested': 'Changes requested',
  'rejected': 'Rejected',
  'simulator': 'Simulator',
  'discovery': 'Discovery only',
  'direct_unverified': 'Direct connection unverified',
  'partner_unverified': 'Partner connection unverified',
  'companies': 'Company inventory',
  'sites': 'Sites',
  'chargers': 'Chargers',
  'connectors': 'Connectors',
  'connector': 'Connector',
  'disclaimer':
      'Business inventory and maintenance only. Live availability is unknown; these screens do not provision hardware or send charger commands.',
  'block': 'Block new starts',
  'release': 'Remove maintenance block',
  'reason': 'Maintenance reason',
  'consent':
      'This changes business admission for linked simulator inventory. It does not stop an active session or send a hardware command. Record the reason before confirming.',
  'blocked': 'Maintenance block active',
  'notBlocked': 'No maintenance block recorded',
  'unknown': 'Live availability: unknown',
  'approval':
      'Current company approval and license are required to remove a block.',
  'empty': 'No inventory records in this scope.',
  'uncertain':
      'The update may have been saved. Refresh authoritative inventory before submitting again.',
};
const inventoryArabic = {
  'draft': 'مسودة',
  'submitted': 'قيد المراجعة',
  'approved': 'معتمدة',
  'suspended': 'معلّقة',
  'changes_requested': 'تعديلات مطلوبة',
  'rejected': 'مرفوضة',
  'simulator': 'محاكاة',
  'discovery': 'للاستكشاف فقط',
  'direct_unverified': 'اتصال مباشر غير متحقق',
  'partner_unverified': 'اتصال شريك غير متحقق',
  'companies': 'مخزون الشركة',
  'sites': 'المواقع',
  'chargers': 'الشواحن',
  'connectors': 'الموصلات',
  'connector': 'الموصل',
  'disclaimer':
      'مخزون الأعمال والصيانة فقط. التوافر المباشر غير معروف؛ لا تُجهّز هذه الشاشات أجهزة فعلية ولا ترسل أوامر للشواحن.',
  'block': 'حظر جلسات البدء الجديدة',
  'release': 'إزالة حظر الصيانة',
  'reason': 'سبب الصيانة',
  'consent':
      'يغيّر هذا الإجراء قبول الجلسات للمخزون التجريبي المرتبط. لا يوقف جلسة نشطة ولا يرسل أمراً لجهاز فعلي. سجّل السبب قبل التأكيد.',
  'blocked': 'حظر الصيانة مفعّل',
  'notBlocked': 'لا يوجد حظر صيانة مسجّل',
  'unknown': 'التوافر المباشر: غير معروف',
  'approval': 'تتطلب إزالة الحظر موافقة الشركة وصلاحية الرخصة الحالية.',
  'empty': 'لا توجد سجلات مخزون ضمن هذا النطاق.',
  'uncertain': 'ربما حُفظ التحديث. حدّث المخزون المعتمد قبل إعادة الإرسال.',
};
