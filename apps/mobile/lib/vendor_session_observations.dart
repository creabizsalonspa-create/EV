import 'package:flutter/material.dart';
import 'api.dart';
import 'reservation_times.dart';

const vendorSessionStates = [
  'pending_start',
  'charging',
  'stopping',
  'stopped',
  'cancelled',
  'expired_unstarted',
  'unknown',
];

bool _integer(dynamic value, {bool positive = false}) =>
    value is int && value >= (positive ? 1 : 0) && value <= 9007199254740991;
String _instant(dynamic value) {
  final parsed = value is String ? DateTime.tryParse(value) : null;
  if (value is! String ||
      !RegExp(
        r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$',
      ).hasMatch(value) ||
      parsed == null ||
      !parsed.isUtc ||
      parsed.toIso8601String().substring(0, 19) != value.substring(0, 19)) {
    throw const FormatException('Invalid recorded time.');
  }
  return value;
}

class VendorSessionObservation {
  final int id, stationId;
  final String stationName, state, energyState, created, updated, evidence;
  final String? stopped, protocol;
  final int? energyWh;
  const VendorSessionObservation._(
    this.id,
    this.stationId,
    this.stationName,
    this.state,
    this.energyState,
    this.energyWh,
    this.created,
    this.updated,
    this.stopped,
    this.protocol,
    this.evidence,
  );
  factory VendorSessionObservation.parse(Map<String, dynamic> row) {
    final energy = row['energy_wh'], energyState = row['energy_state'];
    if (!_integer(row['id'], positive: true) ||
        !_integer(row['station_id'], positive: true) ||
        row['station_name'] is! String ||
        (row['station_name'] as String).isEmpty ||
        (row['station_name'] as String).runes.length > 255 ||
        !vendorSessionStates.contains(row['recorded_status']) ||
        !['recorded', 'unavailable'].contains(energyState) ||
        (energyState == 'recorded' ? !_integer(energy) : energy != null) ||
        ![null, '1.6', '2.0.1'].contains(row['protocol']) ||
        ![
          'SIMULATED',
          'SIMULATED_CSMS',
          'RECORDED_SOURCE_UNVERIFIED',
        ].contains(row['evidence']) ||
        (row['evidence'] == 'SIMULATED_CSMS' && row['protocol'] == null) ||
        (row['evidence'] == 'SIMULATED' && row['protocol'] != null) ||
        row['physical_charging_verified'] != false ||
        row['live_availability_verified'] != false) {
      throw const FormatException('Unsupported session observation.');
    }
    return VendorSessionObservation._(
      row['id'],
      row['station_id'],
      row['station_name'],
      row['recorded_status'],
      energyState,
      energy,
      _instant(row['record_created_at']),
      _instant(row['record_updated_at']),
      row['stopped_at'] == null ? null : _instant(row['stopped_at']),
      row['protocol'],
      row['evidence'],
    );
  }
}

class VendorSessionObservationPage {
  final List<VendorSessionObservation> items;
  final int current, last, total;
  final String asOf;
  const VendorSessionObservationPage._(
    this.items,
    this.current,
    this.last,
    this.total,
    this.asOf,
  );
  factory VendorSessionObservationPage.parse(
    dynamic value, {
    required int company,
    required int page,
    String? status,
  }) {
    if (value is! Map ||
        value['scope'] != 'company_recorded_charging_sessions' ||
        value['company_id'] != company ||
        !_integer(value['company_id'], positive: true) ||
        value['status_filter'] != status ||
        value['inventory_station_scope'] != 'current_owned_unique_site_links' ||
        value['customer_identity_fields_included'] != false ||
        value['financial_fields_included'] != false ||
        value['control_actions_exposed'] != false ||
        value['physical_charging_verified'] != false ||
        value['live_availability_verified'] != false ||
        value['data'] is! List ||
        (value['data'] as List).length > 25 ||
        value['per_page'] != 25 ||
        value['current_page'] != page ||
        !_integer(value['current_page'], positive: true) ||
        page > 100000 ||
        !_integer(value['last_page'], positive: true) ||
        !_integer(value['total'])) {
      throw const FormatException('Unsupported company page.');
    }
    final rows = (value['data'] as List).map((dynamic row) {
      if (row is! Map || row.keys.any((key) => key is! String))
        throw const FormatException('Invalid observation row.');
      return VendorSessionObservation.parse(Map<String, dynamic>.from(row));
    }).toList();
    final int total = value['total'], last = value['last_page'];
    if (last != (total == 0 ? 1 : (total - 1) ~/ 25 + 1) ||
        rows.length > total ||
        (page > last && rows.isNotEmpty) ||
        rows.map((row) => row.id).toSet().length != rows.length ||
        List.generate(
          rows.length > 0 ? rows.length - 1 : 0,
          (i) => rows[i].id > rows[i + 1].id,
        ).any((ordered) => !ordered)) {
      throw const FormatException('Incoherent company pagination.');
    }
    return VendorSessionObservationPage._(
      List.unmodifiable(rows),
      page,
      last,
      total,
      _instant(value['as_of']),
    );
  }
}

class VendorMeterObservation {
  final int id, sequence, counterWh;
  final String kind, occurred, recorded;
  const VendorMeterObservation(
    this.id,
    this.sequence,
    this.counterWh,
    this.kind,
    this.occurred,
    this.recorded,
  );
  factory VendorMeterObservation.parse(dynamic value) {
    if (value is! Map ||
        !_integer(value['id'], positive: true) ||
        !_integer(value['sequence']) ||
        !_integer(value['cumulative_meter_wh']) ||
        !['started', 'meter', 'ended'].contains(value['kind']))
      throw const FormatException('Invalid recorded counter.');
    return VendorMeterObservation(
      value['id'],
      value['sequence'],
      value['cumulative_meter_wh'],
      value['kind'],
      _instant(value['source_occurred_at']),
      _instant(value['recorded_at']),
    );
  }
}

class VendorMeterObservationPage {
  final List<VendorMeterObservation> items;
  final int current, last, total, through;
  final bool sourceLinked;
  final String evidence, asOf;
  final String? protocol;
  const VendorMeterObservationPage._(
    this.items,
    this.current,
    this.last,
    this.total,
    this.through,
    this.sourceLinked,
    this.protocol,
    this.evidence,
    this.asOf,
  );
  factory VendorMeterObservationPage.parse(
    dynamic v, {
    required int company,
    required int session,
    required int page,
    int? through,
  }) {
    if (v is! Map ||
        v['scope'] != 'company_csms_meter_history' ||
        !_integer(v['company_id'], positive: true) ||
        v['company_id'] != company ||
        !_integer(v['session_id'], positive: true) ||
        v['session_id'] != session ||
        !_integer(v['through_id']) ||
        (through != null && v['through_id'] != through) ||
        v['source_linked'] is! bool ||
        v['coverage'] != 'RECORDED_APPLIED_CSMS_EVENTS' ||
        v['history_complete'] != false ||
        v['billing_authority'] != false ||
        v['physical_energy_verified'] != false ||
        v['commands_sent'] != false ||
        v['network_requests'] is! int ||
        v['network_requests'] != 0 ||
        v['observations'] is! Map)
      throw const FormatException('Invalid recorded meter scope.');
    final p = v['observations'];
    if (p['data'] is! List ||
        (p['data'] as List).length > 25 ||
        p['per_page'] != 25 ||
        !_integer(p['current_page'], positive: true) ||
        p['current_page'] != page ||
        page > 100000 ||
        !_integer(p['last_page'], positive: true) ||
        !_integer(p['total']))
      throw const FormatException('Invalid recorded meter page.');
    final rows = (p['data'] as List).map(VendorMeterObservation.parse).toList();
    final int total = p['total'], last = p['last_page'], cut = v['through_id'];
    if (last != (total == 0 ? 1 : (total - 1) ~/ 25 + 1) ||
        rows.length > total ||
        (page > last && rows.isNotEmpty) ||
        rows.map((r) => r.id).toSet().length != rows.length ||
        rows.any((r) => r.id > cut) ||
        List.generate(
          rows.isEmpty ? 0 : rows.length - 1,
          (i) => rows[i].id > rows[i + 1].id,
        ).any((ordered) => !ordered) ||
        (cut == 0 && total != 0) ||
        (v['source_linked'] == true
            ? (!['1.6', '2.0.1'].contains(v['protocol']) ||
                  ![
                    'SIMULATED_CSMS',
                    'RECORDED_SOURCE_UNVERIFIED',
                  ].contains(v['evidence']))
            : (v['protocol'] != null ||
                  v['evidence'] != 'NO_RECORDED_CSMS_LINK' ||
                  total != 0 ||
                  cut != 0)))
      throw const FormatException('Incoherent recorded meter history.');
    return VendorMeterObservationPage._(
      List.unmodifiable(rows),
      page,
      last,
      total,
      cut,
      v['source_linked'],
      v['protocol'],
      v['evidence'],
      _instant(v['as_of']),
    );
  }
}

class VendorSessionObservationsView extends StatefulWidget {
  final EvApi api;
  final int companyId;
  final int? sessionId;
  final VoidCallback onExpired;
  const VendorSessionObservationsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.onExpired,
    this.sessionId,
  });
  @override
  State<VendorSessionObservationsView> createState() =>
      _VendorSessionObservationsState();
}

class _VendorSessionObservationsState
    extends State<VendorSessionObservationsView>
    with WidgetsBindingObserver {
  VendorSessionObservationPage? data;
  VendorMeterObservationPage? meterData;
  int? through;
  late bool Function() authorized;
  late bool foreground;
  bool busy = false;
  int generation = 0, target = 1;
  String? status, error;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int run) =>
      mounted && foreground && authorized() && run == generation;
  void clear() {
    generation++;
    data = null;
    meterData = null;
    through = null;
    error = null;
    busy = false;
    target = 1;
    status = null;
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    foreground =
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant VendorSessionObservationsView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api ||
        old.companyId != widget.companyId ||
        old.sessionId != widget.sessionId) {
      clear();
      status = null;
      authorized = widget.api.sessionFence();
      if (foreground) load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (mounted)
      setState(() {
        foreground = state == AppLifecycleState.resumed;
        if (!foreground) clear();
      });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int page = 1, bool refresh = false}) async {
    if (!mounted || !foreground || !authorized() || busy) return;
    if (!_integer(widget.companyId, positive: true) ||
        (widget.sessionId != null &&
            !_integer(widget.sessionId, positive: true))) {
      setState(() => error = 'invalid');
      return;
    }
    final run = ++generation,
        request = widget.api,
        company = widget.companyId,
        filter = status,
        session = widget.sessionId,
        cut = refresh ? null : through;
    setState(() {
      busy = true;
      error = null;
      data = null;
      meterData = null;
      if (refresh) through = null;
      target = page;
    });
    try {
      final value = await request.request(
        'vendor-ops/companies/$company/charging-sessions${session == null ? '' : '/$session/meter-history'}',
        query: {
          'page': '$page',
          if (session == null && filter != null) 'status': filter,
          if (session != null && cut != null) 'through': '$cut',
        },
      );
      if (!current(run)) return;
      if (session != null) {
        final parsed = VendorMeterObservationPage.parse(
          value,
          company: company,
          session: session,
          page: page,
          through: cut,
        );
        if (current(run))
          setState(() {
            meterData = parsed;
            through = parsed.through;
          });
      } else {
        final parsed = VendorSessionObservationPage.parse(
          value,
          company: company,
          page: page,
          status: filter,
        );
        if (current(run)) setState(() => data = parsed);
      }
    } catch (e) {
      if (mounted &&
          foreground &&
          generation == run &&
          request == widget.api &&
          e is ApiException &&
          e.endsCurrentSession) {
        setState(clear);
        widget.onExpired();
        return;
      }
      if (current(run)) {
        if (e is ApiException && [401, 409, 419].contains(e.status))
          widget.onExpired();
        if (current(run))
          setState(() {
            data = null;
            meterData = null;
            error = 'unavailable';
          });
      }
    } finally {
      if (current(run))
        setState(() => busy = false);
      else if (mounted && generation == run && !authorized())
        setState(clear);
    }
  }

  String stateLabel(String state) => switch (state) {
    'pending_start' => t('Recorded start pending', 'بدء مسجل قيد الانتظار'),
    'charging' => t('Recorded charging', 'شحن مسجل'),
    'stopping' => t('Recorded stop pending', 'إيقاف مسجل قيد الانتظار'),
    'stopped' => t('Recorded stopped', 'متوقف مسجل'),
    'cancelled' => t('Cancelled before start', 'ملغى قبل البدء'),
    'expired_unstarted' => t('Expired without starting', 'انتهى دون بدء'),
    _ => t('Unknown recorded status', 'حالة مسجلة غير معروفة'),
  };
  String evidenceLabel(String value) => switch (value) {
    'SIMULATED' => t('Simulated record', 'سجل تجريبي'),
    'SIMULATED_CSMS' => t('Simulated CSMS record', 'سجل نظام شحن تجريبي'),
    _ => t(
      'Recorded source; physical charging unverified',
      'مصدر مسجل؛ الشحن الفعلي غير موثق',
    ),
  };
  VoidCallback? readAction(int page, {bool refresh = false}) {
    final run = generation, request = widget.api, allowed = authorized;
    if (busy || !foreground || !allowed()) return null;
    return () {
      if (current(run) && request == widget.api && allowed() && !busy)
        load(page: page, refresh: refresh);
    };
  }

  VoidCallback? meterAction(int session) {
    final run = generation,
        request = widget.api,
        company = widget.companyId,
        allowed = authorized;
    if (busy || !foreground || !allowed()) return null;
    return () {
      if (!current(run) || request != widget.api || !allowed()) return;
      setState(clear);
      final destination = generation;
      Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) =>
              mounted &&
                  foreground &&
                  allowed() &&
                  request == widget.api &&
                  company == widget.companyId &&
                  destination == generation
              ? VendorSessionObservationsView(
                  api: request,
                  companyId: company,
                  sessionId: session,
                  onExpired: widget.onExpired,
                )
              : const Scaffold(body: SizedBox.shrink()),
        ),
      );
    };
  }

  Widget meterView() {
    final p = meterData;
    return Scaffold(
      appBar: AppBar(
        title: Text(t('Recorded meter history', 'سجل العدادات المسجل')),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              t(
                'Cumulative source counter readings are not consumed or billed energy. This retained applied-event snapshot is not a complete charging history or verified physical measurement.',
                'قراءات العداد التراكمية من المصدر ليست طاقة مستهلكة أو مفوترة. لقطة الأحداث المطبقة المحتفظ بها ليست سجلاً كاملاً للشحن أو قياساً فعلياً موثقاً.',
              ),
            ),
            Wrap(
              spacing: 4,
              children: [
                Text(t('Session', 'الجلسة')),
                Text('#${widget.sessionId}', textDirection: TextDirection.ltr),
              ],
            ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: readAction(1, refresh: true),
                  child: Text(
                    t('Refresh recorded snapshot', 'تحديث لقطة السجل'),
                  ),
                ),
                if (error != null)
                  OutlinedButton(
                    onPressed: readAction(target),
                    child: Text(t('Retry this page', 'إعادة قراءة هذه الصفحة')),
                  ),
              ],
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              Text(
                t(
                  'Recorded meter history unavailable. Retry this read explicitly.',
                  'سجل العدادات غير متاح. أعد هذه القراءة صراحة.',
                ),
              ),
            if (p != null) ...[
              Text(
                p.sourceLinked
                    ? evidenceLabel(p.evidence)
                    : t(
                        'No recorded CSMS link. No meter history is invented.',
                        'لا يوجد ربط مسجل بنظام الشحن. لا يتم إنشاء سجل عداد افتراضي.',
                      ),
              ),
              if (p.protocol != null) Text('OCPP ${p.protocol}'),
              Text(t('Observed at UTC', 'تمت الملاحظة بالتوقيت العالمي')),
              Text(serverEventTime(context, p.asOf)),
              if (p.items.isEmpty)
                Text(
                  t(
                    'No applied source events in this recorded snapshot.',
                    'لا توجد أحداث مصدر مطبقة في هذه اللقطة المسجلة.',
                  ),
                ),
              for (final row in p.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(switch (row.kind) {
                          'started' => t('Recorded start', 'بداية مسجلة'),
                          'ended' => t('Recorded end', 'نهاية مسجلة'),
                          _ => t('Recorded meter', 'عداد مسجل'),
                        }),
                        Wrap(
                          spacing: 4,
                          children: [
                            Text(
                              '${t('Cumulative counter', 'العداد التراكمي')}: ',
                            ),
                            Text(
                              '${row.counterWh} Wh',
                              textDirection: TextDirection.ltr,
                            ),
                          ],
                        ),
                        Text('${t('Sequence', 'التسلسل')}: ${row.sequence}'),
                        Text(
                          t(
                            'Source timestamp UTC',
                            'توقيت المصدر بالتوقيت العالمي',
                          ),
                        ),
                        Text(row.occurred, textDirection: TextDirection.ltr),
                        Text(
                          t('Recorded at UTC', 'وقت التسجيل بالتوقيت العالمي'),
                        ),
                        Text(row.recorded, textDirection: TextDirection.ltr),
                      ],
                    ),
                  ),
                ),
              Wrap(
                spacing: 4,
                children: [
                  Text(t('Page', 'الصفحة')),
                  Text(
                    '${p.current} / ${p.last}',
                    textDirection: TextDirection.ltr,
                  ),
                ],
              ),
              Wrap(
                spacing: 8,
                children: [
                  OutlinedButton(
                    onPressed: p.current > 1 ? readAction(p.current - 1) : null,
                    child: Text(t('Previous', 'السابق')),
                  ),
                  OutlinedButton(
                    onPressed: p.current < p.last
                        ? readAction(p.current + 1)
                        : null,
                    child: Text(t('Next', 'التالي')),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!authorized()) {
      meterData = null;
      through = null;
      data = null;
      error = null;
      busy = false;
    }
    if (widget.sessionId != null) return meterView();
    final p = data;
    final filterRun = generation,
        filterApi = widget.api,
        filterFence = authorized;
    return Scaffold(
      appBar: AppBar(
        title: Text(t('Recorded charging sessions', 'جلسات الشحن المسجلة')),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              t(
                'Company-scoped, read-only records. Only currently owned stations linked to this company are included. Customer identities, financial fields and charger controls are excluded. Recorded state is not live availability or verified physical charging.',
                'سجلات للقراءة فقط ضمن الشركة. تُدرج فقط المحطات المملوكة حالياً والمرتبطة بهذه الشركة. تُستبعد هوية العملاء والحقول المالية والتحكم بالشواحن. الحالة المسجلة ليست إتاحة مباشرة أو شحناً فعلياً موثقاً.',
              ),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey('session-filter-${widget.companyId}-$status'),
              initialValue: status ?? '',
              isExpanded: true,
              decoration: InputDecoration(
                labelText: t('Recorded status filter', 'مرشح الحالة المسجلة'),
              ),
              items: [
                DropdownMenuItem(
                  value: '',
                  child: Text(t('All recorded states', 'جميع الحالات المسجلة')),
                ),
                for (final value in vendorSessionStates)
                  DropdownMenuItem(
                    value: value,
                    child: Text(
                      stateLabel(value),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: busy || !foreground || !authorized()
                  ? null
                  : (value) {
                      if (!current(filterRun) ||
                          filterApi != widget.api ||
                          !filterFence() ||
                          busy ||
                          (value != '' && !vendorSessionStates.contains(value)))
                        return;
                      setState(() {
                        clear();
                        status = value == '' ? null : value;
                      });
                      load();
                    },
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: readAction(1, refresh: true),
                  child: Text(t('Read first page', 'قراءة الصفحة الأولى')),
                ),
                if (error != null)
                  OutlinedButton(
                    onPressed: readAction(target),
                    child: Text(t('Retry this page', 'إعادة قراءة هذه الصفحة')),
                  ),
              ],
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              Text(
                t(
                  'Session observations unavailable. Read again explicitly; no command was sent.',
                  'ملاحظات الجلسات غير متاحة. أعد القراءة صراحة؛ لم يُرسل أمر.',
                ),
              ),
            if (p != null) ...[
              Text(
                t(
                  'Recorded sessions: ${p.total}',
                  'الجلسات المسجلة: ${p.total}',
                ),
              ),
              Text(t('Observed at UTC', 'تمت الملاحظة بالتوقيت العالمي')),
              Text(serverEventTime(context, p.asOf)),
              Wrap(
                spacing: 4,
                children: [
                  Text(t('Page', 'الصفحة')),
                  Text(
                    '${p.current} / ${p.last}',
                    textDirection: TextDirection.ltr,
                  ),
                ],
              ),
              Wrap(
                spacing: 8,
                children: [
                  OutlinedButton(
                    onPressed: p.current > 1 ? readAction(p.current - 1) : null,
                    child: Text(t('Previous', 'السابق')),
                  ),
                  OutlinedButton(
                    onPressed: p.current < p.last
                        ? readAction(p.current + 1)
                        : null,
                    child: Text(t('Next', 'التالي')),
                  ),
                ],
              ),
              if (p.items.isEmpty)
                Text(
                  t(
                    'No records in this filtered scope.',
                    'لا توجد سجلات ضمن النطاق المرشح.',
                  ),
                ),
              for (final row in p.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          row.stationName,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        Text(
                          '${t('Session', 'الجلسة')} #${row.id} · ${stateLabel(row.state)}',
                        ),
                        Text(
                          row.energyWh == null
                              ? t(
                                  'Recorded energy unavailable',
                                  'الطاقة المسجلة غير متاحة',
                                )
                              : '${t('Recorded energy', 'الطاقة المسجلة')}: ${row.energyWh} Wh',
                        ),
                        Text(evidenceLabel(row.evidence)),
                        OutlinedButton(
                          key: ValueKey('meter-history-${row.id}'),
                          onPressed: meterAction(row.id),
                          child: Text(
                            t('Recorded meter history', 'سجل العدادات المسجل'),
                          ),
                        ),
                        Text(
                          t(
                            'Record updated at UTC',
                            'تحديث السجل بالتوقيت العالمي',
                          ),
                        ),
                        Text(serverEventTime(context, row.updated)),
                        if (row.stopped != null) ...[
                          Text(
                            t(
                              'Recorded stop at UTC',
                              'التوقف المسجل بالتوقيت العالمي',
                            ),
                          ),
                          Text(serverEventTime(context, row.stopped)),
                        ],
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
}
