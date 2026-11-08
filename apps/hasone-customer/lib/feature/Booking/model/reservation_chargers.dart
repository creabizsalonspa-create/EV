import '../../../core/service/network/network_client.dart';

bool validReservationCharger(String value) =>
    RegExp(r'^[A-Za-z0-9_-]{1,64}$').hasMatch(value);

class ReservationCharger {
  final String id, protocol;
  const ReservationCharger(this.id, this.protocol);
}

class ReservationChargers {
  final String stationId;
  final List<ReservationCharger> choices;
  const ReservationChargers(this.stationId, this.choices);
  factory ReservationChargers.fromJson(Object? value, String station) {
    if (value is! Map ||
        value['stationId'] != station ||
        value['readOnly'] != true ||
        value['reservationScope'] != 'station' ||
        value['independentCapacity'] != false ||
        value['hardwareAvailability'] != 'unverified' ||
        value['choices'] is! List) {
      throw const FormatException('Invalid reservation charger catalog');
    }
    final rows = value['choices'] as List;
    if (rows.length > 64) {
      throw const FormatException('Charger catalog limit exceeded');
    }
    final seen = <String>{}, choices = <ReservationCharger>[];
    for (final row in rows) {
      if (row is! Map ||
          row['chargerId'] is! String ||
          !validReservationCharger(row['chargerId']) ||
          !seen.add(row['chargerId']) ||
          !['ocpp1.6', 'ocpp2.0.1'].contains(row['protocol'])) {
        throw const FormatException('Invalid approved charger identity');
      }
      choices.add(ReservationCharger(row['chargerId'], row['protocol']));
    }
    return ReservationChargers(station, List.unmodifiable(choices));
  }
}

Future<ReservationChargers> loadReservationChargers(
  NetworkClient client,
  String station,
) async {
  if (!RegExp(r'^[a-fA-F0-9]{24}$').hasMatch(station)) {
    throw const FormatException('Invalid station');
  }
  final res = await client.getRequest(
    '/api/v1/stations/$station/reservation-chargers',
  );
  final body = res.responseData;
  if (!res.isSuccess || body is! Map || body['status'] != 'Success') {
    throw const FormatException('Approved chargers unavailable');
  }
  return ReservationChargers.fromJson(body['data'], station);
}
