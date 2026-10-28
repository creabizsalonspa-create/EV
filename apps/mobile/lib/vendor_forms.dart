import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'vendor_operations_strings.dart';

String vendorDigits(String value) => value.runes
    .map(
      (c) => c >= 0x660 && c <= 0x669
          ? '${c - 0x660}'
          : c >= 0x6f0 && c <= 0x6f9
          ? '${c - 0x6f0}'
          : c == 0x66b
          ? '.'
          : c == 0x2212
          ? '-'
          : String.fromCharCode(c),
    )
    .join();
bool vendorDate(String s) {
  final d = DateTime.tryParse(s);
  return RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(s) &&
      d != null &&
      d.year >= 1 &&
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}' ==
          s;
}

class VendorEditView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String kind;
  final Map<String, dynamic>? company, site, charger;
  final bool editing;
  const VendorEditView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.kind,
    this.company,
    this.site,
    this.charger,
    this.editing = false,
  });
  @override
  State<VendorEditView> createState() => _VendorEditState();
}

class _VendorEditState extends State<VendorEditView> {
  final form = GlobalKey<FormState>();
  final controls = <String, TextEditingController>{};
  final key = operationKey();
  List<Map<String, dynamic>> regions = [];
  Map<String, dynamic>? station;
  bool busy = false, uncertain = false;
  Object? error;
  int generation = 0;
  String t(String k) => vendorOpsText(context, k);
  bool get needsRegions => ['company', 'site'].contains(widget.kind);
  List<String> get fields => switch (widget.kind) {
    'company' => [
      'legal_name',
      'country',
      'trade_license_number',
      'license_expires_on',
    ],
    'site' => [
      'name',
      'country',
      'timezone',
      'address',
      'latitude',
      'longitude',
    ],
    'charger' => [
      'asset_reference',
      'manufacturer',
      'model',
      'serial_number',
      'ocpp_protocol',
      'source',
    ],
    _ => ['connector_number', 'connector_type', 'max_power_kw'],
  };
  bool optional(String f) =>
      (widget.editing && f == 'trade_license_number') ||
      ['serial_number', 'ocpp_protocol'].contains(f);
  Map<String, String>? choices(String f) => switch (f) {
    'country' => {
      for (final r in regions) '${r['country']}': '${r['country']}',
    },
    'ocpp_protocol' => {
      '': t('notSet'),
      '1.6': 'OCPP1.6',
      '2.0.1': 'OCPP2.0.1',
    },
    'source' => {
      for (final v in [
        'discovery',
        'simulator',
        'direct_unverified',
        'partner_unverified',
      ])
        v: t(v),
    },
    'connector_type' => {
      'CCS2': 'CCS2',
      'Type2': 'Type2',
      'CHAdeMO': 'CHAdeMO',
    },
    _ => null,
  };
  @override
  void initState() {
    super.initState();
    for (final f in fields) {
      String value = '';
      if (widget.editing && f != 'trade_license_number')
        value = '${widget.company?[f] ?? ''}';
      if (f == 'license_expires_on' && value.length > 10)
        value = value.substring(0, 10);
      if (f == 'source') value = 'discovery';
      if (f == 'connector_type') value = 'CCS2';
      controls[f] = TextEditingController(text: value);
    }
    if (needsRegions) loadRegions();
  }

  @override
  void dispose() {
    generation++;
    for (final c in controls.values) c.dispose();
    super.dispose();
  }

  Future<void> loadRegions() async {
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      final r = (await widget.api.request('regions') as List)
          .map((r) => Map<String, dynamic>.from(r as Map))
          .toList();
      if (!mounted || epoch != generation) return;
      setState(() {
        regions = r;
        if (controls['country']!.text.isEmpty)
          controls['country']!.text =
              '${widget.company?['country'] ?? r.firstOrNull?['country'] ?? ''}';
        if (widget.kind == 'site')
          controls['timezone']!.text =
              '${r.where((x) => x['country'] == controls['country']!.text).firstOrNull?['timezone'] ?? ''}';
        error = null;
      });
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  void fail(Object e) {
    setState(() => error = e);
    if (e is ApiException && [401, 419].contains(e.status)) {
      for (final c in controls.values) c.clear();
      widget.onExpired();
      Navigator.of(context).popUntil((r) => r.isFirst);
    }
  }

  String? validate(String f, String value) {
    if (value.isEmpty) return optional(f) ? null : t('required');
    final n = vendorDigits(value);
    if (f == 'license_expires_on' && !vendorDate(n)) return t('invalid');
    if (['connector_number', 'max_power_kw'].contains(f)) {
      final i = int.tryParse(n);
      if (i == null || i < 1 || i > (f == 'connector_number' ? 100 : 1000))
        return t('invalid');
    }
    if (['latitude', 'longitude'].contains(f)) {
      final d = double.tryParse(n);
      if (d == null || !d.isFinite || d.abs() > (f == 'latitude' ? 90 : 180))
        return t('invalid');
    }
    return null;
  }

  Future<void> save() async {
    if (busy || uncertain || !form.currentState!.validate()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = <String, dynamic>{};
      for (final f in fields) {
        final value = controls[f]!.text.trim();
        if (value.isEmpty && optional(f)) continue;
        body[f] = ['connector_number', 'max_power_kw'].contains(f)
            ? int.parse(vendorDigits(value))
            : ['latitude', 'longitude'].contains(f)
            ? double.parse(vendorDigits(value))
            : f == 'license_expires_on'
            ? vendorDigits(value)
            : value;
      }
      if (station != null) {
        if (station!['country'] != body['country'])
          throw ApiException(422, t('invalid'));
        body['station_id'] = station!['id'];
      }
      if (!widget.editing) body['idempotency_key'] = key;
      final company = 'vendor-ops/companies/${widget.company?['id']}';
      final path = switch (widget.kind) {
        'company' => widget.editing ? company : 'vendor-ops/companies',
        'site' => '$company/sites',
        'charger' => '$company/sites/${widget.site!['id']}/chargers',
        _ => '$company/chargers/${widget.charger!['id']}/connectors',
      };
      final r = await widget.api.request(
        path,
        method: widget.editing ? 'PATCH' : 'POST',
        body: body,
      );
      if (mounted && epoch == generation)
        Navigator.pop(context, Map<String, dynamic>.from(r as Map));
    } catch (e) {
      if (mounted && epoch == generation) {
        fail(e);
        setState(() => uncertain = e is! ApiException || e.status != 422);
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> pickStation() async {
    final r = await Navigator.of(context).push<Map<String, dynamic>>(
      MaterialPageRoute(
        builder: (_) => VendorStationPicker(
          api: widget.api,
          company: widget.company!['id'] as int,
          onExpired: widget.onExpired,
        ),
      ),
    );
    if (!mounted || r == null) return;
    setState(() {
      station = r;
      for (final f in ['country', 'timezone', 'latitude', 'longitude'])
        controls[f]!.text = '${r[f]}';
    });
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        t(
          widget.editing
              ? 'editCompany'
              : {
                  'company': 'newCompany',
                  'site': 'newSite',
                  'charger': 'newCharger',
                  'connector': 'newConnector',
                }[widget.kind]!,
        ),
      ),
    ),
    body: SafeArea(
      child: Form(
        key: form,
        child: ListView(
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          padding: const EdgeInsets.all(16),
          children: [
            Text(t('notice')),
            if (widget.editing) Text(t('privateLicense')),
            if (busy) const LinearProgressIndicator(),
            if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
            if (uncertain) ...[
              Text(t('uncertain')),
              TextButton(
                onPressed: busy ? null : () => Navigator.pop(context),
                child: Text(t('reviewExisting')),
              ),
            ],
            for (final f in fields)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: choices(f) != null
                    ? DropdownButtonFormField<String>(
                        key: ValueKey('$f-${controls[f]!.text}'),
                        initialValue: choices(f)!.containsKey(controls[f]!.text)
                            ? controls[f]!.text
                            : null,
                        isExpanded: true,
                        decoration: InputDecoration(labelText: t(f)),
                        items: choices(f)!.entries
                            .map(
                              (e) => DropdownMenuItem(
                                value: e.key,
                                child: Text(e.value),
                              ),
                            )
                            .toList(),
                        onChanged: busy || uncertain
                            ? null
                            : (v) => setState(() {
                                controls[f]!.text = v!;
                                if (f == 'country' && widget.kind == 'site')
                                  controls['timezone']!.text =
                                      '${regions.where((r) => r['country'] == v).firstOrNull?['timezone'] ?? ''}';
                              }),
                        validator: (v) => validate(f, v ?? ''),
                      )
                    : TextFormField(
                        controller: controls[f],
                        enabled: !busy && !uncertain,
                        maxLength: f == 'address'
                            ? 300
                            : f == 'asset_reference'
                            ? 80
                            : f == 'legal_name' || f == 'name'
                            ? 150
                            : 100,
                        decoration: InputDecoration(
                          labelText: t(
                            f == 'trade_license_number' && widget.editing
                                ? 'optionalLicense'
                                : f,
                          ),
                        ),
                        keyboardType: ['latitude', 'longitude'].contains(f)
                            ? const TextInputType.numberWithOptions(
                                decimal: true,
                                signed: true,
                              )
                            : ['connector_number', 'max_power_kw'].contains(f)
                            ? TextInputType.number
                            : TextInputType.text,
                        validator: (v) => validate(f, v?.trim() ?? ''),
                      ),
              ),
            if (widget.kind == 'site') ...[
              Text(station == null ? t('noStation') : '${station!['name']}'),
              TextButton(
                onPressed: busy || uncertain ? null : pickStation,
                child: Text(t('ownedStation')),
              ),
              if (station != null)
                TextButton(
                  onPressed: busy || uncertain
                      ? null
                      : () => setState(() => station = null),
                  child: Text(t('clearStation')),
                ),
            ],
            if (needsRegions && regions.isEmpty && !busy)
              TextButton(onPressed: loadRegions, child: Text(t('retry'))),
            FilledButton(
              onPressed: busy || uncertain || (needsRegions && regions.isEmpty)
                  ? null
                  : save,
              child: Text(t('save')),
            ),
          ],
        ),
      ),
    ),
  );
}

class VendorStationPicker extends StatefulWidget {
  final EvApi api;
  final int company;
  final VoidCallback onExpired;
  const VendorStationPicker({
    super.key,
    required this.api,
    required this.company,
    required this.onExpired,
  });
  @override
  State<VendorStationPicker> createState() => _StationPickerState();
}

class _StationPickerState extends State<VendorStationPicker> {
  final query = TextEditingController();
  ApiPage<Map<String, dynamic>>? rows;
  Object? error;
  bool busy = false;
  int page = 1, generation = 0;
  String t(String k) => vendorOpsText(context, k);
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    query.dispose();
    super.dispose();
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      rows = null;
      error = null;
    });
    try {
      final r = ApiPage.fromJson(
        await widget.api.request(
          'vendor-ops/companies/${widget.company}/catalog-stations',
          query: {
            'page': '$page',
            if (query.text.trim().isNotEmpty) 'q': query.text.trim(),
          },
        ),
        (r) => r,
      );
      if (mounted && epoch == generation) setState(() => rows = r);
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('ownedStation'))),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: query,
            maxLength: 100,
            decoration: InputDecoration(labelText: t('search')),
          ),
          TextButton(
            onPressed: busy
                ? null
                : () {
                    page = 1;
                    load();
                  },
            child: Text(t('search')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          if (rows?.items.isEmpty == true) Text(t('empty')),
          if (rows != null)
            for (final r in rows!.items)
              ListTile(
                title: Text('${r['name']}'),
                subtitle: Text('${r['country']} · ${r['timezone']}'),
                onTap: () => Navigator.pop(context, r),
              ),
          Wrap(
            children: [
              if (page > 1)
                TextButton(
                  onPressed: busy
                      ? null
                      : () {
                          page--;
                          load();
                        },
                  child: Text(t('previous')),
                ),
              if (rows != null && page < rows!.last)
                TextButton(
                  onPressed: busy
                      ? null
                      : () {
                          page++;
                          load();
                        },
                  child: Text(t('next')),
                ),
              TextButton(
                onPressed: busy ? null : load,
                child: Text(t('refresh')),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}
