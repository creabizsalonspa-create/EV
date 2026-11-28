import 'package:flutter/material.dart';
import 'api.dart';
import 'partner_report_map.dart';
import 'vendor_forms.dart' show vendorDigits;

/// A deliberately read-only projection of the two independent discovery sources.
class FederatedNearbyResult {
  final int publishedTotal;
  final List<({String name, String evidence, String detail})> published;
  final List<({String name, String attribution, String license})>? licensed;
  final List<PartnerMapPoint> mapPoints;
  const FederatedNearbyResult(this.publishedTotal,this.published,this.licensed,this.mapPoints);

  static String label(dynamic value) => value is String
    ? String.fromCharCodes(value.trim().runes.take(250)) : '';

  factory FederatedNearbyResult.parse(dynamic raw) {
    Never invalid() => throw const FormatException('Unverified federated discovery');
    if(raw is! Map || raw['evidence']!='FEDERATED_GEOGRAPHIC_DISCOVERY_ONLY' ||
      raw['distance_basis']!='great_circle'||raw['complete_geographic_coverage']!=false||
      raw['live_status_verified']!=false||!raw.containsKey('combined_total')||raw['combined_total']!=null)invalid();
    final page=raw['published'],source=raw['licensed'];
    if(page is! Map||page['data'] is! List||page['current_page']!=1||
      page['per_page']!=25||page['total'] is! int||page['last_page'] is! int||
      page['last_page']<1||page['data'].length>25||page['total']<page['data'].length)invalid();
    final keys=<String>{};
    final points=<PartnerMapPoint>[];
    final published=<({String name,String evidence,String detail})>[];
    for(final row in page['data']){
      if(row is! Map||row['key'] is! String||!keys.add(row['key'] as String))invalid();
      if(row['kind']=='catalog'){
        final station=row['station'];
        if(station is! Map||station['id'] is! int||station['id']<1||
          row['key']!='catalog:${station['id']}'||
          !['SIMULATED','DISCOVERY_ONLY'].contains(station['evidence'])||
          (station['evidence']=='DISCOVERY_ONLY'&&station['reservable']==true))invalid();
        final point=PartnerMapPoint.fromPublishedCatalog(Map<String,dynamic>.from(station));
        if(point!=null)points.add(point);
        published.add((name:label(station['name']),evidence:'platform',detail:label(station['evidence'])));
      }else if(row['kind']=='partner'){
        final location=row['location'];
        if(row['record_id'] is! int||row['record_id']<1||row['key']!='partner:${row['record_id']}'||
          row['evidence']!='PARTNER_REPORTED_UNVERIFIED'||row['availability']!='unknown'||
          row['reservable']!=false||row['remote_control']!=false||location is! Map||
          location['availability']!='unknown'||location['reservable']!=false||location['remote_control']!=false)invalid();
        final point=PartnerMapPoint.fromPublishedLocation(Map<String,dynamic>.from(location),providerLabel:label(row['provider_label']));
        if(point!=null&&row['location_available']!=false)points.add(point);
        published.add((name:label(location['name']),evidence:'partner',
          detail:[row['provider_label'],location['address'],location['city'],location['country']].map(label).where((v)=>v.isNotEmpty).join(' · ')));
      }else{invalid();}
    }
    if(source is! Map)invalid();
    if(source['state']=='unavailable'){
      if(!source.containsKey('sample')||source['sample']!=null)invalid();
      return FederatedNearbyResult(page['total'] as int,published,null,List.unmodifiable(points));
    }
    final sample=source['sample'];
    if(source['state']!='observed'||sample is! Map||sample['provider']!='open_charge_map'||
      sample['evidence']!='OPEN_LICENSED_DISCOVERY_ONLY'||sample['availability']!='unknown'||
      sample['remote_control']!=false||sample['reservable']!=false||sample['billing_eligible']!=false||
      sample['license_policy']!='PER_RECORD_ATTRIBUTION_REQUIRED'||sample['results'] is! List||
      sample['results'].length>25||sample['count']!=sample['results'].length)invalid();
    final ids=<String>{};
    final licensed=<({String name,String attribution,String license})>[];
    for(final row in sample['results']){
      if(row is! Map||row['id'] is! String||!RegExp(r'^ocm:[1-9][0-9]*$').hasMatch(row['id'])||
        !ids.add(row['id'] as String)||row['source_status_evidence']!='OPEN_CHARGE_MAP_DISCOVERY_ONLY'||
        row['availability']!='unknown'||row['remote_control']!=false||row['reservable']!=false||
        row['billing_eligible']!=false||row['data_license'] is! String||row['data_license'].trim().isEmpty||
        row['data_license'].runes.length>240||row['attribution'] is! String||
        !row['attribution'].startsWith('Open Charge Map / ')||row['attribution'].runes.length>250||
        row['source_url']!='https://openchargemap.org/site/poi/details/${row['id'].substring(4)}')invalid();
      final point=PartnerMapPoint.fromLicensedLocation(Map<String,dynamic>.from(row));
      if(point!=null)points.add(point);
      licensed.add((name:label(row['name']),attribution:row['attribution'] as String,license:row['data_license'] as String));
    }
    return FederatedNearbyResult(page['total'] as int,published,licensed,List.unmodifiable(points));
  }
}

/// Mounted inside the authenticated unified discovery screen. Parent changes
/// its key on redaction; the supplied fence also retires any in-flight reply.
class FederatedNearbyPanel extends StatefulWidget {
  final EvApi api;
  final bool Function() currentSession;
  final ValueChanged<List<PartnerMapPoint>>? onMap;
  const FederatedNearbyPanel({super.key,required this.api,required this.currentSession,this.onMap});
  @override
  State<FederatedNearbyPanel> createState()=>_FederatedNearbyPanelState();
}

class _FederatedNearbyPanelState extends State<FederatedNearbyPanel>{
  final fields={for(final key in ['latitude','longitude','radius_km'])key:TextEditingController()};
  String country='';
  bool consent=false,busy=false;
  String? error;
  FederatedNearbyResult? result;
  int generation=0;
  String tr(String en,String ar)=>Localizations.localeOf(context).languageCode=='ar'?ar:en;
  Future<void> search() async {
    if(!mounted||busy||!widget.currentSession())return;
    final body=<String,dynamic>{'consent':true};
    final bounds={'latitude':(-90,90),'longitude':(-180,180),'radius_km':(1,200)};
    for(final field in fields.entries){
      final value=vendorDigits(field.value.text.trim());
      final number=double.tryParse(value);
      final range=bounds[field.key]!;
      if(!RegExp(r'^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$').hasMatch(value)||
          number==null||!number.isFinite||number<range.$1||number>range.$2){
        setState((){result=null;error=tr('Enter valid coordinates and a radius of 1–200 km.','أدخل إحداثيات صحيحة ونطاقاً من ١ إلى ٢٠٠ كم.');});return;
      }
      body[field.key]=number;
    }
    if(!consent){setState((){result=null;error=tr('Explicit consent is required.','الموافقة الصريحة مطلوبة.');});return;}
    if(['AE','US','CA'].contains(country))body['country']=country;
    final turn=++generation,api=widget.api,fence=widget.currentSession;
    bool active()=>mounted&&generation==turn&&identical(widget.api,api)&&fence()&&widget.currentSession();
    setState((){consent=false;busy=true;error=null;result=null;});
    try{
      final raw=await api.request('discovery/federated-nearby',method:'POST',body:body);
      if(!active())return;
      final parsed=FederatedNearbyResult.parse(raw);
      if(active())setState(()=>result=parsed);
    }catch(_){
      if(active())setState(()=>error=tr('Nearby search failed. Confirm consent and submit again.','تعذر البحث القريب. أكد الموافقة وأعد الإرسال.'));
    }finally{if(active())setState(()=>busy=false);}
  }
  @override
  Widget build(BuildContext context){
    if(!widget.currentSession())return const SizedBox.shrink();
    final data=result;
    return ExpansionTile(
      key:const Key('federated-nearby-panel'),
      title:Text(tr('Nearby across published and licensed sources','المواقع القريبة عبر المصادر المنشورة والمرخصة')),
      children:[
        Text(tr('Enter coordinates manually. Only with explicit consent is this geographic query sent to Open Charge Map. Results are separate, read-only and not complete coverage or verified live availability.',
          'أدخل الإحداثيات يدوياً. لا يرسل هذا البحث الجغرافي إلى Open Charge Map إلا بموافقتك الصريحة. النتائج منفصلة وللقراءة فقط ولا تؤكد التغطية الكاملة أو التوفر المباشر.')),
        for(final field in [('latitude','Latitude','خط العرض'),('longitude','Longitude','خط الطول'),('radius_km','Radius (1–200 km)','نطاق البحث (١–٢٠٠ كم)')])
          TextField(key:Key('federated-${field.$1}'),controller:fields[field.$1],enabled:!busy,maxLength:24,
            keyboardType:const TextInputType.numberWithOptions(decimal:true,signed:true),
            decoration:InputDecoration(labelText:tr(field.$2,field.$3))),
        DropdownButton<String>(key:const Key('federated-country'),value:country,isExpanded:true,
          items:[for(final choice in [('','All available markets','كل الأسواق المتاحة'),('AE','United Arab Emirates','الإمارات العربية المتحدة'),('US','United States','الولايات المتحدة'),('CA','Canada','كندا')])
            DropdownMenuItem(value:choice.$1,child:Text(tr(choice.$2,choice.$3)))],
          onChanged:busy?null:(value)=>setState(()=>country=value??'')),
        CheckboxListTile(key:const Key('federated-consent'),value:consent,
          onChanged:busy?null:(value)=>setState(()=>consent=value==true),
          title:Text(tr('I agree to share these coordinates and radius with Open Charge Map for this search.','أوافق على مشاركة هذه الإحداثيات والنطاق مع Open Charge Map لهذا البحث.'))),
        FilledButton(key:const Key('federated-submit'),onPressed:busy?null:search,
          child:Text(tr('Search nearby sources','البحث في المصادر القريبة'))),
        if(busy)const LinearProgressIndicator(),
        if(error!=null)Text(error!),
        if(data!=null)...[
          Text(tr('Published first page · ${data.publishedTotal} published matches','الصفحة الأولى المنشورة · ${data.publishedTotal} نتيجة منشورة')),
          for(final row in data.published)ListTile(title:Text(row.name.isEmpty?tr('Unnamed location','موقع بلا اسم'):row.name),
            subtitle:Text((row.evidence=='platform'?tr('Platform catalog','كتالوج المنصة'):tr('Partner reported · availability unknown','تقرير شريك · التوفر غير معروف'))+' · '+row.detail)),
          Text(tr('Licensed sample (separate)','عينة مرخصة (منفصلة)')),
          if(data.licensed==null)Text(tr('Licensed source unavailable; this is not zero matching locations.','المصدر المرخص غير متاح؛ وهذا لا يعني عدم وجود مواقع مطابقة.'))
          else...[
            Text(tr('${data.licensed!.length} licensed reports (maximum 25; not full coverage)','${data.licensed!.length} تقريراً مرخصاً (٢٥ كحد أقصى؛ ليست تغطية كاملة)')),
            for(final row in data.licensed!)ListTile(title:Text(row.name.isEmpty?tr('Unnamed location','موقع بلا اسم'):row.name),
              subtitle:Text(tr('Crowd reported · availability unknown','تقرير مجتمع · التوفر غير معروف')+'\n${row.attribution} · ${row.license}')),
          ],
          if(widget.onMap!=null&&data.mapPoints.isNotEmpty)
            OutlinedButton(key:const Key('federated-map-open'),
              onPressed:busy?null:(){if(mounted&&widget.currentSession()&&identical(result,data))widget.onMap?.call(data.mapPoints);},
              child:Text(tr('Map loaded nearby results','عرض النتائج القريبة المحملة على الخريطة'))),
          Text(tr('Discovery only. Open the regular catalog to inspect booking options.','للاكتشاف فقط. افتح الكتالوج العادي للاطلاع على خيارات الحجز.')),
        ],
      ],
    );
  }
  @override
  void dispose(){generation++;for(final controller in fields.values){controller.dispose();}super.dispose();}
}
