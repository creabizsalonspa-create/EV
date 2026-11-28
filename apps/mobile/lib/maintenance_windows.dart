import 'dart:async';

import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'reservation_times.dart';

String maintenanceTitle(BuildContext c) => Localizations.localeOf(c).languageCode == 'ar' ? 'الصيانة المجدولة' : 'Scheduled maintenance';

class MaintenanceWindowsView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId, connectorId;
  final bool canManage, operational;
  final VoidCallback onExpired;
  const MaintenanceWindowsView({super.key, required this.api, required this.companyId, required this.chargerId, required this.connectorId, required this.canManage, required this.operational, required this.onExpired});
  @override
  State<MaintenanceWindowsView> createState() => _MaintenanceState();
}

class _MaintenanceState extends State<MaintenanceWindowsView> with WidgetsBindingObserver {
  final form = GlobalKey<FormState>();
  final start = TextEditingController(), end = TextEditingController(), reason = TextEditingController();
  ApiPage<Map<String,dynamic>>? data;
  Map<String,dynamic>? pending;
  String key = operationKey();
  Object? error;
  bool busy = false, foreground = true;
  int generation = 0;
  late bool Function() owner;
  StreamSubscription<int>? sessionChanges;
  bool current(int epoch) => mounted && foreground && owner() && epoch == generation;
  void clear({required bool discardIntent}) {
    generation++;
    data = null; error = null; busy = false;
    if (discardIntent) { pending = null; key = operationKey(); }
    start.clear(); end.clear(); reason.clear();
  }
  void bindSession() {
    sessionChanges?.cancel();
    final source = widget.api;
    owner = source.sessionFence();
    sessionChanges = source.sessionEvents.listen((_) {
      if (mounted && identical(source, widget.api)) {
        setState(() => clear(discardIntent: true));
      }
    });
  }
  String get path => 'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/connectors/${widget.connectorId}/maintenance-windows';
  String tr(String en, String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    bindSession();
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load();
  }
  @override
  void didUpdateWidget(covariant MaintenanceWindowsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.companyId != widget.companyId ||
        oldWidget.chargerId != widget.chargerId || oldWidget.connectorId != widget.connectorId ||
        oldWidget.canManage != widget.canManage || oldWidget.operational != widget.operational) {
      clear(discardIntent: true);
      // Never reauthorize a revoked session merely because inventory or permissions changed.
      if (oldWidget.api != widget.api) bindSession();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted) setState(() { if (!foreground) clear(discardIntent: false); });
  }
  @override
  void dispose() {
    generation++; sessionChanges?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    start.dispose(); end.dispose(); reason.dispose(); super.dispose();
  }
  void fail(Object e) {
    if (!mounted || !foreground || !owner()) return;
    setState(() { data = null; error = e; });
    if (e is ApiException && [401,419].contains(e.status)) {
      setState(() => clear(discardIntent: true));
      widget.onExpired();
    }
  }
  Future<void> load({int page=1}) async {
    if (!foreground || !owner()) return;
    final epoch=++generation;
    setState(() { busy=true; data=null; error=null; });
    try {
      final response=await widget.api.request(path,query:{'page':'$page'});
      if(current(epoch)) setState((){data=ApiPage.fromJson(response,(v)=>v);error=null;});
    } catch(e) {if(current(epoch)){setState(()=>data=null);fail(e);}}
    finally {if(current(epoch))setState(()=>busy=false);}
  }
  String? utc(String? raw) {
    final text=raw?.trim()??'';
    final date=DateTime.tryParse(text);
    return RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$').hasMatch(text) && date!=null && date.toUtc().toIso8601String().replaceFirst('.000Z','Z')==text ? null : tr('Use a valid UTC timestamp, e.g. 2027-01-15T09:00:00Z','استخدم وقت UTC صالحاً مثل 2027-01-15T09:00:00Z');
  }
  Future<void> save(int viewEpoch) async {
    if (!current(viewEpoch) || busy || !widget.canManage) return;
    if(pending==null && !form.currentState!.validate())return;
    pending??={'starts_at':start.text.trim(),'ends_at':end.text.trim(),'reason':reason.text.trim(),'idempotency_key':key};
    final epoch = ++generation;
    setState((){busy=true;error=null;});
    try {
      await widget.api.request(path,method:'POST',body:Map<String,dynamic>.from(pending!));
      if(!current(epoch))return;
      pending=null;key=operationKey();start.clear();end.clear();reason.clear();
      await load();
    } catch(e) {
      if (!current(epoch)) return;
      if(e is ApiException && [409,422].contains(e.status)) {pending=null;key=operationKey();}
      fail(e);
    }
    finally {if(current(epoch))setState(()=>busy=false);}
  }
  Future<void> cancel(int id, int viewEpoch) async {
    if (!current(viewEpoch) || busy || !widget.canManage || !widget.operational) return;
    final accepted=await showDialog<bool>(context:context,builder:(context)=>AlertDialog(
      title:Text(tr('Cancel maintenance','إلغاء الصيانة')),
      content:Text(tr('Remove this admission block? No charger command or physical-readiness verification occurs.','إزالة منع القبول؟ لا يُرسل أمر للشاحن ولا يتم التحقق من الجاهزية الفعلية.')),
      actions:[TextButton(onPressed:()=>Navigator.pop(context,false),child:Text(tr('Back','رجوع'))),FilledButton(onPressed:()=>Navigator.pop(context,true),child:Text(tr('Confirm','تأكيد')))],
    ));
    if(!current(viewEpoch) || accepted!=true || busy || !widget.canManage || !widget.operational)return;
    final epoch = ++generation;
    setState((){busy=true;error=null;});
    try {await widget.api.request('$path/$id',method:'DELETE');if(current(epoch))await load(page:data?.current??1);}
    catch(e){if(current(epoch))fail(e);}finally{if(current(epoch))setState(()=>busy=false);}
  }
  @override
  Widget build(BuildContext context) {
    final viewEpoch = generation;
    return Scaffold(
    appBar:AppBar(title:Text(maintenanceTitle(context))),
    body:!foreground || !owner() ? const SizedBox.shrink() : ListView(padding:const EdgeInsets.all(16),children:[
      Text(tr('UTC intervals block new bookings and starts only. Resolve existing reservations first. Active sessions are not stopped; physical maintenance readiness is not verified.','فترات UTC تمنع الحجوزات والبدء الجديد فقط. عالج الحجوزات الحالية أولاً. لا تتوقف الجلسات النشطة ولا يُتحقق من جاهزية الصيانة الفعلية.')),
      if(busy)const LinearProgressIndicator(),
      if(error!=null)Text(EvStrings.of(context).t(errorKey(error!))),
      TextButton(onPressed:busy?null:(){if(current(viewEpoch))load(page:data?.current??1);},child:Text(tr('Refresh','تحديث'))),
      if(widget.canManage)Form(key:form,child:Column(children:[
        TextFormField(controller:start,enabled:!busy && pending==null,decoration:InputDecoration(labelText:tr('Starts UTC (YYYY-MM-DDTHH:mm:ssZ)','البداية UTC (YYYY-MM-DDTHH:mm:ssZ)')),validator:utc),
        TextFormField(controller:end,enabled:!busy && pending==null,decoration:InputDecoration(labelText:tr('Ends UTC (YYYY-MM-DDTHH:mm:ssZ)','النهاية UTC (YYYY-MM-DDTHH:mm:ssZ)')),validator:utc),
        TextFormField(controller:reason,enabled:!busy && pending==null,maxLength:1000,decoration:InputDecoration(labelText:tr('Maintenance reason','سبب الصيانة')),validator:(v)=>(v?.trim().length??0)<10?tr('Enter at least 10 characters','أدخل ١٠ أحرف على الأقل'):null),
        if(pending!=null)Text(tr('The original request is retained. Refresh saved windows or explicitly retry the same request; it is never replayed automatically.','تم الاحتفاظ بالطلب الأصلي. حدّث الفترات المحفوظة أو أعد المحاولة صراحة بنفس الطلب؛ لا يعاد تلقائياً.')),
        FilledButton(onPressed:busy?null:()=>save(viewEpoch),child:Text(pending==null?tr('Schedule maintenance','جدولة الصيانة'):tr('Retry saved request','إعادة الطلب المحفوظ'))),
      ])),
      for(final row in data?.items??<Map<String,dynamic>>[])Card(child:Padding(padding:const EdgeInsets.all(12),child:Column(children:[
        Text('${serverEventTime(context, row['starts_at'])} → ${serverEventTime(context, row['ends_at'])}'),Text('${row['reason']}'),
        if(row['cancelled_at']!=null)Text(tr('Maintenance cancelled','أُلغيت الصيانة'))
        else if(widget.canManage && widget.operational)TextButton(onPressed:busy?null:()=>cancel(row['id'] as int, viewEpoch),child:Text(tr('Cancel maintenance','إلغاء الصيانة'))),
      ]))),
      if((data?.current??1)>1)TextButton(onPressed:busy?null:(){if(current(viewEpoch))load(page:data!.current-1);},child:Text(tr('Previous','السابق'))),
      if((data?.current??1)<(data?.last??1))TextButton(onPressed:busy?null:(){if(current(viewEpoch))load(page:data!.current+1);},child:Text(tr('Next','التالي'))),
    ]),
  );
  }
}
