import 'package:flutter/material.dart';
import 'api.dart';

class ChargingProvenanceView extends StatefulWidget {
  final EvApi api;
  final int invoiceId;
  final VoidCallback onExpired;
  const ChargingProvenanceView({
    super.key,
    required this.api,
    required this.invoiceId,
    required this.onExpired,
  });
  @override
  State<ChargingProvenanceView> createState() => _ProvenanceState();
}

class _ProvenanceState extends State<ChargingProvenanceView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? proof;
  int generation = 0;
  bool busy = false, background = false, failed = false;
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant ChargingProvenanceView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.invoiceId != widget.invoiceId)
      load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    generation++;
    setState(() {
      background = state != AppLifecycleState.resumed;
      proof = null;
      busy = false;
    });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  bool valid(Map<String, dynamic> v) {
    final verified = v['source_verified'];
    final kind = v['kind'];
    if (v['schema_version'] != 1 ||
        v['invoice_id'] != widget.invoiceId ||
        v['evidence'] != 'SIMULATED_ONLY' ||
        v['physical_charging_verified'] != false ||
        v['financial_records_changed'] != false ||
        v['payment_authority'] != false ||
        v['charging_command_authority'] != false ||
        v['energy_wh'] is! int ||
        (v['energy_wh'] as int) < 0 ||
        verified is! bool ||
        !['APP_SIMULATION', 'OCPP_SIMULATOR', 'OCPI_SANDBOX'].contains(kind) ||
        ![
          'verified_simulated',
          'requires_review',
          'not_source_verified',
        ].contains(v['status']) ||
        verified != (v['status'] == 'verified_simulated') ||
        v['proofs'] is! Map)
      return false;
    if (v['protocol'] != null &&
        !['OCPP 1.6', 'OCPP 2.0.1', 'OCPI 2.2.1'].contains(v['protocol']))
      return false;
    final hashes = v['proofs'] as Map;
    if (!verified) return hashes.isEmpty;
    if (kind == 'APP_SIMULATION') return false;
    final expected = kind == 'OCPI_SANDBOX'
        ? ['session_sha256', 'cdr_sha256']
        : ['start_sha256', 'end_sha256'];
    if (kind == 'OCPI_SANDBOX'
        ? v['protocol'] != 'OCPI 2.2.1'
        : !['OCPP 1.6', 'OCPP 2.0.1'].contains(v['protocol']))
      return false;
    return hashes.length == expected.length &&
        expected.every(
          (key) =>
              hashes[key] is String &&
              RegExp(r'^[a-f0-9]{64}$').hasMatch(hashes[key] as String),
        );
  }

  Future<void> load() async {
    if (background) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      proof = null;
      failed = false;
    });
    try {
      final result = Map<String, dynamic>.from(
        await widget.api.request(
              'finance/invoices/${widget.invoiceId}/source-evidence',
            )
            as Map,
      );
      if (!mounted || background || epoch != generation) return;
      if (!valid(result))
        throw const FormatException('Unsupported charging evidence');
      setState(() => proof = result);
    } catch (e) {
      if (!mounted || background || epoch != generation) return;
      setState(() {
        proof = null;
        failed = true;
      });
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(tr('Charging source evidence', 'أدلة مصدر الشحن')),
    ),
    body: ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text(
          tr(
            'Recorded simulation evidence only. This does not verify physical charging, payment or command authority.',
            'أدلة محاكاة مسجلة فقط. لا تثبت الشحن الفعلي أو الدفع أو صلاحية الأوامر.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (failed)
          Text(
            tr(
              'Charging evidence could not be verified. Retry safely.',
              'تعذر التحقق من أدلة الشحن. أعد المحاولة بأمان.',
            ),
          ),
        TextButton(
          onPressed: busy || background ? null : load,
          child: Text(tr('Refresh evidence', 'تحديث الأدلة')),
        ),
        if (proof != null) ...[
          Text(
            proof!['source_verified'] == true
                ? tr(
                    'Source receipts match this simulated invoice',
                    'تطابق إيصالات المصدر هذه الفاتورة التجريبية',
                  )
                : proof!['status'] == 'requires_review'
                ? tr(
                    'Source evidence requires review',
                    'تتطلب أدلة المصدر المراجعة',
                  )
                : tr(
                    'Application simulation without source verification',
                    'محاكاة التطبيق دون تحقق من المصدر',
                  ),
          ),
          if (proof!['protocol'] is String) Text(proof!['protocol'] as String),
          Text('${proof!['energy_wh']} Wh'),
          for (final hash in (proof!['proofs'] as Map).values)
            SelectableText(hash as String),
        ],
      ],
    ),
  );
}
