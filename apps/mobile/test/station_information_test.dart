import 'dart:async';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_information.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Tenant-owned API contract fixture. Laravel feature tests cover real authorization.
class _OwnedStationApi extends EvApi {
  _OwnedStationApi({
    this.stationId = 7,
    this.stationName = 'Local Plaza',
    this.description,
    this.holdInfo,
    this.rejectHeldInfo = false,
    this.calendarPolicy,
    this.wrongStation = false,
    this.corruptSave = false,
  }) : super(Uri.parse('https://example.invalid'));
  final int stationId;
  final String stationName;
  final String? description;
  final Completer<void>? holdInfo;
  final bool rejectHeldInfo;
  final Object? calendarPolicy;
  final bool wrongStation, corruptSave;
  final writes = <Map<String, dynamic>>[];

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'vendor/stations' && method == 'GET') {
      return {
        'data': [
          {'id': stationId, 'name': stationName, 'timezone': 'Asia/Dubai'},
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    }
    if (path == 'stations/$stationId/information' && method == 'GET') {
      if (holdInfo != null) await holdInfo!.future;
      if (rejectHeldInfo) {
        throw const ApiException(401, 'Previous vendor token expired');
      }
      return {
        'station_id': wrongStation ? stationId + 999 : stationId,
        'source_label': 'VENDOR_REPORTED_UNVERIFIED',
        'revision': 1,
        if (calendarPolicy != null) 'enforce_operating_hours': calendarPolicy,
        'description_en': description,
        'description_ar': null,
        'access_en': null,
        'access_ar': null,
        'weekly_hours': {
          for (final day in stationInfoDays)
            day: {
              'open': day == 'fri' ? null : '08:00',
              'close': day == 'fri' ? null : '22:00',
            },
        },
        'closed_dates': [],
        'amenities': [],
        'accessibility': [],
      };
    }
    if (path == 'stations/$stationId/information' && method == 'PUT') {
      final input = Map<String, dynamic>.from(body!);
      writes.add(input);
      return {
        ...input,
        'station_id': stationId,
        'source_label': 'VENDOR_REPORTED_UNVERIFIED',
        'revision': corruptSave ? 1 : 2,
      };
    }
    throw ApiException(404, 'Unexpected station test route: $method $path');
  }
}

/// Scroll the actual vendor form into the visible 800x600 widget viewport.
/// Long forms can initially have descendants built far below the hit-test area.
Future<void> revealStationEditorControl(
  WidgetTester tester,
  Finder control,
) async {
  for (var attempts = 0; attempts < 30; attempts++) {
    final y = tester.getCenter(control).dy;
    if (y >= 100 && y <= 575) return;
    await tester.drag(
      find.byType(ListView).first,
      Offset(0, y > 500 ? -230 : 230),
    );
    await tester.pumpAndSettle();
  }
  expect(tester.getCenter(control).dy, inInclusiveRange(100, 575));
}

// Follow the platform lifecycle transitions observed by editable text fields.
void pauseStationEditor(WidgetTester tester) {
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
}

void resumeStationEditor(WidgetTester tester) {
  if (tester.binding.lifecycleState == AppLifecycleState.paused) {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
  }
  if (tester.binding.lifecycleState == AppLifecycleState.hidden) {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
  }
  if (tester.binding.lifecycleState != AppLifecycleState.resumed) {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  }
}

void main() {
  Map<String, dynamic> station() => {
    'id': 14,
    'name': 'Central Plaza',
    'connector_type': 'CCS2',
    'power_kw': 50,
    'evidence': 'SIMULATED',
    'reservable': false,
    'timezone': 'Asia/Dubai',
    'information': {
      'revision': 2,
      'source_label': 'VENDOR_REPORTED_UNVERIFIED',
      'description_en': 'Level B',
      'description_ar': 'الطابق ب',
      'access_en': 'Gate B',
      'access_ar': 'البوابة ب',
      'weekly_hours': {
        for (final day in stationInfoDays)
          day: {
            'open': day == 'fri' ? null : '08:00',
            'close': day == 'fri' ? null : '22:00',
          },
      },
      'closed_dates': ['2026-12-02'],
      'amenities': ['wifi'],
      'accessibility': ['step_free'],
    },
  };

  test('metadata survives favorite and connector copies', () {
    final s = Station.fromJson(station());
    expect(s.timezone, 'Asia/Dubai');
    expect(s.information!['access_en'], 'Gate B');
    final favorite = Station.withFavorite(s, true);
    expect(favorite.information!['revision'], 2);
    final connector = Station.forConnector(s, {
      'id': 3,
      'evse_number': 1,
      'connector_number': 1,
      'connector_type': 'Type2',
      'max_power_kw': 22,
      'reservable': false,
    });
    expect(connector.information!['access_en'], 'Gate B');
    expect(connector.timezone, 'Asia/Dubai');
  });

  testWidgets(
    'native catalog details show vendor-reported closures, access and truthful availability',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ListView(
              children: [
                StationInformationPanel(
                  information: Station.fromJson(station()).information,
                  timezone: 'Asia/Dubai',
                ),
              ],
            ),
          ),
        ),
      );
      await tester.tap(find.text('Hours, access & facilities'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Hours (Asia/Dubai)'), findsOneWidget);
      expect(find.textContaining('Fri: Closed'), findsOneWidget);
      expect(
        find.textContaining('Holiday closures: 2026-12-02'),
        findsOneWidget,
      );
      expect(find.textContaining('Step-free access'), findsOneWidget);
      expect(find.textContaining('Gate B'), findsOneWidget);
      expect(find.textContaining('availability is unknown'), findsOneWidget);
    },
  );

  testWidgets(
    'missing vendor data cannot be presented as published opening times',
    (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: StationInformationPanel(information: null, timezone: 'UTC'),
          ),
        ),
      );
      await tester.tap(find.text('Hours, access & facilities'));
      await tester.pumpAndSettle();
      expect(find.textContaining('has not published'), findsOneWidget);
      expect(find.textContaining('Mon:'), findsNothing);
    },
  );

  testWidgets(
    'Arabic customer view preserves an explicit unknown availability warning',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(
            body: ListView(
              children: [
                StationInformationPanel(
                  information: Station.fromJson(station()).information,
                  timezone: 'Asia/Dubai',
                ),
              ],
            ),
          ),
        ),
      );
      await tester.tap(find.text('ساعات العمل والدخول والمرافق'));
      await tester.pumpAndSettle();
      expect(find.textContaining('الجمعة: مغلق'), findsOneWidget);
      expect(find.textContaining('البوابة ب'), findsOneWidget);
      expect(find.textContaining('غير معروف'), findsWidgets);
    },
  );
  testWidgets(
    'vendor can edit owned station details without touching charger truth',
    (tester) async {
      final api = _OwnedStationApi();
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: api, onExpired: () {}),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Local Plaza'), findsOneWidget);
        expect(
          find.textContaining(
            'Opening hours (station local time) · Asia/Dubai',
          ),
          findsOneWidget,
        );
        await tester.enterText(
          find.byKey(const Key('station-info-description_en')),
          'Roof deck',
        );
        await tester.enterText(
          find.byKey(const Key('station-info-access_en')),
          'Gate A',
        );
        final noticeEnglish = find.byKey(const Key('station-info-notice_en'));
        await revealStationEditorControl(tester, noticeEnglish);
        await tester.enterText(noticeEnglish, 'Temporary: use the rear gate');
        final expiryInput = find.byKey(const Key('station-info-notice-until'));
        await revealStationEditorControl(tester, expiryInput);
        await tester.enterText(expiryInput, '2026-12-31');
        final wifi = find.text('Wi-Fi');
        await revealStationEditorControl(tester, wifi);
        await tester.tap(wifi);
        final save = find.byKey(const Key('station-info-save'));
        await revealStationEditorControl(tester, save);
        await tester.tap(save);
        await tester.pumpAndSettle();
        expect(api.writes, hasLength(1));
        expect(api.writes.single['revision'], 1);
        expect(api.writes.single['description_en'], 'Roof deck');
        expect(api.writes.single['access_en'], 'Gate A');
        expect(api.writes.single['notice_en'], 'Temporary: use the rear gate');
        expect(api.writes.single['notice_until'], '2026-12-31');
        expect((api.writes.single['amenities'] as List), contains('wifi'));
        final week = api.writes.single['weekly_hours'] as Map;
        expect((week['fri'] as Map)['open'], isNull);
        expect((week['mon'] as Map)['open'], '08:00');
        expect(api.writes.single.containsKey('charging_status'), isFalse);
        expect(api.writes.single.containsKey('tariff'), isFalse);
      } finally {
        api.close();
      }
    },
  );

  testWidgets(
    'operating calendar is explicit opt-in and saved with the original revision',
    (tester) async {
      final api = _OwnedStationApi();
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: api, onExpired: () {}),
          ),
        );
        await tester.pumpAndSettle();
        final toggle = find.byKey(const Key('station-info-enforce-hours'));
        expect(tester.widget<SwitchListTile>(toggle).value, isFalse);
        await revealStationEditorControl(tester, toggle);
        await tester.tap(toggle);
        await tester.pumpAndSettle();
        expect(tester.widget<SwitchListTile>(toggle).value, isTrue);
        final save = find.byKey(const Key('station-info-save'));
        await revealStationEditorControl(tester, save);
        await tester.tap(save);
        await tester.pumpAndSettle();
        expect(api.writes.single['enforce_operating_hours'], isTrue);
        expect(api.writes.single['revision'], 1);
        expect(api.writes.single['weekly_hours']['mon']['open'], '08:00');
        expect(
          api.writes.single.containsKey('operating_calendar_snapshot'),
          isFalse,
        );
      } finally {
        api.close();
      }
    },
  );

  testWidgets('malformed policy cannot be silently published as disabled', (
    tester,
  ) async {
    final api = _OwnedStationApi(calendarPolicy: 'true');
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: VendorStationInformationView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('station-info-save')), findsNothing);
      expect(find.byKey(const Key('station-info-error')), findsOneWidget);
      expect(api.writes, isEmpty);
    } finally {
      api.close();
    }
  });

  testWidgets(
    'vendor switch clears the previous enforced policy while loading',
    (tester) async {
      final a = _OwnedStationApi(calendarPolicy: true);
      final held = Completer<void>();
      final b = _OwnedStationApi(stationId: 8, holdInfo: held);
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: a, onExpired: () {}),
          ),
        );
        await tester.pumpAndSettle();
        final toggle = find.byKey(const Key('station-info-enforce-hours'));
        expect(tester.widget<SwitchListTile>(toggle).value, isTrue);
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: b, onExpired: () {}),
          ),
        );
        await tester.pump();
        expect(toggle, findsNothing);
        held.complete();
        await tester.pumpAndSettle();
        expect(tester.widget<SwitchListTile>(toggle).value, isFalse);
        expect(a.writes, isEmpty);
        expect(b.writes, isEmpty);
      } finally {
        if (!held.isCompleted) held.complete();
        a.close();
        b.close();
      }
    },
  );

  testWidgets(
    'enforced calendar explanation is truthful Arabic RTL at phone width',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final info = Map<String, dynamic>.from(station()['information'] as Map)
        ..['enforce_operating_hours'] = true;
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar'), Locale('en')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: Scaffold(
            body: ListView(
              children: [
                StationInformationPanel(
                  information: info,
                  timezone: 'Asia/Dubai',
                ),
              ],
            ),
          ),
        ),
      );
      await tester.tap(find.text('ساعات العمل والدخول والمرافق'));
      await tester.pumpAndSettle();
      final explanation = find.textContaining('يُطبق جدول العمل');
      expect(explanation, findsOneWidget);
      expect(Directionality.of(tester.element(explanation)), TextDirection.rtl);
      expect(find.textContaining('يمكن إكمال الشحن الجاري'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('wrong-station metadata never enables calendar editing', (
    tester,
  ) async {
    final api = _OwnedStationApi(wrongStation: true);
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: VendorStationInformationView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('station-info-save')), findsNothing);
      expect(find.byKey(const Key('station-info-error')), findsOneWidget);
      expect(api.writes, isEmpty);
    } finally {
      api.close();
    }
  });

  testWidgets(
    'an unconfirmed calendar revision never displays publication success',
    (tester) async {
      final api = _OwnedStationApi(corruptSave: true);
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: api, onExpired: () {}),
          ),
        );
        await tester.pumpAndSettle();
        final toggle = find.byKey(const Key('station-info-enforce-hours'));
        await revealStationEditorControl(tester, toggle);
        await tester.tap(toggle);
        final save = find.byKey(const Key('station-info-save'));
        await revealStationEditorControl(tester, save);
        await tester.tap(save);
        await tester.pumpAndSettle();
        expect(api.writes, hasLength(1));
        await tester.drag(find.byType(ListView).first, const Offset(0, 10000));
        await tester.pumpAndSettle();
        expect(find.text('Saved'), findsNothing);
        expect(find.byKey(const Key('station-info-error')), findsOneWidget);
        expect(api.writes.single['revision'], 1);
      } finally {
        api.close();
      }
    },
  );

  testWidgets(
    'catalog scroll offset cannot deserialize as station expansion bool',
    (tester) async {
      final bucket = PageStorageBucket();
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: PageStorage(
              bucket: bucket,
              child: ListView(
                key: const PageStorageKey<String>('catalog-scroll'),
                children: [
                  Builder(
                    builder: (context) {
                      // Reproduce catalog's numeric scroll state in this page bucket.
                      bucket.writeState(context, 137.0);
                      return const StationInformationPanel(
                        information: null,
                        timezone: 'UTC',
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        tester.takeException(),
        isNull,
        reason: 'Station expansion must not cast numeric scroll state to bool',
      );
      await tester.tap(find.text('Hours, access & facilities'));
      await tester.pumpAndSettle();
      expect(find.textContaining('has not published'), findsOneWidget);
    },
  );

  testWidgets('reused vendor view clears prior account station details', (
    tester,
  ) async {
    final held = Completer<void>();
    final first = _OwnedStationApi(
      stationName: 'Vendor A private station',
      description: 'Vendor A confidential entry',
      holdInfo: held,
    );
    final second = _OwnedStationApi(
      stationId: 8,
      stationName: 'Vendor B station',
      description: 'Vendor B own entry',
    );
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: VendorStationInformationView(api: first, onExpired: () {}),
        ),
      );
      await tester.pump();
      await tester.pumpWidget(
        MaterialApp(
          home: VendorStationInformationView(api: second, onExpired: () {}),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(find.text('Vendor B station'), findsOneWidget);
      expect(find.text('Vendor A private station'), findsNothing);
      expect(
        tester
            .widget<TextFormField>(
              find.byKey(const Key('station-info-description_en')),
            )
            .controller!
            .text,
        'Vendor B own entry',
      );
      held.complete();
      await tester.pumpAndSettle();
      expect(find.text('Vendor A private station'), findsNothing);
      expect(
        tester
            .widget<TextFormField>(
              find.byKey(const Key('station-info-description_en')),
            )
            .controller!
            .text,
        'Vendor B own entry',
      );
    } finally {
      if (!held.isCompleted) held.complete();
      first.close();
      second.close();
    }
  });

  testWidgets(
    'active vendor access notice is visible but expired notice is not',
    (tester) async {
      final active = Map<String, dynamic>.from(station());
      final activeInfo = Map<String, dynamic>.from(
        active['information'] as Map,
      );
      activeInfo.addAll({
        'notice_active': true,
        'notice_en': 'Use the rear gate',
        'notice_ar': 'استخدم البوابة الخلفية',
        'notice_until': '2026-12-31',
      });
      active['information'] = activeInfo;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: StationInformationPanel(
              information: active['information'] as Map<String, dynamic>,
              timezone: 'Asia/Dubai',
            ),
          ),
        ),
      );
      await tester.tap(find.text('Hours, access & facilities'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Operator access notice: Use the rear gate'),
        findsOneWidget,
      );
      expect(
        find.textContaining('Notice expires (UTC): 2026-12-31'),
        findsOneWidget,
      );

      (active['information'] as Map<String, dynamic>)['notice_active'] = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: StationInformationPanel(
              information: active['information'] as Map<String, dynamic>,
              timezone: 'Asia/Dubai',
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('Operator access notice:'), findsNothing);
    },
  );

  testWidgets('stale vendor information denial cannot sign out a new session', (
    tester,
  ) async {
    final held = Completer<void>();
    final api = _OwnedStationApi(holdInfo: held, rejectHeldInfo: true);
    var expired = 0;
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: VendorStationInformationView(
            api: api,
            onExpired: () => expired++,
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(find.text('Local Plaza'), findsOneWidget);
      // The previous account is signed out while the old GET is in flight.
      await api.forgetSession();
      held.complete();
      await tester.pumpAndSettle();
      expect(
        expired,
        0,
        reason: 'A stale 401 must not log out the next vendor',
      );
      expect(find.byKey(const Key('station-info-error')), findsNothing);
    } finally {
      if (!held.isCompleted) held.complete();
      api.close();
    }
  });
  testWidgets(
    'background clears vendor station editor until explicit refresh',
    (tester) async {
      final api = _OwnedStationApi(
        description: 'Private vendor access instructions',
      );
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: api, onExpired: () {}),
          ),
        );
        await tester.pumpAndSettle();
        expect(
          find.byKey(const Key('station-info-description_en')),
          findsOneWidget,
        );
        pauseStationEditor(tester);
        await tester.pumpAndSettle();
        expect(
          find.byKey(const Key('station-info-description_en')),
          findsNothing,
        );
        expect(find.text('Local Plaza'), findsNothing);
        expect(find.byType(LinearProgressIndicator), findsNothing);
        resumeStationEditor(tester);
        await tester.pumpAndSettle();
        expect(
          find.byKey(const Key('station-info-description_en')),
          findsNothing,
        );
        await tester.tap(find.text('Refresh from server'));
        await tester.pumpAndSettle();
        expect(
          tester
              .widget<TextFormField>(
                find.byKey(const Key('station-info-description_en')),
              )
              .controller!
              .text,
          'Private vendor access instructions',
        );
      } finally {
        resumeStationEditor(tester);
        api.close();
      }
    },
  );

  testWidgets(
    'retained vendor editor never writes after its account fence changes',
    (tester) async {
      final api = _OwnedStationApi(
        description: 'Former vendor private details',
      );
      var expired = 0;
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(
              api: api,
              onExpired: () => expired++,
            ),
          ),
        );
        await tester.pumpAndSettle();
        final save = find.byKey(const Key('station-info-save'));
        await revealStationEditorControl(tester, save);
        await api.forgetSession();
        await tester.tap(save);
        await tester.pumpAndSettle();
        expect(api.writes, isEmpty);
        expect(expired, 0);
        expect(find.text('Local Plaza'), findsNothing);
        expect(
          find.byKey(const Key('station-info-description_en')),
          findsNothing,
        );
        expect(find.byType(LinearProgressIndicator), findsNothing);
      } finally {
        api.close();
      }
    },
  );

  testWidgets(
    'background drops a delayed successful vendor information response',
    (tester) async {
      final held = Completer<void>();
      final api = _OwnedStationApi(
        holdInfo: held,
        description: 'Hidden vendor entry',
      );
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: VendorStationInformationView(api: api, onExpired: () {}),
          ),
        );
        await tester.pump();
        await tester.pump();
        expect(find.text('Local Plaza'), findsOneWidget);
        pauseStationEditor(tester);
        await tester.pump();
        held.complete();
        await tester.pumpAndSettle();
        resumeStationEditor(tester);
        await tester.pumpAndSettle();
        expect(find.text('Local Plaza'), findsNothing);
        expect(
          find.byKey(const Key('station-info-description_en')),
          findsNothing,
        );
        expect(api.writes, isEmpty);
      } finally {
        if (!held.isCompleted) held.complete();
        resumeStationEditor(tester);
        api.close();
      }
    },
  );
}
