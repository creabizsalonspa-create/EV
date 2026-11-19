import 'package:flutter/material.dart';
import 'api.dart';

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

class VendorSessionObservationsView extends StatefulWidget {
  final EvApi api;
  final int companyId;
  final VoidCallback onExpired;
  const VendorSessionObservationsView({
    super.key,
    required this.api,
    required this.companyId,
    required this.onExpired,
  });
  @override
  State<VendorSessionObservationsView> createState() =>
      _VendorSessionObservationsState();
}

class _VendorSessionObservationsState
    extends State<VendorSessionObservationsView>
    with WidgetsBindingObserver {
  VendorSessionObservationPage? data;
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
    if (old.api != widget.api || old.companyId != widget.companyId) {
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

  Future<void> load({int page = 1}) async {
    if (!mounted || !foreground || !authorized() || busy) return;
    if (!_integer(widget.companyId, positive: true)) {
      setState(() => error = 'invalid');
      return;
    }
    final run = ++generation,
        request = widget.api,
        company = widget.companyId,
        filter = status;
    setState(() {
      busy = true;
      error = null;
      data = null;
      target = page;
    });
    try {
      final value = await request.request(
        'vendor-ops/companies/$company/charging-sessions',
        query: {'page': '$page', if (filter != null) 'status': filter},
      );
      if (!current(run)) return;
      final parsed = VendorSessionObservationPage.parse(
        value,
        company: company,
        page: page,
        status: filter,
      );
      if (current(run)) setState(() => data = parsed);
    } catch (e) {
      if (current(run)) {
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
        if (current(run))
          setState(() {
            data = null;
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
  VoidCallback? readAction(int page) {
    final run = generation, request = widget.api, allowed = authorized;
    if (busy || !foreground || !allowed()) return null;
    return () {
      if (current(run) && request == widget.api && allowed() && !busy)
        load(page: page);
    };
  }

  @override
  Widget build(BuildContext context) {
    if (!authorized()) {
      data = null;
      error = null;
      busy = false;
    }
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
                  onPressed: readAction(1),
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
              Text(p.asOf, textDirection: TextDirection.ltr),
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
                        Text(
                          t(
                            'Record updated at UTC',
                            'تحديث السجل بالتوقيت العالمي',
                          ),
                        ),
                        Text(row.updated, textDirection: TextDirection.ltr),
                        if (row.stopped != null) ...[
                          Text(
                            t(
                              'Recorded stop at UTC',
                              'التوقف المسجل بالتوقيت العالمي',
                            ),
                          ),
                          Text(row.stopped!, textDirection: TextDirection.ltr),
                        ],
                      ],
                    ),
                  ),
                ),
              Text('${t('Page', 'الصفحة')} ${p.current} / ${p.last}'),
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
}
