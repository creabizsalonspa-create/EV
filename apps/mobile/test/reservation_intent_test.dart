import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/reservation_intent.dart';

void main() {
  test('booking receipt binds exact owner, station, connector, vehicle, UTC and policy', () {
    final valid = <String,dynamic>{
      'id':41,'user_id':11,'station_id':20,'connector_mapping_id':30,
      'vehicle_id':40,'starts_at':'2026-11-12 12:00:00',
      'idempotency_key':'booking-key','status':'confirmed',
      'reservation_terms_snapshot':{'version':2},
    };
    bool matches(dynamic row) => reservationReceiptMatches(row,
      ownerId:11,stationId:20,mappingId:30,vehicleId:40,
      startsAtUtc:'2026-11-12T12:00:00Z',termsVersion:2,
      operationKey:'booking-key');
    expect(matches(valid),isTrue);
    expect(matches({...valid,'status':'cancelled'}),isTrue);
    expect(matches({...valid,'starts_at':'2026-11-12T12:00:00Z'}),isTrue);
    for (final row in [null,{'id':41},{...valid,'user_id':12},
      {...valid,'station_id':21},{...valid,'connector_mapping_id':31},
      {...valid,'vehicle_id':null},{...valid,'vehicle_id':40.0},
      {...valid,'starts_at':'2026-11-12 12:30:00'},
      {...valid,'idempotency_key':'different'},
      {...valid,'reservation_terms_snapshot':{'version':3}},
      {...valid,'status':null}]){
      expect(matches(row),isFalse,reason:'Wrong receipt: $row');
    }
  });

  test('uncertain response retries the exact same booking key', () {
    final keys = ReservationIntentKeys();
    final api = Object();
    const starts = '2026-11-12T12:00:00Z';
    String attempt() => keys.forBooking(
      api: api,
      ownerId: 11,
      stationId: 20,
      mappingId: 30,
      vehicleId: 40,
      startsAtUtc: starts,
      sessionFence: () => true,
    );
    final first = attempt();
    expect(attempt(), first);
    keys.acknowledge('different-key');
    expect(attempt(), first);
    keys.acknowledge(first);
    expect(attempt(), isNot(first));
  });

  test('vehicle, slot and connector changes each create a new operation', () {
    final keys = ReservationIntentKeys();
    final api = Object();
    String attempt({int? mapping = 3, int? vehicle = 5, String time = '2026-11-12T12:00:00Z'}) =>
      keys.forBooking(
        api: api,
        ownerId: 2,
        stationId: 7,
        mappingId: mapping,
        vehicleId: vehicle,
        startsAtUtc: time,
        sessionFence: () => true,
      );
    final first = attempt();
    final differentVehicle = attempt(vehicle: 6);
    final differentConnector = attempt(mapping: 4, vehicle: 6);
    final differentSlot = attempt(mapping: 4, vehicle: 6, time: '2026-11-12T12:30:00Z');
    expect({first, differentVehicle, differentConnector, differentSlot}.length, 4);
  });

  test('account or authenticated session switch invalidates pending key', () {
    final keys = ReservationIntentKeys();
    final api = Object();
    var epoch = 1;
    String attempt(int owner) {
      final captured = epoch;
      return keys.forBooking(
        api: api,
        ownerId: owner,
        stationId: 20,
        mappingId: null,
        vehicleId: null,
        startsAtUtc: '2026-11-12T12:00:00Z',
        sessionFence: () => captured == epoch,
      );
    }
    final first = attempt(11);
    epoch++;
    final afterSessionSwitch = attempt(11);
    final afterAccountSwitch = attempt(12);
    expect(first, isNot(afterSessionSwitch));
    expect(afterSessionSwitch, isNot(afterAccountSwitch));
  });
}
