import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'topology_strings.dart';

class ConnectorChoicesView extends StatefulWidget {
  final Station station;
  final Future<ApiPage<Map<String, dynamic>>> Function(int) loader;
  final Future<void> Function(Station) onReserve;
  const ConnectorChoicesView({
    super.key,
    required this.station,
    required this.loader,
    required this.onReserve,
  });
  @override
  State<ConnectorChoicesView> createState() => _ConnectorChoicesViewState();
}

class _ConnectorChoicesViewState extends State<ConnectorChoicesView> {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    target = page;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final result = await widget.loader(page);
      if (mounted && epoch == generation) setState(() => data = result);
    } catch (e) {
      if (mounted && epoch == generation) setState(() => error = e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = data;
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
          if (busy) const LinearProgressIndicator(),
          if (error != null) ...[
            Text(s.t(errorKey(error!))),
            TextButton(
              onPressed: busy ? null : () => load(page: target),
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
                    if (row['reservable'] == true)
                      FilledButton(
                        onPressed: busy
                            ? null
                            : () async {
                                setState(() => busy = true);
                                try {
                                  await widget.onReserve(
                                    Station.forConnector(widget.station, row),
                                  );
                                } finally {
                                  if (mounted) setState(() => busy = false);
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
                  onPressed: busy || p.current <= 1
                      ? null
                      : () => load(page: p.current - 1),
                  child: Text(s.t('previous')),
                ),
                OutlinedButton(
                  onPressed: busy || p.current >= p.last
                      ? null
                      : () => load(page: p.current + 1),
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
