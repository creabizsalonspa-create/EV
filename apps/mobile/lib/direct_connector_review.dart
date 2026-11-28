import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';

class DirectConnectorReviewView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId, connectorId;
  final String protocol;
  final bool canReview;
  final VoidCallback onExpired;

  const DirectConnectorReviewView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.connectorId,
    required this.protocol,
    required this.canReview,
    required this.onExpired,
  });

  @override
  State<DirectConnectorReviewView> createState() => _DirectConnectorReviewState();
}

class _DirectConnectorReviewState extends State<DirectConnectorReviewView>
    with WidgetsBindingObserver {
  final evse = TextEditingController(), number = TextEditingController();
  late bool Function() authorized;
  StreamSubscription<int>? sessionSubscription;
  bool retired = false;
  int generation = 0;
  bool foreground = true, busy = false, readEmpty = false, uncertain = false;
  Map<String, dynamic>? receipt;
  String? error;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get path =>
      'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/direct-connector-reviews';
  bool get canRenew => receipt?['review_state'] == 'renewal_required';
  bool current(int n) =>
      mounted && !retired && foreground && authorized() && n == generation;
  void clear({bool invalidate = true}) {
    if (invalidate) generation++;
    busy = false;
    readEmpty = false;
    uncertain = false;
    receipt = null;
    error = null;
    evse.text = widget.protocol == '1.6' ? '0' : '';
    number.clear();
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    final source = widget.api;
    sessionSubscription = source.sessionEvents.listen((_) {
      if (mounted && identical(source, widget.api) && !authorized()) {
        // A real 401 may synchronously retire this fence before throwing.
        // Redact immediately but keep that request's generation so its
        // endsCurrentSession callback can still notify the parent.
        setState(() {
          retired = true;
          clear(invalidate: false);
        });
      }
    });
    clear();
  }

  @override
  void didUpdateWidget(covariant DirectConnectorReviewView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api || old.companyId != widget.companyId ||
        old.chargerId != widget.chargerId || old.connectorId != widget.connectorId ||
        old.protocol != widget.protocol || old.canReview != widget.canReview) {
      // Never bind an already-open private route to a replacement account or scope.
      retired = true;
      clear();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (mounted) {
      setState(() {
        foreground = state == AppLifecycleState.resumed;
        if (!foreground) clear();
      });
    }
  }

  @override
  void dispose() {
    generation++;
    sessionSubscription?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    evse.dispose();
    number.dispose();
    super.dispose();
  }

  bool validTarget(int e, int n) =>
      ['1.6', '2.0.1'].contains(widget.protocol) &&
      (widget.protocol == '1.6' ? e == 0 : e >= 1 && e <= 1000) &&
      n >= 1 && n <= 100;

  Map<String, dynamic> parseReceipt(dynamic row) {
    if (row is! Map || row['id'] is! int || row['id'] <= 0 ||
        row['id'] > 9007199254740991 ||
        row['connector_id'] != widget.connectorId ||
        row['source_evse_number'] is! int || row['source_connector_number'] is! int ||
        !validTarget(row['source_evse_number'], row['source_connector_number']) ||
        row['current_registration_matches'] is! bool ||
        !['current', 'renewal_required', 'inventory_changed', 'operator_review_required'].contains(row['review_state']) ||
        row['current_registration_matches'] != (row['review_state'] == 'current') ||
        row['source_observed_at'] is! String || row['reviewed_at'] is! String ||
        row['evidence'] != 'DIRECT_CONNECTOR_REVIEW_ONLY' ||
        row['charging_mapping_created'] != false ||
        row['physical_availability_verified'] != false ||
        row['remote_control_authorized'] != false) {
      throw const FormatException('Invalid direct connector review receipt');
    }
    return Map<String, dynamic>.from(row);
  }

  Future<void> read() async {
    if (retired || !foreground || !authorized() || busy) return;
    final n = ++generation;
    setState(() {
      busy = true;
      receipt = null;
      readEmpty = false;
      error = null;
    });
    try {
      final page = await widget.api.request(path, query: {
        'connector_id': '${widget.connectorId}',
      });
      if (!current(n)) return;
      if (page is! Map || page['current_page'] != 1 || page['last_page'] != 1 ||
          page['data'] is! List || page['data'].length > 1 ||
          page['total'] != page['data'].length) {
        throw const FormatException('Invalid review page');
      }
      final result = page['data'].isEmpty ? null : parseReceipt(page['data'][0]);
      setState(() {
        receipt = result;
        readEmpty = result == null;
        if (result != null && result['review_state'] == 'renewal_required') {
          evse.text = '${result['source_evse_number']}';
          number.text = '${result['source_connector_number']}';
        }
        uncertain = false;
      });
    } catch (e) {
      if (e is ApiException && e.endsCurrentSession && mounted && foreground &&
          generation == n) {
        setState(clear);
        widget.onExpired();
      } else if (current(n)) {
        setState(() => error = 'read');
      }
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (retired || !foreground || !authorized() || busy || uncertain || (!readEmpty && !canRenew) ||
        !widget.canReview) return;
    final e = int.tryParse(evse.text.trim()), c = int.tryParse(number.text.trim());
    if (e == null || c == null || !validTarget(e, c) ||
        (canRenew && (receipt!['source_evse_number'] != e ||
          receipt!['source_connector_number'] != c))) {
      setState(() => error = 'target');
      return;
    }
    final n = ++generation;
    setState(() {
      busy = true;
      readEmpty = false;
      receipt = null;
      error = null;
    });
    try {
      final row = parseReceipt(await widget.api.request(path, method: 'POST', body: {
        'connector_id': widget.connectorId,
        'source_evse_number': e,
        'source_connector_number': c,
      }));
      if (!current(n)) return;
      if (row['source_evse_number'] != e || row['source_connector_number'] != c ||
          row['current_registration_matches'] != true) {
        throw const FormatException('Unexpected review target');
      }
      setState(() => receipt = row);
    } catch (ex) {
      if (ex is ApiException && ex.endsCurrentSession && mounted && foreground &&
          generation == n) {
        setState(clear);
        widget.onExpired();
      } else if (current(n)) {
        setState(() => uncertain = true);
      }
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final visible = !retired && foreground && authorized();
    return Scaffold(
      appBar: AppBar(title: Text(t('Direct connector identity review', 'مراجعة هوية الموصل المباشر'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('Review records identity only. Charging, physical availability and control remain disabled.',
            'تسجل المراجعة الهوية فقط. يظل الشحن والتوفر الفعلي والتحكم غير مفعّلة.')),
          if (visible) ...[
            OutlinedButton(
              onPressed: busy ? null : read,
              child: Text(t('Read stored connector review', 'قراءة مراجعة الموصل المحفوظة')),
            ),
            if (busy) const LinearProgressIndicator(),
            if (error == 'read')
              Text(t('Connector review unavailable. Read again explicitly.', 'مراجعة الموصل غير متاحة. أعد القراءة صراحةً.')),
            if (error == 'target')
              Text(t('Enter a valid protocol-specific connector target.', 'أدخل هدف موصل صالحاً للبروتوكول.')),
            if (uncertain)
              Text(t('Review outcome uncertain; read stored review before another submission.',
                'نتيجة المراجعة غير مؤكدة؛ اقرأ المراجعة المحفوظة قبل إرسال طلب آخر.')),
            if (receipt != null) ...[
              Text(switch (receipt!['review_state']) {
                'current' => t('Stored connector review matches current registration', 'مراجعة الموصل المحفوظة تطابق التسجيل الحالي'),
                'renewal_required' => t('Stored review requires registration reconciliation', 'تتطلب المراجعة المحفوظة تسوية التسجيل'),
                'inventory_changed' => t('Connector inventory changed; operator investigation required', 'تغيرت بيانات الموصل؛ يلزم تحقيق المشغل'),
                _ => t('Connector review requires operator investigation', 'تتطلب مراجعة الموصل تحقيق المشغل'),
              }),
              Text('#${receipt!['id']} · ${receipt!['source_evse_number']}/${receipt!['source_connector_number']}'),
              Text('${receipt!['reviewed_at']}'),
            ],
            if (readEmpty || canRenew) ...[
              if (readEmpty) Text(t('No connector review recorded', 'لم تُسجل مراجعة للموصل')),
              if (widget.canReview && ['1.6', '2.0.1'].contains(widget.protocol)) ...[
                TextField(
                  controller: evse,
                  readOnly: widget.protocol == '1.6' || canRenew,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: t('Source EVSE number', 'رقم وحدة الشحن لدى المصدر')),
                ),
                TextField(
                  controller: number,
                  readOnly: canRenew,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: t('Source connector number', 'رقم الموصل لدى المصدر')),
                ),
                Text(canRenew
                  ? t('A newer source event is required; the original review will be retained.',
                    'يلزم حدث أحدث من المصدر؛ سيتم الاحتفاظ بالمراجعة الأصلية.')
                  : t('The server rechecks the exact recent source target before saving.',
                    'يعيد الخادم التحقق من هدف المصدر الحديث المحدد قبل الحفظ.')),
                FilledButton(
                  onPressed: busy ? null : submit,
                  child: Text(canRenew
                    ? t('Re-review same connector identity', 'إعادة مراجعة هوية الموصل نفسه')
                    : t('Record connector identity review', 'تسجيل مراجعة هوية الموصل')),
                ),
              ],
            ],
          ],
        ],
      ),
    );
  }
}
