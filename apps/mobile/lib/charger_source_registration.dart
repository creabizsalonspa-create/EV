import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

class ChargerSourceRegistrationSnapshot {
  final String state;
  final String? verifiedAt;
  final bool inventoryMatches, canInspect;
  final int? registrationId, sourceStationId;
  const ChargerSourceRegistrationSnapshot._(
    this.state,
    this.verifiedAt,
    this.inventoryMatches,
    this.canInspect,
    this.registrationId,
    this.sourceStationId,
  );
  static ChargerSourceRegistrationSnapshot parse(dynamic value) {
    const states = [
      'not_prepared',
      'prepared',
      'registering',
      'registered',
      'requires_inspection',
    ];
    if (value is! Map ||
        !states.contains(value['state']) ||
        value['source_snapshot_only'] != true ||
        value['live_connection_verified'] != false ||
        value['device_commands_sent'] is! int ||
        value['device_commands_sent'] != 0 ||
        value['current_inventory_matches_registration'] is! bool ||
        value['charging_capability_granted'] != false ||
        value['charger_hardware_verified'] != false ||
        value['automatic_retry_allowed'] != false ||
        value['can_prepare'] != false ||
        value['can_register'] != false) {
      throw const FormatException('Unsupported source registration evidence.');
    }
    bool identifier(dynamic v) => v is int && v > 0 && v <= 9007199254740991;
    final state = value['state'] as String;
    if (state == 'not_prepared') {
      if ([
        'registration_id',
        'binding_id',
        'profile_id',
        'source_station_id',
        'verified_at',
      ].any((key) => value[key] != null))
        throw const FormatException('Unexpected source registration.');
    } else if (![
      'registration_id',
      'binding_id',
      'profile_id',
    ].every((key) => identifier(value[key]))) {
      throw const FormatException('Invalid source registration identity.');
    }
    final registered = state == 'registered';
    if (value['upstream_registration_verified'] != registered ||
        value['evidence'] !=
            (registered
                ? 'authenticated_source_record_created_and_read_back'
                : 'no_verified_registration'))
      throw const FormatException('Conflicting source registration evidence.');
    if (registered &&
        (!identifier(value['source_station_id']) ||
            value['verified_at'] is! String ||
            !RegExp(
              r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$',
            ).hasMatch(value['verified_at']) ||
            DateTime.tryParse(value['verified_at']) == null))
      throw const FormatException('Missing source verification record.');
    if (!registered && value['verified_at'] != null)
      throw const FormatException('Unverified source timestamp.');
    if (value['source_station_id'] != null &&
        !identifier(value['source_station_id']))
      throw const FormatException('Invalid source identity.');
    return ChargerSourceRegistrationSnapshot._(
      state,
      value['verified_at'] as String?,
      value['current_inventory_matches_registration'] as bool,
      registered &&
          value['current_inventory_matches_registration'] == true &&
          value['can_inspect_connection'] == true,
      value['registration_id'] as int?,
      value['source_station_id'] as int?,
    );
  }
}

/// Vendor/customer read-only snapshot. Admin registration actions stay on web.
class ChargerSourceRegistrationView extends StatefulWidget {
  final EvApi api;
  final int companyId, chargerId;
  final VoidCallback onExpired;
  const ChargerSourceRegistrationView({
    super.key,
    required this.api,
    required this.companyId,
    required this.chargerId,
    required this.onExpired,
  });
  @override
  State<ChargerSourceRegistrationView> createState() => _RegistrationState();
}

class _RegistrationState extends State<ChargerSourceRegistrationView>
    with WidgetsBindingObserver {
  late bool Function() owner;
  ChargerSourceRegistrationSnapshot? data;
  bool busy = false, hidden = false;
  int generation = 0;
  Object? error;
  ({String state, String? observedAt, String? firmware})? health;
  String tr(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  @override
  void initState() {
    super.initState();
    owner = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void dispose() {
    generation++;
    data = null;
    health = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant ChargerSourceRegistrationView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.companyId != widget.companyId ||
        oldWidget.chargerId != widget.chargerId) {
      generation++;
      data = null;
      health = null;
      error = null;
      busy = false;
      owner = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    generation++;
    if (mounted)
      setState(() {
        hidden = state != AppLifecycleState.resumed;
        data = null;
        health = null;
        error = null;
        busy = false;
      });
  }

  Future<void> load({bool inspect = false}) async {
    final registration = data;
    if (inspect && registration?.canInspect != true) return;
    if (busy || hidden || !owner()) return;
    if (widget.companyId < 1 ||
        widget.chargerId < 1 ||
        widget.companyId > 9007199254740991 ||
        widget.chargerId > 9007199254740991) {
      setState(
        () => error = const FormatException('Invalid inventory identity.'),
      );
      return;
    }
    final api = widget.api,
        company = widget.companyId,
        charger = widget.chargerId,
        run = ++generation;
    bool current() =>
        mounted &&
        !hidden &&
        run == generation &&
        identical(api, widget.api) &&
        company == widget.companyId &&
        charger == widget.chargerId;
    setState(() {
      busy = true;
      if (!inspect) data = null;
      health = null;
      error = null;
    });
    try {
      final reply = await api.request(
        'vendor-ops/companies/$company/chargers/$charger/source-registration'
        '${inspect ? '/${registration!.registrationId}/connection-health' : ''}',
      );
      if (!current() || !owner()) return;
      if (inspect) {
        final state = reply is Map ? reply['connection_state'] : null;
        if (reply is! Map ||
            state is! String ||
            !['recent', 'stale', 'offline', 'unknown'].contains(state) ||
            reply['company_id'] != company ||
            reply['charger_id'] != charger ||
            reply['registration_id'] != registration!.registrationId ||
            reply['source_station_id'] != registration?.sourceStationId ||
            reply['evidence'] != 'AUTHENTICATED_REGISTERED_SOURCE' ||
            reply['device_commands_sent'] != 0 ||
            reply['charging_capability_granted'] != false ||
            reply['charger_hardware_verified'] != false) {
          throw const FormatException('Invalid source health evidence.');
        }
        final at = reply['source_last_message_at'];
        final firmware = reply['source_firmware_version'];
        if ((at != null &&
                (at is! String || DateTime.tryParse(at)?.isUtc != true)) ||
            (firmware != null && firmware is! String)) {
          throw const FormatException('Invalid source health metadata.');
        }
        setState(
          () => health = (
            state: state,
            observedAt: at as String?,
            firmware: firmware as String?,
          ),
        );
        return;
      }
      final parsed = ChargerSourceRegistrationSnapshot.parse(reply);
      setState(() => data = parsed);
    } catch (e) {
      if (!current()) return;
      if (e is ApiException && e.endsCurrentSession) {
        setState(() {
          data = null;
          health = null;
          busy = false;
        });
        widget.onExpired();
        return;
      }
      if (!owner()) return;
      setState(() => error = e);
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  String stateLabel(String state) => switch (state) {
    'not_prepared' => tr('No registration prepared', 'لم يُعدّ التسجيل'),
    'prepared' => tr(
      'Prepared; not registered upstream',
      'أُعدّ؛ لم يُسجّل لدى المصدر',
    ),
    'registering' => tr('Registration outcome pending', 'نتيجة التسجيل معلّقة'),
    'registered' => tr(
      'Source record created and read back',
      'أُنشئ سجل المصدر وقُرئ للتحقق',
    ),
    _ => tr(
      'Requires authorized inspection; do not retry automatically',
      'يتطلب فحصاً مصرحاً؛ لا تُعد المحاولة تلقائياً',
    ),
  };
  String healthLabel(String state) => switch (state) {
    'recent' => tr('Recent source message', 'رسالة حديثة من المصدر'),
    'stale' => tr('Source message is stale', 'رسالة المصدر قديمة'),
    'offline' => tr('Source reports offline', 'المصدر يبلغ أن الشاحن غير متصل'),
    _ => tr('Source message has not been observed', 'لم تُرصد رسالة من المصدر'),
  };

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(tr('Source registration', 'التسجيل لدى المصدر')),
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          tr(
            'Read-only source record snapshot. Registration does not prove a live charger connection, enable charging or verify physical hardware. Preparation and source registration require authorized web administration.',
            'لقطة سجل مصدر للقراءة فقط. لا يثبت التسجيل اتصالاً حياً بالشاحن ولا يتيح الشحن ولا يتحقق من الأجهزة الفعلية. يتطلب الإعداد والتسجيل إدارة ويب مصرحاً بها.',
          ),
        ),
        if (hidden || !owner())
          Text(
            tr(
              'Private status hidden. Return to your inventory to continue.',
              'حالة خاصة مخفية. ارجع إلى المخزون للمتابعة.',
            ),
          )
        else ...[
          if (busy) const LinearProgressIndicator(),
          if (data != null) ...[
            Text(
              stateLabel(data!.state),
              key: const Key('source-registration-state'),
            ),
            if (data!.state == 'registered' && !data!.inventoryMatches)
              Text(
                tr(
                  'Historical source receipt no longer matches the current inventory or source configuration. Authorized inspection is required.',
                  'لم يعد إيصال المصدر التاريخي يطابق المخزون الحالي أو إعداد المصدر. يلزم فحص مصرح به.',
                ),
              ),
            if (data!.verifiedAt != null)
              Text(
                '${tr('Recorded verification time', 'وقت التحقق المسجل')}: ${data!.verifiedAt}',
                textDirection: TextDirection.ltr,
              ),
          ],
          if (data?.canInspect == true)
            OutlinedButton(
              key: const Key('source-connection-inspect'),
              onPressed: busy ? null : () => load(inspect: true),
              child: Text(tr('Inspect source connection', 'فحص اتصال المصدر')),
            ),
          if (health case final observation?)
            Semantics(
              liveRegion: true,
              child: Text(
                [
                  healthLabel(observation.state),
                  if (observation.firmware != null) observation.firmware!,
                  if (observation.observedAt != null) observation.observedAt!,
                ].join(' · '),
              ),
            ),
          if (error != null)
            Semantics(
              liveRegion: true,
              child: Text(
                tr(
                  'Status is unavailable or requires review. Refresh to check authoritative records.',
                  'الحالة غير متاحة أو تتطلب مراجعة. حدّث للتحقق من السجلات الأصلية.',
                ),
              ),
            ),
          OutlinedButton(
            key: const Key('source-registration-refresh'),
            onPressed: busy ? null : load,
            child: Text(
              tr('Refresh registration status', 'تحديث حالة التسجيل'),
            ),
          ),
        ],
      ],
    ),
  );
}
