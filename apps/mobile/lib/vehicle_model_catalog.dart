import 'package:flutter/material.dart';
import 'api.dart';

String modelLabel(Map<String, dynamic> model) {
  return [
    model['make'], model['model'], model['variant'], model['model_year'],
  ].where((value) => value != null && value.toString().trim().isNotEmpty)
      .join(' ') + ' · ' + model['connector_type'].toString();
}

String modelText(BuildContext context, String en, String ar) =>
    Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

/// Catalog assertions are Admin-curated, not independently manufacturer-verified.
class VehicleModelAssociationView extends StatefulWidget {
  final EvApi api;
  final int vehicleId;
  final String vehicleConnector;
  final VoidCallback onExpired;
  const VehicleModelAssociationView({
    super.key, required this.api, required this.vehicleId,
    required this.vehicleConnector, required this.onExpired,
  });
  @override
  State<VehicleModelAssociationView> createState() => _VehicleModelAssociationState();
}

class _VehicleModelAssociationState extends State<VehicleModelAssociationView> {
  final search = TextEditingController();
  Map<String, dynamic>? linked;
  List<Map<String, dynamic>> choices = [];
  int page = 1, lastPage = 1, generation = 0;
  int? selectedId;
  bool busy = false, saving = false;
  String? error, success;
  String t(String en, String ar) => modelText(context, en, ar);

  @override
  void initState() {super.initState(); reload();}

  @override
  void didUpdateWidget(covariant VehicleModelAssociationView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.vehicleId == widget.vehicleId &&
        oldWidget.vehicleConnector == widget.vehicleConnector &&
        identical(oldWidget.api, widget.api)) return;
    // Do not retain a previous vehicle's model link, search, compatible
    // choices, or pending save when Flutter reuses this State.
    generation++;
    search.clear();
    linked = null;choices = [];selectedId = null;
    page = lastPage = 1;
    busy = saving = false;
    error = success = null;
    reload();
  }

  @override
  void dispose() {generation++;search.dispose();super.dispose();}

  void failed(Object e) {
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(() {
      error = e is ApiException && e.status == 409
          ? t('Model association changed. Refresh before saving.', 'تغير ارتباط الطراز. حدّث قبل الحفظ.')
          : e.toString();
    });
  }

  Future<void> reload([int target = 1]) async {
    final ticket = ++generation, fence = widget.api.sessionFence();
    setState(() {busy=true;error=success=null;});
    try {
      final current = await widget.api.request('vehicles/' +
          widget.vehicleId.toString() + '/model');
      if (!mounted || ticket != generation || !fence()) return;
      // Filter at the API before pagination. Client-side filtering alone can
      // hide all compatible models behind pages of other connector types.
      final raw = await widget.api.request('vehicle-models', query: {
        'page': target.toString(),
        'connector_type': widget.vehicleConnector,
        if (search.text.trim().isNotEmpty) 'q': search.text.trim(),
      });
      if (!mounted || ticket != generation || !fence()) return;
      final listing = ApiPage<Map<String, dynamic>>.fromJson(raw, (v) => v);
      final models = listing.items.where((m) =>
          m['published'] == true &&
          m['connector_type'] == widget.vehicleConnector).toList();
      setState(() {
        linked = Map<String, dynamic>.from(current as Map);
        page = listing.current;lastPage=listing.last;
        choices = models;
        // Keep the currently linked compatible choice selected on refresh.
        // An existing link can be on a different filtered page (or archived).
        final linkedId = current['model_id'];
        selectedId = models.isEmpty
            ? null
            : models.any((m) => m['id'] == linkedId)
                ? linkedId as int
                : models.first['id'] as int;
      });
    } catch (e) {
      if (mounted && ticket == generation) failed(e);
    } finally {
      if (mounted && ticket == generation) setState(() => busy=false);
    }
  }

  Future<void> save(int? modelId) async {
    if (busy || saving || linked == null) return;
    final ticket = generation, fence = widget.api.sessionFence();
    final expectedId = linked!['model_id'];
    setState(() {saving=true;error=success=null;});
    try {
      final result = await widget.api.request(
        'vehicles/' + widget.vehicleId.toString() + '/model',
        method:'PUT',
        body:{'model_id':modelId, 'expected_model_id':expectedId},
      );
      if (!mounted || ticket != generation || !fence()) return;
      setState(() {
        linked = Map<String,dynamic>.from(result as Map);
        success = t('Vehicle model association saved.', 'تم حفظ ارتباط طراز المركبة.');
      });
    } catch (e) {
      if (mounted && ticket == generation) failed(e);
    } finally {
      if (mounted && ticket == generation) setState(() => saving=false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final model = linked?['model'];
    final linkedModel = model is Map ? Map<String,dynamic>.from(model) : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Vehicle model catalog', 'كتالوج طراز المركبة'))),
      body: SafeArea(child: ListView(padding:const EdgeInsets.all(16),children:[
        Text(t(
          'Catalog records are curated by an Admin and not manufacturer-verified. Linking a model does not change the vehicle connector or live charging permissions.',
          'تدير الإدارة سجلات الكتالوج دون تحقق من الشركة المصنعة. ربط الطراز لا يغير موصل المركبة أو صلاحيات الشحن المباشر.',
        )),
        Text(t('Vehicle connector: ', 'موصل المركبة: ')+widget.vehicleConnector),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Semantics(liveRegion:true,
          child:Text(error!,key:const Key('model-link-error'))),
        if (success != null) Semantics(liveRegion:true,
          child:Text(success!,key:const Key('model-link-success'))),
        if (linked != null) ...[
          Text(linkedModel == null
              ? t('No catalog model linked.', 'لا يوجد طراز مرتبط.')
              : t('Linked: ', 'مرتبط: ') + modelLabel(linkedModel) +
                (linkedModel['published'] == false
                    ? t(' (archived)', ' (مؤرشف)') : '')),
          TextField(
            key:const Key('model-search'),
            controller:search,maxLength:100,
            decoration:InputDecoration(
              labelText:t('Search make or model', 'البحث عن العلامة أو الطراز'),
              suffixIcon:IconButton(icon:const Icon(Icons.search),
                onPressed:busy || saving ? null : () => reload(1)),
            ),
            onSubmitted:(_) => reload(1),
          ),
          if (choices.isEmpty)
            Text(t('No compatible published models on this page.',
                   'لا توجد طرازات منشورة متوافقة في هذه الصفحة.')),
          if (choices.isNotEmpty)
            DropdownButtonFormField<int>(
              key:ValueKey('model-options-$page-$selectedId'),
              initialValue:selectedId,
              decoration:InputDecoration(labelText:t('Select model', 'اختر الطراز')),
              items:choices.map((m)=>DropdownMenuItem<int>(
                value:m['id'] as int,child:Text(modelLabel(m),
                  overflow:TextOverflow.ellipsis),
              )).toList(),
              onChanged:busy || saving ? null : (v)=>setState(()=>selectedId=v),
            ),
          if (selectedId != null)
            for (final choice in choices)
              if (choice['id'] == selectedId && choice['source_url'] is String)
                SelectableText(
                  t('Admin-provided reference: ', 'المرجع المقدم من الإدارة: ') +
                  choice['source_url'].toString(),
                ),
          FilledButton(
            key:const Key('model-link-save'),
            onPressed:busy || saving || selectedId==null
              ? null : ()=>save(selectedId),
            child:Text(t('Link selected model', 'ربط الطراز المحدد')),
          ),
          TextButton(
            key:const Key('model-link-clear'),
            onPressed:busy || saving || linked?['model_id']==null
              ? null : ()=>save(null),
            child:Text(t('Remove model link', 'إزالة ارتباط الطراز')),
          ),
          Row(children:[
            TextButton(onPressed:busy||saving||page<=1 ? null : ()=>reload(page-1),
              child:Text(t('Previous', 'السابق'))),
            Text('$page / $lastPage'),
            TextButton(onPressed:busy||saving||page>=lastPage ? null : ()=>reload(page+1),
              child:Text(t('Next', 'التالي'))),
          ]),
        ],
        TextButton.icon(
          onPressed:busy||saving ? null : ()=>reload(page),
          icon:const Icon(Icons.refresh),label:Text(t('Refresh catalog', 'تحديث الكتالوج')),
        ),
      ])),
    );
  }
}

/// Admin curation, not a statement of manufacturer-verified EV specifications.
class AdminVehicleModelCatalogView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const AdminVehicleModelCatalogView({
    super.key,required this.api,required this.onExpired,
  });
  @override
  State<AdminVehicleModelCatalogView> createState()=>_AdminVehicleModelCatalogState();
}

class _AdminVehicleModelCatalogState extends State<AdminVehicleModelCatalogView> {
  final make=TextEditingController(), model=TextEditingController();
  final variant=TextEditingController(), year=TextEditingController();
  final source=TextEditingController(), query=TextEditingController();
  List<Map<String,dynamic>> rows=[];
  Map<String,dynamic>? editing;
  String connector='CCS2';
  bool published=false, loading=false, saving=false;
  int page=1, lastPage=1, generation=0;
  String? error,success;
  String t(String en,String ar)=>modelText(context,en,ar);

  @override
  void initState() {super.initState();load();}
  @override
  void dispose() {
    generation++;
    for (final c in [make,model,variant,year,source,query]) {c.dispose();}
    super.dispose();
  }

  void failed(Object e) {
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(()=>error=e is ApiException&&e.status==409
      ? t('Record changed. Refresh to edit again.', 'تغير السجل؛ حدث قبل التعديل.')
      : e.toString());
  }

  void edit(Map<String,dynamic>? entry) {
    setState(() {
      editing=entry;
      make.text=entry?['make']?.toString()??'';
      model.text=entry?['model']?.toString()??'';
      variant.text=entry?['variant']?.toString()??'';
      year.text=entry?['model_year']?.toString()??'';
      source.text=entry?['source_url']?.toString()??'';
      connector=entry?['connector_type']?.toString()??'CCS2';
      published=entry?['published']==true;
      error=success=null;
    });
  }

  Future<void> load([int target=1]) async {
    final ticket=++generation,fence=widget.api.sessionFence();
    setState(() {loading=true;error=null;});
    try {
      final raw=await widget.api.request('vehicle-models',query:{
        'page':target.toString(),'include_archived':'1',
        if(query.text.trim().isNotEmpty)'q':query.text.trim(),
      });
      if (!mounted||ticket!=generation||!fence()) return;
      final data=ApiPage<Map<String,dynamic>>.fromJson(raw,(v)=>v);
      setState(() {page=data.current;lastPage=data.last;rows=data.items;});
    } catch (e) {
      if(mounted&&ticket==generation)failed(e);
    } finally {
      if(mounted&&ticket==generation)setState(()=>loading=false);
    }
  }

  Future<void> publish(Map<String,dynamic> entry) async {
    if(saving||loading)return;
    final ticket=generation,fence=widget.api.sessionFence();
    setState(() {saving=true;error=success=null;});
    try {
      await widget.api.request('vehicle-models/'+entry['id'].toString(),
        method:'PUT',body:{
        for(final key in ['make','model','variant','model_year','connector_type','source_url'])
          key:entry[key],
        'revision':entry['revision'],'published':entry['published']!=true,
      });
      if(!mounted||ticket!=generation||!fence())return;
      setState(()=>saving=false);
      await load(page);
    }catch(e) {
      if(mounted&&ticket==generation)failed(e);
    }finally {
      if(mounted&&ticket==generation)setState(()=>saving=false);
    }
  }

  Future<void> save() async {
    if(saving||loading)return;
    final y=year.text.trim().isEmpty?null:int.tryParse(year.text.trim());
    if(make.text.trim().length<2||model.text.trim().isEmpty||
       !source.text.trim().startsWith('https://')||
       (year.text.trim().isNotEmpty&&(y==null||y<1990||y>2100))) {
      setState(()=>error=t('Check required fields, HTTPS source URL and year.',
        'تحقق من الحقول المطلوبة والرابط HTTPS وسنة الطراز.'));
      return;
    }
    final body=<String,dynamic>{
      'make':make.text.trim(),'model':model.text.trim(),
      'variant':variant.text.trim().isEmpty?null:variant.text.trim(),
      'model_year':y,'connector_type':connector,
      'source_url':source.text.trim(),'published':published,
      if(editing!=null)'revision':editing!['revision'],
    };
    final ticket=generation,fence=widget.api.sessionFence();
    setState(() {saving=true;error=success=null;});
    try {
      await widget.api.request(editing==null?'vehicle-models':
         'vehicle-models/'+editing!['id'].toString(),
         method:editing==null?'POST':'PUT',body:body);
      if(!mounted||ticket!=generation||!fence())return;
      edit(null);
      setState(()=>saving=false);
      await load(1);
      if(mounted)setState(()=>success=t('Catalog record saved.','تم حفظ سجل الكتالوج.'));
    }catch(e) {
      if(mounted&&ticket==generation)failed(e);
    }finally {
      if(mounted&&ticket==generation)setState(()=>saving=false);
    }
  }

  Widget field(String name,String en,String ar,TextEditingController controller,
      {TextInputType keyboard=TextInputType.text,int max=100})=>
    TextFormField(key:Key('admin-model-'+name),controller:controller,
      maxLength:max,keyboardType:keyboard,
      decoration:InputDecoration(labelText:t(en,ar)));

  @override
  Widget build(BuildContext context)=>Scaffold(
    appBar:AppBar(title:Text(t('Curated vehicle catalog','كتالوج المركبات المنسق'))),
    body:SafeArea(child:ListView(padding:const EdgeInsets.all(16),children:[
      Text(t('Admin-sourced entries require an HTTPS reference. Publishing does not certify vehicle specifications.',
        'تتطلب السجلات رابط مصدر HTTPS؛ النشر لا يصادق على مواصفات المركبات.')),
      if(loading)const LinearProgressIndicator(),
      if(error!=null)Semantics(liveRegion:true,
        child:Text(error!,key:const Key('admin-model-error'))),
      if(success!=null)Semantics(liveRegion:true,
        child:Text(success!,key:const Key('admin-model-success'))),
      TextField(controller:query,maxLength:100,
        decoration:InputDecoration(labelText:t('Search catalog','بحث في الكتالوج'),
          suffixIcon:IconButton(icon:const Icon(Icons.search),
            onPressed:loading||saving?null:()=>load(1))),
        onSubmitted:(_)=>load(1)),
      Text(editing==null?t('Create catalog entry','إنشاء سجل في الكتالوج')
        :t('Edit catalog entry','تعديل سجل الكتالوج'),
        style:Theme.of(context).textTheme.titleMedium),
      field('make','Make / brand','العلامة التجارية',make,max:80),
      field('model','Model','الطراز',model),
      field('variant','Variant (optional)','الفئة (اختياري)',variant),
      field('year','Model year (optional)','سنة الطراز (اختياري)',year,
        keyboard:TextInputType.number,max:4),
      field('source','HTTPS source URL','رابط المصدر HTTPS',source,max:500),
      DropdownButtonFormField<String>(
        key:ValueKey('admin-model-connector-'+connector),initialValue:connector,
        decoration:InputDecoration(labelText:t('Catalog connector claim','الموصل بحسب الكتالوج')),
        items:['CCS2','Type2','CHAdeMO'].map((c)=>DropdownMenuItem(
          value:c,child:Text(c))).toList(),
        onChanged:saving?null:(v)=>setState(()=>connector=v??'CCS2'),
      ),
      CheckboxListTile(value:published,
        title:Text(t('Published for customers','منشور للعملاء')),
        onChanged:saving?null:(v)=>setState(()=>published=v==true)),
      FilledButton(key:const Key('admin-model-save'),onPressed:loading||saving?null:save,
        child:Text(t('Save catalog entry','حفظ سجل الكتالوج'))),
      if(editing!=null)TextButton(onPressed:saving?null:()=>edit(null),
        child:Text(t('Cancel edit','إلغاء التعديل'))),
      const Divider(),
      Text(t('Catalog records','سجلات الكتالوج'),style:Theme.of(context).textTheme.titleMedium),
      if(rows.isEmpty&&!loading)Text(t('No catalog entries yet.','لا توجد سجلات بعد.')),
      for(final entry in rows)
        Card(child:Column(children:[
          ListTile(title:Text(modelLabel(entry)),
            subtitle:Text(entry['published']==true?t('Published','منشور'):t('Archived','مؤرشف'))),
          Wrap(children:[
            TextButton(onPressed:loading||saving?null:()=>edit(entry),
              child:Text(t('Edit','تعديل'))),
            TextButton(onPressed:loading||saving?null:()=>publish(entry),
              child:Text(entry['published']==true?t('Archive','أرشفة'):t('Publish','نشر'))),
          ]),
        ])),
      Row(children:[
        TextButton(onPressed:loading||saving||page<=1?null:()=>load(page-1),
          child:Text(t('Previous','السابق'))),
        Text('$page / $lastPage'),
        TextButton(onPressed:loading||saving||page>=lastPage?null:()=>load(page+1),
          child:Text(t('Next','التالي'))),
      ]),
    ])),
  );
}
