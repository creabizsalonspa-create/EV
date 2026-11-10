import 'package:flutter/material.dart';
import 'api.dart';
import 'rider_history.dart';
import 'strings.dart';

String riderTitle(BuildContext c) => Localizations.localeOf(c).languageCode=='ar' ? 'بطاقات ورموز الشحن' : 'Charging cards & tokens';
enum RiderRecords {tokens, grants, operatorRequests, stations}
class RiderAccessView extends StatefulWidget {
  final EvApi api; final VoidCallback onExpired; final RiderRecords records; final int? tokenId,companyId;
  const RiderAccessView({super.key,required this.api,required this.onExpired,this.records=RiderRecords.tokens,this.tokenId,this.companyId});
  @override
  State<RiderAccessView> createState()=>_RiderAccessState();
}
class _RiderAccessState extends State<RiderAccessView> with WidgetsBindingObserver {
  ApiPage<Map<String,dynamic>>? data; Object? error; bool busy=false,sourceEnabled=false,reveal=false;String? oneTime;
  int generation=0;Map<String,dynamic>? pendingCreate;final search=TextEditingController();
  late bool Function() sessionFence;
  bool privatePaused=false;
  bool get sessionAlive => sessionFence();
  void clearPrivate({bool background=false}){
    if(!mounted)return;
    generation++;
    pendingCreate?.clear();
    pendingCreate=null;
    setState((){
      data=null;error=null;sourceEnabled=false;oneTime=null;reveal=false;
      busy=false;privatePaused=background;
    });
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    if(state!=AppLifecycleState.resumed)clearPrivate(background:true);
  }

  String t(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  String get path=>switch(widget.records){RiderRecords.tokens=>'rider-access/tokens',RiderRecords.grants=>'rider-access/tokens/${widget.tokenId}/grants',RiderRecords.operatorRequests=>'vendor-ops/companies/${widget.companyId}/rider-grants',RiderRecords.stations=>'rider-access/stations'};
  @override
  void initState(){super.initState();sessionFence=widget.api.sessionFence();WidgetsBinding.instance.addObserver(this);load();}
  @override
  void dispose(){generation++;WidgetsBinding.instance.removeObserver(this);search.dispose();pendingCreate?.clear();pendingCreate=null;oneTime=null;data=null;super.dispose();}
  void fail(Object e){if(!mounted)return;if(!sessionAlive || (e is ApiException&&[401,419].contains(e.status))){clearPrivate();widget.onExpired();return;}setState(()=>error=e);}
  Future<void> load({int page=1}) async {
    if (!mounted) return;
    if (!sessionAlive) {clearPrivate();widget.onExpired();return;}
    final epoch=++generation;
    setState((){busy=true;error=null;data=null;sourceEnabled=false;privatePaused=false;});
    try {
      final response=await widget.api.request(
        path,query:{'page':'$page',if(widget.records==RiderRecords.stations)'q':search.text},
      );
      if (!mounted||epoch!=generation||privatePaused) return;
      if (!sessionAlive) {clearPrivate();widget.onExpired();return;}
      setState((){
        data=ApiPage.fromJson(response,(v)=>v);
        sourceEnabled=response['source_adapter_enabled']==true;
      });
    } catch(e) {
      if (!mounted||epoch!=generation||privatePaused)return;
      if (!sessionAlive){clearPrivate();widget.onExpired();return;}
      fail(e);
    } finally {
      if(mounted&&epoch==generation)setState(()=>busy=false);
    }
  }
  Future<void> mutate(String url,String method,Map<String,dynamic>? body) async {
    if(!sessionAlive||privatePaused){
      if(!sessionAlive){clearPrivate();widget.onExpired();}
      return;
    }
    final epoch=++generation;
    setState((){busy=true;error=null;});
    try {
      await widget.api.request(url,method:method,body:body);
      if(!mounted||epoch!=generation||privatePaused)return;
      if(!sessionAlive){clearPrivate();widget.onExpired();return;}
      await load(page:data?.current??1);
    } catch(e) {
      if(mounted&&epoch==generation&&!privatePaused)fail(e);
    } finally {
      if(mounted&&epoch==generation)setState(()=>busy=false);
    }
  }
  Future<bool> confirm(String text)async=>await showDialog<bool>(context:context,builder:(c)=>AlertDialog(content:Text(text),actions:[TextButton(onPressed:()=>Navigator.pop(c,false),child:Text(t('Cancel','إلغاء'))),FilledButton(onPressed:()=>Navigator.pop(c,true),child:Text(t('Confirm','تأكيد')))]))??false;
  Future<Map<String,dynamic>?> form(String title,List<_RiderField> fields)=>showDialog<Map<String,dynamic>>(context:context,builder:(_)=>_RiderDialog(title:title,fields:fields));
  Future<void> issue()async{
    if(!sessionAlive||privatePaused){if(!sessionAlive){clearPrivate();widget.onExpired();}return;}
    if(pendingCreate==null){final fields=await form(t('Register charging access','تسجيل وصول الشحن'),[
      _RiderField('label',t('Label','الاسم'),max:80),
      _RiderField('token_type',t('Identifier type','نوع المعرّف'),initial:'Central',choices:{'Central':t('Virtual token','رمز افتراضي'),'ISO14443':'ISO14443','ISO15693':'ISO15693'}),
      _RiderField('value',t('Card identifier (optional for virtual)','معرّف البطاقة (اختياري للرمز الافتراضي)'),required:false,secret:true,max:20),
      _RiderField('expires_at',t('Expiry UTC (YYYY-MM-DDTHH:mm:ssZ)','الانتهاء UTC (YYYY-MM-DDTHH:mm:ssZ)'),initial:DateTime.now().toUtc().add(const Duration(days:30)).toIso8601String().replaceFirst(RegExp(r'\.\d+Z$'),'Z'),max:20),
    ]);if(!mounted||fields==null||privatePaused)return;if(!sessionAlive){clearPrivate();widget.onExpired();return;}if(fields['value']=='')fields.remove('value');pendingCreate={...fields,'idempotency_key':operationKey()};}
    final epoch=++generation;
    setState((){busy=true;error=null;});
    try{
      final response=await widget.api.request(path,method:'POST',body:Map<String,dynamic>.from(pendingCreate!));
      if(!mounted||epoch!=generation||privatePaused)return;
      if(!sessionAlive){clearPrivate();widget.onExpired();return;}
      pendingCreate=null;
      setState((){oneTime=response['value_once'] as String?;reveal=false;});
      await load();
    }
    catch(e){
      if(!mounted||epoch!=generation||privatePaused)return;
      if(!sessionAlive){clearPrivate();widget.onExpired();return;}
      if(e is ApiException&&[409,422].contains(e.status))pendingCreate=null;
      fail(e);
    }finally{if(mounted&&epoch==generation)setState(()=>busy=false);}
  }
  Future<void> renew(Map<String,dynamic> row)async{final body=await form(t('Change expiry','تغيير الانتهاء'),[_RiderField('expires_at',t('Expiry UTC','الانتهاء UTC'),max:20)]);if(!mounted||body==null||privatePaused||!sessionAlive)return;await mutate('$path/${row['id']}','PATCH',body);}
  Future<void> review(Map<String,dynamic> row)async{
    final body=await form(t('Review access request','مراجعة طلب الوصول'),[
      _RiderField('state',t('Decision','القرار'),initial:'approved',choices:{'approved':t('Approve','موافقة'),'rejected':t('Reject','رفض'),'revoked':t('Revoke','إبطال')}),
      _RiderField('expires_at',t('Approval expiry UTC','انتهاء الموافقة UTC'),required:false,max:20),
      _RiderField('note',t('Review notes (at least 10 characters)','ملاحظات المراجعة (١٠ أحرف على الأقل)'),min:10,max:2000),
    ]);if(!mounted||body==null||privatePaused||!sessionAlive)return;if(body['expires_at']=='')body.remove('expires_at');await mutate('$path/${row['id']}/review','POST',body);
  }
  Future<void> requestStation()async{final station=await Navigator.of(context).push<int>(MaterialPageRoute(builder:(_)=>RiderAccessView(api:widget.api,onExpired:widget.onExpired,records:RiderRecords.stations)));if(!mounted||station==null||privatePaused||!sessionAlive)return;await mutate(path,'POST',{'station_id':station});}
  @override
  Widget build(BuildContext context)=>Scaffold(appBar:AppBar(title:Text(widget.records==RiderRecords.operatorRequests?t('Charging access requests','طلبات وصول الشحن'):widget.records==RiderRecords.grants?t('Station approvals','موافقات المحطات'):riderTitle(context))),body:ListView(padding:const EdgeInsets.all(16),children:[
    Text(t('Operator approval, source provisioning and a current booking are required. Test authorization is not physical card or hardware acceptance. Revocation does not forcibly stop active sessions.','تلزم موافقة المشغل وإعداد المصدر وحجز حالي. تفويض الاختبار ليس قبولاً للبطاقة أو الأجهزة الفعلية. لا يوقف الإبطال الجلسات النشطة قسراً.')),
    if(!sessionAlive)Text(t('Session expired. Sign in again.','انتهت الجلسة. سجّل الدخول من جديد.')),
    if(privatePaused)Text(t('Private records hidden. Refresh to reload.','تم إخفاء السجلات الخاصة. حدّث لإعادة التحميل.')),
    if(busy&&sessionAlive&&!privatePaused)const LinearProgressIndicator(),
    if(error!=null&&sessionAlive&&!privatePaused)Text(EvStrings.of(context).t(errorKey(error!))),
    TextButton(onPressed:busy||!sessionAlive?null:()=>load(page:data?.current??1),child:Text(t('Refresh','تحديث'))),
    if(widget.records==RiderRecords.tokens)FilledButton(onPressed:busy||privatePaused||!sessionAlive?null:issue,child:Text(pendingCreate==null?t('Register charging access','تسجيل وصول الشحن'):t('Retry saved request','إعادة الطلب المحفوظ'))),
    if(oneTime!=null&&sessionAlive&&!privatePaused)...[Text(t('One-time identifier; not saved on this device. Hiding it is permanent.','معرّف يعرض مرة واحدة ولا يحفظ على هذا الجهاز. إخفاؤه نهائي.')),if(reveal)SelectableText(oneTime!)else const Text('••••••••••••••••'),TextButton(onPressed:()=>setState(()=>reveal=true),child:Text(t('Show identifier','عرض المعرّف'))),TextButton(onPressed:()=>setState((){oneTime=null;reveal=false;}),child:Text(t('Hide identifier','إخفاء المعرّف')))],
    if(widget.records==RiderRecords.grants)FilledButton(onPressed:busy||privatePaused||!sessionAlive?null:requestStation,child:Text(t('Request station approval','طلب موافقة المحطة'))),
    if(widget.records==RiderRecords.stations)...[TextField(controller:search,maxLength:100,decoration:InputDecoration(labelText:t('Station name','اسم المحطة'))),TextButton(onPressed:busy?null:()=>load(),child:Text(t('Search','بحث')))],
    for(final row in sessionAlive&&!privatePaused?(data?.items??<Map<String,dynamic>>[]):<Map<String,dynamic>>[])Card(child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
      Text('${row['label']??row['station_name']??row['name']??row['station_id']}'),
      if(widget.records==RiderRecords.tokens)...[
        TextButton(onPressed:busy?null:()=>Navigator.of(context).push(MaterialPageRoute<void>(builder:(_)=>RiderHistoryView(api:widget.api,onExpired:widget.onExpired,tokenId:row['id'] as int))),child:Text(t('Charging access activity','سجل وصول الشحن'))),
        Text('${row['token_type']} · ••••${row['suffix']}'),Text('${row['expires_at']}'),Text('${row['provisioning_state']}'),
        if(row['revoked_at']!=null)Text(t('Revoked','مبطل'))else...[
          TextButton(onPressed:busy?null:()async{if(await confirm(t('Revoke this token and all station approvals?','إبطال الرمز وجميع موافقات محطاته؟'))&&mounted)await mutate('$path/${row['id']}','DELETE',null);},child:Text(t('Revoke token','إبطال الرمز'))),TextButton(onPressed:busy?null:()=>renew(row),child:Text(t('Change expiry','تغيير الانتهاء'))),
        ],TextButton(onPressed:busy?null:()=>Navigator.of(context).push(MaterialPageRoute<void>(builder:(_)=>RiderAccessView(api:widget.api,onExpired:widget.onExpired,records:RiderRecords.grants,tokenId:row['id'] as int))),child:Text(t('Station approvals','موافقات المحطات'))),
      ]else if(widget.records==RiderRecords.grants)...[
        Text('${row['state']} · ${row['expires_at']??'—'}'),if(row['revoked_at']==null)TextButton(onPressed:busy?null:()async{if(await confirm(t('Withdraw this station approval?','سحب موافقة هذه المحطة؟'))&&mounted)await mutate('$path/${row['id']}','DELETE',null);},child:Text(t('Withdraw approval','سحب الموافقة'))),
      ]else if(widget.records==RiderRecords.operatorRequests)...[
        TextButton(onPressed:busy?null:()=>Navigator.of(context).push(MaterialPageRoute<void>(builder:(_)=>RiderHistoryView(api:widget.api,onExpired:widget.onExpired,companyId:widget.companyId,grantId:row['id'] as int))),child:Text(t('Charging access activity','سجل وصول الشحن'))),
        Text('${row['token_type']} · ••••${row['suffix']} · ${row['state']}'),Text('${row['provisioning_state']}'),
        if(row['revoked_at']==null&&row['token_revoked_at']==null)TextButton(onPressed:busy?null:()=>review(row),child:Text(t('Review access request','مراجعة طلب الوصول'))),
        if(sourceEnabled&&row['state']=='approved'&&row['revoked_at']==null&&row['token_revoked_at']==null)TextButton(onPressed:busy?null:()=>mutate('vendor-ops/companies/${widget.companyId}/rider-tokens/${row['token_id']}/provision','POST',{}),child:Text(t('Provision test-source authorization','إعداد تفويض مصدر الاختبار'))),
      ]else TextButton(onPressed:busy?null:()=>Navigator.pop(context,row['id'] as int),child:Text(t('Request station approval','طلب موافقة المحطة'))),
    ]))),
    if(sessionAlive&&!privatePaused&&(data?.current??1)>1)TextButton(onPressed:busy?null:()=>load(page:data!.current-1),child:Text(t('Previous','السابق'))),if(sessionAlive&&!privatePaused&&(data?.current??1)<(data?.last??1))TextButton(onPressed:busy?null:()=>load(page:data!.current+1),child:Text(t('Next','التالي'))),
  ]));
}
class _RiderField {
  final String key,label,initial;final bool required,secret;final int min,max;final Map<String,String>? choices;
  const _RiderField(this.key,this.label,{this.initial='',this.required=true,this.secret=false,this.min=1,this.max=100,this.choices});
}
class _RiderDialog extends StatefulWidget {
  final String title;final List<_RiderField> fields;const _RiderDialog({required this.title,required this.fields});
  @override
  State<_RiderDialog> createState()=>_RiderDialogState();
}
class _RiderDialogState extends State<_RiderDialog> {
  final form=GlobalKey<FormState>();final controls=<String,TextEditingController>{};
  @override
  void initState(){super.initState();for(final f in widget.fields)controls[f.key]=TextEditingController(text:f.initial);}
  @override
  void dispose(){for(final c in controls.values)c.dispose();super.dispose();}
  @override
  Widget build(BuildContext context){final ar=Localizations.localeOf(context).languageCode=='ar';return AlertDialog(title:Text(widget.title),content:SingleChildScrollView(child:Form(key:form,child:Column(mainAxisSize:MainAxisSize.min,children:[for(final f in widget.fields)if(f.choices!=null)DropdownButtonFormField<String>(initialValue:controls[f.key]!.text,items:f.choices!.entries.map((e)=>DropdownMenuItem(value:e.key,child:Text(e.value))).toList(),onChanged:(v)=>controls[f.key]!.text=v??'',decoration:InputDecoration(labelText:f.label))else TextFormField(controller:controls[f.key],obscureText:f.secret,autocorrect:false,enableSuggestions:!f.secret,maxLength:f.max,decoration:InputDecoration(labelText:f.label),validator:(v)=>f.required&&(v?.trim().length??0)<f.min?(ar?'تحقق من الحقل المطلوب وطوله':'Check required value and minimum length'):null)]))),actions:[TextButton(onPressed:()=>Navigator.pop(context),child:Text(ar?'إلغاء':'Cancel')),FilledButton(onPressed:(){if(form.currentState!.validate())Navigator.pop(context,{for(final e in controls.entries)e.key:e.value.text.trim()});},child:Text(ar?'حفظ':'Save'))]);}
}
