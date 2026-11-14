import 'package:flutter/material.dart';
import 'api.dart';

String vendorAgreementTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'اتفاقية الشركة'
    : 'Vendor agreement';

/// Owner review only. Publishing remains in the protected Admin web workflow.
class VendorAgreementsView extends StatefulWidget {
  final EvApi api;
  final int companyId;
  final VoidCallback onExpired;
  const VendorAgreementsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.onExpired,
  });
  @override
  State<VendorAgreementsView> createState() => _VendorAgreementsState();
}

class _VendorAgreementsState extends State<VendorAgreementsView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? data, receipt;
  bool busy = false, accepted = false, foreground = true;
  String? error;
  int generation = 0, page = 1;
  late bool Function() authorized;
  String get base => 'vendor-ops/companies/${widget.companyId}/agreement';
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int epoch) =>
      mounted && foreground && authorized() && epoch == generation;
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  void clear() {
    generation++;
    data = null;
    receipt = null;
    error = null;
    busy = false;
    accepted = false;
  }

  @override
  void didUpdateWidget(covariant VendorAgreementsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.companyId != widget.companyId) {
      clear();
      page = 1;
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted)
      setState(() {
        if (!foreground) clear();
      });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void fail(Object e, int epoch) {
    if (!current(epoch)) return;
    setState(
      () => error = t(
        'Unable to complete this request. Refresh the recorded state before continuing.',
        'تعذر إكمال الطلب. حدّث الحالة المسجلة قبل المتابعة.',
      ),
    );
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int number = 1, String? id}) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      data = null;
      receipt = null;
      accepted = false;
    });
    try {
      final raw = await widget.api.request(
        id == null ? base : '$base/receipts/$id',
        query: {if (id == null) 'page': '$number'},
      );
      if (!current(epoch)) return;
      setState(() {
        if (id == null) {
          data = Map<String, dynamic>.from(raw);
          page = number;
        } else {
          receipt = Map<String, dynamic>.from(raw);
        }
      });
    } catch (e) {
      fail(e, epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> accept() async {
    if (!foreground ||
        !authorized() ||
        busy ||
        !accepted ||
        data?['can_accept'] != true)
      return;
    final version = Map<String, dynamic>.from(data!['version']);
    final epoch = ++generation;
    setState(() {
      busy = true;
      accepted = false;
      error = null;
    });
    try {
      await widget.api.request(
        '$base/accept',
        method: 'POST',
        body: {
          'version_id': version['id'],
          'sha256': version['sha256'],
          'accepted': true,
        },
      );
      if (current(epoch)) await load();
    } catch (e) {
      if (current(epoch)) setState(() => data = null);
      fail(e, epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  List<Widget> version(Map v) => [
    Text(
      '${v[Localizations.localeOf(context).languageCode == 'ar' ? 'title_ar' : 'title_en']}',
      style: Theme.of(context).textTheme.titleLarge,
    ),
    Text('${v['country']} · ${v['revision']} · ${v['sha256']}'),
    SelectableText(
      '${v[Localizations.localeOf(context).languageCode == 'ar' ? 'body_ar' : 'body_en']}',
    ),
  ];
  @override
  Widget build(BuildContext context) {
    if (!foreground || !authorized())
      return Scaffold(
        appBar: AppBar(title: Text(vendorAgreementTitle(context))),
      );
    final value = data, record = receipt, history = value?['history'] as Map?;
    return Scaffold(
      appBar: AppBar(title: Text(vendorAgreementTitle(context))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Recorded agreement evidence does not approve a company, authorize charging or payments, or establish legal acceptance. Only the company owner can accept.',
              'دليل الاتفاقية المسجل لا يعتمد الشركة أو يفوض الشحن أو الدفع ولا يثبت القبول القانوني. يمكن لمالك الشركة فقط الموافقة.',
            ),
          ),
          TextButton(
            onPressed: busy ? null : () => load(number: page),
            child: Text(t('Refresh', 'تحديث')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(error!),
          if (record != null) ...[
            ...version(record['version'] as Map),
            Text(
              '${record['receipt']['id']} · ${record['receipt']['created_at']} · ${record['receipt']['version_sha256']}',
            ),
          ],
          if (value != null) ...[
            if (value['version'] is Map)
              ...version(value['version'] as Map)
            else
              Text(
                t(
                  'No agreement is published for this country. Contact the administrator.',
                  'لا توجد اتفاقية منشورة لهذا البلد. تواصل مع المسؤول.',
                ),
              ),
            if (value['acceptance'] != null)
              Text(
                '${t('Accepted', 'تمت الموافقة')} · ${value['acceptance']['created_at']} · ${value['acceptance']['id']}',
              ),
            if (value['can_accept'] == true) ...[
              CheckboxListTile(
                value: accepted,
                onChanged: busy
                    ? null
                    : (v) => setState(() => accepted = v == true),
                title: Text(
                  t(
                    'I reviewed this exact version and am authorized to accept for this company.',
                    'راجعت هذه النسخة بالتحديد وأنا مخول بالموافقة عن هذه الشركة.',
                  ),
                ),
              ),
              FilledButton(
                onPressed: busy || !accepted ? null : accept,
                child: Text(
                  t(
                    'Accept reviewed agreement',
                    'الموافقة على الاتفاقية المراجعة',
                  ),
                ),
              ),
            ],
            Text(
              t('Acceptance history', 'سجل الموافقات'),
              style: Theme.of(context).textTheme.titleMedium,
            ),
            for (final row in (history?['data'] as List? ?? []))
              ListTile(
                title: Text(
                  '${row['country']} · ${row['revision']} · ${row['created_at']}',
                ),
                subtitle: Text('${row['version_sha256']}'),
                trailing: TextButton(
                  onPressed: busy ? null : () => load(id: '${row['id']}'),
                  child: Text(t('Read receipt', 'قراءة الإيصال')),
                ),
              ),
            if (history != null)
              Row(
                children: [
                  TextButton(
                    onPressed: busy || page <= 1
                        ? null
                        : () => load(number: page - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                  Text('${history['current_page']} / ${history['last_page']}'),
                  TextButton(
                    onPressed: busy || page >= (history['last_page'] as int)
                        ? null
                        : () => load(number: page + 1),
                    child: Text(t('Next', 'التالي')),
                  ),
                ],
              ),
          ],
        ],
      ),
    );
  }
}
