import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'topology_strings.dart';

class VendorTopologyView extends StatefulWidget {
  final EvApi api;
  final Map<String, dynamic> company, charger;
  final Map<String, dynamic>? evse;
  final VoidCallback onExpired;
  const VendorTopologyView({
    super.key,
    required this.api,
    required this.company,
    required this.charger,
    required this.onExpired,
    this.evse,
  });
  @override
  State<VendorTopologyView> createState() => _VendorTopologyViewState();
}

class _VendorTopologyViewState extends State<VendorTopologyView> {
  ApiPage<Map<String, dynamic>>? data, sources;
  Object? error;
  bool busy = false, uncertain = false;
  int generation = 0, target = 1, sourceTarget = 1;
  String get base =>
      'vendor-ops/companies/${widget.company['id']}/chargers/${widget.charger['id']}';
  bool get writer =>
      (widget.company['capabilities'] as Map?)?['inventory_write'] == true &&
      (widget.company['capabilities'] as Map?)?['inventory_operational'] ==
          true;
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

  void failed(Object e) {
    if (!mounted) return;
    setState(() {
      data = null;
      sources = null;
      error = e;
    });
    if (e is ApiException && [401, 419].contains(e.status)) {
      widget.onExpired();
      Navigator.of(context).popUntil((r) => r.isFirst);
    }
  }

  Future<void> load({int page = 1, int sourcePage = 1}) async {
    final epoch = ++generation;
    target = page;
    sourceTarget = sourcePage;
    setState(() {
      busy = true;
      data = null;
      sources = null;
      error = null;
      uncertain = false;
    });
    try {
      final results = await Future.wait([
        widget.api.request(
          '$base/${widget.evse == null ? 'evses' : 'connectors'}',
          query: {'page': '$page'},
        ),
        if (widget.evse == null)
          widget.api.request(
            '$base/source-options',
            query: {'page': '$sourcePage'},
          ),
      ]);
      if (mounted && epoch == generation)
        setState(() {
          data = ApiPage.fromJson(results[0], (v) => v);
          if (results.length > 1)
            sources = ApiPage.fromJson(results[1], (v) => v);
        });
    } catch (e) {
      if (mounted && epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> mutate(Map<String, dynamic>? connector) async {
    final payload = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _TopologyDialog(
        create: connector == null,
        csms: widget.evse?['mode'] == 'csms',
      ),
    );
    if (payload == null || !mounted) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      sources = null;
      error = null;
    });
    try {
      await widget.api.request(
        connector == null
            ? '$base/evses'
            : '$base/evses/${widget.evse!['id']}/connectors/${connector['id']}/mapping',
        method: 'POST',
        body: payload,
      );
      if (mounted && epoch == generation)
        await load(page: target, sourcePage: sourceTarget);
    } catch (e) {
      if (mounted && epoch == generation) {
        uncertain = e is! ApiException || e.status == 0 || e.status >= 500;
        failed(e);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget pager(ApiPage<Map<String, dynamic>> p, bool source) {
    final s = EvStrings.of(context);
    return Column(
      children: [
        Text(
          s.t('page', {'page': p.current, 'last': p.last, 'total': p.total}),
        ),
        Wrap(
          spacing: 12,
          children: [
            OutlinedButton(
              onPressed: busy || p.current <= 1
                  ? null
                  : () => source
                        ? load(page: target, sourcePage: p.current - 1)
                        : load(page: p.current - 1, sourcePage: sourceTarget),
              child: Text(s.t('previous')),
            ),
            OutlinedButton(
              onPressed: busy || p.current >= p.last
                  ? null
                  : () => source
                        ? load(page: target, sourcePage: p.current + 1)
                        : load(page: p.current + 1, sourcePage: sourceTarget),
              child: Text(s.t('next')),
            ),
          ],
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = data;
    String t(String k) => topologyText(context, k);
    return Scaffold(
      appBar: AppBar(
        title: Text(t('title')),
        actions: [
          IconButton(
            onPressed: busy
                ? null
                : () => load(page: target, sourcePage: sourceTarget),
            tooltip: s.t('refresh'),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('immutable')),
          Text(t('disclaimer')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) ...[
            Text(s.t(errorKey(error!))),
            if (uncertain) Text(t('uncertain')),
            TextButton(
              onPressed: busy
                  ? null
                  : () => load(page: target, sourcePage: sourceTarget),
              child: Text(s.t('retry')),
            ),
          ],
          if (sources != null) ...[
            Text(t('binding')),
            for (final row in sources!.items)
              Text('#${row['id']} · OCPP ${row['protocol']}'),
            pager(sources!, true),
          ],
          if (p != null && widget.evse == null && writer)
            FilledButton(
              onPressed: busy ? null : () => mutate(null),
              child: Text(t('create')),
            ),
          if (p != null && p.items.isEmpty) Text(t('empty')),
          for (final row in p?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      widget.evse == null
                          ? '${t('evse')} ${row['number']} · #${row['id']}'
                          : '${t('connector')} ${row['connector_number']} · ${row['connector_type']}',
                    ),
                    if (widget.evse == null) ...[
                      Text(t(row['mode'] as String)),
                      if (writer)
                        OutlinedButton(
                          onPressed: busy
                              ? null
                              : () async {
                                  await Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: (_) => VendorTopologyView(
                                        api: widget.api,
                                        company: widget.company,
                                        charger: widget.charger,
                                        evse: row,
                                        onExpired: widget.onExpired,
                                      ),
                                    ),
                                  );
                                  if (mounted &&
                                      ModalRoute.of(context)?.isCurrent == true)
                                    await load(
                                      page: target,
                                      sourcePage: sourceTarget,
                                    );
                                },
                          child: Text(t('map')),
                        ),
                    ] else if (row['canonical_mapping_id'] != null)
                      Text('#${row['canonical_mapping_id']}')
                    else if (writer)
                      OutlinedButton(
                        onPressed: busy ? null : () => mutate(row),
                        child: Text(t('map')),
                      ),
                  ],
                ),
              ),
            ),
          if (p != null) pager(p, false),
        ],
      ),
    );
  }
}

class _TopologyDialog extends StatefulWidget {
  final bool create, csms;
  const _TopologyDialog({required this.create, required this.csms});
  @override
  State<_TopologyDialog> createState() => _TopologyDialogState();
}

class _TopologyDialogState extends State<_TopologyDialog> {
  final form = GlobalKey<FormState>();
  final values = <String, String>{};
  String mode = 'application';
  Widget field(
    String key,
    int min,
    int max, {
    bool optional = false,
    String? initial,
  }) => TextFormField(
    initialValue: initial,
    key: ValueKey(key),
    decoration: InputDecoration(labelText: topologyText(context, key)),
    keyboardType: TextInputType.number,
    maxLength: 18,
    onSaved: (v) => values[key] = v ?? '',
    validator: (v) {
      if (optional && (v?.isEmpty ?? true)) return null;
      final n = decimalMinor(v ?? '', 0, maximum: max);
      return n == null || n < min ? topologyText(context, 'invalid') : null;
    },
  );
  @override
  Widget build(BuildContext context) {
    String t(String k) => topologyText(context, k);
    final s = EvStrings.of(context);
    return AlertDialog(
      title: Text(t(widget.create ? 'create' : 'map')),
      content: SingleChildScrollView(
        child: Form(
          key: form,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(t('immutable')),
              if (widget.create) ...[
                field('number', 1, 1000),
                DropdownButtonFormField<String>(
                  initialValue: mode,
                  decoration: InputDecoration(labelText: t('mode')),
                  items: ['application', 'csms']
                      .map((m) => DropdownMenuItem(value: m, child: Text(t(m))))
                      .toList(),
                  onChanged: (v) => setState(() => mode = v!),
                ),
                if (mode == 'csms') ...[
                  field('binding', 1, 2000000000),
                  field('sourceEvse', 0, 1000, initial: '0'),
                ],
              ] else if (widget.csms)
                field('sourceConnector', 1, 100),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(s.t('cancel')),
        ),
        FilledButton(
          onPressed: () {
            if (!form.currentState!.validate()) return;
            form.currentState!.save();
            final payload = widget.create
                ? {
                    'number': decimalMinor(values['number']!, 0, maximum: 1000),
                    'mode': mode,
                    'binding_id': mode == 'csms'
                        ? decimalMinor(
                            values['binding']!,
                            0,
                            maximum: 2000000000,
                          )
                        : null,
                    'source_evse_number': mode == 'csms'
                        ? decimalMinor(values['sourceEvse']!, 0, maximum: 1000)
                        : 0,
                  }
                : {
                    'source_connector_number': widget.csms
                        ? decimalMinor(
                            values['sourceConnector']!,
                            0,
                            maximum: 100,
                          )
                        : null,
                  };
            Navigator.pop(context, payload);
          },
          child: Text(t('save')),
        ),
      ],
    );
  }
}
