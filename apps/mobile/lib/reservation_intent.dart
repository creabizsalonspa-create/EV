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
