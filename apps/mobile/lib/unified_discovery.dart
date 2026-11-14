import 'connector_freshness.dart';
import 'partner_report_map.dart';
import 'package:flutter/material.dart';
import 'api.dart';

/// Published local records only. This view never grants remote charging rights.
class UnifiedDiscoveryView extends StatefulWidget {
  final EvApi api;
  final ValueChanged<Station> onCatalog;
  final bool customer;
  const UnifiedDiscoveryView({super.key, required this.api, required this.onCatalog, this.customer=false});
  @override
  State<UnifiedDiscoveryView> createState() => _UnifiedDiscoveryViewState();
}
class _UnifiedDiscoveryViewState extends State<UnifiedDiscoveryView> with WidgetsBindingObserver {
  final query=TextEditingController();
  late bool Function() ownSession;
  String sourceStatus='',submittedSourceStatus='';
  String scope='all',connector='',submitted='',submittedScope='all',submittedConnector='';
  int generation=0,page=1,last=1,total=0;
  bool busy=false,foreground=true,savedOnly=false,submittedSaved=false,writing=false,uncertain=false;
  String? error;
  List<Map<String,dynamic>> rows=[];
  String tr(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  @override
  void initState(){super.initState();ownSession=widget.api.sessionFence();WidgetsBinding.instance.addObserver(this);}
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    foreground=state==AppLifecycleState.resumed;
    if(!foreground){generation++;if(mounted)setState((){rows=[];busy=false;writing=false;uncertain=false;error=null;});}
  }
  @override
  void didUpdateWidget(covariant UnifiedDiscoveryView old){super.didUpdateWidget(old);if(!identical(old.api,widget.api)){ownSession=widget.api.sessionFence();generation++;rows=[];busy=false;writing=false;uncertain=false;error=null;}}
  Future<void> load(int target)async{
    if(!foreground||writing)return;
    if(!ownSession()){setState((){rows=[];busy=false;writing=false;error=tr('Account changed. Reopen search.','تغير الحساب. أعد فتح البحث.');});return;}
    final turn=++generation;final api=widget.api;final fence=api.sessionFence();
    bool active()=>mounted&&foreground&&turn==generation&&ownSession()&&fence();
    setState((){busy=true;uncertain=false;error=null;rows=[];});
    try{
      final data=await api.request('discovery/search',query:{'q':submitted,'scope':submittedScope,'connector':submittedConnector,'page':'$target','saved_only':submittedSaved?'1':'0',if(submittedSourceStatus.isNotEmpty)'recent_source_status':submittedSourceStatus});
      if(!active())return;
      if(data is! Map||data['data'] is! List||data['current_page'] is! int||data['last_page'] is! int||data['total'] is! int)throw const FormatException('Invalid search page');
      final parsed=(data['data'] as List).map((row)=>Map<String,dynamic>.from(row as Map)).toList();
      for(final row in parsed){
        if(row['kind']=='catalog'){
          final station=Station.fromJson(Map<String,dynamic>.from(row['station'] as Map));
          if(row['key']!='catalog:${station.id}'||station.id<1)throw const FormatException('Invalid station identity');
        }else if(row['kind']!='partner'||row['location'] is! Map||row['record_id'] is! int||(row['record_id'] as int)<1||row['key']!='partner:${row['record_id']}'||row['availability']!='unknown'||row['reservable']!=false||row['remote_control']!=false){throw const FormatException('Invalid partner authority');}
      }
      setState((){rows=parsed;page=data['current_page'];last=data['last_page'];total=data['total'];});
    }catch(_){if(active())setState(()=>error=tr('Search failed. Retry explicitly.','تعذر البحث. أعد المحاولة صراحةً.'));}
    finally{if(mounted&&foreground&&turn==generation)setState((){busy=false;if(!ownSession()){rows=[];error=tr('Account changed. Reopen search.','تغير الحساب. أعد فتح البحث.');}});}
  }
  @override
  Widget build(BuildContext context)=>Scaffold(
    appBar:AppBar(title:Text(tr('Search all sources','البحث في كل المصادر'))),
    body:ListView(padding:const EdgeInsets.all(16),children:[
      Text(tr('Search published platform and authorized partner records. Partner reports do not authorize booking, charging or payment. External provider lookups require separate consent.','البحث في سجلات المنصة والشركاء المصرح لهم. تقارير الشركاء لا تسمح بالحجز أو الشحن أو الدفع. تتطلب الاستعلامات الخارجية موافقة منفصلة.')),
      TextField(key:const Key('unified-query'),controller:query,maxLength:80,decoration:InputDecoration(labelText:tr('Name, address or operator','الاسم أو العنوان أو المشغل'))),
      DropdownButtonFormField<String>(initialValue:scope,decoration:InputDecoration(labelText:tr('Source','المصدر')),items:[for(final choice in [('all','All published sources','كل المصادر المنشورة'),('catalog','Platform catalog','كتالوج المنصة'),('partner','Authorized partner reports','تقارير الشركاء المصرح لهم')])DropdownMenuItem(value:choice.$1,child:Text(tr(choice.$2,choice.$3)))],onChanged:busy?null:(value)=>setState(()=>scope=value!)),
      DropdownButtonFormField<String>(initialValue:connector,decoration:InputDecoration(labelText:tr('Connector','الموصل')),items:[for(final value in ['','CCS2','Type2','CHAdeMO'])DropdownMenuItem(value:value,child:Text(value.isEmpty?tr('All connectors','كل الموصلات'):value))],onChanged:busy?null:(value)=>setState(()=>connector=value!)),
      DropdownButtonFormField<String>(key:const Key('unified-source-status'),initialValue:sourceStatus,decoration:InputDecoration(labelText:tr('Simulated source state at search','حالة مصدر المحاكاة عند البحث')),items:[DropdownMenuItem(value:'',child:Text(tr('All states','كل الحالات'))),for(final status in reportedConnectorStatuses)DropdownMenuItem(value:status,child:Text(connectorStatusLabel(status,Localizations.localeOf(context).languageCode=='ar')))],onChanged:busy||writing?null:(value)=>setState(()=>sourceStatus=value!)),
      Text(tr('This filter matches canonical connector reports within five minutes at search. Partner reports cannot satisfy it. Physical availability is unverified; search again to recheck.','يطابق هذا المرشح تقارير موصلات المنصة خلال خمس دقائق عند البحث. لا تنطبق تقارير الشركاء عليه. التوفر الفعلي غير مؤكد؛ أعد البحث للتحقق.')),
      if(widget.customer)CheckboxListTile(value:savedOnly,onChanged:busy||writing?null:(value)=>setState(()=>savedOnly=value==true),title:Text(tr('Saved places only','الأماكن المحفوظة فقط'))),
      FilledButton(onPressed:busy||writing?null:(){submitted=query.text.trim();submittedScope=scope;submittedConnector=connector;submittedSaved=savedOnly;submittedSourceStatus=sourceStatus;load(1);},child:Text(tr('Search all sources','البحث في كل المصادر'))),
      if(busy)const LinearProgressIndicator(),if(error!=null)Text(error!),
      Text(tr('$total published results','$total نتيجة منشورة')),
      if (mapPoints().isNotEmpty) OutlinedButton(key:const Key('unified-map-open'),onPressed:()=>Navigator.of(context).push(MaterialPageRoute<void>(builder:(_)=>PartnerReportMap(points:mapPoints()))),child:Text(tr('Map these loaded results','عرض هذه النتائج المحملة على الخريطة'))),
      for(final row in rows)card(row),
      Wrap(children:[if(page>1)TextButton(onPressed:busy||writing?null:()=>load(page-1),child:Text(tr('Previous','السابق'))),Text('$page / $last'),if(page<last)TextButton(onPressed:busy||writing?null:()=>load(page+1),child:Text(tr('Next','التالي')))]),
    ]),
  );
  List<PartnerMapPoint> mapPoints()=>[
    for(final row in rows)
      if(row['kind']=='catalog') PartnerMapPoint.fromPublishedCatalog(Map<String,dynamic>.from(row['station'] as Map))
      else PartnerMapPoint.fromPublishedLocation(Map<String,dynamic>.from(row['location'] as Map),providerLabel:row['provider_label'] as String?),
  ].whereType<PartnerMapPoint>().toList();
  Widget card(Map<String,dynamic> row){
    if(row['kind']=='catalog'){
      final station=Station.fromJson(Map<String,dynamic>.from(row['station'] as Map));
      return Card(key:ValueKey(row['key']),child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(station.name),if(widget.customer)favoriteButton(row),Text(tr('Platform catalog · ${station.source}','كتالوج المنصة · ${station.source}')),TextButton(onPressed:foreground&&ownSession()?()=>widget.onCatalog(station):null,child:Text(tr('View station and booking options','عرض المحطة وخيارات الحجز')))])));
    }
    final location=Map<String,dynamic>.from(row['location'] as Map);
    return Card(key:ValueKey(row['key']),child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text('${location['name']??location['id']??''}'),if(widget.customer)favoriteButton(row),Text('${row['provider_label']??''}'),Text([location['address'],location['city'],location['country']].where((v)=>v!=null).join(' · ')),Text(tr('Partner reported · availability unknown · no booking or remote control','تقرير شريك · التوفر غير معروف · لا حجز أو تحكم عن بُعد')),Text(tr('Reported source time: ${row['source_updated_at']??'unknown'}','وقت المصدر المبلغ عنه: ${row['source_updated_at']??'غير معروف'}'))])));
  }
  Widget favoriteButton(Map<String,dynamic> row)=>TextButton(
    key:ValueKey('unified-favorite-${row['key']}'),
    onPressed:busy||writing||uncertain||!foreground||!ownSession()?null:()=>favorite(row),
    child:Text(row['is_favorite']==true?tr('Remove saved place','إزالة المكان المحفوظ'):tr('Save place','حفظ المكان')),
  );
  Future<void> favorite(Map<String,dynamic> row)async{
    if(!foreground||writing||busy||uncertain||!widget.customer||!ownSession())return;
    final api=widget.api;final turn=generation;final fence=api.sessionFence();
    bool active()=>mounted&&foreground&&turn==generation&&ownSession()&&fence();
    final desired=row['is_favorite']!=true;
    final id=row['kind']=='catalog'?(row['station'] as Map)['id']:row['record_id'];
    setState((){writing=true;error=null;});
    try{
      final data=await api.request('${row['kind']=='catalog'?'favorites':'roaming/favorites'}/$id',method:desired?'PUT':'DELETE');
      if(!active())return;
      if(data is! Map||data['is_favorite']!=desired)throw const FormatException('Unknown preference outcome');
      setState(()=>writing=false);await load(1);
    }catch(_){if(active())setState((){uncertain=true;error=tr('Saved-state outcome unknown. Search again to refresh before retrying.','نتيجة الحفظ غير معروفة. أعد البحث لتحديث الحالة قبل المحاولة.');});}
    finally{if(mounted&&foreground&&turn==generation)setState((){writing=false;if(!ownSession()){rows=[];error=tr('Account changed. Reopen search.','تغير الحساب. أعد فتح البحث.');}});}
  }
  @override
  void dispose(){generation++;WidgetsBinding.instance.removeObserver(this);query.dispose();super.dispose();}
}
