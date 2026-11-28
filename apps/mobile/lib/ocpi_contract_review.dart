import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';

/// Internal document evidence only; never a live roaming or payment approval.
class OcpiContractReviewView extends StatefulWidget {
  final EvApi api;
  final int providerId;
  final bool admin, providerAuthorized;
  final VoidCallback onExpired;
  const OcpiContractReviewView({super.key, required this.api,
    required this.providerId, required this.admin, required this.providerAuthorized,
    required this.onExpired});
  @override
  State<OcpiContractReviewView> createState() => _ReviewState();
}

class _ReviewState extends State<OcpiContractReviewView> with WidgetsBindingObserver {
  final reference = TextEditingController(), digest = TextEditingController(),
      expiry = TextEditingController(), reason = TextEditingController();
  late bool Function() fence;
  StreamSubscription<int>? subscription;
  Map<String, dynamic>? status;
  List<Map<String, dynamic>>? history;
  int generation = 0, page = 1, last = 1;
  bool foreground = true, busy = false;
  String? message;
  String get path => 'ocpi/providers/' + widget.providerId.toString() + '/contract-review';
  String tr(String en, String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int turn) => mounted && foreground && fence() && turn == generation;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    bind();
    load();
  }
  void bind() {
    fence = widget.api.sessionFence();
    subscription = widget.api.sessionEvents.listen((_) { if (mounted) setState(redact); });
  }
  void wipe() { for (final c in [reference, digest, expiry, reason]) { c.clear(); } }
  void redact() {
    generation++; wipe(); status = null; history = null;
    message = null; busy = false; page = last = 1;
  }
  @override
  void didUpdateWidget(covariant OcpiContractReviewView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.providerId != widget.providerId ||
        oldWidget.admin != widget.admin || oldWidget.providerAuthorized != widget.providerAuthorized) {
      subscription?.cancel(); redact(); bind();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted) setState(() { if (!foreground) redact(); });
  }
  @override
  void dispose() {
    generation++; subscription?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    for (final c in [reference, digest, expiry, reason]) { c.dispose(); }
    super.dispose();
  }

  static bool evidence(Map v) =>
      v['external_partner_acceptance_verified'] == false &&
      v['contract_legally_executed_verified'] == false &&
      v['live_roaming_enabled'] == false && v['payment_authority'] == false &&
      v['operational_enforcement_available'] == false &&
      v['administrative_authorization_review_required'] is bool;
  static bool date(Object? v) {
    if (v is! String || !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v)) return false;
    final d = DateTime.tryParse(v);
    return d != null && d.toIso8601String().substring(0, 10) == v;
  }
  static Map<String, dynamic> parseStatus(dynamic v) {
    if (v is! Map || !evidence(v) ||
        v['source'] != 'INTERNAL_MANUAL_DOCUMENT_DIGEST_ONLY' ||
        v['automatic_partner_requests'] != 0 ||
        !['missing', 'internally_reviewed', 'revoked'].contains(v['state']) ||
        v['revision'] is! int || v['revision'] < 0 ||
        v['review_valid_for_current_owner'] is! bool ||
        (v['review_valid_for_current_owner'] == true && v['state'] != 'internally_reviewed') ||
        (v['valid_until'] != null && !date(v['valid_until']))) {
      throw const FormatException('Invalid review evidence');
    }
    return Map<String, dynamic>.from(v);
  }
  void fail(Object e, int turn, {bool mutation = false}) {
    if (!current(turn)) return;
    setState(() {
      redact();
      message = mutation
          ? tr('Review outcome uncertain. Refresh before another decision.', 'نتيجة المراجعة غير مؤكدة. حدّث قبل اتخاذ قرار آخر.')
          : tr('Internal review unavailable. No approval is assumed.', 'المراجعة الداخلية غير متاحة. لا يُفترض وجود موافقة.');
    });
    if (e is ApiException && [401,419].contains(e.status)) widget.onExpired();
  }
  Future<void> load() async {
    if (!foreground || !fence() || busy) return;
    final turn = ++generation;
    setState(() { wipe(); status = null; history = null; message = null; busy = true; });
    try {
      final v = parseStatus(await widget.api.request(path));
      if (current(turn)) setState(() => status = v);
    } catch (e) { fail(e, turn); }
    finally { if (current(turn)) setState(() => busy = false); }
  }
  Future<void> audit([int target = 1]) async {
    if (!foreground || !fence() || busy || status == null) return;
    final snapshot = status!;
    final turn = ++generation;
    setState(() { busy = true; history = null; message = null; });
    try {
      final v = await widget.api.request('$path/history', query: {'page': '$target'});
      if (v is! Map || !evidence(v) || v['scope'] != 'private_ocpi_contract_review_history' ||
          v['current_revision'] != snapshot['revision'] ||
          v['administrative_authorization_review_required'] != snapshot['administrative_authorization_review_required']) {
        throw const FormatException('Invalid audit');
      }
      final h = v['history'];
      if (h is! Map || h['data'] is! List || (h['data'] as List).length > 25 ||
          h['current_page'] != target || h['last_page'] is! int ||
          h['last_page'] < 1 || h['last_page'] > 100000 ||
          h['total'] is! int || h['total'] < (h['data'] as List).length) {
        throw const FormatException('Invalid history page');
      }
      final rows = <Map<String,dynamic>>[], seen = <int>{};
      for (final row in h['data'] as List) {
        if (row is! Map || row['revision'] is! int || row['revision'] < 1 ||
            row['revision'] > snapshot['revision'] || !seen.add(row['revision'] as int) ||
            !['review','revoke'].contains(row['decision']) || row['recorded_at'] is! String ||
            (row['valid_until'] != null && !date(row['valid_until']))) {
          throw const FormatException('Invalid history row');
        }
        final safe = <String,dynamic>{'revision': row['revision'], 'decision': row['decision'],
          'recorded_at': row['recorded_at'], 'valid_until': row['valid_until']};
        if (widget.admin) {
          for (final key in ['reference_id','reason','evidence_sha256']) {
            if (row[key] != null && (row[key] is! String || (row[key] as String).length > 1000)) {
              throw const FormatException('Invalid audit detail');
            }
            safe[key] = row[key];
          }
        }
        rows.add(safe);
      }
      if (current(turn)) setState(() { history = rows; page = target; last = h['last_page'] as int; });
    } catch (e) { fail(e, turn); }
    finally { if (current(turn)) setState(() => busy = false); }
  }
  Future<void> decide(String decision) async {
    if (!foreground || !fence() || busy || !widget.admin || status == null) return;
    final snapshot = status!;
    if (decision == 'revoke' && (snapshot['state'] != 'internally_reviewed' ||
        (snapshot['administrative_authorization_review_required'] == true && widget.providerAuthorized))) return;
    if (reason.text.trim().length < 15 || reason.text.length > 1000 ||
        (decision == 'review' && (!RegExp(r'^[A-Za-z0-9][A-Za-z0-9_.-]{5,119}$').hasMatch(reference.text) ||
            !RegExp(r'^[a-f0-9]{64}$').hasMatch(digest.text) || !date(expiry.text)))) {
      setState(() => message = tr('Enter valid internal review evidence and reason.', 'أدخل دليل المراجعة الداخلية وسببها بشكل صحيح.'));
      return;
    }
    final turn = ++generation;
    setState(() { busy = true; message = null; });
    final agreed = await showDialog<bool>(context: context, builder: (context) => AlertDialog(
      title: Text(tr('Confirm internal review decision','تأكيد قرار المراجعة الداخلية')),
      content: Text(tr('This does not activate live roaming or payments.', 'هذا لا يفعّل التجوال المباشر أو المدفوعات.')),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context,false), child: Text(tr('Cancel','إلغاء'))),
        TextButton(onPressed: () => Navigator.pop(context,true), child: Text(tr('Confirm','تأكيد'))),
      ],
    ));
    if (!current(turn)) return;
    if (agreed != true) { setState(() => busy = false); return; }
    try {
      final payload = <String,dynamic>{'decision': decision, 'reason': reason.text,
        'expected_revision': snapshot['revision'], 'idempotency_key': operationKey(),
        if (decision == 'review') ...{'reference_id': reference.text, 'evidence_sha256': digest.text, 'valid_until': expiry.text}};
      setState(() { wipe(); status = null; history = null; });
      await widget.api.request(path, method: 'POST', body: payload);
      if (current(turn)) { setState(() => busy = false); await load(); }
    } catch (e) { fail(e, turn, mutation: true); }
    finally { if (current(turn)) setState(() => busy = false); }
  }
  Widget field(TextEditingController c, String label, int max) => TextField(
    controller: c, enabled: !busy, maxLength: max, decoration: InputDecoration(labelText: label));
  @override
  Widget build(BuildContext context) {
    final v = status;
    return Scaffold(appBar: AppBar(title: Text(tr('Partner contract review','مراجعة عقد الشريك'))),
      body: !foreground || !fence() ? const SizedBox.shrink() : ListView(
        padding: const EdgeInsets.all(16), children: [
          Text(tr('Internal document digest review only — not a signed contract, partner acceptance, live charging or payment authority.',
              'مراجعة داخلية لبصمة المستند فقط، وليست عقداً موقعاً أو قبولاً من الشريك أو صلاحية للشحن أو الدفع.')),
          TextButton(onPressed: busy ? null : load, child: Text(tr('Refresh review','تحديث المراجعة'))),
          if (busy) const LinearProgressIndicator(),
          if (message != null) Text(message!),
          if (v != null) ...[
            Text(switch(v['state']) {
              'internally_reviewed' => tr('Internally reviewed only','تمت مراجعته داخلياً فقط'),
              'revoked' => tr('Internal review revoked','أُلغيت المراجعة الداخلية'),
              _ => tr('Missing internal review','المراجعة الداخلية مفقودة'),
            }),
            Text(tr('Revision: ','الإصدار: ') + v['revision'].toString()),
            Text(v['review_valid_for_current_owner'] == true ? tr('Current internal review','المراجعة الداخلية سارية') : tr('No current internal review','لا توجد مراجعة داخلية سارية')),
            if (v['valid_until'] != null) Text(tr('Valid until (UTC): ','سارية حتى (UTC): ') + v['valid_until'].toString()),
            Text(v['administrative_authorization_review_required'] == true
                ? tr('Internal review required for new administrative authorization.','المراجعة الداخلية مطلوبة للتفويض الإداري الجديد.')
                : tr('Internal review policy is not enabled.','سياسة المراجعة الداخلية غير مفعلة.')),
            TextButton(onPressed: busy ? null : () => audit(), child: Text(tr('Read review history','قراءة سجل المراجعة'))),
            if (history != null) ...[
              for (final row in history!) Card(child: Padding(padding: const EdgeInsets.all(8),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('#' + row['revision'].toString() + ' · ' + row['decision'].toString() + ' · ' + row['recorded_at'].toString()),
                  if (row['valid_until'] != null) Text(row['valid_until'].toString()),
                  if (widget.admin) for (final key in ['reference_id','reason','evidence_sha256'])
                    if (row[key] != null) Text(row[key].toString()),
                ]))),
              Row(children: [
                TextButton(onPressed: busy || page <= 1 ? null : () => audit(page-1), child: Text(tr('Previous','السابق'))),
                Text('$page / $last'),
                TextButton(onPressed: busy || page >= last ? null : () => audit(page+1), child: Text(tr('Next','التالي'))),
              ]),
            ],
            if (widget.admin) ...[
              field(reference,tr('Internal case reference','مرجع القضية الداخلية'),120),
              field(digest,tr('Reviewed document SHA-256 digest','بصمة SHA-256 للمستند المراجع'),64),
              field(expiry,tr('Validity date YYYY-MM-DD (UTC)','تاريخ الصلاحية (UTC)'),10),
              field(reason,tr('Internal review reason','سبب المراجعة الداخلية'),1000),
              FilledButton(onPressed: busy ? null : () => decide('review'), child: Text(tr('Record internal review','تسجيل المراجعة الداخلية'))),
              if (v['state'] == 'internally_reviewed')
                if (v['administrative_authorization_review_required'] == true && widget.providerAuthorized)
                  Text(tr('Revoke provider authorization before withdrawing the review.','ألغِ تفويض مقدم الخدمة قبل سحب المراجعة.'))
                else TextButton(onPressed: busy ? null : () => decide('revoke'), child: Text(tr('Revoke internal review','إلغاء المراجعة الداخلية'))),
            ],
          ],
        ],
      ),
    );
  }
}
