/// Remembers one uncertain reservation POST without replaying a different intent.
/// The server remains the authority for idempotent booking and connector capacity.
class ReservationIntentKeys {
  Object? _api;
  bool Function()? _fence;
  String? _fingerprint;
  String? _key;
  int _sequence = 0;

  String forBooking({
    required Object api,
    required int ownerId,
    required int stationId,
    required int? mappingId,
    required int? vehicleId,
    required String startsAtUtc,
    int? termsVersion,
    required bool Function() sessionFence,
  }) {
    if (!sessionFence()) {
      throw StateError('The booking session changed.');
    }
    final fingerprint =
        '$ownerId:$stationId:${mappingId ?? 0}:${vehicleId ?? 0}:$startsAtUtc:${termsVersion ?? 0}';
    if (identical(_api, api) &&
        _fence?.call() == true &&
        _fingerprint == fingerprint &&
        _key != null) {
      return _key!;
    }
    _api = api;
    _fence = sessionFence;
    _fingerprint = fingerprint;
    _sequence++;
    _key =
        'mobile-${DateTime.now().microsecondsSinceEpoch}-$stationId-$_sequence';
    return _key!;
  }

  void acknowledge(String operationKey) {
    if (_key == operationKey) clear();
  }

  void clear() {
    _api = null;
    _fence = null;
    _fingerprint = null;
    _key = null;
  }
}

/// The Laravel create/replay response is the persisted Reservation row.
/// A successful transport response alone cannot release a pending booking key.
bool reservationReceiptMatches(dynamic row, {
  required int ownerId,
  required int stationId,
  required int? mappingId,
  required int? vehicleId,
  required String startsAtUtc,
  required int? termsVersion,
  required String operationKey,
}) {
  if (row is! Map || row['id'] is! int || row['id'] <= 0 ||
      row['user_id'] is! int || row['user_id'] != ownerId ||
      row['station_id'] is! int || row['station_id'] != stationId ||
      row['connector_mapping_id'] != mappingId ||
      row['vehicle_id'] != vehicleId ||
      row['idempotency_key'] != operationKey ||
      row['status'] is! String || (row['status'] as String).isEmpty) return false;
  if ((mappingId != null && row['connector_mapping_id'] is! int) ||
      (vehicleId != null && row['vehicle_id'] is! int)) return false;
  // starts_at is stored as a UTC SQL timestamp (no model datetime cast).
  final sql = startsAtUtc.replaceFirst('T', ' ').replaceFirst(RegExp(r'Z$'), '');
  if (row['starts_at'] != sql && row['starts_at'] != startsAtUtc) return false;
  if (termsVersion != null) {
    final snapshot = row['reservation_terms_snapshot'];
    if (snapshot is! Map || snapshot['version'] is! int ||
        snapshot['version'] != termsVersion) return false;
  }
  return true;
}
