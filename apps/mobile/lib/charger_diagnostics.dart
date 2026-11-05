import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

String diagnosticsTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'تشخيص المصدر'
    : 'Source diagnostics';

class ChargerDiagnosticsView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final VoidCallback onExpired;
  const ChargerDiagnosticsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
  });
  @override
  State<ChargerDiagnosticsView> createState() => _DiagnosticsState();
}

class _DiagnosticsState extends State<ChargerDiagnosticsView> {
  Map<String, dynamic>? data;
  Object? error;
  bool busy = false;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load({int? before}) async {
    final epoch = ++generation;
    setState(() { busy = true; data = null; error = null; });
    try {
      final value = await widget.api.request(
        'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/source-diagnostics',
        query: {if (before != null) 'before': '$before'},
      );
      if (mounted && epoch == generation) {
        setState(() => data = Map<String, dynamic>.from(value));
      }
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    String tr(String en, String arabic) => ar ? arabic : en;
    final value = data;
    return Scaffold(
      appBar: AppBar(title: Text(diagnosticsTitle(context))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(tr('Authenticated simulator observations, not connector availability or physical acceptance. No commands are sent.', 'ملاحظات محاكي موثقة وليست توفر الموصل أو قبول الأجهزة الفعلية. لا تُرسل أوامر.')),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
        TextButton(onPressed: busy ? null : () => load(), child: Text(tr('Refresh', 'تحديث'))),
        if (value != null) ...[
          Text('${value['protocol']}'),
          Text(value['source_registered'] == true ? tr('Source registered', 'المصدر مسجل') : tr('Source not observed', 'لم يُرصد المصدر')),
          if (value['source_registered'] == true) ...[
            Text(value['source_online'] == true ? tr('Source reports online', 'المصدر يبلغ عن الاتصال') : tr('Source reports offline', 'المصدر يبلغ عن انقطاع الاتصال')),
            Text(value['recent_message_observed'] == true ? tr('Recent source message', 'رسالة مصدر حديثة') : tr('No recent source message', 'لا توجد رسالة مصدر حديثة')),
            Text('${value['last_message_at'] ?? '—'}'),
            Text('${value['firmware_version'] ?? '—'}'),
          ],
          for (final event in value['events'] as List)
            ListTile(title: Text('${event['connectorStatus']} · ${event['errorCode'] ?? '—'}'), subtitle: Text('${event['evseId'] ?? '—'}/${event['connectorId'] ?? '—'} · ${event['timestamp']}')),
          if (value['next_before'] != null)
            TextButton(onPressed: busy ? null : () => load(before: value['next_before'] as int), child: Text(tr('Next', 'التالي'))),
        ],
      ]),
    );
  }
}
