import 'package:flutter/material.dart';
import 'api.dart';

/// Per-vehicle planning preferences. Never changes the live charger state.
class VehicleDriverPreferencesView extends StatefulWidget {
  final EvApi api;
  final int vehicleId;
  final VoidCallback onExpired;
  const VehicleDriverPreferencesView({
    super.key,
    required this.api,
    required this.vehicleId,
    required this.onExpired,
  });

  @override
  State<VehicleDriverPreferencesView> createState() => _DriverPreferencesState();
}

class _DriverPreferencesState extends State<VehicleDriverPreferencesView> {
  final power = TextEditingController();
  Map<String, dynamic>? preferences;
  String goal = 'balanced';
  bool accessible = false, freeParking = false, loading = false, saving = false;
  String? error, success;
  int generation = 0;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() { super.initState(); reload(); }
  @override
  void dispose() { generation++; power.dispose(); super.dispose(); }

  void fail(Object e) {
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(() => error = e is ApiException && e.status == 409
        ? t('Preferences changed on another device. Refresh before saving.',
            'تغيرت التفضيلات في جهاز آخر. حدّث قبل الحفظ.')
        : e.toString());
  }

  Future<void> reload() async {
    final ticket = ++generation, fence = widget.api.sessionFence();
    setState(() { loading = true; error = success = null; preferences = null; });
    try {
      final response = await widget.api.request(
        'vehicles/' + widget.vehicleId.toString() + '/driver-preferences',
      );
      if (!mounted || ticket != generation || !fence()) return;
      final value = Map<String, dynamic>.from(response as Map);
      setState(() {
        preferences = value;
        power.text = value['preferred_power_kw']?.toString() ?? '';
        goal = value['charging_goal'] as String? ?? 'balanced';
        accessible = value['accessible_site_required'] == true;
        freeParking = value['avoid_paid_parking'] == true;
      });
    } catch (e) {
      if (mounted && ticket == generation) fail(e);
    } finally {
      if (mounted && ticket == generation) setState(() => loading = false);
    }
  }

  Future<void> save() async {
    if (preferences == null || loading || saving) return;
    final raw = power.text.trim();
    final value = raw.isEmpty ? null : int.tryParse(raw);
    if (raw.isNotEmpty && (value == null || value < 1 || value > 1000)) {
      setState(() => error = t('Preferred power must be 1–1000 kW.',
          'يجب أن تكون القدرة المفضلة بين ١ و١٠٠٠ كيلوواط.'));
      return;
    }
    final payload = <String, dynamic>{
      'revision': preferences!['revision'],
      'preferred_power_kw': value,
      'charging_goal': goal,
      'accessible_site_required': accessible,
      'avoid_paid_parking': freeParking,
    };
    final ticket = generation, fence = widget.api.sessionFence();
    setState(() { saving = true; error = success = null; });
    try {
      final response = await widget.api.request(
        'vehicles/' + widget.vehicleId.toString() + '/driver-preferences',
        method: 'PUT', body: payload,
      );
      if (!mounted || ticket != generation || !fence()) return;
      setState(() {
        preferences = Map<String, dynamic>.from(response as Map);
        success = t('Driver preferences saved.', 'تم حفظ تفضيلات السائق.');
      });
    } catch (e) {
      if (mounted && ticket == generation) fail(e);
    } finally {
      if (mounted && ticket == generation) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('Driver charging preferences', 'تفضيلات شحن السائق'))),
    body: SafeArea(child: ListView(padding: const EdgeInsets.all(16), children: [
      Text(t('Preferences are for planning only. They do not change charger availability, pricing or charging permission.',
             'هذه التفضيلات للتخطيط فقط ولا تغير توفر الشاحن أو الأسعار أو إذن الشحن.')),
      if (loading) const LinearProgressIndicator(),
      if (error != null) Semantics(liveRegion: true,
        child: Text(error!, key: const Key('driver-preferences-error'))),
      if (success != null) Semantics(liveRegion: true,
        child: Text(success!, key: const Key('driver-preferences-success'))),
      if (preferences != null) ...[
        TextFormField(
          key: const Key('driver-preferences-power'), controller: power,
          keyboardType: TextInputType.number, maxLength: 4,
          decoration: InputDecoration(
            labelText: t('Preferred power (kW, optional)', 'القدرة المفضلة (كيلوواط، اختياري)'),
            helperText: t('1–1000 kW or leave blank', 'من ١ إلى ١٠٠٠ أو اتركه فارغًا'),
          ),
        ),
        DropdownButtonFormField<String>(
          key: ValueKey('driver-goal-' + goal), initialValue: goal,
          decoration: InputDecoration(labelText: t('Charging priority', 'أولوية الشحن')),
          items: [
            DropdownMenuItem(value: 'balanced', child: Text(t('Balanced', 'متوازن'))),
            DropdownMenuItem(value: 'fastest', child: Text(t('Fastest', 'الأسرع'))),
            DropdownMenuItem(value: 'lowest_cost', child: Text(t('Lower cost', 'تكلفة أقل'))),
          ],
          onChanged: saving ? null : (v) => setState(() => goal = v ?? 'balanced'),
        ),
        CheckboxListTile(
          key: const Key('driver-preferences-access'),
          title: Text(t('Prefer accessible charging sites', 'أفضل مواقع الشحن المهيأة')),
          value: accessible, contentPadding: EdgeInsets.zero,
          onChanged: saving ? null : (v) => setState(() => accessible = v == true),
        ),
        CheckboxListTile(
          key: const Key('driver-preferences-parking'),
          title: Text(t('Prefer free parking', 'أفضل مواقف السيارات المجانية')),
          value: freeParking, contentPadding: EdgeInsets.zero,
          onChanged: saving ? null : (v) => setState(() => freeParking = v == true),
        ),
        FilledButton(
          key: const Key('driver-preferences-save'),
          onPressed: saving ? null : save,
          child: Text(t('Save preferences', 'حفظ التفضيلات')),
        ),
      ],
      TextButton.icon(
        onPressed: loading || saving ? null : reload,
        icon: const Icon(Icons.refresh), label: Text(t('Refresh', 'تحديث')),
      ),
    ])),
  );
}
