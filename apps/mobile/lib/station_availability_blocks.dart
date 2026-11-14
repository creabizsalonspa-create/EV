import 'package:flutter/material.dart';
import 'api.dart';

class StationAvailabilityBlocksView extends StatefulWidget {
  final EvApi api;
  final int stationId;
  const StationAvailabilityBlocksView({super.key, required this.api, required this.stationId});
  @override
  State<StationAvailabilityBlocksView> createState() => _StationBlocksState();
}
class _StationBlocksState extends State<StationAvailabilityBlocksView> with WidgetsBindingObserver {
  final fields = <String, TextEditingController>{for (final key in ['starts_at','ends_at','reason_en','reason_ar']) key:TextEditingController()};
  List<Map<String,dynamic>> rows = [];
  Map<String,dynamic>? pending;
  late bool Function() owner;
  bool busy = false, background = false;
  int generation = 0;
  String? message;
  String t(String en,String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get path => 'stations/${widget.stationId}/availability-blocks';
  bool current(int ticket) => mounted && owner() && !background && generation == ticket;
  @override
  void initState() { super.initState(); owner=widget.api.sessionFence(); WidgetsBinding.instance.addObserver(this); act(read); }
  Future<void> read(int ticket) async {
    final data=await widget.api.request(path);
    if (!current(ticket)) return;
    if (data is! Map || data['station_id'] != widget.stationId || data['data'] is! List) throw const ApiException(502,'Invalid block response');
    setState(() => rows=(data['data'] as List).map((row)=>Map<String,dynamic>.from(row as Map)).toList());
  }
  Future<void> act(Future<void> Function(int) task) async {
    if (busy || background || !owner()) return;
    final ticket=generation;setState(() {busy=true;message=null;});
    try {await task(ticket);} catch(error) {
      if (current(ticket)) setState(() { if (error is ApiException && error.status==422) pending=null;
        message=t('Action not confirmed. Refresh status or retry the same request.','لم يتأكد الإجراء. حدّث الحالة أو أعد محاولة الطلب نفسه.'); });
    } finally {if(current(ticket))setState(()=>busy=false);}
  }
  Future<void> publish() => act((ticket) async {
    pending ??= {'idempotency_key':operationKey(),for(final entry in fields.entries)entry.key:entry.value.text.trim()};
    final result=await widget.api.request(path,method:'POST',body:pending);
    if(!current(ticket))return;
    if(result is! Map || result['station_id']!=widget.stationId || result['id'] is! String)throw const ApiException(502,'Invalid block receipt');
    pending=null;for(final controller in fields.values)controller.clear();await read(ticket);
  });
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if(state==AppLifecycleState.resumed){if(background){setState(()=>background=false);act(read);}}
    else{generation++;for(final controller in fields.values)controller.clear();rows=[];if(mounted)setState((){background=true;busy=false;});}
  }
  @override
  Widget build(BuildContext context)=>Scaffold(appBar:AppBar(title:Text(t('Station availability blocks','فترات حجب المحطة'))),
    body:background||!owner()?const SizedBox.shrink():ListView(padding:const EdgeInsets.all(16),children:[
      Text(t('Station-wide operator policy. Blocks prevent new bookings and starts; active charging can finish. Times are UTC. No physical command is sent.','سياسة المشغل للمحطة كاملة. يمنع الحجب الحجوزات وبدء الشحن الجديدة ويمكن إكمال الشحن الجاري. الأوقات UTC ولا ترسل أوامر فعلية.')),
      if(busy)const LinearProgressIndicator(),if(message!=null)Text(message!),
      OutlinedButton(onPressed:busy?null:()=>act(read),child:Text(t('Refresh station blocks','تحديث فترات الحجب'))),
      for(final row in rows)...[
        Text('${row['starts_at']} → ${row['ends_at']} (UTC)',textDirection:TextDirection.ltr),
        Text('${Localizations.localeOf(context).languageCode=='ar'?row['reason_ar']:row['reason_en']}'),
        OutlinedButton(onPressed:busy?null:()=>act((ticket)async{await widget.api.request('$path/${row['id']}/cancel',method:'POST',body:{'revision':row['revision']});if(current(ticket))await read(ticket);}),child:Text(t('Cancel this block','إلغاء فترة الحجب'))),
      ],
      for(final entry in fields.entries)TextField(key:Key('block-${entry.key}'),controller:entry.value,enabled:!busy&&pending==null,
        maxLength:entry.key.startsWith('reason')?300:20,decoration:InputDecoration(labelText:switch(entry.key){
          'starts_at'=>t('Start UTC: YYYY-MM-DDTHH:mm:ssZ','البداية UTC: YYYY-MM-DDTHH:mm:ssZ'),
          'ends_at'=>t('End UTC: YYYY-MM-DDTHH:mm:ssZ','النهاية UTC: YYYY-MM-DDTHH:mm:ssZ'),
          'reason_en'=>t('Public reason (English)','السبب المنشور بالإنجليزية'),_=>t('Public reason (Arabic)','السبب المنشور بالعربية')})),
      FilledButton(onPressed:busy?null:publish,child:Text(pending==null?t('Publish station block','نشر حجب المحطة'):t('Retry same block request','إعادة محاولة طلب الحجب نفسه'))),
    ]));
  @override
  void dispose(){generation++;WidgetsBinding.instance.removeObserver(this);for(final controller in fields.values)controller.dispose();super.dispose();}
}
