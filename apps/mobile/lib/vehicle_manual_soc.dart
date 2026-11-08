import 'package:flutter/material.dart';
import 'api.dart';

/// Manual user estimate. Never battery data sourced from a phone, charger or
/// vehicle API, and never a basis for automatic charging commands.
class VehicleManualSocView extends StatefulWidget {
  final EvApi api;
  final int vehicleId;
  final VoidCallback onExpired;
  const VehicleManualSocView({
    super.key,required this.api,required this.vehicleId,required this.onExpired,
  });
  @override
  State<VehicleManualSocView> createState()=>_VehicleManualSocState();
}

class _VehicleManualSocState extends State<VehicleManualSocView> {
  final percent=TextEditingController();
  Map<String,dynamic>? latest,pending;
  List<Map<String,dynamic>> reports=[];
  int page=1,lastPage=1,generation=0;
  late bool Function() accountFence;
  bool loading=false,saving=false;
  String? error,success;
  String t(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  String get path=>'vehicles/'+widget.vehicleId.toString()+'/manual-soc';

  // An exact, source-qualified history row establishes that this user report
  // was recorded. A later revision does not turn an accepted report into an
  // uncertain submission or make the old percentage the current estimate.
  String? confirmationKind(Map<String,dynamic> submitted,
      Map<String,dynamic> current,List<Map<String,dynamic>> history){
    final expected=submitted['expected_revision'];
    final reported=submitted['battery_percent'];
    if(expected is! int||reported is! int||
        current['vehicle_id']!=widget.vehicleId)return null;
    final revision=expected+1;
    final latestRevision=current['revision'];
    if(latestRevision is! int||latestRevision<revision)return null;
    final recorded=history.any((row)=>
      row['vehicle_id']==widget.vehicleId &&
      row['revision']==revision &&
      row['battery_percent']==reported &&
      row['source']=='CUSTOMER_MANUAL_SELF_REPORT');
    if(!recorded)return null;
    if(latestRevision==revision){
      return current['battery_percent']==reported?'current':null;
    }
    return 'superseded';
  }

  String confirmationMessage(String kind)=>kind=='superseded'
    ? t('Manual battery report recorded; a newer battery report is now the latest.',
        'تم تسجيل التقرير اليدوي، لكن يوجد تقرير أحدث للبطارية.')
    : t('Manual battery report recorded.','تم تسجيل التقرير اليدوي للبطارية.');

  @override
  void initState(){
    super.initState();
    accountFence=widget.api.sessionFence();
    load();
  }

  @override
  void didUpdateWidget(covariant VehicleManualSocView oldWidget){
    super.didUpdateWidget(oldWidget);
    if(oldWidget.vehicleId==widget.vehicleId&&
       identical(oldWidget.api,widget.api)&&accountFence())return;
    // Reused Flutter State must never retain a previous vehicle's battery
    // history, pending idempotency key or editable percentage.
    generation++;
    accountFence=widget.api.sessionFence();
    percent.clear();
    latest=pending=null;
    reports=[];
    page=lastPage=1;
    loading=saving=false;
    error=success=null;
    load();
  }

  @override
  void dispose(){generation++;percent.dispose();super.dispose();}

  void failed(Object e){
    if(e is ApiException&&[401,419].contains(e.status))widget.onExpired();
    if(mounted)setState(()=>error=e is ApiException&&e.status==409
      ? t('Report changed. Refresh before making a new report.',
          'تغير التقرير. حدّث قبل تقديم تقرير جديد.')
      : e.toString());
  }

  Future<void> load([int target=1]) async {
    final token=++generation,fence=widget.api.sessionFence();
    setState((){loading=true;error=null;});
    try{
      final current=await widget.api.request(path);
      final raw=await widget.api.request(path+'/history',query:{'page':target.toString()});
      if(!mounted||token!=generation||!fence())return;
      final currentMap=Map<String,dynamic>.from(current as Map);
      if(currentMap['source']!='CUSTOMER_MANUAL_SELF_REPORT'||
         currentMap['measured_by_phone']!=false||
         currentMap['measured_by_vehicle_api']!=false||
         currentMap['charging_capability_verified']!=false) {
        throw const FormatException('Unexpected battery report provenance');
      }
      final history=ApiPage<Map<String,dynamic>>.fromJson(raw,(v)=>v);
      final confirmation=pending==null?null:
        confirmationKind(pending!,currentMap,history.items);
      setState((){
        latest=currentMap;reports=history.items;
        page=history.current;lastPage=history.last;
        if(confirmation!=null){
          pending=null;percent.clear();
          success=confirmationMessage(confirmation);
        }
      });
    }catch(e){if(mounted&&token==generation&&fence())failed(e);}
    finally{if(mounted&&token==generation&&fence())setState(()=>loading=false);}
  }

  Future<void> save() async {
    if(saving||loading||latest==null)return;
    if(pending==null){
      final value=int.tryParse(percent.text.trim());
      if(value==null||value<0||value>100){
        setState(()=>error=t('Enter a manual estimate from 0 to 100%.',
          'أدخل تقديرًا يدويًا من ٠ إلى ١٠٠٪.'));return;
      }
      pending={
        'operation_key':operationKey(),
        'expected_revision':latest!['revision'],
        'battery_percent':value,
      };
    }
    final body=Map<String,dynamic>.unmodifiable(pending!);
    final token=generation,fence=widget.api.sessionFence(),requestPath=path;
    setState((){saving=true;error=success=null;});
    try{
      await widget.api.request(requestPath,method:'POST',body:body);
      if(!mounted||token!=generation||!fence())return;
      // POST may commit even if read-back fails. Retain the original operation
      // key until BOTH current state and history have been verified.
      final current=await widget.api.request(requestPath);
      if(!mounted||token!=generation||!fence())return;
      final raw=await widget.api.request(requestPath+'/history',
        query:{'page':'1'});
      if(!mounted||token!=generation||!fence())return;
      final currentMap=Map<String,dynamic>.from(current as Map);
      if(currentMap['source']!='CUSTOMER_MANUAL_SELF_REPORT'||
         currentMap['measured_by_phone']!=false||
         currentMap['measured_by_vehicle_api']!=false||
         currentMap['charging_capability_verified']!=false){
        throw const FormatException('Unexpected battery report provenance');
      }
      final history=ApiPage<Map<String,dynamic>>.fromJson(raw,(v)=>v);
      // The exact observation must be in authoritative history. A newer
      // current revision is allowed only when that older row was confirmed.
      final confirmation=confirmationKind(body,currentMap,history.items);
      if(confirmation==null){
        throw const FormatException(
          'Submitted manual battery report not confirmed by server readback');
      }
      setState((){
        latest=currentMap;reports=history.items;
        page=history.current;lastPage=history.last;
        pending=null;percent.clear();
        success=confirmationMessage(confirmation);
      });
    }catch(e){
      if(mounted&&token==generation&&fence()){
        failed(e);
        if(mounted)setState(()=>error=t(
          'Result uncertain. Check the history or retry the identical report.',
          'النتيجة غير مؤكدة. راجع السجل أو أعد نفس التقرير.'));
      }
    }finally{if(mounted&&token==generation&&fence())setState(()=>saving=false);}
  }

  void clearPending(){
    setState((){
      pending=null;
      error=success=null;
    });
  }

  @override
  Widget build(BuildContext context)=>Scaffold(
    appBar:AppBar(title:Text(t('Manual battery report','تقرير البطارية اليدوي'))),
    body:SafeArea(child:ListView(padding:const EdgeInsets.all(16),children:[
      Text(t(
        'Enter your own battery estimate. Your phone does not measure vehicle SOC. These reports do not start or stop charging, authorize reservations or calculate fees.',
        'أدخل تقديرك الشخصي للبطارية. الهاتف لا يقيس شحن المركبة. التقارير لا تبدأ أو توقف الشحن أو تسمح بالحجز أو تحسب الرسوم.',
      )),
      if(loading)const LinearProgressIndicator(),
      if(error!=null)Semantics(liveRegion:true,
        child:Text(error!,key:const Key('manual-soc-error'))),
      if(success!=null)Semantics(liveRegion:true,
        child:Text(success!,key:const Key('manual-soc-success'))),
      if(latest!=null)...[
        Text(t('Source: customer manual self-report (unverified).',
          'المصدر: تقرير يدوي من العميل (غير متحقق منه).')),
        Text(latest!['battery_percent']==null
          ? t('No report yet.','لا يوجد تقرير بعد.')
          : t('Latest estimate: ','آخر تقدير: ')+latest!['battery_percent'].toString()+
              '% · '+(latest!['reported_at']??'').toString()),
        Text(t('Battery may have changed since the report.',
          'قد تكون حالة البطارية تغيرت منذ التقرير.')),
        TextFormField(
          key:const Key('manual-soc-percent'),controller:percent,
          enabled:pending==null&&!saving,
          keyboardType:TextInputType.number,maxLength:3,
          decoration:InputDecoration(labelText:t('My estimate (0–100%)',
            'تقديري (٠–١٠٠٪)')),
        ),
        FilledButton(
          key:const Key('manual-soc-save'),
          onPressed:saving||loading?null:save,
          child:Text(pending==null?t('Save manual report','حفظ التقرير اليدوي')
            :t('Retry identical report','إعادة نفس التقرير')),
        ),
        if(pending!=null)TextButton(
          onPressed:saving?null:clearPending,
          child:Text(t('Clear pending after checking history',
            'مسح المعلق بعد مراجعة السجل')),
        ),
        const SizedBox(height:12),
        Text(t('Manual report history','سجل التقارير اليدوية'),
          style:Theme.of(context).textTheme.titleMedium),
        if(reports.isEmpty&&!loading)Text(t('No reports recorded.',
          'لم يتم تسجيل تقارير.')),
        for(final report in reports)
          Card(child:ListTile(
            title:Text(report['battery_percent'].toString()+'%'),
            subtitle:Text(t('Reported at: ','تاريخ التقرير: ')+
              (report['reported_at']??'').toString()+
              ' · #'+report['revision'].toString()),
          )),
        Row(children:[
          TextButton(onPressed:loading||saving||page<=1?null:()=>load(page-1),
            child:Text(t('Previous','السابق'))),
          Text('$page / $lastPage'),
          TextButton(onPressed:loading||saving||page>=lastPage?null:()=>load(page+1),
            child:Text(t('Next','التالي'))),
        ]),
      ],
      TextButton.icon(
        onPressed:loading||saving?null:()=>load(page),
        icon:const Icon(Icons.refresh),label:Text(t('Refresh from server','تحديث من الخادم')),
      ),
    ])),
  );
}
