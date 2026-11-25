import 'dart:async';
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

class _VehicleBatteryViewState extends State<VehicleBatteryView> with WidgetsBindingObserver {
  final threshold = TextEditingController();
  final percent = TextEditingController(text: '15');
  Map<String, dynamic>? preferences, report, pending;
  bool loading = false, enabled = true;
  Object? error;
  int generation = 0;
  late bool Function() accountFence;
  StreamSubscription<int>? subscription;
  bool foreground = true;
  bool get active => mounted && foreground && accountFence();
  bool current(int ticket, EvApi api) => active && ticket == generation && identical(api,widget.api);
  VoidCallback guard(VoidCallback action) {
    final ticket = generation, api = widget.api;
    return () { if (current(ticket,api) && !loading) action(); };
  }
  void redact({bool clearPending = true}) {
    preferences = report = null;
    if (clearPending) pending = null;
    threshold.clear();
    percent.text = '15';
    enabled = true;
    loading = false;
    error = null;
  }
  void subscribe() {
    final api = widget.api;
    subscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api,widget.api) && !accountFence()) setState(redact);
    });
  }
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  String get path => 'vehicles/${widget.vehicleId}';
  @override
  void initState() {
    super.initState();
    accountFence = widget.api.sessionFence();
    final state = WidgetsBinding.instance.lifecycleState;
    foreground = state == null || state == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    if (active) load();
  }

  @override
  void didUpdateWidget(covariant VehicleBatteryView old) {
    super.didUpdateWidget(old);
    if (!identical(old.api,widget.api)) {
      generation++;
      unawaited(subscription?.cancel());
      redact();
      accountFence = widget.api.sessionFence();
      subscribe();
      if (active) load();
    } else if (!accountFence()) {
      generation++;
      redact();
    } else if (old.vehicleId != widget.vehicleId) {
      generation++;
      redact();
      if (active) load();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (!mounted || foreground == resumed) return;
    generation++;
    setState(() {
      foreground = resumed;
      redact(clearPending: !accountFence());
    });
    // Explicit refresh on resume; never automatically replay a write.
  }
  Map<String,dynamic> checkedPreferences(dynamic raw) {
    final value = Map<String,dynamic>.from(raw as Map);
    if (value['vehicle_id'] != widget.vehicleId ||
        value['telemetry_source'] != 'SIMULATOR_ONLY' ||
        value['alerts_enabled'] is! bool || value['can_simulate'] is! bool ||
        value['threshold_percent'] is! int || value['threshold_percent'] < 1 || value['threshold_percent'] > 50 ||
        value['version'] is! int || value['version'] < 1 ||
        !['CCS2','Type2','CHAdeMO'].contains(value['connector_type'])) {
      throw const FormatException('Invalid battery preference evidence.');
    }
    return value;
  }
  Future<void> load() async {
    if (!active || loading) return;
    final request = ++generation, api = widget.api;
    setState(() {
      loading = true;
      preferences = report = null;
      error = null;
    });
    try {
      final raw = await api.request('$path/battery-preferences');
      if (!current(request,api)) return;
      final data = checkedPreferences(raw);
      setState(() {
        preferences = data;
        enabled = data['alerts_enabled'] == true;
        threshold.text = '${data['threshold_percent']}';
      });
    } catch (e) {
      fail(e, request);
    } finally {
      if (current(request,api)) setState(() => loading = false);
      else if (mounted && request == generation && identical(api,widget.api) && !accountFence()) setState(redact);
    }
  }

  void fail(Object e, int request) {
    if (!mounted || request != generation || !foreground) return;
    if (e is ApiException && (e.endsCurrentSession || (active && [401,419].contains(e.status)))) {
      widget.onExpired();
    } else if (active) {
      setState(() => error = t('Battery request not confirmed. Refresh or retry the same observation.', 'لم يتأكد طلب البطارية. حدّث أو أعد نفس القراءة.'));
    }
  }

  Future<void> save() async {
    if (!active || loading || pending != null || preferences == null) return;
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
    final request = ++generation, api = widget.api;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final raw = await api.request('$path/battery-preferences',method:'PUT',body:body);
      if (!current(request,api)) return;
      final value = checkedPreferences(raw);
      if (value['version'] != (body['expected_version'] as int)+1 ||
          value['alerts_enabled'] != body['alerts_enabled'] ||
          value['threshold_percent'] != body['threshold_percent']) {
        throw const FormatException('Unconfirmed preference receipt.');
      }
      setState(() => preferences = value);
    } catch (e) {
      fail(e, request);
    } finally {
      if (current(request,api)) setState(() => loading = false);
      else if (mounted && request == generation && identical(api,widget.api) && !accountFence()) setState(redact);
    }
  }

  Future<void> simulate() async {
    if (!active || loading || preferences?['can_simulate'] != true) return;
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
    final body = pending ??= Map<String,dynamic>.unmodifiable({
      'operation_key': operationKey(),
      'battery_percent': number,
    });
    final request = ++generation, api = widget.api;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final value = Map<String, dynamic>.from(
        await api.request(
          '$path/battery-simulation',
          method: 'POST',
          body: body,
        ),
      );
      if (!current(request,api)) return;
      final threshold = value['threshold_percent'], version = value['preferences_version'];
      final notified = value['notification_id'];
      if (value['source'] != 'simulator' || value['battery_percent'] != body['battery_percent'] ||
          threshold is! int || threshold < 1 || threshold > 50 || version is! int || version < 1 ||
          value['alerts_enabled'] is! bool || value['alerted'] is! bool ||
          (notified != null && (notified is! int || notified < 1)) ||
          value['alerted'] != (notified != null) ||
          value['alerted'] != (value['alerts_enabled'] == true && (body['battery_percent'] as int) <= threshold)) {
        throw const FormatException('Unconfirmed simulator observation.');
      }
      setState(() { report = value; pending = null; });
    } catch (e) {
      fail(e, request);
    } finally {
      if (current(request,api)) setState(() => loading = false);
      else if (mounted && request == generation && identical(api,widget.api) && !accountFence()) setState(redact);
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(subscription?.cancel());
    threshold.dispose();
    percent.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!active) return const SizedBox.shrink();
    final ticket = generation, api = widget.api;
    return Column(
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
        onPressed: loading ? null : guard(() { load(); }),
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
              : (v) { if (current(ticket,api) && !loading && pending == null) setState(() => enabled = v == true); },
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
          onPressed: loading || pending != null ? null : guard(() { save(); }),
          child: Text(t('Save battery preferences', 'حفظ تفضيلات البطارية')),
        ),
        if (pending != null) Text(t('Unconfirmed simulator observation: ${pending!['battery_percent']}%. Retry uses the same saved request.', 'قراءة المحاكاة غير مؤكدة: ${pending!['battery_percent']}٪. تستخدم إعادة المحاولة نفس الطلب المحفوظ.')),
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
            onPressed: loading ? null : guard(() { simulate(); }),
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
              : guard(() => widget.onDiscover(preferences!['connector_type'] as String)),
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
}
