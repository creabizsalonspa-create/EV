import 'dart:async';
import 'package:flutter/material.dart';
import 'vendor_forms.dart' show vendorDigits;
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
  State<VehicleDriverPreferencesView> createState() =>
      _DriverPreferencesState();
}

class _DriverPreferencesState extends State<VehicleDriverPreferencesView> with WidgetsBindingObserver {
  final power = TextEditingController();
  Map<String, dynamic>? preferences;
  String goal = 'balanced';
  bool accessible = false, freeParking = false, loading = false, saving = false;
  String? error, success;
  int generation = 0;
  late bool Function() accountFence;
  StreamSubscription<int>? subscription;
  bool foreground=true;
  bool get active=>mounted&&foreground&&accountFence();
  bool current(int ticket,EvApi api)=>active&&ticket==generation&&identical(api,widget.api);
  void redact(){
    preferences=null;power.clear();goal='balanced';
    accessible=freeParking=loading=saving=false;error=success=null;
  }
  void subscribe(){
    final api=widget.api;
    subscription=api.sessionEvents.listen((_){
      if(mounted&&identical(api,widget.api)&&!accountFence())setState(redact);
    });
  }
  VoidCallback guard(VoidCallback action){
    final ticket=generation,api=widget.api;
    return (){if(current(ticket,api)&&!loading&&!saving)action();};
  }

  // These values are personal planning preferences, not vehicle telemetry or
  // commands. Validate source, ownership, and revision before exposing them
  // in an editor or treating a PUT as saved.
  bool validPreferences(Map<String, dynamic> row, {int? afterRevision}) {
    final revision = row['revision'];
    final powerKw = row['preferred_power_kw'];
    return row['vehicle_id'] == widget.vehicleId &&
        row['scope'] == 'DRIVER_PREFERENCE_ONLY' &&
        row['applied_to_charging'] == false &&
        revision is int &&
        revision >= 0 && revision <= 2147483647 &&
        (afterRevision == null || revision == afterRevision + 1) &&
        (powerKw == null ||
            (powerKw is int && powerKw >= 1 && powerKw <= 1000)) &&
        const [
          'balanced',
          'fastest',
          'lowest_cost',
        ].contains(row['charging_goal']) &&
        row['accessible_site_required'] is bool &&
        row['avoid_paid_parking'] is bool;
  }

  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState(){
    super.initState();
    accountFence=widget.api.sessionFence();
    final state=WidgetsBinding.instance.lifecycleState;
    foreground=state==null||state==AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    if(active)reload();
  }
  @override
  void didUpdateWidget(covariant VehicleDriverPreferencesView oldWidget){
    super.didUpdateWidget(oldWidget);
    if(!identical(oldWidget.api,widget.api)){
      generation++;
      unawaited(subscription?.cancel());
      redact();
      accountFence=widget.api.sessionFence();
      subscribe();
      if(active)reload();
    }else if(!accountFence()){
      generation++;redact();
    }else if(oldWidget.vehicleId!=widget.vehicleId){
      generation++;redact();
      if(active)reload();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    final resumed=state==AppLifecycleState.resumed;
    if(!mounted||resumed==foreground)return;
    generation++;
    setState((){foreground=resumed;redact();});
    // Require explicit read-back after backgrounding, never replay a PUT.
  }
  @override
  void dispose(){
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(subscription?.cancel());
    power.dispose();
    super.dispose();
  }
  void fail(Object e,int ticket,EvApi api,{required bool write}){
    if(!mounted||!foreground||ticket!=generation||!identical(api,widget.api))return;
    if(e is ApiException&&(e.endsCurrentSession||(active&&[401,419].contains(e.status)))){
      widget.onExpired();
    }else if(active){
      setState((){
        preferences=null;power.clear();goal='balanced';
        accessible=freeParking=false;success=null;
        error=e is ApiException&&e.status==409
          ?t('Preferences changed on another device. Refresh before saving.',
            'تغيرت التفضيلات في جهاز آخر. حدّث قبل الحفظ.')
          :write?t('Unexpected driver preference save response; refresh before editing.',
            'استجابة حفظ التفضيلات غير مؤكدة؛ حدّث قبل التعديل.')
          :t('Unexpected driver preference response; refresh before editing.',
            'استجابة التفضيلات غير مؤكدة؛ حدّث قبل التعديل.');
      });
    }
  }

  Future<void> reload() async {
    if(!active||loading||saving)return;
    final ticket = ++generation, api = widget.api;
    setState(() {
      loading = true;
      error = success = null;
      preferences = null;
    });
    try {
      final response = await api.request(
        'vehicles/' + widget.vehicleId.toString() + '/driver-preferences',
      );
      if (!current(ticket,api)) return;
      final value = Map<String, dynamic>.from(response as Map);
      if (!validPreferences(value)) {
        throw StateError(
          'Unexpected driver preference response; refresh before editing.',
        );
      }
      setState(() {
        preferences = value;
        power.text = value['preferred_power_kw']?.toString() ?? '';
        goal = value['charging_goal'] as String? ?? 'balanced';
        accessible = value['accessible_site_required'] == true;
        freeParking = value['avoid_paid_parking'] == true;
      });
    } catch (e) {
      fail(e,ticket,api,write:false);
    } finally {
      if (current(ticket,api)) setState(() => loading = false);
    }
  }

  Future<void> save() async {
    if (!active||preferences == null || loading || saving || preferences!['revision']>=2147483647) return;
    final raw = vendorDigits(power.text.trim());
    final value = raw.isEmpty ? null : int.tryParse(raw);
    if (raw.isNotEmpty && (value == null || value < 1 || value > 1000)) {
      setState(
        () => error = t(
          'Preferred power must be 1–1000 kW.',
          'يجب أن تكون القدرة المفضلة بين ١ و١٠٠٠ كيلوواط.',
        ),
      );
      return;
    }
    final payload = <String, dynamic>{
      'revision': preferences!['revision'],
      'preferred_power_kw': value,
      'charging_goal': goal,
      'accessible_site_required': accessible,
      'avoid_paid_parking': freeParking,
    };
    final ticket = ++generation, api = widget.api;
    setState(() {
      saving = true;
      error = success = null;
    });
    try {
      final response = await api.request(
        'vehicles/' + widget.vehicleId.toString() + '/driver-preferences',
        method: 'PUT',
        body: payload,
      );
      if (!current(ticket,api)) return;
      final saved = Map<String, dynamic>.from(response as Map);
      if (!validPreferences(saved, afterRevision: payload['revision'] as int) ||
          ['preferred_power_kw','charging_goal','accessible_site_required','avoid_paid_parking'].any((key)=>saved[key]!=payload[key])) {
        throw StateError(
          'Unexpected driver preference save response; refresh before editing.',
        );
      }
      setState(() {
        preferences = saved;
        success = t('Driver preferences saved.', 'تم حفظ تفضيلات السائق.');
      });
    } catch (e) {
      fail(e,ticket,api,write:true);
    } finally {
      if (current(ticket,api)) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if(!active)return Scaffold(appBar:AppBar(title:Text(t('Driver charging preferences','تفضيلات شحن السائق'))),
      body:Center(child:Text(!accountFence()?t('Account changed. Reopen preferences.','تغير الحساب. أعد فتح التفضيلات.'):t('Private preferences paused.','التفضيلات الخاصة متوقفة مؤقتاً.'),key:const Key('driver-preferences-error'))));
    final ticket=generation, api=widget.api;
    bool editable()=>current(ticket,api)&&!loading&&!saving&&preferences!=null;
    return Scaffold(
    appBar: AppBar(
      title: Text(t('Driver charging preferences', 'تفضيلات شحن السائق')),
    ),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Preferences are for planning only. They do not change charger availability, pricing or charging permission.',
              'هذه التفضيلات للتخطيط فقط ولا تغير توفر الشاحن أو الأسعار أو إذن الشحن.',
            ),
          ),
          if (loading) const LinearProgressIndicator(),
          if (error != null)
            Semantics(
              liveRegion: true,
              child: Text(error!, key: const Key('driver-preferences-error')),
            ),
          if (success != null)
            Semantics(
              liveRegion: true,
              child: Text(
                success!,
                key: const Key('driver-preferences-success'),
              ),
            ),
          if (preferences != null) ...[
            TextFormField(
              key: const Key('driver-preferences-power'),
              controller: power,
              enabled:!saving&&!loading,
              keyboardType: TextInputType.number,
              maxLength: 4,
              decoration: InputDecoration(
                labelText: t(
                  'Preferred power (kW, optional)',
                  'القدرة المفضلة (كيلوواط، اختياري)',
                ),
                helperText: t(
                  '1–1000 kW or leave blank',
                  'من ١ إلى ١٠٠٠ أو اتركه فارغًا',
                ),
              ),
            ),
            DropdownButtonFormField<String>(
              key: ValueKey('driver-goal-' + goal),
              initialValue: goal,
              decoration: InputDecoration(
                labelText: t('Charging priority', 'أولوية الشحن'),
              ),
              items: [
                DropdownMenuItem(
                  value: 'balanced',
                  child: Text(t('Balanced', 'متوازن')),
                ),
                DropdownMenuItem(
                  value: 'fastest',
                  child: Text(t('Fastest', 'الأسرع')),
                ),
                DropdownMenuItem(
                  value: 'lowest_cost',
                  child: Text(t('Lower cost', 'تكلفة أقل')),
                ),
              ],
              onChanged: saving
                  ? null
                  : (v) {if(editable())setState(() => goal = v ?? 'balanced');},
            ),
            CheckboxListTile(
              key: const Key('driver-preferences-access'),
              title: Text(
                t(
                  'Prefer accessible charging sites',
                  'أفضل مواقع الشحن المهيأة',
                ),
              ),
              value: accessible,
              contentPadding: EdgeInsets.zero,
              onChanged: saving
                  ? null
                  : (v) {if(editable())setState(() => accessible = v == true);},
            ),
            CheckboxListTile(
              key: const Key('driver-preferences-parking'),
              title: Text(
                t('Prefer free parking', 'أفضل مواقف السيارات المجانية'),
              ),
              value: freeParking,
              contentPadding: EdgeInsets.zero,
              onChanged: saving
                  ? null
                  : (v) {if(editable())setState(() => freeParking = v == true);},
            ),
            FilledButton(
              key: const Key('driver-preferences-save'),
              onPressed: saving||preferences!['revision']>=2147483647 ? null : guard((){save();}),
              child: Text(t('Save preferences', 'حفظ التفضيلات')),
            ),
          ],
          TextButton.icon(
            onPressed: loading || saving ? null : guard((){reload();}),
            icon: const Icon(Icons.refresh),
            label: Text(t('Refresh', 'تحديث')),
          ),
        ],
      ),
    ),
  );
  }
}
