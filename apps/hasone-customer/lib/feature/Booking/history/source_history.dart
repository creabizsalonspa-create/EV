import 'package:flutter/foundation.dart';

import '../../../core/service/network/network_client.dart';
import '../../../core/service/session/session.dart';

bool _id(Object? value) =>
    value is String && RegExp(r'^[a-fA-F0-9]{24}$').hasMatch(value);
int? _nonnegativeInt(Object? value) =>
    value is int && value >= 0 ? value : null;
DateTime? _instant(Object? value) =>
    value is String && RegExp(r'(Z|[+-]\d{2}:\d{2})$').hasMatch(value)
    ? DateTime.tryParse(value)?.toUtc()
    : null;

/// Private, immutable projection; no authorization credentials or raw proof
/// are retained. Meters and money require independent source qualification.
class SourceHistoryEntry {
  final String sessionId, bookingId, stationName, state;
  final String? chargerId, protocol;
  final bool sourceVerified, meterVerified, chargerEnabled;
  final int? energyWh, totalFils;
  final DateTime? startedAt, endedAt;

  const SourceHistoryEntry._({
    required this.sessionId,
    required this.bookingId,
    required this.stationName,
    required this.state,
    required this.chargerId,
    required this.protocol,
    required this.sourceVerified,
    required this.meterVerified,
    required this.chargerEnabled,
    required this.energyWh,
    required this.totalFils,
    required this.startedAt,
    required this.endedAt,
  });

  factory SourceHistoryEntry.fromJson(Map<String, dynamic> json) {
    if (!_id(json['sessionId']) ||
        !_id(json['bookingId']) ||
        json['readOnly'] != true) {
      throw const FormatException('Invalid source history identity');
    }
    final protocol = json['protocol'];
    final source =
        json['sourceVerified'] == true &&
        (protocol == 'ocpp1.6' || protocol == 'ocpp2.0.1');
    final wh = _nonnegativeInt(json['energyWh']);
    final kwh = json['energyKwh'];
    final meter =
        source &&
        json['meterVerified'] == true &&
        wh != null &&
        kwh is num &&
        kwh.isFinite &&
        kwh >= 0 &&
        (kwh * 1000 - wh).abs() < 0.0001;
    final start = source ? _instant(json['startedAt']) : null;
    final end = source ? _instant(json['endedAt']) : null;
    final ended =
        json['state'] == 'ended' &&
        start != null &&
        end != null &&
        !end.isBefore(start);
    final subtotal = _nonnegativeInt(json['subtotalFils']);
    final vat = _nonnegativeInt(json['vatFils']);
    final total = _nonnegativeInt(json['totalFils']);
    final calculated =
        meter &&
        ended &&
        json['billingStatus'] == 'calculated_unpaid' &&
        json['paymentStatus'] == 'not_collected' &&
        subtotal != null &&
        vat != null &&
        total != null &&
        subtotal + vat == total;
    return SourceHistoryEntry._(
      sessionId: json['sessionId'] as String,
      bookingId: json['bookingId'] as String,
      stationName: json['stationName'] is String ? json['stationName'] : '',
      chargerId: json['chargerId'] is String ? json['chargerId'] : null,
      protocol: source ? protocol as String : null,
      sourceVerified: source,
      meterVerified: meter,
      chargerEnabled: json['chargerEnabled'] == true,
      state: meter && ended
          ? 'ended'
          : meter && start != null && json['state'] == 'active'
          ? 'active'
          : 'unverified',
      energyWh: meter ? wh : null,
      totalFils: calculated ? total : null,
      startedAt: start,
      endedAt: ended ? end : null,
    );
  }
}

class SourceHistoryPage {
  final List<SourceHistoryEntry> entries;
  final String? nextCursor;
  final int unavailableCount;
  const SourceHistoryPage._(
    this.entries,
    this.nextCursor,
    this.unavailableCount,
  );

  factory SourceHistoryPage.fromJson(
    Map<String, dynamic> json, {
    String? cursor,
  }) {
    final data = json['data'];
    if (json['status'] != 'Success' ||
        data is! Map ||
        data['readOnly'] != true ||
        data['currency'] != 'AED' ||
        data['billingMode'] != 'calculation_only_no_collection' ||
        data['entries'] is! List ||
        data['hasMore'] is! bool) {
      throw const FormatException('Unsupported source history contract');
    }
    final raw = data['entries'] as List;
    final scanned = _nonnegativeInt(data['scanned']);
    final unavailable = _nonnegativeInt(data['unavailableCount']);
    final next = data['nextCursor'];
    if (raw.length > 25 ||
        scanned == null ||
        scanned > 25 ||
        unavailable == null ||
        scanned != raw.length + unavailable ||
        (data['hasMore'] == true ? !_id(next) || scanned == 0 : next != null) ||
        (cursor != null &&
            next is String &&
            next.toLowerCase().compareTo(cursor.toLowerCase()) >= 0)) {
      throw const FormatException('Invalid source history pagination');
    }
    final entries = <SourceHistoryEntry>[];
    String? previous = cursor?.toLowerCase();
    for (final row in raw) {
      if (row is! Map<String, dynamic>) {
        throw const FormatException('Invalid history row');
      }
      final entry = SourceHistoryEntry.fromJson(row);
      final id = entry.sessionId.toLowerCase();
      if (previous != null && id.compareTo(previous) >= 0 ||
          next is String && id.compareTo(next.toLowerCase()) < 0) {
        throw const FormatException('History cursor order changed');
      }
      entries.add(entry);
      previous = id;
    }
    return SourceHistoryPage._(
      List.unmodifiable(entries),
      next as String?,
      unavailable,
    );
  }
}

typedef HistoryLoader = Future<SourceHistoryPage> Function(String? cursor);

class SourceHistoryApi {
  final NetworkClient client;
  SourceHistoryApi(this.client);
  Future<SourceHistoryPage> load(String? cursor) async {
    final response = await client.getRequest(
      '/api/v1/ChargingSession/history',
      query: {'limit': '25', if (cursor != null) 'cursor': cursor},
    );
    if (!response.isSuccess || response.responseData is! Map<String, dynamic>) {
      throw const FormatException('Source history unavailable');
    }
    return SourceHistoryPage.fromJson(response.responseData, cursor: cursor);
  }
}

/// Owns only this screen's memory. Failed seeks keep their cursor for an
/// explicit retry. Backgrounding invalidates requests and removes private rows.
class SourceHistoryController extends ChangeNotifier {
  final HistoryLoader load;
  final String? Function() identity;
  SourceHistoryController({required this.load, String? Function()? identity})
    : identity = identity ?? (() => Session.accessToken);
  List<SourceHistoryEntry> _entries = const [];
  String? _owner, _cursor;
  int _generation = 0, _unavailableCount = 0;
  bool _loading = false, _failed = false, _suspended = false, _disposed = false;
  bool get _sameOwner => _owner != null && _owner == identity();
  List<SourceHistoryEntry> get entries => _sameOwner ? _entries : const [];
  bool get loading => _loading;
  bool get failed => _failed;
  bool get hasMore => _sameOwner && _cursor != null;
  int get unavailableCount => _sameOwner ? _unavailableCount : 0;

  Future<void> refresh() => _fetch(reset: true);
  Future<void> more() => _fetch(reset: false);
  Future<void> _fetch({required bool reset}) async {
    if (_disposed || _suspended || (!reset && (_loading || !hasMore))) return;
    if (reset) {
      _generation++;
      _entries = const [];
      _cursor = null;
      _unavailableCount = 0;
      _owner = identity();
      _loading = false;
      _failed = false;
    }
    if (_owner == null || _owner!.isEmpty || !_sameOwner) {
      notifyListeners();
      return;
    }
    final generation = _generation;
    final cursor = reset ? null : _cursor;
    _loading = true;
    _failed = false;
    notifyListeners();
    try {
      final page = await load(cursor);
      if (!_accept(generation)) return;
      _entries = List.unmodifiable([..._entries, ...page.entries]);
      _cursor = page.nextCursor;
      _unavailableCount += page.unavailableCount;
    } catch (_) {
      if (_accept(generation)) _failed = true;
    } finally {
      if (_accept(generation)) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  bool _accept(int generation) =>
      !_disposed && !_suspended && generation == _generation && _sameOwner;
  void suspend() {
    _generation++;
    _suspended = true;
    _entries = const [];
    _cursor = null;
    _owner = null;
    _loading = false;
    _failed = false;
    _unavailableCount = 0;
    notifyListeners();
  }

  Future<void> resume() {
    _suspended = false;
    return refresh();
  }

  @override
  void dispose() {
    _disposed = true;
    _generation++;
    _entries = const [];
    _owner = null;
    super.dispose();
  }
}
