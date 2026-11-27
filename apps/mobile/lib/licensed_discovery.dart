import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'api.dart';
import 'foreground_location.dart';

/// A licensed crowd report never becomes a canonical charging identity.
class LicensedReport {
  final String id, name, address, operator, license, attribution, cost, access;
  final double latitude, longitude, distance;
  final int? age;
  final bool? operational;
  final List<String> connections;
  LicensedReport._(
    this.id,
    this.name,
    this.address,
    this.operator,
    this.license,
    this.attribution,
    this.cost,
    this.access,
    this.latitude,
    this.longitude,
    this.distance,
    this.age,
    this.operational,
    this.connections,
  );
  Uri get source => Uri.parse(
    'https://openchargemap.org/site/poi/details/${id.substring(4)}',
  );
  static String label(dynamic value, int max, {bool required = false}) {
    if (value is! String ||
        value.runes.length > max ||
        RegExp(r'[\x00-\x1f\x7f]').hasMatch(value) ||
        (required && value.trim().isEmpty)) {
      throw const FormatException('Invalid licensed source label.');
    }
    return value;
  }

  static double number(dynamic value, double max, {bool signed = false}) {
    if (value is! num ||
        !value.isFinite ||
        value.abs() > max ||
        (!signed && value < 0)) {
      throw const FormatException('Invalid licensed source number.');
    }
    return value.toDouble();
  }

  factory LicensedReport.fromJson(dynamic value) {
    if (value is! Map) throw const FormatException('Invalid licensed source.');
    void same(String key, dynamic expected) {
      if (value[key] != expected)
        throw const FormatException('Unsupported source authority.');
    }

    for (final flag in ['remote_control', 'reservable', 'billing_eligible']) {
      same(flag, false);
    }
    same('provider', 'open_charge_map');
    same('availability', 'unknown');
    same('distance_basis', 'great_circle');
    same('source_status_evidence', 'OPEN_CHARGE_MAP_DISCOVERY_ONLY');
    same('operator_evidence', 'OPEN_CHARGE_MAP_REPORTED_UNVERIFIED');
    same(
      'reported_operational_evidence',
      'OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED',
    );
    same('reported_access_evidence', 'OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED');
    same('reported_cost_evidence', 'OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED');
    final id = label(value['id'], 14, required: true);
    if (!RegExp(r'^ocm:[1-9][0-9]{0,9}$').hasMatch(id) ||
        int.parse(id.substring(4)) > 2147483647 ||
        value['source_url'] !=
            'https://openchargemap.org/site/poi/details/${id.substring(4)}') {
      throw const FormatException('Invalid licensed source identity.');
    }
    final provider = label(value['data_provider'], 120, required: true);
    same('attribution', 'Open Charge Map / $provider');
    final age = value['source_age_minutes'];
    if (age != null && (age is! int || age < 0 || age > 9007199254740991)) {
      throw const FormatException('Invalid source age.');
    }
    final clock = value['source_clock_evidence'];
    if (![
          'CROWD_REPORTED_TIME',
          'SOURCE_TIME_UNKNOWN',
          'SOURCE_CLOCK_AHEAD',
        ].contains(clock) ||
        (clock == 'CROWD_REPORTED_TIME') != (age != null)) {
      throw const FormatException('Invalid source clock.');
    }
    final timestamp = value['source_updated_at'];
    if (timestamp != null) {
      if (timestamp is! String ||
          !RegExp(
            r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$',
          ).hasMatch(timestamp) ||
          DateTime.tryParse(timestamp)?.toUtc().toIso8601String() !=
              timestamp.replaceFirst('Z', '.000Z')) {
        throw const FormatException('Invalid source timestamp.');
      }
    }
    if ((clock == 'SOURCE_TIME_UNKNOWN') != (timestamp == null)) {
      throw const FormatException('Invalid source time evidence.');
    }
    for (final key in [
      'reported_operational',
      'reported_membership_required',
      'reported_access_key_required',
    ]) {
      if (value[key] != null && value[key] is! bool)
        throw const FormatException('Invalid crowd flag.');
    }
    final rawConnections = value['connections'];
    if (rawConnections is! List || rawConnections.length > 20)
      throw const FormatException('Invalid crowd connectors.');
    final connections = <String>[];
    for (final connection in rawConnections) {
      if (connection is! Map ||
          connection['availability'] != 'unknown' ||
          [
            'remote_control',
            'reservable',
            'billing_eligible',
          ].any(connection.containsKey)) {
        throw const FormatException('Unsupported connector authority.');
      }
      final type = label(
        connection['reported_connector_type'],
        80,
        required: true,
      );
      final power = connection['reported_power_kw'];
      if (power != null) number(power, 1000);
      connections.add('$type${power == null ? '' : ' · $power kW'}');
    }
    return LicensedReport._(
      id,
      label(value['name'], 160, required: true),
      [
        label(value['address'], 160),
        label(value['city'], 100),
        label(value['country'], 2),
      ].where((s) => s.isNotEmpty).join(', '),
      label(value['operator_label'], 120),
      label(value['data_license'], 240, required: true),
      label(value['attribution'], 160, required: true),
      label(value['reported_cost_note'], 240),
      label(value['reported_access'], 120),
      number(value['latitude'], 90, signed: true),
      number(value['longitude'], 180, signed: true),
      number(value['distance_km'], 200),
      age as int?,
      value['reported_operational'] as bool?,
      List.unmodifiable(connections),
    );
  }
}

class LicensedSample {
  final List<LicensedReport> reports;
  final int unknownTime, oldTime, unknownOperation;
  LicensedSample._(
    this.reports,
    this.unknownTime,
    this.oldTime,
    this.unknownOperation,
  );
  factory LicensedSample.fromJson(dynamic value) {
    if (value is! Map ||
        value['provider'] != 'open_charge_map' ||
        value['evidence'] != 'OPEN_LICENSED_DISCOVERY_ONLY' ||
        value['availability'] != 'unknown' ||
        value['distance_basis'] != 'great_circle' ||
        value['license_policy'] != 'PER_RECORD_ATTRIBUTION_REQUIRED' ||
        [
          'remote_control',
          'reservable',
          'billing_eligible',
        ].any((key) => value[key] != false) ||
        value['results'] is! List ||
        (value['results'] as List).length > 25) {
      throw const FormatException('Unsupported discovery source.');
    }
    final rows = (value['results'] as List)
        .map(LicensedReport.fromJson)
        .toList();
    if (value['count'] is! int ||
        value['count'] != rows.length ||
        rows.map((r) => r.id).toSet().length != rows.length) {
      throw const FormatException('Invalid source sample.');
    }
    final unknown = rows.where((r) => r.age == null).length;
    final old = rows.where((r) => r.age != null && r.age! >= 1440).length;
    final operating = rows.where((r) => r.operational == null).length;
    final q = value['quality_sample'];
    if (q is Map &&
        [
          'returned_count',
          'source_time_unknown_or_future_count',
          'source_older_than_24h_count',
          'operational_report_unknown_count',
          'max_source_sample',
        ].any((key) => q[key] is! int)) {
      throw const FormatException('Invalid source sample counts.');
    }
    if (q is! Map ||
        q['evidence'] != 'FILTERED_LICENSED_CROWD_SAMPLE_ONLY' ||
        q['max_source_sample'] != 25 ||
        q['complete_geographic_coverage'] != false ||
        q['live_status_verified'] != false ||
        q['returned_count'] != rows.length ||
        q['source_time_unknown_or_future_count'] != unknown ||
        q['source_older_than_24h_count'] != old ||
        q['operational_report_unknown_count'] != operating) {
      throw const FormatException('Unsupported coverage claim.');
    }
    return LicensedSample._(List.unmodifiable(rows), unknown, old, operating);
  }
}

class LicensedDiscoveryScreen extends StatefulWidget {
  final EvApi api;
  final VoidCallback? onExpired;
  final TileProvider? mapTileProvider;
  final ForegroundLocationReader? locationReader;
  final Future<bool> Function(Uri)? openSource;
  final Future<bool> Function(Uri)? openNavigation;
  const LicensedDiscoveryScreen({
    super.key,
    required this.api,
    this.onExpired,
    this.mapTileProvider,
    this.locationReader,
    this.openSource,
    this.openNavigation,
  });
  @override
  State<LicensedDiscoveryScreen> createState() =>
      _LicensedDiscoveryScreenState();
}

class _LicensedDiscoveryScreenState extends State<LicensedDiscoveryScreen>
    with WidgetsBindingObserver {
  final fields = {
    for (final key in [
      'latitude',
      'longitude',
      'radius_km',
      'country',
      'min_reported_power_kw',
      'reported_connector',
      'operator_contains',
      'max_source_age_hours',
    ])
      key: TextEditingController(),
  };
  late bool Function() ownSession;
  StreamSubscription<int>? sessionSubscription;
  bool foreground = true, busy = false, consent = false;
  bool? enabled;
  bool showMap = false;
  String membership = '', reportedState = '';
  int generation = 0;
  String? error;
  LicensedSample? sample;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    ownSession = widget.api.sessionFence();
    foreground =
        WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    fields['radius_km']!.text = '25';
    WidgetsBinding.instance.addObserver(this);
    subscribeToSession();
    if (foreground) capabilities();
  }

  // A same-client credential change must redact visible source reports
  // even if no request is pending and the Navigator route stays mounted.
  void subscribeToSession() {
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api, widget.api) && !ownSession()) {
        setState(() => clear(all: true));
      }
    });
  }

  void clear({bool all = false}) {
    generation++;
    sample = null;
    showMap = false;
    error = null;
    busy = false;
    if (all) {
      consent = false;
      membership = '';
      reportedState = '';
      enabled = null;
      for (final field in fields.values) {
        field.clear();
      }
    }
  }

  @override
  void didUpdateWidget(covariant LicensedDiscoveryScreen old) {
    super.didUpdateWidget(old);
    if (!identical(old.api, widget.api)) {
      unawaited(sessionSubscription?.cancel());
      clear(all: true);
      ownSession = widget.api.sessionFence();
      subscribeToSession();
    } else if (!ownSession()) {
      clear(all: true);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground && mounted) setState(() => clear(all: true));
  }

  // The API can change credential ownership before completing a read. Purge
  // this view's old drafts/spinner as well as dropping its late source data.
  bool current(int ticket) {
    if (!mounted || !foreground || ticket != generation) return false;
    if (!ownSession()) {
      setState(() => clear(all: true));
      return false;
    }
    return true;
  }

  void failure(Object e, int ticket, EvApi api) {
    // EvApi invalidates the fence before throwing a genuine 401/419.
    // Redaction may already have advanced generation. A still-current
    // expiry from this exact client must nevertheless reach the owner.
    if (!mounted || !identical(api, widget.api)) return;
    if (foreground &&
        (ticket == generation || !ownSession()) &&
        e is ApiException &&
        e.endsCurrentSession) {
      setState(() {
        clear(all: true);
        error = t(
          'Session expired. Sign in again.',
          'انتهت الجلسة. سجّل الدخول مجدداً.',
        );
      });
      widget.onExpired?.call();
      return;
    }
    if (current(ticket))
      setState(() {
        busy = false;
        sample = null;
        error = e is FormatException
            ? t(
                'Source response is unsupported. No reports displayed.',
                'استجابة المصدر غير مدعومة. لم تُعرض تقارير.',
              )
            : t(
                'Source unavailable or rate limited. Wait, then retry explicitly.',
                'المصدر غير متاح أو تم بلوغ حد الطلبات. انتظر ثم أعد المحاولة يدوياً.',
              );
      });
  }

  Future<void> capabilities() async {
    if (!foreground || busy || !ownSession()) return;
    final api = widget.api;
    final ticket = ++generation;
    setState(() {
      busy = true;
      showMap = false;
      error = null;
      sample = null;
      consent = false;
    });
    try {
      final result = await api.request(
        'discovery/open-charge-map/capabilities',
      );
      if (!current(ticket)) return;
      if (result is! Map ||
          result['provider'] != 'open_charge_map' ||
          result['enabled'] is! bool ||
          result['evidence'] != 'DISCOVERY_ONLY' ||
          result['requires_explicit_consent'] != true ||
          result['max_radius_km'] != 200 ||
          result['max_results'] != 25 ||
          result['attribution_required_per_record'] != true ||
          result['availability'] != 'unknown' ||
          result['remote_control'] != false ||
          result['reservable'] != false)
        throw const FormatException('Invalid source capability.');
      setState(() {
        enabled = result['enabled'] as bool;
        busy = false;
      });
    } catch (e) {
      failure(e, ticket, api);
    }
  }

  void edited(String _) {
    setState(clear);
  }

  Future<void> locate() async {
    if (!consent || enabled != true || busy || !foreground || !ownSession())
      return;
    final ticket = ++generation;
    setState(() {
      busy = true;
      showMap = false;
      sample = null;
      error = null;
    });
    try {
      final fix = await (widget.locationReader ?? ForegroundLocationReader())
          .read();
      if (!current(ticket) || !consent) return;
      setState(() {
        fields['latitude']!.text = fix.latitude.toStringAsFixed(6);
        fields['longitude']!.text = fix.longitude.toStringAsFixed(6);
        busy = false;
      });
    } catch (_) {
      if (current(ticket))
        setState(() {
          busy = false;
          error = t(
            'Location unavailable. Enter coordinates manually.',
            'الموقع غير متاح. أدخل الإحداثيات يدوياً.',
          );
        });
    }
  }

  Future<void> search() async {
    if (!consent || enabled != true || busy || !foreground || !ownSession())
      return;
    final input = <String, dynamic>{
      'consent': true,
      if (membership.isNotEmpty)
        'reported_membership_required': membership == 'true',
      if (reportedState.isNotEmpty)
        'reported_operational': reportedState == 'true',
    };
    for (final key in ['latitude', 'longitude', 'radius_km']) {
      final n = double.tryParse(fields[key]!.text.trim());
      final max = key == 'latitude'
          ? 90
          : key == 'longitude'
          ? 180
          : 200;
      if (n == null ||
          !n.isFinite ||
          n.abs() > max ||
          (key == 'radius_km' && n < 1)) {
        setState(() {
          sample = null;
          error = t(
            'Enter valid coordinates and a radius from 1 to 200 km.',
            'أدخل إحداثيات صالحة ونطاقاً من 1 إلى 200 كم.',
          );
        });
        return;
      }
      input[key] = n;
    }
    for (final entry in fields.entries.where(
      (e) => !input.containsKey(e.key),
    )) {
      final text = entry.value.text.trim();
      if (text.isEmpty) continue;
      bool valid = true;
      if (entry.key == 'country') {
        valid = RegExp(r'^[A-Za-z]{2}$').hasMatch(text);
        input[entry.key] = text.toUpperCase();
      } else if (entry.key == 'min_reported_power_kw') {
        final power = double.tryParse(text);
        valid = power != null && power.isFinite && power >= 1 && power <= 1000;
        if (valid) input[entry.key] = power;
      } else if (entry.key == 'max_source_age_hours') {
        final age = int.tryParse(text);
        valid = age != null && age >= 1 && age <= 168;
        if (valid) input[entry.key] = age;
      } else {
        valid =
            text.runes.length <=
                (entry.key == 'reported_connector' ? 80 : 120) &&
            !RegExp(r'[\x00-\x1f\x7f]').hasMatch(text);
        input[entry.key] = text;
      }
      if (!valid) {
        setState(() {
          sample = null;
          error = t(
            'Check the optional country, power and report-age filters.',
            'تحقق من مرشحات الدولة والقدرة وعمر التقرير الاختيارية.',
          );
        });
        return;
      }
    }
    final api = widget.api;
    final ticket = ++generation;
    setState(() {
      busy = true;
      showMap = false;
      sample = null;
      error = null;
    });
    try {
      final result = await api.request(
        'discovery/open-charge-map/search',
        method: 'POST',
        body: input,
      );
      if (!current(ticket) || !consent) return;
      final parsed = LicensedSample.fromJson(result);
      setState(() {
        sample = parsed;
        busy = false;
      });
    } catch (e) {
      failure(e, ticket, api);
    }
  }

  Future<void> source(LicensedReport report, {bool navigation = false}) async {
    if (!foreground ||
        !ownSession() ||
        busy ||
        sample?.reports.contains(report) != true)
      return;
    final ticket = generation;
    try {
      final uri = navigation
          ? Uri.https('www.google.com', '/maps/dir/', {
              'api': '1',
              'destination': '${report.latitude},${report.longitude}',
              'travelmode': 'driving',
            })
          : report.source;
      final opener = navigation ? widget.openNavigation : widget.openSource;
      final opened =
          await (opener?.call(uri) ??
              launchUrl(uri, mode: LaunchMode.externalApplication));
      if (!opened && current(ticket))
        setState(
          () => error = t(
            'Could not open source. Try again explicitly.',
            'تعذر فتح المصدر. أعد المحاولة يدوياً.',
          ),
        );
    } catch (_) {
      if (current(ticket))
        setState(() => error = t('Could not open source.', 'تعذر فتح المصدر.'));
    }
  }

  @override
  void dispose() {
    generation++;
    unawaited(sessionSubscription?.cancel());
    WidgetsBinding.instance.removeObserver(this);
    for (final f in fields.values) {
      f.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final visible = foreground && ownSession();
    return Scaffold(
      appBar: AppBar(
        title: Text(t('Licensed discovery', 'اكتشاف البيانات المرخصة')),
      ),
      body: !visible
          ? Center(
              child: Text(
                t(
                  'Reopen discovery after signing in.',
                  'أعد فتح الاكتشاف بعد تسجيل الدخول.',
                ),
              ),
            )
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    t(
                      'Open Charge Map crowd reports. Availability is unknown; no booking, charging control or payment authority.',
                      'تقارير مجتمعية من Open Charge Map. التوفر غير معروف؛ لا تمنح صلاحية الحجز أو التحكم بالشحن أو الدفع.',
                    ),
                  ),
                  Text(
                    t(
                      'At most 25 nearby reports, not complete coverage. Distance is straight-line, not a route.',
                      'حتى 25 تقريراً قريباً، وليست تغطية شاملة. المسافة خط مستقيم وليست مساراً.',
                    ),
                  ),
                  if (busy) const LinearProgressIndicator(),
                  if (enabled != true) ...[
                    if (enabled == false)
                      Text(
                        t(
                          'Approved source is not configured. Managed and partner search remain available.',
                          'المصدر المعتمد غير مهيأ. يبقى البحث في المنصة والشركاء متاحاً.',
                        ),
                      ),
                    TextButton(
                      onPressed: busy ? null : capabilities,
                      child: Text(
                        t(
                          'Check source availability',
                          'التحقق من إتاحة المصدر',
                        ),
                      ),
                    ),
                  ],
                  if (error != null)
                    Semantics(liveRegion: true, child: Text(error!)),
                  if (enabled == true) ...[
                    CheckboxListTile(
                      key: const Key('licensed-consent'),
                      contentPadding: EdgeInsets.zero,
                      value: consent,
                      onChanged: (value) => setState(() {
                        clear();
                        consent = value ?? false;
                        if (!consent) {
                          for (final field in fields.values) {
                            field.clear();
                          }
                        }
                      }),
                      title: Text(
                        t(
                          'Allow search coordinates to be sent to Open Charge Map. No location or search preference is saved.',
                          'السماح بإرسال إحداثيات البحث إلى Open Charge Map. لا يُحفظ الموقع أو البحث كتفضيل.',
                        ),
                      ),
                    ),
                    TextButton(
                      onPressed: consent && !busy ? locate : null,
                      child: Text(
                        t(
                          'Use GPS once (permission required)',
                          'استخدام الموقع مرة واحدة (يتطلب الإذن)',
                        ),
                      ),
                    ),
                    for (final entry in fields.entries)
                      TextField(
                        key: Key('licensed-${entry.key}'),
                        controller: entry.value,
                        enabled: consent && !busy,
                        textDirection:
                            [
                              'latitude',
                              'longitude',
                              'radius_km',
                              'country',
                              'min_reported_power_kw',
                              'max_source_age_hours',
                            ].contains(entry.key)
                            ? TextDirection.ltr
                            : null,
                        maxLength: entry.key == 'reported_connector'
                            ? 80
                            : entry.key == 'operator_contains'
                            ? 120
                            : 32,
                        onChanged: edited,
                        decoration: InputDecoration(
                          labelText: fieldLabel(entry.key),
                        ),
                      ),
                    for (final filter in ['membership', 'operational'])
                      DropdownButtonFormField<String>(
                        key: ValueKey('licensed-$filter'),
                        isExpanded: true,
                        initialValue: filter == 'membership'
                            ? membership
                            : reportedState,
                        decoration: InputDecoration(
                          labelText: filter == 'membership'
                              ? t(
                                  'Reported membership requirement',
                                  'اشتراط العضوية المبلغ عنه',
                                )
                              : t(
                                  'Reported operating status (not live)',
                                  'حالة التشغيل المبلغ عنها (ليست مباشرة)',
                                ),
                        ),
                        items: [
                          DropdownMenuItem(
                            value: '',
                            child: Text(
                              t(
                                'Any / unknown included',
                                'الكل / يتضمن المجهول',
                              ),
                            ),
                          ),
                          DropdownMenuItem(
                            value: 'true',
                            child: Text(
                              t('Reported yes only', 'المبلغ عنه بنعم فقط'),
                            ),
                          ),
                          DropdownMenuItem(
                            value: 'false',
                            child: Text(
                              t('Reported no only', 'المبلغ عنه بلا فقط'),
                            ),
                          ),
                        ],
                        onChanged: !consent || busy
                            ? null
                            : (value) => setState(() {
                                clear();
                                if (filter == 'membership') {
                                  membership = value ?? '';
                                } else {
                                  reportedState = value ?? '';
                                }
                              }),
                      ),
                    FilledButton(
                      key: const Key('licensed-search'),
                      onPressed: consent && !busy ? search : null,
                      child: Text(
                        t(
                          'Search licensed reports',
                          'البحث في التقارير المرخصة',
                        ),
                      ),
                    ),
                  ],
                  if (sample case final result?) ...[
                    Text(
                      t(
                        '${result.reports.length} filtered reports · unknown source time: ${result.unknownTime} · older than 24h: ${result.oldTime} · unknown operational report: ${result.unknownOperation}',
                        '${result.reports.length} تقريراً بعد التصفية · وقت المصدر مجهول: ${result.unknownTime} · أقدم من 24 ساعة: ${result.oldTime} · تقرير التشغيل مجهول: ${result.unknownOperation}',
                      ),
                    ),
                    if (result.reports.isEmpty)
                      Text(
                        t(
                          'No matching licensed reports.',
                          'لا توجد تقارير مرخصة مطابقة.',
                        ),
                      ),
                    if (result.reports.isNotEmpty) ...[
                      Text(
                        t(
                          'Optional map: enabling sends your IP and viewed area to OpenStreetMap. Only these crowd reports are shown; no new discovery search is made by map movement.',
                          'خريطة اختيارية: عند تفعيلها يُرسل عنوان الإنترنت والمنطقة المعروضة إلى OpenStreetMap. تعرض هذه التقارير المجتمعية فقط؛ لا يؤدي تحريك الخريطة إلى بحث جديد.',
                        ),
                      ),
                      TextButton(
                        key: const Key('licensed-map-toggle'),
                        onPressed: () => setState(() => showMap = !showMap),
                        child: Text(
                          showMap
                              ? t(
                                  'Disable licensed report map',
                                  'إيقاف خريطة التقارير المرخصة',
                                )
                              : t(
                                  'Enable licensed report map',
                                  'تفعيل خريطة التقارير المرخصة',
                                ),
                        ),
                      ),
                      if (showMap) reportMap(result),
                    ],
                    for (final report in result.reports)
                      Card(
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                report.name,
                                style: Theme.of(context).textTheme.titleMedium,
                              ),
                              Text(report.address),
                              Text(report.operator),
                              Text(
                                t(
                                  'Unverified crowd report · availability unknown',
                                  'تقرير مجتمعي غير متحقق منه · التوفر غير معروف',
                                ),
                              ),
                              Text(
                                '${report.distance} km',
                                textDirection: TextDirection.ltr,
                              ),
                              Text(
                                t(
                                  'Source age at search: ${report.age ?? 'unknown'} minutes',
                                  'عمر المصدر عند البحث: ${report.age ?? 'مجهول'} دقيقة',
                                ),
                              ),
                              for (final connection in report.connections)
                                Text(
                                  connection,
                                  textDirection: TextDirection.ltr,
                                ),
                              Text(
                                t(
                                  'Reported access: ${report.access}',
                                  'الوصول المبلغ عنه: ${report.access}',
                                ),
                              ),
                              Text(
                                t(
                                  'Unverified cost note (not a quote): ${report.cost}',
                                  'ملاحظة تكلفة غير مؤكدة (ليست عرض سعر): ${report.cost}',
                                ),
                              ),
                              Text(report.attribution),
                              Text(report.license),
                              TextButton(
                                onPressed: () =>
                                    source(report, navigation: true),
                                child: Text(
                                  t(
                                    'Send destination to Google Maps (external)',
                                    'إرسال الوجهة إلى خرائط Google (خارجي)',
                                  ),
                                ),
                              ),
                              TextButton(
                                onPressed: () => source(report),
                                child: Text(
                                  t(
                                    'Open original source (external website)',
                                    'فتح المصدر الأصلي (موقع خارجي)',
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ],
              ),
            ),
    );
  }

  Widget reportMap(LicensedSample result) {
    final points = result.reports
        .where((r) => r.latitude.abs() <= 85.05112878)
        .toList();
    if (points.isEmpty)
      return Text(
        t(
          'Reports are outside the map projection; use the list.',
          'التقارير خارج نطاق عرض الخريطة؛ استخدم القائمة.',
        ),
      );
    return SizedBox(
      height: 300,
      child: FlutterMap(
        key: const Key('licensed-report-map'),
        options: MapOptions(
          initialCenter: LatLng(points.first.latitude, points.first.longitude),
          initialZoom: 11,
        ),
        children: [
          TileLayer(
            urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            userAgentPackageName: 'com.creabiz.ev',
            tileProvider: widget.mapTileProvider,
            panBuffer: 0,
            maxZoom: 19,
          ),
          MarkerLayer(
            markers: [
              for (final report in points)
                Marker(
                  point: LatLng(report.latitude, report.longitude),
                  width: 44,
                  height: 44,
                  child: Semantics(
                    label: t(
                      'Unverified crowd report: ${report.name}. Availability unknown.',
                      'تقرير مجتمعي غير مؤكد: ${report.name}. التوفر غير معروف.',
                    ),
                    child: Tooltip(
                      message: report.name,
                      child: const Icon(
                        Icons.location_on,
                        color: Colors.deepPurple,
                        size: 36,
                      ),
                    ),
                  ),
                ),
            ],
          ),
          RichAttributionWidget(
            attributions: [
              TextSourceAttribution(
                'OpenStreetMap contributors',
                onTap: () => launchUrl(
                  Uri.parse('https://www.openstreetmap.org/copyright'),
                  mode: LaunchMode.externalApplication,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String fieldLabel(String key) => switch (key) {
    'latitude' => t('Latitude', 'خط العرض'),
    'longitude' => t('Longitude', 'خط الطول'),
    'radius_km' => t('Radius (1–200 km)', 'النطاق (1–200 كم)'),
    'country' => t('Country ISO code (optional)', 'رمز الدولة (اختياري)'),
    'min_reported_power_kw' => t(
      'Minimum reported power (kW, optional)',
      'القدرة المبلغ عنها الدنيا (كيلوواط، اختياري)',
    ),
    'reported_connector' => t(
      'Reported connector (optional)',
      'الموصل المبلغ عنه (اختياري)',
    ),
    'operator_contains' => t(
      'Reported operator (optional)',
      'المشغل المبلغ عنه (اختياري)',
    ),
    _ => t(
      'Maximum report age (1–168 hours, optional)',
      'أقصى عمر للتقرير (1–168 ساعة، اختياري)',
    ),
  };
}
