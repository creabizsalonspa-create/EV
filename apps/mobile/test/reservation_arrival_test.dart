import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/reservation_arrival.dart';
import 'test_support.dart';

class ArrivalApi extends FixtureApi {
  int writes = 0;
  Object? failure;
  Completer<void>? pending;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'reservations/7/check-in');
    expect(method, 'POST');
    expect(body, {'self_reported_arrival': true});
    writes++;
    if (pending != null) await pending!.future;
    if (failure != null) throw failure!;
    return {'physical_presence_verified': false};
  }
}

Widget app(Widget child, {bool arabic = false}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: Scaffold(body: child),
);
void main() {
  test(
    'arrival translations have equal keys and explicit physical-presence limits',
    () {
      expect(arrivalAr.keys.toSet(), arrivalEn.keys.toSet());
      expect(
        arrivalEn['consent'],
        contains('does not verify physical presence'),
      );
    },
  );
  testWidgets(
    'consent is required and successful POST refreshes authoritative records once',
    (t) async {
      final api = ArrivalApi();
      int changed = 0;
      await t.pumpWidget(
        app(
          ReservationArrivalButton(
            api: api,
            reservationId: 7,
            onChanged: () async {
              changed++;
            },
            onExpired: () {},
          ),
        ),
      );
      await t.tap(find.text('Check in'));
      await t.pumpAndSettle();
      await t.tap(find.text('Cancel'));
      await t.pumpAndSettle();
      expect(api.writes, 0);
      await t.tap(find.text('Check in'));
      await t.pumpAndSettle();
      await t.tap(find.text('Confirm'));
      await t.pumpAndSettle();
      expect(api.writes, 1);
      expect(changed, 1);
    },
  );
  testWidgets(
    'lost acknowledgement never automatically repeats a write or exposes diagnostics',
    (t) async {
      final api = ArrivalApi()
        ..failure = const ApiException(0, 'private internal diagnostic');
      await t.pumpWidget(
        app(
          ReservationArrivalButton(
            api: api,
            reservationId: 7,
            onChanged: () async {},
            onExpired: () {},
          ),
        ),
      );
      await t.tap(find.text('Check in'));
      await t.pumpAndSettle();
      await t.tap(find.text('Confirm'));
      await t.pumpAndSettle();
      await t.pump(const Duration(seconds: 30));
      expect(api.writes, 1);
      expect(find.textContaining('private internal'), findsNothing);
    },
  );
  testWidgets('expired authentication reaches root handler', (t) async {
    final api = ArrivalApi()..failure = const ApiException(401, 'expired');
    int expired = 0;
    await t.pumpWidget(
      app(
        ReservationArrivalButton(
          api: api,
          reservationId: 7,
          onChanged: () async {},
          onExpired: () {
            expired++;
          },
        ),
      ),
    );
    await t.tap(find.text('Check in'));
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm'));
    await t.pumpAndSettle();
    expect(expired, 1);
  });
  testWidgets(
    'disposed in-flight response does not refresh or signal stale scope',
    (t) async {
      final api = ArrivalApi()..pending = Completer<void>();
      int changed = 0;
      await t.pumpWidget(
        app(
          ReservationArrivalButton(
            api: api,
            reservationId: 7,
            onChanged: () async {
              changed++;
            },
            onExpired: () {
              changed++;
            },
          ),
        ),
      );
      await t.tap(find.text('Check in'));
      await t.pumpAndSettle();
      await t.tap(find.text('Confirm'));
      await t.pump();
      await t.pumpWidget(app(const SizedBox()));
      api.pending!.complete();
      await t.pumpAndSettle();
      expect(changed, 0);
      expect(t.takeException(), isNull);
    },
  );
  testWidgets('Arabic narrow layout has RTL consent and no overflow', (
    t,
  ) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    addTearDown(t.view.resetDevicePixelRatio);
    await t.pumpWidget(
      app(
        ReservationArrivalButton(
          api: ArrivalApi(),
          reservationId: 7,
          onChanged: () async {},
          onExpired: () {},
        ),
        arabic: true,
      ),
    );
    await t.tap(find.text(arrivalAr['title']!));
    await t.pumpAndSettle();
    expect(
      Directionality.of(t.element(find.text(arrivalAr['consent']!))),
      TextDirection.rtl,
    );
    expect(t.takeException(), isNull);
  });
}
