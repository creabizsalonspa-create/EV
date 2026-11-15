import 'package:flutter/material.dart';
import 'api.dart';

class RoamingMeterHistoryView extends StatefulWidget {
  final EvApi api;
  final int orderId;
  final int? providerId;
  final VoidCallback onExpired;
  const RoamingMeterHistoryView({
    super.key,
    required this.api,
    required this.orderId,
    this.providerId,
    required this.onExpired,
  });
  @override
  State<RoamingMeterHistoryView> createState() => _MeterHistoryState();
}

class _MeterHistoryState extends State<RoamingMeterHistoryView>
    with WidgetsBindingObserver {
  late bool Function() authorized;
  int generation = 0;
  int? through;
  bool foreground = true, busy = false;
  Map<String, dynamic>? data;
  String? error;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int n) =>
      mounted && foreground && authorized() && generation == n;
  void clear() {
    generation++;
    through = null;
    data = null;
    error = null;
    busy = false;
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant RoamingMeterHistoryView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api ||
        old.orderId != widget.orderId ||
        old.providerId != widget.providerId) {
      clear();
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground && mounted) setState(clear);
  }

  bool valid(dynamic d, int page) {
    if (d is! Map ||
        d['scope'] != 'roaming_source_meter_history' ||
        d['order_id'] != widget.orderId ||
        d['through_id'] is! int ||
        d['through_id'] < 0 ||
        (through != null && d['through_id'] != through) ||
        d['source_bound'] is! bool ||
        d['coverage'] != 'ACCEPTED_VERSIONS_SINCE_INSTRUMENTATION' ||
        d['evidence'] != 'PARTNER_REPORTED_UNVERIFIED' ||
        [
          'backfilled',
          'physical_energy_verified',
          'billing_authority',
          'commands_sent',
        ].any((k) => d[k] != false))
      return false;
    final p = d['observations'];
    if (p is! Map ||
        p['data'] is! List ||
        p['data'].length > 25 ||
        p['current_page'] != page ||
        p['last_page'] is! int ||
        p['last_page'] < 1)
      return false;
    for (final row in p['data']) {
      if (row is! Map ||
          ![
            'PENDING',
            'ACTIVE',
            'COMPLETED',
            'INVALID',
            'RESERVATION',
          ].contains(row['status']) ||
          [
            'meter_regressed',
            'precision_requires_review',
            'source_clock_ahead',
          ].any((k) => row[k] is! bool) ||
          row['source_updated_at'] is! String ||
          row['received_at'] is! String)
        return false;
      final energy = row['reported_energy_kwh'];
      if (energy != null &&
          (energy is! String ||
              !RegExp(
                r'^(0|[1-9][0-9]{0,6})(?:\.[0-9]{1,9})?$',
              ).hasMatch(energy)))
        return false;
    }
    return true;
  }

  Future<void> load({int page = 1, bool refresh = false}) async {
    if (!foreground || !authorized()) return;
    if (refresh) through = null;
    final n = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    final path = widget.providerId == null
        ? 'roaming/sandbox-orders/${widget.orderId}/meter-history'
        : 'ocpi/providers/${widget.providerId}/sandbox-orders/${widget.orderId}/meter-history';
    try {
      final result = await widget.api.request(
        path,
        query: {'page': '$page', if (through != null) 'through': '$through'},
      );
      if (!current(n)) return;
      if (!valid(result, page))
        throw const FormatException('Invalid source history');
      setState(() {
        data = Map<String, dynamic>.from(result);
        through = result['through_id'] as int;
      });
    } catch (e) {
      if (!current(n)) return;
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
      if (current(n))
        setState(
          () => error = t(
            'Source history unavailable. Refresh explicitly.',
            'سجل المصدر غير متاح. حدّث صراحةً.',
          ),
        );
    } finally {
      if (current(n)) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final visible = foreground && authorized() ? data : null;
    final p = visible?['observations'];
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t('Recorded source meter history', 'سجل قراءات عداد المصدر'),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Accepted partner observations only; physical energy and billing are unverified. Earlier versions were not backfilled.',
              'ملاحظات مقبولة من الشريك فقط؛ لم تُتحقق الطاقة الفعلية أو الفوترة. لم يُستكمل السجل السابق بأثر رجعي.',
            ),
          ),
          TextButton(
            onPressed: busy || !foreground || !authorized()
                ? null
                : () => load(refresh: true),
            child: Text(t('Refresh history', 'تحديث السجل')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null && foreground && authorized()) Text(error!),
          if (visible != null) ...[
            if (p['data'].isEmpty)
              Text(
                t(
                  'No accepted source observations recorded for this order.',
                  'لا توجد ملاحظات مصدر مقبولة مسجلة لهذا الطلب.',
                ),
              ),
            for (final row in p['data'])
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${row['status']} · ${row['reported_energy_kwh'] ?? t('Unknown', 'غير معروف')} kWh',
                      ),
                      Text(
                        '${t('Source timestamp', 'وقت المصدر')}: ${row['source_updated_at']}',
                      ),
                      Text(
                        '${t('Received', 'وقت الاستلام')}: ${row['received_at']}',
                      ),
                      if (row['meter_regressed'] == true)
                        Text(
                          t(
                            'Meter decreased; reconciliation required.',
                            'انخفض العداد؛ تلزم مطابقة البيانات.',
                          ),
                        ),
                      if (row['precision_requires_review'] == true)
                        Text(
                          t(
                            'Meter precision requires review.',
                            'تحتاج دقة العداد مراجعة.',
                          ),
                        ),
                      if (row['source_clock_ahead'] == true)
                        Text(
                          t(
                            'Source clock is ahead of receipt.',
                            'ساعة المصدر متقدمة على وقت الاستلام.',
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            Row(
              children: [
                TextButton(
                  onPressed: busy || p['current_page'] <= 1
                      ? null
                      : () => load(page: p['current_page'] - 1),
                  child: Text(t('Previous', 'السابق')),
                ),
                TextButton(
                  onPressed: busy || p['current_page'] >= p['last_page']
                      ? null
                      : () => load(page: p['current_page'] + 1),
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
