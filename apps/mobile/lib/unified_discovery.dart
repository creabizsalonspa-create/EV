import 'dart:async';
import 'vendor_forms.dart' show vendorDigits;
import 'partner_catalog_policy.dart';
export 'partner_catalog_policy.dart' show verifiedPartnerSourceTime;
import 'connector_freshness.dart';
import 'partner_report_map.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart' show TileProvider;
import 'api.dart';
import 'federated_nearby.dart';

/// Published local records only. This view never grants remote charging rights.
String partnerCatalogAgeLabel(Map<String,dynamic> row, bool ar) {
  if (verifiedPartnerSourceTime(row) == null) return ar ? 'عمر الكتالوج المبلغ عنه غير معروف أو ساعة الشريك متقدمة' : 'Reported catalog age unknown or partner clock ahead';
  final age = row['reported_source_age_minutes'] as int;
  final stale = age >= 1440;
  return ar
      ? 'عمر الكتالوج المبلغ عنه عند البحث: $age دقيقة${stale ? ' · أقدم من 24 ساعة' : ''}'
      : 'Reported catalog age at search: $age min${stale ? ' · over 24 hours old' : ''}';
}

/// A partial published location can be shown as read-only evidence but cannot
/// create a booking or favorite action that depends on absent connector data.
bool completePublishedStation(Map<String,dynamic> station) =>
  station['id'] is int && (station['id'] as int)>0 &&
  station['name'] is String && (station['name'] as String).trim().isNotEmpty &&
  ['SIMULATED','DISCOVERY_ONLY'].contains(station['evidence']) &&
  ['CCS2','Type2','CHAdeMO'].contains(station['connector_type']) &&
  station['power_kw'] is int && (station['power_kw'] as int)>=1 &&
  (station['power_kw'] as int)<=1000 &&
  // A discovery-only record must never appear to authorize a reservation.
  (station['evidence'] != 'DISCOVERY_ONLY' || station['reservable'] != true) &&
  // Station.fromJson uses typed optional fields. An invalid optional server
  // value must degrade to a read-only station card, never crash the widget.
  (station['timezone'] == null ||
      (station['timezone'] is String &&
       (station['timezone'] as String).trim().isNotEmpty)) &&
  (station['reservation_policy'] == null ||
      station['reservation_policy'] is String) &&
  (station['distance_km'] == null ||
      (station['distance_km'] is num &&
       (station['distance_km'] as num).toDouble().isFinite &&
       (station['distance_km'] as num) >= 0));

class UnifiedDiscoveryView extends StatefulWidget {
  final EvApi api;
  final ValueChanged<Station> onCatalog;
  final bool customer;
  final TileProvider? mapTileProvider;
  const UnifiedDiscoveryView({super.key, required this.api, required this.onCatalog, this.customer=false, this.mapTileProvider});
  @override
  State<UnifiedDiscoveryView> createState() => _UnifiedDiscoveryViewState();
}
class _UnifiedDiscoveryViewState extends State<UnifiedDiscoveryView> with WidgetsBindingObserver {
  final query=TextEditingController();
  final advanced={for(final key in ['operator','address','access','min_power_kw','max_power_kw','max_rate_per_kwh','price_currency'])key:TextEditingController()};
  Map<String,String> submittedAdvanced={}, submittedViewport={};
  late bool Function() ownSession;
  String sourceStatus='',submittedSourceStatus='',sourceAge='',submittedSourceAge='';
  String scope='all',country='',connector='',submitted='',submittedScope='all',submittedCountry='',submittedConnector='';
  int generation=0,page=1,last=1,total=0,formEpoch=0;
  bool rememberNextSearch=false;
  final List<Map<String,String>> recentPresets=[];
  bool busy=false,foreground=true,savedOnly=false,submittedSaved=false,writing=false,uncertain=false;
  String? error;
  List<Map<String,dynamic>> rows=[];
  String tr(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  StreamSubscription<int>? sessionSubscription;
  final mapEvents=StreamController<int>.broadcast(sync:true);
  int mapAuthority=0;
  void retireMap(){
    final epoch=++mapAuthority;
    // A parent widget can change identity during framework build. Deliver
    // the privacy repaint to the detached Navigator route after that frame.
    scheduleMicrotask((){if(!mapEvents.isClosed)mapEvents.add(epoch);});
  }
  void redact(){
    generation++;formEpoch++;
    query.clear();
    for(final control in advanced.values)control.clear();
    submittedAdvanced={};submittedViewport={};
    recentPresets.clear();rememberNextSearch=false;
    submitted='';scope='all';submittedScope='all';
    country='';submittedCountry='';connector='';submittedConnector='';
    sourceStatus='';submittedSourceStatus='';sourceAge='';submittedSourceAge='';
    submittedSaved=false;savedOnly=false;
    page=last=1;total=0;rows=[];
    busy=false;writing=false;uncertain=false;error=null;
  }
  void subscribe(){
    final api=widget.api;
    sessionSubscription=api.sessionEvents.listen((_){
      if(mounted&&identical(api,widget.api)&&!ownSession()){
        retireMap();setState(redact);
      }
    });
  }
  @override
  void initState(){
    super.initState();
    ownSession=widget.api.sessionFence();
    final state=WidgetsBinding.instance.lifecycleState;
    foreground=state==null||state==AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    final resumed=state==AppLifecycleState.resumed;
    if(!mounted)return;
    if(!resumed&&state!=AppLifecycleState.inactive)retireMap();
    if(foreground==resumed)return;
    setState((){foreground=resumed;if(!resumed)redact();});
  }
  @override
  void didUpdateWidget(covariant UnifiedDiscoveryView old){
    super.didUpdateWidget(old);
    if(!identical(old.api,widget.api)){
      retireMap();
      unawaited(sessionSubscription?.cancel());
      redact();
      ownSession=widget.api.sessionFence();
      subscribe();
    }else if(old.customer!=widget.customer||!ownSession()){
      retireMap();redact();
    }
  }
  bool current(int epoch,EvApi api,bool Function() fence)=>
    mounted&&foreground&&generation==epoch&&identical(api,widget.api)&&
    identical(fence,ownSession)&&fence();
  VoidCallback guard(VoidCallback action){
    final epoch=generation,api=widget.api,fence=ownSession;
    return (){if(current(epoch,api,fence)&&!busy&&!writing)action();};
  }
  Future<void> load(int target)async{
    if(!mounted||!foreground||writing||busy)return;
    if(!ownSession()){setState((){rows=[];recentPresets.clear();rememberNextSearch=false;busy=false;writing=false;error=tr('Account changed. Reopen search.','تغير الحساب. أعد فتح البحث.');});return;}
    // Only an explicitly submitted search is eligible for history.
    // Pagination, map selections and favorite refresh never record history.
    final remember=target==1 && rememberNextSearch && submittedViewport.isEmpty;
    final snapshot=remember?<String,String>{
      'q':submitted,'scope':submittedScope,'country':submittedCountry,
      'connector':submittedConnector,'source_status':submittedSourceStatus,
      'source_age':submittedSourceAge,'saved':submittedSaved?'1':'0',
      for(final entry in submittedAdvanced.entries)'advanced_${entry.key}':entry.value,
    }:null;
    rememberNextSearch=false;
    final turn=++generation;final api=widget.api;final fence=ownSession;
    bool active()=>current(turn,api,fence);
    setState((){busy=true;uncertain=false;error=null;rows=[];});
    try{
      final data=await api.request('discovery/search',query:{...submittedAdvanced,...submittedViewport,'q':submitted,'scope':submittedScope,if(submittedCountry.isNotEmpty)'country':submittedCountry,'connector':submittedConnector,'page':'$target','saved_only':submittedSaved?'1':'0',if(submittedSourceStatus.isNotEmpty)'recent_source_status':submittedSourceStatus,if(submittedSourceAge.isNotEmpty)'max_source_age_hours':submittedSourceAge});
      if(!active())return;
      if(data is! Map||data['data'] is! List||
          (data['data'] as List).length>25||
          data['current_page'] is! int||data['current_page']!=target||
          data['last_page'] is! int||data['last_page']<1||
          data['last_page']>1000000||
          data['total'] is! int||data['total']<0||
          data['total']<(data['data'] as List).length||
          (target>data['last_page']&&(data['data'] as List).isNotEmpty))
        throw const FormatException('Unverified private discovery page');
      final parsed=(data['data'] as List).map((row)=>Map<String,dynamic>.from(row as Map)).toList();
      final keys=<String>{};
      for(final row in parsed){
        final key=row['key'];
        if(key is! String||!keys.add(key))
          throw const FormatException('Duplicate discovery identity');
        if(row['kind']=='catalog'){
          if(row['station'] is! Map)throw const FormatException('Invalid catalog payload');
          final raw=Map<String,dynamic>.from(row['station'] as Map);
          final id=raw['id'];
          if(id is! int||id<1||row['key']!='catalog:$id')throw const FormatException('Invalid station identity');
          // Noncritical metadata may be incomplete. Keep the identity in the
          // read-only catalog, but never construct Station or a booking link.
        }else if(row['kind']!='partner'||row['location'] is! Map||row['record_id'] is! int||(row['record_id'] as int)<1||row['key']!='partner:${row['record_id']}'||row['availability']!='unknown'||row['reservable']!=false||row['remote_control']!=false){throw const FormatException('Invalid partner authority');}
      }
      setState((){
        rows=parsed;page=data['current_page'];last=data['last_page'];total=data['total'];
        if(snapshot!=null){
          bool same(Map<String,String> p)=>p.length==snapshot.length &&
            p.entries.every((entry)=>snapshot[entry.key]==entry.value);
          recentPresets.removeWhere(same);
          recentPresets.insert(0,snapshot);
          if(recentPresets.length>5)recentPresets.removeLast();
        }
      });
    }catch(_){if(active())setState(()=>error=tr('Search failed. Retry explicitly.','تعذر البحث. أعد المحاولة صراحةً.'));}
    finally{if(mounted&&foreground&&turn==generation)setState((){busy=false;if(!ownSession()){rows=[];recentPresets.clear();error=tr('Account changed. Reopen search.','تغير الحساب. أعد فتح البحث.');}});}
  }
  /// Only an explicit map action can submit this transient rectangle.
  /// Session/lifecycle revalidation prevents another account from inheriting
  /// a route result or a backgrounded map from triggering a private search.
  Future<void> searchFromMap() async {
    if (!foreground || busy || writing || !ownSession()) return;
    final snapshot = generation;
    final api=widget.api, fence = ownSession;
    final customer=widget.customer, mapEpoch=mapAuthority;
    bool mapSession()=>mounted&&identical(api,widget.api)&&identical(fence,ownSession)&&customer==widget.customer&&mapEpoch==mapAuthority&&fence();
    final points=mapPoints();
    final bounds = await Navigator.of(context).push<Map<String,String>>(
      MaterialPageRoute(builder: (_) => PartnerReportMap(
        points: points,
        currentSession:mapSession,
        sessionEvents:mapEvents.stream,
        tileProvider: widget.mapTileProvider,
        allowViewportSearch: true,
      )),
    );
    if(!mapSession()||!foreground||generation!=snapshot||bounds==null)return;
    setState(() => submittedViewport = Map<String,String>.of(bounds));
    await load(1);
  }

  String recentLabel(Map<String,String> previous) {
    final source=previous['scope']=='catalog'
      ?tr('Platform catalog','كتالوج المنصة')
      :previous['scope']=='partner'
        ?tr('Partner reports','تقارير الشركاء')
        :tr('All published sources','كل المصادر المنشورة');
    final name=previous['q']?.isNotEmpty==true
      ?previous['q']!:tr('All locations','كل المواقع');
    final market=previous['country']?.isNotEmpty==true
      ?previous['country']!:tr('all countries','كل الدول');
    return '$name · $market · $source';
  }
  // Restoring historical *form fields* cannot issue any request. Changing
  // the ListView key remounts dropdowns with the restored initialValue.
  void restoreRecent(Map<String,String> old){
    if(!mounted||!foreground||busy||writing||!ownSession())return;
    // A detached callback may outlive account/role changes or Clear history.
    // Only a preset still owned by this view may re-enter its current draft.
    if(!recentPresets.any((preset)=>identical(preset,old)))return;
    query.text=old['q']??'';
    for(final field in advanced.entries) {
      field.value.text=old['advanced_${field.key}']??'';
    }
    setState((){
      scope=old['scope']??'all';country=old['country']??'';
      connector=old['connector']??'';
      sourceStatus=old['source_status']??'';sourceAge=old['source_age']??'';
      savedOnly=widget.customer&&old['saved']=='1';
      formEpoch++;
      error=tr('Filters restored locally. Press Search to submit.',
          'استُعيدت المرشحات محلياً. اضغط بحث لإرسالها.');
    });
  }

  Widget federationPanel(){
    final api=widget.api, fence=ownSession, epoch=formEpoch, customer=widget.customer;
    bool current()=>mounted&&foreground&&formEpoch==epoch&&
      identical(widget.api,api)&&identical(ownSession,fence)&&widget.customer==customer&&fence();
    return FederatedNearbyPanel(key:ValueKey('unified-federation-$epoch'),api:api,
      currentSession:current,
      onMap:(points){
        if(!current()||points.isEmpty||points.length>50)return;
        final mapEpoch=mapAuthority;
        Navigator.of(context).push<void>(MaterialPageRoute(builder:(_)=>PartnerReportMap(
          points:List<PartnerMapPoint>.unmodifiable(points),
          tileProvider:widget.mapTileProvider,
          currentSession:()=>current()&&mapAuthority==mapEpoch,
          sessionEvents:mapEvents.stream,
        )));
      });
  }

  @override
  Widget build(BuildContext context)=>!foreground||!ownSession()
    ? Scaffold(
        appBar:AppBar(title:Text(tr('Search all sources','البحث في كل المصادر'))),
        // A native EvApi can change identity while the same widget/API object
        // stays mounted. Never show a previous customer's rows, saved filters,
        // draft coordinates, or provider labels on the next repaint.
        body:Center(child:Text(!ownSession()?tr('Account changed. Reopen search.', 'تغير الحساب. أعد فتح البحث.'):tr('Private discovery paused.','البحث الخاص متوقف مؤقتاً.'))),
      )
    : Scaffold(
    appBar:AppBar(title:Text(tr('Search all sources','البحث في كل المصادر'))),
    body:ListView(key:ValueKey('unified-form-$formEpoch'),padding:const EdgeInsets.all(16),children:[
      Text(tr('Search published platform and authorized partner records. Partner reports do not authorize booking, charging or payment. External provider lookups require separate consent.','البحث في سجلات المنصة والشركاء المصرح لهم. تقارير الشركاء لا تسمح بالحجز أو الشحن أو الدفع. تتطلب الاستعلامات الخارجية موافقة منفصلة.')),
      TextField(key:const Key('unified-query'),controller:query,maxLength:80,decoration:InputDecoration(labelText:tr('Name, address or operator','الاسم أو العنوان أو المشغل'))),
      DropdownButtonFormField<String>(key:const Key('unified-scope'),initialValue:scope,decoration:InputDecoration(labelText:tr('Source','المصدر')),items:[for(final choice in [('all','All published sources','كل المصادر المنشورة'),('catalog','Platform catalog','كتالوج المنصة'),('partner','Authorized partner reports','تقارير الشركاء المصرح لهم')])DropdownMenuItem(value:choice.$1,child:Text(tr(choice.$2,choice.$3)))],onChanged:busy?null:(value)=>setState(()=>scope=value!)),
      InputDecorator(
        decoration: InputDecoration(labelText: tr('Country (supported markets)', 'الدولة (الأسواق المدعومة)')),
        child: DropdownButtonHideUnderline(
          child: DropdownButton<String>(
            key: const Key('unified-country'),
            value: country,
            isExpanded: true,
            items: [
              for(final option in [
                ('','No country filter (available records)','بدون تصفية حسب الدولة (السجلات المتاحة)'),
                ('AE','United Arab Emirates','الإمارات العربية المتحدة'),
                ('US','United States','الولايات المتحدة'),
                ('CA','Canada','كندا'),
              ])
                DropdownMenuItem(value:option.$1,child:Text(tr(option.$2,option.$3))),
            ],
            onChanged:busy||writing?null:(value)=>setState(()=>country=value!),
          ),
        ),
      ),
      DropdownButtonFormField<String>(key:const Key('unified-connector'),initialValue:connector,decoration:InputDecoration(labelText:tr('Connector','الموصل')),items:[for(final value in ['','CCS2','Type2','CHAdeMO'])DropdownMenuItem(value:value,child:Text(value.isEmpty?tr('All connectors','كل الموصلات'):value))],onChanged:busy?null:(value)=>setState(()=>connector=value!)),
      DropdownButtonFormField<String>(key:const Key('unified-source-status'),initialValue:sourceStatus,decoration:InputDecoration(labelText:tr('Simulated source state at search','حالة مصدر المحاكاة عند البحث')),items:[DropdownMenuItem(value:'',child:Text(tr('All states','كل الحالات'))),for(final status in reportedConnectorStatuses)DropdownMenuItem(value:status,child:Text(connectorStatusLabel(status,Localizations.localeOf(context).languageCode=='ar')))],onChanged:busy||writing?null:(value)=>setState(()=>sourceStatus=value!)),
      DropdownButtonFormField<String>(key:const Key('unified-source-age'),initialValue:sourceAge,decoration:InputDecoration(labelText:tr('Partner source updates within (platform results unchanged)','تحديثات مصدر الشريك خلال (نتائج المنصة دون تغيير)')),items:[for(final choice in [('','All report ages','كل أعمار التقارير'),('1','Last hour','آخر ساعة'),('24','Last 24 hours','آخر 24 ساعة'),('168','Last 7 days','آخر 7 أيام')])DropdownMenuItem(value:choice.$1,child:Text(tr(choice.$2,choice.$3)))],onChanged:busy||writing?null:(value)=>setState(()=>sourceAge=value!)),
      Text(tr('This filter matches canonical connector reports within five minutes at search. Partner reports cannot satisfy it. Physical availability is unverified; search again to recheck.','يطابق هذا المرشح تقارير موصلات المنصة خلال خمس دقائق عند البحث. لا تنطبق تقارير الشركاء عليه. التوفر الفعلي غير مؤكد؛ أعد البحث للتحقق.')),
      if(widget.customer)CheckboxListTile(value:savedOnly,onChanged:busy||writing?null:(value)=>setState(()=>savedOnly=value==true),title:Text(tr('Saved places only','الأماكن المحفوظة فقط'))),
      ExpansionTile(key:const Key('unified-advanced-filters'),title:Text(tr('Advanced filters','مرشحات متقدمة')),children:[
        for(final field in [('operator','Operator label','اسم المشغل',100),('address','Address or city','العنوان أو المدينة',150),('access','Platform access description','وصف الوصول في المنصة',150),('min_power_kw','Minimum published station rating (kW)','أقل قدرة منشورة للمحطة (كيلوواط)',4),('max_power_kw','Maximum published station rating (kW)','أعلى قدرة منشورة للمحطة (كيلوواط)',4),('max_rate_per_kwh','Maximum simulated energy rate per kWh','أقصى سعر طاقة محاكاة لكل كيلوواط ساعة',10),('price_currency','Rate currency (for example AED)','عملة السعر (مثلاً AED)',3)])
          TextField(key:Key('unified-${field.$1}'),controller:advanced[field.$1],maxLength:field.$4,decoration:InputDecoration(labelText:tr(field.$2,field.$3))),
        Text(tr('Access, power and price filters exclude partner reports. Published station kW is catalog rated power, not measured live power. Price matches current simulated energy rates excluding tax, not a total-cost quote. Enter both rate and currency.','تستثني مرشحات الوصول والقدرة والسعر تقارير الشركاء. قدرة المحطة المنشورة وصف كتالوجي وليست قدرة فعلية مقاسة. يطابق السعر أسعار الطاقة المحاكاة الحالية دون الضريبة وليس عرضاً للتكلفة الإجمالية. أدخل السعر والعملة معاً.')),
      ]),
      FilledButton(onPressed:busy||writing?null:guard((){submittedAdvanced={};for(final field in advanced.entries){var value=field.value.text.trim();if(['max_rate_per_kwh','min_power_kw','max_power_kw'].contains(field.key))value=vendorDigits(value);if(field.key=='price_currency')value=value.toUpperCase();if(value.isNotEmpty)submittedAdvanced[field.key]=value;}submitted=query.text.trim();submittedScope=scope;submittedCountry=country;submittedConnector=connector;submittedSaved=savedOnly;submittedSourceStatus=sourceStatus;submittedSourceAge=sourceAge;submittedViewport={};rememberNextSearch=true;load(1);}),child:Text(tr('Search all sources','البحث في كل المصادر'))),
      if(recentPresets.isNotEmpty&&foreground&&ownSession())...[
        Text(tr('Recent filters (this session only)','مرشحات حديثة (هذه الجلسة فقط)')),
        Wrap(spacing:8,children:[
          for(final entry in recentPresets.asMap().entries)
            OutlinedButton(key:Key('unified-recent-${entry.key}'),
              onPressed:busy||writing?null:()=>restoreRecent(entry.value),
              child:Text(tr('Restore filters: ','استعادة المرشحات: ')+
                recentLabel(entry.value))),
        ]),
        TextButton(key:const Key('unified-recent-clear'),
          onPressed:busy||writing?null:guard(()=>setState(recentPresets.clear)),
          child:Text(tr('Clear recent filters','مسح المرشحات الحديثة'))),
      ],
      if(busy)const LinearProgressIndicator(),if(error!=null)Text(error!),
      Text(tr('$total published results','$total نتيجة منشورة')),
      // Discovery into an empty viewport must be possible even when no
      // catalog records had coordinates or the previous page was empty.
      // Opening the map does not perform a network/GPS lookup; tiles and
      // viewport submission each require separate explicit actions.
      OutlinedButton(key:const Key('unified-map-open'),onPressed:busy||writing||!foreground||!ownSession()?null:guard((){searchFromMap();}),child:Text(
        mapPoints().isEmpty
          ?tr('Choose a map area to search','اختر منطقة على الخريطة للبحث')
          :tr('Map these loaded results','عرض هذه النتائج المحملة على الخريطة'),
      )),
      for(final row in rows)card(row),
      Wrap(children:[if(page>1)TextButton(onPressed:busy||writing?null:guard((){load(page-1);}),child:Text(tr('Previous','السابق'))),Text('$page / $last'),if(page<last)TextButton(onPressed:busy||writing?null:guard((){load(page+1);}),child:Text(tr('Next','التالي')))]),
      federationPanel(),
    ]),
  );
  // A partner's map pin or saved-place action needs explicit *read-only*
  // provenance from the server, not just plausible coordinates/record IDs.
  // The standard PartnerMapPoint helper is also used by other catalog views,
  // so this stricter union-source authorization lives at this boundary.
  bool trustedPartner(Map<String,dynamic> row) {
    final location=row['location'];
    return row['kind']=='partner' &&
      row['evidence']=='PARTNER_REPORTED_UNVERIFIED' &&
      row['availability']=='unknown' &&
      row['remote_control']==false && row['reservable']==false &&
      row['location_available']!=false &&
      location is Map &&
      location['availability']=='unknown' &&
      location['remote_control']==false &&
      location['reservable']==false;
  }
  List<PartnerMapPoint> mapPoints()=>[
    for(final row in rows)
      if(row['kind']=='catalog' &&
          completePublishedStation(Map<String,dynamic>.from(row['station'] as Map)))
        PartnerMapPoint.fromPublishedCatalog(Map<String,dynamic>.from(row['station'] as Map))
      else if(trustedPartner(row))
        PartnerMapPoint.fromPublishedLocation(
          Map<String,dynamic>.from(row['location'] as Map),
          providerLabel: row['provider_label'] is String ? row['provider_label'] as String : null),
  ].whereType<PartnerMapPoint>().toList();
  void openCatalog(Station station){
    // The button closure may have been built under an earlier account epoch.
    // Recheck identity at the actual click, not just when rendering it.
    if(!mounted||!foreground||!ownSession())return;
    widget.onCatalog(station);
  }
  Widget card(Map<String,dynamic> row){
    if(row['kind']=='catalog'){
      final raw=Map<String,dynamic>.from(row['station'] as Map);
      if(!completePublishedStation(raw)){
        final rawName=raw['name'];
        final label=rawName is String&&rawName.trim().isNotEmpty
            ?rawName:tr('Unnamed platform station','محطة منصة بلا اسم');
        return Card(key:ValueKey(row['key']),child:Padding(
          padding:const EdgeInsets.all(12),
          child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
            Text(label),
            Text(tr('Published station details incomplete. Booking and saved-place actions are unavailable until catalog metadata is corrected.',
              'بيانات المحطة المنشورة غير مكتملة. لا تتوفر إجراءات الحجز أو الحفظ حتى تصحيح بيانات الكتالوج.')),
          ]),
        ));
      }
      final station=Station.fromJson(raw);
      return Card(key:ValueKey(row['key']),child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(station.name),if(widget.customer)favoriteButton(row),Text(tr('Platform catalog · ${station.source}','كتالوج المنصة · ${station.source}')),TextButton(onPressed:foreground&&ownSession()&&!busy&&!writing?guard((){if(rows.any((v)=>identical(v,row)))openCatalog(station);}):null,child:Text(tr('View station and booking options','عرض المحطة وخيارات الحجز')))])));
    }
    final location=Map<String,dynamic>.from(row['location'] as Map);
    final nameRaw=location['name'];
    final idRaw=location['id'];
    final safeName=nameRaw is String&&nameRaw.trim().isNotEmpty?nameRaw:
      idRaw is String&&idRaw.trim().isNotEmpty?idRaw:
      tr('Partner record #${row['record_id']}','سجل الشريك #${row['record_id']}');
    final providerRaw=row['provider_label'];
    final provider=providerRaw is String&&providerRaw.trim().isNotEmpty
      ?providerRaw:tr('Provider label unavailable (unverified)',
        'اسم مقدم الخدمة غير متاح (غير مؤكد)');
    final address=[location['address'],location['city'],location['country']]
      .whereType<String>().where((value)=>value.trim().isNotEmpty).join(' · ');
    final locationCanMap=PartnerMapPoint.fromPublishedLocation(location)!=null;
    return Card(key:ValueKey(row['key']),child:Padding(padding:const EdgeInsets.all(12),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(safeName),if(widget.customer&&trustedPartner(row))favoriteButton(row),Text(provider),Text(address.isNotEmpty?address:tr('Address details unavailable','تفاصيل العنوان غير متاحة')),if(!locationCanMap)Text(tr('Map coordinates unavailable; this record can only be inspected in the list.','إحداثيات الخريطة غير متاحة؛ يمكن عرض هذا السجل في القائمة فقط.')),Text(tr('Partner reported · availability unknown · no booking or remote control','تقرير شريك · التوفر غير معروف · لا حجز أو تحكم عن بُعد')),Text(tr('Reported source time: ${verifiedPartnerSourceTime(row)??'unknown'}','وقت المصدر المبلغ عنه: ${verifiedPartnerSourceTime(row)??'غير معروف'}')),Text(partnerCatalogAgeLabel(row,Localizations.localeOf(context).languageCode=='ar')),Text(partnerCatalogPolicyText(verifiedPartnerCatalogPolicy(row),Localizations.localeOf(context).languageCode=='ar')),Text(tr('Catalog timestamps do not verify physical availability. Search again to recheck.','أوقات الكتالوج لا تؤكد التوفر الفعلي. أعد البحث للتحقق.'))])));
  }
  Widget favoriteButton(Map<String,dynamic> row)=>TextButton(
    key:ValueKey('unified-favorite-${row['key']}'),
    onPressed:busy||writing||uncertain||!foreground||!ownSession()?null:guard((){favorite(row);}),
    child:Text(row['is_favorite']==true?tr('Remove saved place','إزالة المكان المحفوظ'):tr('Save place','حفظ المكان')),
  );
  Future<void> favorite(Map<String,dynamic> row)async{
    if(!mounted||!foreground||writing||busy||uncertain||!widget.customer||!ownSession()||!rows.any((v)=>identical(v,row)))return;
    if(row['kind']=='partner'&&!trustedPartner(row))return;
    if(row['kind']=='catalog'&&!completePublishedStation(
        Map<String,dynamic>.from(row['station'] as Map)))return;
    final api=widget.api;final turn=generation;final fence=ownSession;
    bool active()=>current(turn,api,fence);
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
  void dispose(){generation++;WidgetsBinding.instance.removeObserver(this);unawaited(sessionSubscription?.cancel());unawaited(mapEvents.close());query.dispose();for(final control in advanced.values){control.dispose();}super.dispose();}
}
