import 'package:flutter/material.dart';
import 'api.dart';

/// Published local records only. This view never grants remote charging rights.
class UnifiedDiscoveryView extends StatefulWidget {
  final EvApi api;
  final ValueChanged<Station> onCatalog;
  const UnifiedDiscoveryView({super.key, required this.api, required this.onCatalog});
  @override
  State<UnifiedDiscoveryView> createState() => _UnifiedDiscoveryViewState();
}
class _UnifiedDiscoveryViewState extends State<UnifiedDiscoveryView> with WidgetsBindingObserver {
  final query=TextEditingController();
  String scope='all',connector='',submitted='',submittedScope='all',submittedConnector='';
  int generation=0,page=1,last=1,total=0;
  bool busy=false,foreground=true;
  String? error;
  List<Map<String,dynamic>> rows=[];
  String tr(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  @override
  void initState(){super.initState();WidgetsBinding.instance.addObserver(this);}
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    foreground=state==AppLifecycleState.resumed;
    if(!foreground){generation++;if(mounted)setState((){rows=[];busy=false;error=null;});}
  }
  @override
  void didUpdateWidget(covariant UnifiedDiscoveryView old){super.didUpdateWidget(old);if(!identical(old.api,widget.api)){generation++;rows=[];busy=false;error=null;}}
  Future<void> load(int target)async{
    if(!foreground)return;
    final turn=++generation;final api=widget.api;final fence=api.sessionFence();
    bool active()=>mounted&&foreground&&turn==generation&&fence();
    setState((){busy=true;error=null;rows=[];});
    try{
      final data=await api.request('discovery/search',query:{'q':submitted,'scope':submittedScope,'connector':submittedConnector,'page':'$target'});
      if(!active())return;
      if(data is! Map||data['data'] is! List||data['current_page'] is! int||data['last_page'] is! int||data['total'] is! int)throw const FormatException('Invalid search page');
      final parsed=(data['data'] as List).map((row)=>Map<String,dynamic>.from(row as Map)).toList();
      for(final row in parsed){
        if(row['kind']=='catalog'){
          final station=Station.fromJson(Map<String,dynamic>.from(row['station'] as Map));
          if(row['key']!='catalog:${station.id}'||station.id<1)throw const FormatException('Invalid station identity');
        }else if(row['kind']!='partner'||row['location'] is! Map||row['record_id'] is! int||row['key']!='partner:${row['record_id']}'||row['availability']!='unknown'||row['reservable']!=false||row['remote_control']!=false){throw const FormatException('Invalid partner authority');}
      }
      setState((){rows=parsed;page=data['current_page'];last=data['last_page'];total=data['total'];});
    }catch(_){if(active())setState(()=>error=tr('Search failed. Retry explicitly.','تعذر البحث. أعد المحاولة صراحةً.'));}
    finally{if(active())setState(()=>busy=false);}
  }
  @override
  Widget build(BuildContext context)=>Scaffold(
    appBar:AppBar(title:Text(tr('Search all sources','البحث في كل المصادر'))),
    body:ListView(padding:const EdgeInsets.all(16),children:[
      Text(tr('Search published platform and authorized partner records. Partner reports do not authorize booking, charging or payment. External provider lookups require separate consent.','البحث في سجلات المنصة والشركاء المصرح لهم. تقارير الشركاء لا تسمح بالحجز أو الشحن أو الدفع. تتطلب الاستعلامات الخارجية موافقة منفصلة.')),
      TextField(key:const Key('unified-query'),controller:query,maxLength:80,decoration:InputDecoration(labelText:tr('Name, address or operator','الاسم أو العنوان أو المشغل'))),
      DropdownButtonFormField<String>(initialValue:scope,decoration:InputDecoration(labelText:tr('Source','المصدر')),items:[for(final choice in [('all','All published sources','كل المصادر المنشورة'),('catalog','Platform catalog','كتالوج المنصة'),('partner','Authorized partner reports','تقارير الشركاء المصرح لهم')])DropdownMenuItem(value:choice.$1,child:Text(tr(choice.$2,choice.$3)))],onChanged:busy?null:(value)=>setState(()=>scope=value!)),
      DropdownButtonFormField<String>(initialValue:connector,decoration:InputDecoration(labelText:tr('Connector','الموصل')),items:[for(final value in ['','CCS2','Type2','CHAdeMO'])DropdownMenuItem(value:value,child:Text(value.isEmpty?tr('All connectors','كل الموصلات'):value))],onChanged:busy?null:(value)=>setState(()=>connector=value!)),
      FilledButton(onPressed:busy?null:(){submitted=query.text.trim();submittedScope=scope;submittedConnector=connector;load(1);},child:Text(tr('Search all sources','البحث في كل المصادر'))),
      if(busy)const LinearProgressIndicator(),if(error!=null)Text(error!),
      Text(tr('$total published results','$total نتيجة منشورة')),
      for(final row in rows)card(row),
      Wrap(children:[if(page>1)TextButton(onPressed:busy?null:()=>load(page-1),child:Text(tr('Previous','السابق'))),Text('$page / $last'),if(page<last)TextButton(onPressed:busy?null:()=>load(page+1),child:Text(tr('Next','التالي')))]),
    ]),
  );
  Widget card(Map<String,dynamic> row){
    if(row['kind']=='catalog'){
      final station=Station.fromJson(Map<String,dynamic>.from(row['station'] as Map));
      return Card(key:ValueKey(row['key']),child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(station.name),Text(tr('Platform catalog · ${station.source}','كتالوج المنصة · ${station.source}')),TextButton(onPressed:foreground?()=>widget.onCatalog(station):null,child:Text(tr('View station and booking options','عرض المحطة وخيارات الحجز')))])));
    }
    final location=Map<String,dynamic>.from(row['location'] as Map);
    return Card(key:ValueKey(row['key']),child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text('${location['name']??location['id']??''}'),Text('${row['provider_label']??''}'),Text([location['address'],location['city'],location['country']].where((v)=>v!=null).join(' · ')),Text(tr('Partner reported · availability unknown · no booking or remote control','تقرير شريك · التوفر غير معروف · لا حجز أو تحكم عن بُعد')),Text(tr('Reported source time: ${row['source_updated_at']??'unknown'}','وقت المصدر المبلغ عنه: ${row['source_updated_at']??'غير معروف'}'))])));
  }
  @override
  void dispose(){generation++;WidgetsBinding.instance.removeObserver(this);query.dispose();super.dispose();}
}
