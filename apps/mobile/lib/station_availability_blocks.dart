import 'dart:async';

import 'package:flutter/material.dart';
import 'api.dart';
import 'reservation_times.dart';

class StationAvailabilityBlocksView extends StatefulWidget {
  final EvApi api;
  final int stationId;
  final VoidCallback? onExpired;
  const StationAvailabilityBlocksView({
    super.key,
    required this.api,
    required this.stationId,
    this.onExpired,
  });
  @override
  State<StationAvailabilityBlocksView> createState() => _StationBlocksState();
}
class _StationBlocksState extends State<StationAvailabilityBlocksView> with WidgetsBindingObserver {
  final fields = <String, TextEditingController>{for (final key in ['starts_at','ends_at','reason_en','reason_ar']) key:TextEditingController()};
  List<Map<String,dynamic>> rows = [];
  Map<String,dynamic>? pending;
  late bool Function() owner;
  StreamSubscription<int>? sessionChanges;
  bool busy = false, background = false;
  int generation = 0;
  String? message;
  String t(String en,String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get path => 'stations/${widget.stationId}/availability-blocks';
  bool current(int ticket) => mounted && owner() && !background && generation == ticket;
  @override
  void initState() {
    super.initState();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    background = lifecycle != null && lifecycle != AppLifecycleState.resumed;
    bindSession();
    WidgetsBinding.instance.addObserver(this);
    if (!background) act(read);
  }
  void bindSession() {
    sessionChanges?.cancel();
    final source = widget.api;
    owner = source.sessionFence();
    sessionChanges = source.sessionEvents.listen((_) {
      if (mounted && identical(source, widget.api)) setState(() {
        generation++; pending = null; rows = []; busy = false; message = null;
        for (final field in fields.values) { field.clear(); }
      });
    });
  }
  @override
  void didUpdateWidget(covariant StationAvailabilityBlocksView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.stationId != widget.stationId) {
      // Changing the station or authenticated API revokes any retained
      // idempotency identity and private station policy snapshot.
      generation++;
      if (oldWidget.api != widget.api) bindSession();
      pending = null;
      rows = [];
      busy = false;
      message = null;
      for (final field in fields.values) { field.clear(); }
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && owner() && !background) act(read);
      });
    }
  }
  bool validReceipt(dynamic row, bool cancelled) =>
      row is Map &&
      row['station_id'] == widget.stationId &&
      row['id'] is String &&
      RegExp(r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$').hasMatch(row['id'] as String) &&
      validInterval(row['starts_at'], row['ends_at']) &&
      row['reason_en'] is String &&
      row['reason_ar'] is String &&
      row['revision'] is int &&
      row['revision'] > 0 &&
      row['scope'] == 'STATION_WIDE_OPERATOR_POLICY' &&
      row['cancelled'] == cancelled &&
      row['physical_availability_verified'] == false;

  DateTime? sourceInstant(dynamic raw) {
    if (raw is! String) return null;
    final match = RegExp(
      r'^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$',
    ).firstMatch(raw);
    if (match == null || match.end != raw.length) return null;
    final p = [for (var i = 1; i <= 6; i++) int.parse(match.group(i)!)];
    final calendar = DateTime.utc(p[0], p[1], p[2], p[3], p[4], p[5]);
    if (calendar.year != p[0] || calendar.month != p[1] ||
        calendar.day != p[2] || calendar.hour != p[3] ||
        calendar.minute != p[4] || calendar.second != p[5]) return null;
    final offset = match.group(7)!;
    if (offset != 'Z') {
      final hours = int.parse(offset.substring(1, 3));
      final minutes = int.parse(offset.substring(4, 6));
      if (hours > 14 || minutes > 59 || (hours == 14 && minutes != 0)) return null;
    }
    return DateTime.tryParse(raw)?.toUtc();
  }
  bool validInterval(dynamic a, dynamic b) {
    final first = sourceInstant(a), second = sourceInstant(b);
    return first != null && second != null && second.isAfter(first);
  }
  bool sameInstant(dynamic a, dynamic b) {
    final first = sourceInstant(a), second = sourceInstant(b);
    return first != null && second != null && first.isAtSameMomentAs(second);
  }

  Future<void> read(int ticket) async {
    final data=await widget.api.request(path);
    if (!current(ticket)) return;
    if (data is! Map ||
        data['station_id'] != widget.stationId ||
        data['data'] is! List ||
        (data['data'] as List).length > 50) {
      throw const ApiException(502, 'Invalid block response');
    }
    final typed = <Map<String, dynamic>>[];
    final ids = <String>{};
    for (final row in data['data'] as List) {
      if (!validReceipt(row, false) || !ids.add(row['id'] as String)) {
        throw const ApiException(502, 'Invalid station block source');
      }
      typed.add(Map<String, dynamic>.from(row as Map));
    }
    setState(() => rows = typed);
  }
  Future<void> act(Future<void> Function(int) task) async {
    if (busy || background || !owner()) return;
    final ticket=++generation;setState(() {busy=true;message=null;});
    try {await task(ticket);} catch(error) {
      if (!current(ticket)) return;
      if (error is ApiException && [401, 419].contains(error.status)) {
        generation++;
        pending = null;
        for (final field in fields.values) { field.clear(); }
        setState(() { rows = []; busy = false; message = null; });
        widget.onExpired?.call();
        return;
      }
      setState(() {
        // A failed authoritative GET or mutation invalidates the displayed
        // station policy; never keep a stale cancel grant on the screen.
        rows = [];
        if (error is ApiException && error.status == 422) pending = null;
        message = t('Action not confirmed. Refresh status or retry the same request.','لم يتأكد الإجراء. حدّث الحالة أو أعد محاولة الطلب نفسه.');
      });
    } finally {if(current(ticket))setState(()=>busy=false);}
  }
  Future<void> publish() => act((ticket) async {
    pending ??= {'idempotency_key':operationKey(),for(final entry in fields.entries)entry.key:entry.value.text.trim()};
    final result=await widget.api.request(path,method:'POST',body:pending);
    if(!current(ticket))return;
    if (result is! Map || !validReceipt(result, result['cancelled'] == true) ||
        result['revision'] != (result['cancelled'] == true ? 2 : 1) ||
        !sameInstant(result['starts_at'], pending!['starts_at']) ||
        !sameInstant(result['ends_at'], pending!['ends_at']) ||
        result['reason_en'] != pending!['reason_en'] ||
        result['reason_ar'] != pending!['reason_ar']) {
      throw const ApiException(502, 'Invalid block receipt');
    }
    pending=null;for(final controller in fields.values)controller.clear();await read(ticket);
  });
  Future<void> cancel(Map<String, dynamic> row) => act((ticket) async {
    if (!rows.any((item) => identical(item, row))) return;
    final result = await widget.api.request('$path/${row['id']}/cancel',
        method: 'POST', body: {'revision': row['revision']});
    if (!current(ticket)) return;
    if (result is! Map || !validReceipt(result, true) ||
        result['id'] != row['id'] ||
        result['revision'] != (row['revision'] as int) + 1 ||
        result['starts_at'] != row['starts_at'] ||
        result['ends_at'] != row['ends_at'] ||
        result['reason_en'] != row['reason_en'] ||
        result['reason_ar'] != row['reason_ar']) {
      throw const ApiException(502, 'Invalid cancellation receipt');
    }
    await read(ticket);
  });

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if(state==AppLifecycleState.resumed){if(background){setState(()=>background=false);act(read);}}
    else{generation++;for(final controller in fields.values)controller.clear();rows=[];if(mounted)setState((){background=true;busy=false;});}
  }
  @override
  Widget build(BuildContext context) {
    final viewEpoch = generation;
    return Scaffold(appBar:AppBar(title:Text(t('Station availability blocks','فترات حجب المحطة'))),
    body:background||!owner()?const SizedBox.shrink():ListView(padding:const EdgeInsets.all(16),children:[
      Text(t('Station-wide operator policy. Blocks prevent new bookings and starts; active charging can finish. Times are UTC. No physical command is sent.','سياسة المشغل للمحطة كاملة. يمنع الحجب الحجوزات وبدء الشحن الجديدة ويمكن إكمال الشحن الجاري. الأوقات UTC ولا ترسل أوامر فعلية.')),
      if(busy)const LinearProgressIndicator(),if(message!=null)Text(message!),
      OutlinedButton(onPressed:busy?null:(){if(current(viewEpoch))act(read);},child:Text(t('Refresh station blocks','تحديث فترات الحجب'))),
      for(final row in rows)...[
        Text('${serverEventTime(context, row['starts_at'])} → ${serverEventTime(context, row['ends_at'])}'),
        Text('${Localizations.localeOf(context).languageCode=='ar'?row['reason_ar']:row['reason_en']}'),
        OutlinedButton(onPressed:busy?null:(){if(current(viewEpoch))cancel(row);},child:Text(t('Cancel this block','إلغاء فترة الحجب'))),
      ],
      for(final entry in fields.entries)TextField(key:Key('block-${entry.key}'),controller:entry.value,enabled:!busy&&pending==null,
        maxLength:entry.key.startsWith('reason')?300:20,decoration:InputDecoration(labelText:switch(entry.key){
          'starts_at'=>t('Start UTC: YYYY-MM-DDTHH:mm:ssZ','البداية UTC: YYYY-MM-DDTHH:mm:ssZ'),
          'ends_at'=>t('End UTC: YYYY-MM-DDTHH:mm:ssZ','النهاية UTC: YYYY-MM-DDTHH:mm:ssZ'),
          'reason_en'=>t('Public reason (English)','السبب المنشور بالإنجليزية'),_=>t('Public reason (Arabic)','السبب المنشور بالعربية')})),
      FilledButton(onPressed:busy?null:(){if(current(viewEpoch))publish();},child:Text(pending==null?t('Publish station block','نشر حجب المحطة'):t('Retry same block request','إعادة محاولة طلب الحجب نفسه'))),
    ]));
  }
  @override
  void dispose(){generation++;sessionChanges?.cancel();WidgetsBinding.instance.removeObserver(this);for(final controller in fields.values)controller.dispose();super.dispose();}
}
