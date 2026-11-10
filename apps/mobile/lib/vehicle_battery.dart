import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'vendor_forms.dart' show vendorDigits;

/// Explicit simulator observations; no background vehicle telemetry or location.
class VehicleBatteryView extends StatefulWidget {
  final EvApi api;
  final int vehicleId;
  final VoidCallback onExpired;
  final ValueChanged<String> onDiscover;
  const VehicleBatteryView({
    super.key,
    required this.api,
    required this.vehicleId,
    required this.onExpired,
    required this.onDiscover,
  });
  @override
  State<VehicleBatteryView> createState() => _VehicleBatteryViewState();
}

class _VehicleBatteryViewState extends State<VehicleBatteryView> {
  final threshold = TextEditingController();
  final percent = TextEditingController(text: '15');
  Map<String, dynamic>? preferences, report, pending;
  bool loading = false, enabled = true;
  Object? error;
  int generation = 0;
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  String get path => 'vehicles/${widget.vehicleId}';
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final data = Map<String, dynamic>.from(
        await widget.api.request('$path/battery-preferences'),
      );
      if (!mounted || request != generation) return;
      setState(() {
        preferences = data;
        enabled = data['alerts_enabled'] == true;
        threshold.text = '${data['threshold_percent']}';
      });
    } catch (e) {
      fail(e, request);
    } finally {
      if (mounted && request == generation) setState(() => loading = false);
    }
  }

  void fail(Object e, int request) {
    if (!mounted || request != generation) return;
    if (e is ApiException && [401, 419].contains(e.status))
      widget.onExpired();
    else
      setState(() => error = e);
  }

  Future<void> save() async {
    final number = int.tryParse(vendorDigits(threshold.text.trim()));
    if (number == null || number < 1 || number > 50) {
      setState(
        () => error = t(
          'Threshold must be 1–50%.',
          'يجب أن تكون العتبة بين 1 و50٪.',
        ),
      );
      return;
    }
    final body = {
      'alerts_enabled': enabled,
      'threshold_percent': number,
      'expected_version': preferences!['version'],
    };
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final value = Map<String, dynamic>.from(
        await widget.api.request(
          '$path/battery-preferences',
          method: 'PUT',
          body: body,
        ),
      );
      if (mounted && request == generation) setState(() => preferences = value);
    } catch (e) {
      fail(e, request);
    } finally {
      if (mounted && request == generation) setState(() => loading = false);
    }
  }

  Future<void> simulate() async {
    final number = int.tryParse(vendorDigits(percent.text.trim()));
    if (pending == null && (number == null || number < 0 || number > 100)) {
      setState(
        () => error = t(
          'Battery must be 0–100%.',
          'يجب أن تكون البطارية بين 0 و100٪.',
        ),
      );
      return;
    }
    final body = pending ??= {
      'operation_key': operationKey(),
      'battery_percent': number,
    };
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final value = Map<String, dynamic>.from(
        await widget.api.request(
          '$path/battery-simulation',
          method: 'POST',
          body: body,
        ),
      );
      if (mounted && request == generation)
        setState(() {
          report = value;
          pending = null;
        });
    } catch (e) {
      fail(e, request);
    } finally {
      if (mounted && request == generation) setState(() => loading = false);
    }
  }

  @override
  void dispose() {
    generation++;
    threshold.dispose();
    percent.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        t(
          'Battery preferences · simulator alerts only',
          'تفضيلات البطارية · تنبيهات محاكاة فقط',
        ),
      ),
      if (loading) const LinearProgressIndicator(),
      if (error != null) Text('$error'),
      TextButton(
        onPressed: loading ? null : load,
        child: Text(t('Refresh preferences', 'تحديث التفضيلات')),
      ),
      if (preferences != null) ...[
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(
            t(
              'Enable low-battery notifications',
              'تفعيل تنبيهات البطارية المنخفضة',
            ),
          ),
          value: enabled,
          onChanged: loading || pending != null
              ? null
              : (v) => setState(() => enabled = v!),
        ),
        TextField(
          controller: threshold,
          enabled: !loading && pending == null,
          keyboardType: TextInputType.number,
          decoration: InputDecoration(
            labelText: t('Alert threshold % (1–50)', 'عتبة التنبيه ٪ (1–50)'),
          ),
        ),
        TextButton(
          onPressed: loading || pending != null ? null : save,
          child: Text(t('Save battery preferences', 'حفظ تفضيلات البطارية')),
        ),
        if (preferences!['can_simulate'] == true) ...[
          TextField(
            controller: percent,
            enabled: !loading && pending == null,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              labelText: t(
                'Simulated battery % (0–100)',
                'بطارية محاكاة ٪ (0–100)',
              ),
            ),
          ),
          TextButton(
            onPressed: loading ? null : simulate,
            child: Text(
              pending == null
                  ? t('Record simulator observation', 'تسجيل قراءة محاكاة')
                  : t(
                      'Retry same simulator observation',
                      'إعادة محاولة قراءة المحاكاة نفسها',
                    ),
            ),
          ),
        ],
        if (report != null)
          Text(
            report!['alerted'] == true
                ? t(
                    'Simulated alert saved in Notifications.',
                    'تم حفظ تنبيه المحاكاة في الإشعارات.',
                  )
                : t(
                    'Simulator observation saved; current preferences did not trigger an alert.',
                    'تم حفظ قراءة المحاكاة؛ لم تؤد التفضيلات الحالية إلى تنبيه.',
                  ),
          ),
        TextButton(
          onPressed: loading
              ? null
              : () =>
                    widget.onDiscover(preferences!['connector_type'] as String),
          child: Text(t('Find compatible chargers', 'البحث عن شواحن متوافقة')),
        ),
        Text(
          t(
            'Choose location explicitly in the catalog for nearby results. Availability is checked again when booking.',
            'اختر الموقع صراحةً في الدليل للنتائج القريبة. يُعاد التحقق من التوفر عند الحجز.',
          ),
        ),
      ],
    ],
  );
}
