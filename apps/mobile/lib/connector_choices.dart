import 'dart:async';
import 'connector_freshness.dart';
import 'package:flutter/material.dart';
import 'api.dart';
import 'reservation_times.dart';
import 'strings.dart';
import 'topology_strings.dart';

class ConnectorChoicesView extends StatefulWidget {
  final Station station;
  final Future<ApiPage<Map<String, dynamic>>> Function(int) loader;
  final Future<void> Function(Station) onReserve;
  final bool Function()? currentSession;
  final Stream<int>? sessionEvents;
  const ConnectorChoicesView({
    super.key,
    required this.station,
    required this.loader,
    required this.onReserve,
    this.currentSession,
    this.sessionEvents,
  });
  @override
  State<ConnectorChoicesView> createState() => _ConnectorChoicesViewState();
}

class _ConnectorChoicesViewState extends State<ConnectorChoicesView> with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
  final age = Stopwatch();
  Timer? freshnessTimer;
  StreamSubscription<int>? subscription;
  bool foreground = true;
  bool get active => mounted && foreground && (widget.currentSession?.call() ?? true);
  bool current(int epoch) => active && epoch == generation;
  void redact() {
    generation++;
    data = null;
    error = null;
    busy = false;
    target = 1;
    age.stop();
  }
  void subscribe() {
    final source = widget.sessionEvents;
    subscription = source?.listen((_) {
      if (mounted && identical(source,widget.sessionEvents) && !(widget.currentSession?.call() ?? true)) {
        setState(redact);
      }
    });
  }
  VoidCallback guard(VoidCallback action) {
    final epoch = generation;
    return () { if (current(epoch) && !busy) action(); };
  }
  ApiPage<Map<String,dynamic>> verified(ApiPage<Map<String,dynamic>> result,int page) {
    if (result.current != page || result.last < 1 || result.total < 0 ||
        result.items.length > 25 || result.items.length > result.total) {
      throw const FormatException('Invalid connector page');
    }
    final ids = <int>{};
    bool positive(dynamic n) => n is int && n > 0;
    for (final row in result.items) {
      if (row['station_id'] != widget.station.id || !positive(row['id']) ||
          !ids.add(row['id'] as int) || !positive(row['evse_number']) ||
          !positive(row['connector_number']) || !positive(row['max_power_kw']) ||
          !['CCS2','Type2','CHAdeMO'].contains(row['connector_type']) ||
          row['evidence'] != 'SIMULATED' || row['availability'] != 'unknown' ||
          row['reservable'] is! bool || row['csms_bound'] is! bool) {
        throw const FormatException('Invalid connector evidence');
      }
    }
    return ApiPage(items:List.unmodifiable(result.items.map((row)=>Map<String,dynamic>.unmodifiable(row))),
      current:result.current,last:result.last,total:result.total);
  }
  @override
  void initState() {
    super.initState();
    final state=WidgetsBinding.instance.lifecycleState;
    foreground=state==null||state==AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    freshnessTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (active && data != null) setState(() {});
    });
    if (active) load();
  }

  @override
  void didUpdateWidget(covariant ConnectorChoicesView old) {
    super.didUpdateWidget(old);
    if (old.station.id != widget.station.id ||
        !identical(old.currentSession,widget.currentSession) ||
        !identical(old.sessionEvents,widget.sessionEvents)) {
      unawaited(subscription?.cancel());
      redact();
      subscribe();
      if(active) load();
    } else if(!active) {
      redact();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed=state==AppLifecycleState.resumed;
    if(!mounted||resumed==foreground)return;
    setState(() { foreground=resumed; redact(); });
    // A new explicit refresh is required after backgrounding.
  }

  @override
  void dispose() {
    generation++;
    freshnessTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    unawaited(subscription?.cancel());
    age.stop();
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    if(!active||busy)return;
    final epoch = ++generation;
    age..reset()..start();
    target = page;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final result = await widget.loader(page);
      if (current(epoch)) {
        final checked=verified(result,page);
        setState(() => data = checked);
      }
    } catch (e) {
      if (current(epoch)) setState(() => error = e);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = data;
    if (!active) return Scaffold(appBar:AppBar(title:Text(topologyText(context,'choose'))),
      body:Center(child:Text(s.arabic?'أعد فتح اختيار الموصل أو حدّثه عند العودة.':'Reopen connector selection or refresh when active.')));
    final epoch = generation;
    return Scaffold(
      appBar: AppBar(title: Text(topologyText(context, 'choose'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            widget.station.name,
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          Text(topologyText(context, 'disclaimer')),
          if (busy) LinearProgressIndicator(semanticsLabel: s.t('loading')),
          if (p == null && error == null && !busy)
            OutlinedButton(onPressed:guard(() { load(); }),child:Text(s.t('refresh'))),
          if (error != null) ...[
            Semantics(liveRegion: true, child: Text(s.t(errorKey(error!)))),
            TextButton(
              onPressed: busy ? null : guard(() { load(page: target); }),
              child: Text(s.t('retry')),
            ),
          ],
          if (p != null && p.items.isEmpty)
            Text(topologyText(context, 'empty')),
          for (final row in p?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${topologyText(context, 'evse')} ${row['evse_number']} · ${topologyText(context, 'connector')} ${row['connector_number']}',
                    ),
                    Text(
                      '${row['connector_type']} · ${row['max_power_kw']} kW',
                    ),
                    Semantics(
                      liveRegion: true,
                      child: Text(connectorFreshnessText(
                        row['status_freshness'],
                        age.elapsed,
                        s.arabic,
                        formatObservedAt: (value) => serverEventTime(context, value),
                      )),
                    ),
                    if (row['reservable'] == true && widget.station.source == 'SIMULATED')
                      FilledButton(
                        onPressed: busy
                            ? null
                            : () async {
                                if (!current(epoch) || busy || !(data?.items.contains(row) ?? false)) return;
                                final selected=Station.forConnector(widget.station,row);
                                setState(() => busy = true);
                                try {
                                  await widget.onReserve(
                                    selected,
                                  );
                                } catch (e) {
                                  if(current(epoch))setState(()=>error=e);
                                } finally {
                                  if (current(epoch)) setState(() => busy = false);
                                }
                              },
                        child: Text(s.t('reserveSimulator')),
                      )
                    else
                      Text(topologyText(context, 'unavailable')),
                  ],
                ),
              ),
            ),
          if (p != null) ...[
            Text(
              s.t('page', {
                'page': p.current,
                'last': p.last,
                'total': p.total,
              }),
            ),
            Wrap(
              spacing: 12,
              children: [
                OutlinedButton(
                  onPressed: busy ? null : guard(() { load(page: p.current); }),
                  child: Text(s.t('refresh')),
                ),
                OutlinedButton(
                  onPressed: busy || p.current <= 1
                      ? null
                      : guard(() { load(page: p.current - 1); }),
                  child: Text(s.t('previous')),
                ),
                OutlinedButton(
                  onPressed: busy || p.current >= p.last
                      ? null
                      : guard(() { load(page: p.current + 1); }),
                  child: Text(s.t('next')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
