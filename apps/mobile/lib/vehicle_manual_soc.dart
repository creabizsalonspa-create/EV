import 'dart:async';
import 'package:flutter/material.dart';
import 'vendor_forms.dart' show vendorDigits;
import 'api.dart';
import 'reservation_times.dart';

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

class _VehicleManualSocState extends State<VehicleManualSocView> with WidgetsBindingObserver {
  final percent=TextEditingController();
  Map<String,dynamic>? latest,pending;
  List<Map<String,dynamic>> reports=[];
  int page=1,lastPage=1,generation=0;
  late bool Function() accountFence;
  StreamSubscription<int>? subscription;
  bool foreground=true;
  bool get active=>mounted&&foreground&&accountFence();
  bool current(int token,EvApi api)=>active&&token==generation&&identical(api,widget.api);
  VoidCallback guard(VoidCallback action){
    final token=generation,api=widget.api;
    return (){if(current(token,api)&&!saving&&!loading)action();};
  }
  void redact({bool clearPending=true}){
    percent.clear();
    latest=null;
    if(clearPending)pending=null;
    reports=[];page=lastPage=1;
    loading=saving=false;error=success=null;
  }
  void subscribe(){
    final api=widget.api;
    subscription=api.sessionEvents.listen((_){
      if(mounted&&identical(api,widget.api)&&!accountFence())setState(redact);
    });
  }
  bool loading=false,saving=false;
  String? error,success;
  String t(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  String reportTime(dynamic value) {
    final formatted=serverUtcTime(context, value);
    return formatted=='—'
      ? t('Report time unavailable','وقت التقرير غير متاح')
      : formatted;
  }
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
    final state=WidgetsBinding.instance.lifecycleState;
    foreground=state==null||state==AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    if(active)load();
  }
  @override
  void didUpdateWidget(covariant VehicleManualSocView oldWidget){
    super.didUpdateWidget(oldWidget);
    if(!identical(oldWidget.api,widget.api)){
      generation++;
      unawaited(subscription?.cancel());
      redact();
      accountFence=widget.api.sessionFence();
      subscribe();
      if(active)load();
    }else if(!accountFence()){
      generation++;
      redact();
    }else if(oldWidget.vehicleId!=widget.vehicleId){
      generation++;
      redact();
      if(active)load();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    final resumed=state==AppLifecycleState.resumed;
    if(!mounted||resumed==foreground)return;
    generation++;
    setState((){
      foreground=resumed;
      redact(clearPending:!accountFence());
    });
    // Never reload or replay a private observation on resume.
  }
  @override
  void dispose(){
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(subscription?.cancel());
    percent.dispose();
    super.dispose();
  }
  void failed(Object e,int token,EvApi api){
    if(!mounted||!foreground||token!=generation||!identical(api,widget.api))return;
    if(e is ApiException&&(e.endsCurrentSession||(active&&[401,419].contains(e.status)))){
      widget.onExpired();
    }else if(active){
      setState(()=>error=e is ApiException&&e.status==409
        ?t('Report changed. Refresh before making a new report.',
          'تغير التقرير. حدّث قبل تقديم تقرير جديد.')
        :t('Battery report not confirmed. Refresh source history.',
          'لم يتأكد تقرير البطارية. حدّث سجل المصدر.'));
    }
  }
  Map<String,dynamic> validatedReport(dynamic raw,{bool history=false}){
    final row=Map<String,dynamic>.from(raw as Map);
    final revision=row['revision'],battery=row['battery_percent'],reported=row['reported_at'];
    if(row['vehicle_id']!=widget.vehicleId||
       row['source']!='CUSTOMER_MANUAL_SELF_REPORT'||
       row['measured_by_phone']!=false||
       row['measured_by_vehicle_api']!=false||
       row['charging_capability_verified']!=false||
       revision is! int||revision<0||revision>2147483647||
       (revision==0?(history||battery!=null||reported!=null):
         (battery is! int||battery<0||battery>100||reported is! String||reported.trim().isEmpty))){
      throw const FormatException('Unexpected battery report provenance.');
    }
    return row;
  }
  ApiPage<Map<String,dynamic>> validatedHistory(dynamic raw,int target,
      Map<String,dynamic> currentReport){
    if(raw is! Map||raw['per_page']!=25||target<1||target>100000){
      throw const FormatException('Invalid battery report page.');
    }
    final result=ApiPage.fromJson(raw,(v)=>validatedReport(v,history:true));
    final expectedLast=result.total==0?1:((result.total-1)~/25)+1;
    final remaining=result.total-(target-1)*25;
    final expectedCount=remaining<0?0:(remaining>25?25:remaining);
    if(result.current!=target||result.last!=expectedLast||target>result.last||
       result.total<0||result.total>2147483647||
       result.items.length!=expectedCount){
      throw const FormatException('Invalid battery report history.');
    }
    final currentRevision=currentReport['revision'] as int;
    var previous=currentRevision+1;
    for(final row in result.items){
      final revision=row['revision'] as int;
      if(revision>=previous||
          (revision==currentRevision&&row['battery_percent']!=currentReport['battery_percent'])){
        throw const FormatException('Inconsistent battery report history.');
      }
      previous=revision;
    }
    // These endpoints are separate reads: a concurrent report can invalidate
    // the pair. Require a new read rather than exposing a mismatched form.
    if(target==1&&(currentRevision==0
        ?result.total!=0
        :result.items.isEmpty||result.items.first['revision']!=currentRevision)){
      throw const FormatException('Current battery report and history disagree.');
    }
    return result;
  }
  Future<void> load([int target=1]) async {
    if(!active||loading||saving)return;
    final token=++generation,api=widget.api,requestPath=path;
    setState((){loading=true;error=null;success=null;});
    try{
      final response=await api.request(requestPath);
      if(!current(token,api))return;
      final currentMap=validatedReport(response);
      final raw=await api.request(requestPath+'/history',query:{'page':target.toString()});
      if(!current(token,api))return;
      final history=validatedHistory(raw,target,currentMap);
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
    }catch(e){
      if(current(token,api))setState((){latest=null;reports=[];page=lastPage=1;});
      failed(e,token,api);
    }
    finally{if(current(token,api))setState(()=>loading=false);}
  }

  Future<void> save() async {
    if(!active||saving||loading||latest==null)return;
    if(pending==null){
      if(latest!['revision']>=2147483647)return;
      final value=int.tryParse(vendorDigits(percent.text.trim()));
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
    final token=++generation,api=widget.api,requestPath=path;
    setState((){saving=true;error=success=null;});
    try{
      await api.request(requestPath,method:'POST',body:body);
      if(!current(token,api))return;
      // POST may commit even if read-back fails. Retain the original operation
      // key until BOTH current state and history have been verified.
      final response=await api.request(requestPath);
      if(!current(token,api))return;
      final currentMap=validatedReport(response);
      final raw=await api.request(requestPath+'/history',query:{'page':'1'});
      if(!current(token,api))return;
      final history=validatedHistory(raw,1,currentMap);
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
      failed(e,token,api);
      if(current(token,api)){
        setState(()=>error=t(
          'Result uncertain. Check the history or retry the identical report.',
          'النتيجة غير مؤكدة. راجع السجل أو أعد نفس التقرير.'));
      }
    }finally{if(current(token,api))setState(()=>saving=false);}
  }

  void clearPending(){
    if(!active||loading||saving)return;
    generation++;
    setState((){
      pending=null;
      percent.clear();
      error=success=null;
    });
  }

  @override
  Widget build(BuildContext context){
    if(!active)return Scaffold(appBar:AppBar(title:Text(t('Manual battery report','تقرير البطارية اليدوي'))),
      body:Center(child:Text(foreground?t('Account changed. Reopen battery history.','تغير الحساب. أعد فتح سجل البطارية.'):
        t('Private battery history paused.','سجل البطارية الخاص متوقف مؤقتاً.'),key:const Key('manual-soc-error'))));
    return Scaffold(
    appBar:AppBar(title:Text(t('Manual battery report','تقرير البطارية اليدوي'))),
    body:SafeArea(child:ListView(padding:const EdgeInsets.all(16),children:[
      Text(t(
        'Enter your own battery estimate. Your phone does not measure vehicle SOC. These reports do not start or stop charging, authorize reservations or calculate fees.',
        'أدخل تقديرك الشخصي للبطارية. الهاتف لا يقيس شحن المركبة. التقارير لا تبدأ أو توقف الشحن أو تسمح بالحجز أو تحسب الرسوم.',
      )),
      if (loading || saving) Semantics(
        key: const Key('manual-soc-progress'),
        liveRegion: true,
        child: LinearProgressIndicator(
          semanticsLabel: saving
            ? t('Saving manual battery report', 'جارٍ حفظ تقرير البطارية اليدوي')
            : t('Reading manual battery history', 'جارٍ قراءة سجل البطارية اليدوي'),
        ),
      ),
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
              '% · '+reportTime(latest!['reported_at'])),
        Text(t('Battery may have changed since the report.',
          'قد تكون حالة البطارية تغيرت منذ التقرير.')),
        TextFormField(
          key:const Key('manual-soc-percent'),controller:percent,
          enabled:pending==null&&!saving&&!loading,
          keyboardType:TextInputType.number,maxLength:3,
          decoration:InputDecoration(labelText:t('My estimate (0–100%)',
            'تقديري (٠–١٠٠٪)')),
        ),
        FilledButton(
          key:const Key('manual-soc-save'),
          onPressed:saving||loading||(pending==null&&latest!['revision']>=2147483647)?null:guard((){save();}),
          child:Text(pending==null?t('Save manual report','حفظ التقرير اليدوي')
            :t('Retry identical report','إعادة نفس التقرير')),
        ),
        if(pending!=null)Text(t('Pending estimate: ${pending!['battery_percent']}%. Retry sends the identical report.','التقدير المعلق: ${pending!['battery_percent']}٪. تعيد المحاولة إرسال التقرير نفسه.')),
        if(pending!=null)TextButton(
          onPressed:saving||loading?null:guard(clearPending),
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
              reportTime(report['reported_at'])+
              ' · #'+report['revision'].toString()),
          )),
        Wrap(crossAxisAlignment:WrapCrossAlignment.center,children:[
          TextButton(onPressed:loading||saving||page<=1?null:guard((){load(page-1);}),
            child:Text(t('Previous','السابق'))),
          Text('$page / $lastPage'),
          TextButton(onPressed:loading||saving||page>=lastPage||page>=100000?null:guard((){load(page+1);}),
            child:Text(t('Next','التالي'))),
        ]),
      ],
      TextButton.icon(
        onPressed:loading||saving?null:guard((){load(page);}),
        icon:const Icon(Icons.refresh),label:Text(t('Refresh from server','تحديث من الخادم')),
      ),
    ])),
  );
  }
}
