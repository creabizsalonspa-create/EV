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

class BoundArrivalApi extends FixtureApi {
  int epoch = 0;
  final changes = StreamController<int>.broadcast(sync:true);
  @override
  Stream<int> get sessionEvents => changes.stream;
  void rotate() { epoch++; changes.add(epoch); }
  int writes = 0;
  final paths = <String>[];
  Completer<void>? pending;

  @override
  bool Function() sessionFence() {
    final original = epoch;
    return () => original == epoch;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'POST');
    expect(body, {'self_reported_arrival': true});
    writes++;
    paths.add(path);
    final waiter = pending;
    if (waiter != null) await waiter.future;
    return {'physical_presence_verified': false};
  }
}

Widget scopedArrival(
  BoundArrivalApi api,
  int id, {
  VoidCallback? onExpired,
  Future<void> Function()? onChanged,
}) => app(ReservationArrivalButton(
  api: api,
  reservationId: id,
  onChanged: onChanged ?? () async {},
  onExpired: onExpired ?? () {},
));

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
  test('recorded expiry distinguishes no report, self-reported arrival, and unsent start', () {
    expect(
      reservationExpiryText('unused_without_reported_arrival', arabic: false),
      contains('no recorded arrival report'),
    );
    expect(
      reservationExpiryText('unused_after_reported_arrival', arabic: false),
      contains('after a self-reported arrival'),
    );
    expect(
      reservationExpiryText('expired_before_dispatch', arabic: true),
      contains('قبل إرسال'),
    );
    expect(reservationExpiryText('outcome_unknown', arabic: false), isNull);
    expect(reservationExpiryText(null, arabic: true), isNull);
  });

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
  testWidgets('same API credential rotation inside check-in consent sends no old reservation POST', (
    tester,
  ) async {
    final api = BoundArrivalApi();
    await tester.pumpWidget(scopedArrival(api, 7));
    await tester.tap(find.text('Check in'));
    await tester.pumpAndSettle();
    expect(find.text(arrivalEn['consent']!), findsOneWidget);
    api.epoch++;
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.writes, 0);
    expect(find.text('Check in'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('same StatefulWidget with new reservation ignores old modal and retained button', (
    tester,
  ) async {
    final api = BoundArrivalApi();
    var changed = 0;
    await tester.pumpWidget(scopedArrival(api, 7, onChanged: () async {
      changed++;
    }));
    final previousButton = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Check in'),
    ).onPressed!;
    await tester.tap(find.text('Check in'));
    await tester.pumpAndSettle();
    await tester.pumpWidget(scopedArrival(api, 8, onChanged: () async {
      changed++;
    }));
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    previousButton();
    await tester.pumpAndSettle();
    expect(api.writes, 0);
    expect(find.byType(AlertDialog), findsNothing);
    await tester.tap(find.text('Check in'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.paths, ['reservations/8/check-in']);
    expect(changed, 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets('late stale 401 never expires successor or refreshes wrong customer', (
    tester,
  ) async {
    final former = BoundArrivalApi()..pending = Completer<void>();
    final next = BoundArrivalApi();
    var expires = 0, refreshed = 0;
    await tester.pumpWidget(scopedArrival(
      former, 7,
      onExpired: () => expires++,
      onChanged: () async { refreshed++; },
    ));
    await tester.tap(find.text('Check in'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm'));
    await tester.pump();
    expect(former.writes, 1);
    await tester.pumpWidget(scopedArrival(
      next, 8,
      onExpired: () => expires++,
      onChanged: () async { refreshed++; },
    ));
    former.pending!.completeError(const ApiException(401, 'expired old login'));
    await tester.pumpAndSettle();
    expect(expires, 0);
    expect(refreshed, 0);
    expect(next.writes, 0);
    expect(find.text('Check in'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  for (final ar in [false, true]) {
    testWidgets('background revokes in-progress arrival consent without automatic retry ($ar)', (
      tester,
    ) async {
      final api = BoundArrivalApi();
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
        AppLifecycleState.resumed,
      ));
      await tester.pumpWidget(app(
        ReservationArrivalButton(
          api: api, reservationId: 7,
          onChanged: () async {},
          onExpired: () {},
        ),
        arabic: ar,
      ));
      await tester.tap(find.text(ar ? arrivalAr['title']! : arrivalEn['title']!));
      await tester.pumpAndSettle();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      await tester.tap(find.text(ar ? 'تأكيد' : 'Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      expect(find.text(ar ? arrivalAr['title']! : arrivalEn['title']!),
          findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('arrival event retires controls immediately and rejects retained button',(tester) async {
    final api=BoundArrivalApi();
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(scopedArrival(api,7));
    await tester.pumpAndSettle();
    final stale=tester.widget<FilledButton>(
      find.widgetWithText(FilledButton,'Check in')).onPressed!;
    api.rotate();
    await tester.pumpAndSettle();
    expect(find.text('Check in'),findsNothing);
    stale();
    await tester.pumpWidget(scopedArrival(api,8));
    await tester.pumpAndSettle();
    expect(find.text('Check in'),findsNothing);
    expect(api.writes,0);
  });
  testWidgets('duplicate retained arrival action opens only one consent dialog',(tester) async {
    final api=BoundArrivalApi();
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(scopedArrival(api,7));
    // Finish initial Navigator layout before the first real user gesture.
    await tester.pumpAndSettle();
    final checkIn = find.widgetWithText(FilledButton,'Check in');
    final stale=tester.widget<FilledButton>(checkIn).onPressed!;
    await tester.tap(checkIn);
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog),findsOneWidget);
    // Simulate callbacks retained before the disabled button was rebuilt.
    stale();
    stale();
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog),findsOneWidget);
    expect(api.writes,0);
    final confirm = find.descendant(of:find.byType(AlertDialog),
      matching:find.widgetWithText(FilledButton,'Confirm'));
    expect(confirm,findsOneWidget);
    await tester.tap(confirm);
    await tester.pumpAndSettle();
    expect(api.writes,1);
    expect(api.paths,['reservations/7/check-in']);
    expect(find.byType(AlertDialog),findsNothing);
    expect(tester.takeException(),isNull);
  });

}
