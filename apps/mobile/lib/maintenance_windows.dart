import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

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

class _MaintenanceState extends State<MaintenanceWindowsView> {
  final form = GlobalKey<FormState>();
  final start = TextEditingController(), end = TextEditingController(), reason = TextEditingController();
  ApiPage<Map<String,dynamic>>? data;
  Map<String,dynamic>? pending;
  String key = operationKey();
  Object? error;
  bool busy = false;
  int generation = 0;
  String get path => 'vendor-ops/companies/${widget.companyId}/chargers/${widget.chargerId}/connectors/${widget.connectorId}/maintenance-windows';
  String tr(String en, String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {super.initState(); load();}
  @override
  void dispose() {start.dispose();end.dispose();reason.dispose();super.dispose();}
  void fail(Object e) {
    if (!mounted) return;
    setState(()=>error=e);
    if (e is ApiException && [401,419].contains(e.status)) widget.onExpired();
  }
  Future<void> load({int page=1}) async {
    final epoch=++generation;
    setState(()=>busy=true);
    try {
      final response=await widget.api.request(path,query:{'page':'$page'});
      if(mounted && epoch==generation) setState((){data=ApiPage.fromJson(response,(v)=>v);error=null;});
    } catch(e) {if(mounted && epoch==generation){setState(()=>data=null);fail(e);}}
    finally {if(mounted && epoch==generation)setState(()=>busy=false);}
  }
  String? utc(String? raw) {
    final text=raw?.trim()??'';
    final date=DateTime.tryParse(text);
    return RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$').hasMatch(text) && date!=null && date.toUtc().toIso8601String().replaceFirst('.000Z','Z')==text ? null : tr('Use a valid UTC timestamp, e.g. 2027-01-15T09:00:00Z','استخدم وقت UTC صالحاً مثل 2027-01-15T09:00:00Z');
  }
  Future<void> save() async {
    if(pending==null && !form.currentState!.validate())return;
    pending??={'starts_at':start.text.trim(),'ends_at':end.text.trim(),'reason':reason.text.trim(),'idempotency_key':key};
    setState((){busy=true;error=null;});
    try {
      await widget.api.request(path,method:'POST',body:Map<String,dynamic>.from(pending!));
      if(!mounted)return;
      pending=null;key=operationKey();start.clear();end.clear();reason.clear();
      await load();
    } catch(e) {
      if(e is ApiException && [409,422].contains(e.status)) {pending=null;key=operationKey();}
      fail(e);
    }
    finally {if(mounted)setState(()=>busy=false);}
  }
  Future<void> cancel(int id) async {
    final accepted=await showDialog<bool>(context:context,builder:(context)=>AlertDialog(
      title:Text(tr('Cancel maintenance','إلغاء الصيانة')),
      content:Text(tr('Remove this admission block? No charger command or physical-readiness verification occurs.','إزالة منع القبول؟ لا يُرسل أمر للشاحن ولا يتم التحقق من الجاهزية الفعلية.')),
      actions:[TextButton(onPressed:()=>Navigator.pop(context,false),child:Text(tr('Back','رجوع'))),FilledButton(onPressed:()=>Navigator.pop(context,true),child:Text(tr('Confirm','تأكيد')))],
    ));
    if(!mounted || accepted!=true)return;
    setState((){busy=true;error=null;});
    try {await widget.api.request('$path/$id',method:'DELETE');if(mounted)await load(page:data?.current??1);}
    catch(e){fail(e);}finally{if(mounted)setState(()=>busy=false);}
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar:AppBar(title:Text(maintenanceTitle(context))),
    body:ListView(padding:const EdgeInsets.all(16),children:[
      Text(tr('UTC intervals block new bookings and starts only. Resolve existing reservations first. Active sessions are not stopped; physical maintenance readiness is not verified.','فترات UTC تمنع الحجوزات والبدء الجديد فقط. عالج الحجوزات الحالية أولاً. لا تتوقف الجلسات النشطة ولا يُتحقق من جاهزية الصيانة الفعلية.')),
      if(busy)const LinearProgressIndicator(),
      if(error!=null)Text(EvStrings.of(context).t(errorKey(error!))),
      TextButton(onPressed:busy?null:()=>load(page:data?.current??1),child:Text(tr('Refresh','تحديث'))),
      if(widget.canManage)Form(key:form,child:Column(children:[
        TextFormField(controller:start,enabled:!busy && pending==null,decoration:InputDecoration(labelText:tr('Starts UTC (YYYY-MM-DDTHH:mm:ssZ)','البداية UTC (YYYY-MM-DDTHH:mm:ssZ)')),validator:utc),
        TextFormField(controller:end,enabled:!busy && pending==null,decoration:InputDecoration(labelText:tr('Ends UTC (YYYY-MM-DDTHH:mm:ssZ)','النهاية UTC (YYYY-MM-DDTHH:mm:ssZ)')),validator:utc),
        TextFormField(controller:reason,enabled:!busy && pending==null,maxLength:1000,decoration:InputDecoration(labelText:tr('Maintenance reason','سبب الصيانة')),validator:(v)=>(v?.trim().length??0)<10?tr('Enter at least 10 characters','أدخل ١٠ أحرف على الأقل'):null),
        if(pending!=null)Text(tr('The original request is retained. Refresh saved windows or explicitly retry the same request; it is never replayed automatically.','تم الاحتفاظ بالطلب الأصلي. حدّث الفترات المحفوظة أو أعد المحاولة صراحة بنفس الطلب؛ لا يعاد تلقائياً.')),
        FilledButton(onPressed:busy?null:save,child:Text(pending==null?tr('Schedule maintenance','جدولة الصيانة'):tr('Retry saved request','إعادة الطلب المحفوظ'))),
      ])),
      for(final row in data?.items??<Map<String,dynamic>>[])Card(child:Padding(padding:const EdgeInsets.all(12),child:Column(children:[
        Text('${row['starts_at']} → ${row['ends_at']} UTC'),Text('${row['reason']}'),
        if(row['cancelled_at']!=null)Text(tr('Maintenance cancelled','أُلغيت الصيانة'))
        else if(widget.canManage && widget.operational)TextButton(onPressed:busy?null:()=>cancel(row['id'] as int),child:Text(tr('Cancel maintenance','إلغاء الصيانة'))),
      ]))),
      if((data?.current??1)>1)TextButton(onPressed:busy?null:()=>load(page:data!.current-1),child:Text(tr('Previous','السابق'))),
      if((data?.current??1)<(data?.last??1))TextButton(onPressed:busy?null:()=>load(page:data!.current+1),child:Text(tr('Next','التالي'))),
    ]),
  );
}
