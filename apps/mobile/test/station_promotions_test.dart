import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_promotions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

class PromotionApi extends EvApi {
  PromotionApi() : super(Uri.parse('https://platform.example'));

  final calls = <Map<String, String>>[];
  bool eligible = true;
  Future<void>? nextResponse;
  Map<String, dynamic> Function(Map<String,String>)? overridePage;
  final events = StreamController<int>.broadcast(sync: true);
  int epoch = 0;

  @override
  Stream<int> get sessionEvents => events.stream;
  @override
  bool Function() sessionFence() {
    final bound = epoch;
    return () => bound == epoch;
  }
  void rotate() { epoch++; events.add(epoch); }
  @override
  void close() { unawaited(events.close()); super.close(); }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'commercial/campaign-placements');
    expect(method, 'GET');
    expect(body, isNull);
    calls.add(query);
    final response = nextResponse;
    nextResponse = null;
    if (response != null) await response;
    if (overridePage != null) return overridePage!(query);
    return {
      'current_page': 1,
      'last_page': 1,
      'ranking_basis': 'SPONSORED_ID_ORDER_NOT_PERFORMANCE',
      'client_telemetry_billable': false,
      'placements': [
        if (eligible)
          {
            'campaign_id': 100,
            'station_id': int.parse(
              query['station_id'] ?? query['station_ids[0]']!,
            ),
            'title': 'Synthetic offer',
            'title_ar': 'عرض تجريبي',
            'description': 'Sponsor details',
            'description_ar': 'تفاصيل الراعي',
            'sponsored': true,
            'compatibility_verified': false,
            'availability_verified': false,
            'capability_override': false,
          },
      ],
    };
  }
}


Map<String, dynamic> sponsored(int campaign, {int station = 42}) => {
  'campaign_id': campaign, 'station_id': station,
  'title': 'Offer #$campaign', 'title_ar': 'عرض رقم $campaign',
  'description': 'Sponsor details', 'description_ar': 'تفاصيل الراعي',
  'sponsored': true, 'compatibility_verified': false,
  'availability_verified': false, 'capability_override': false,
};

Map<String,dynamic> placementPage(int page, int last,
    List<Map<String,dynamic>> rows, {int? total, int? perPage}) => {
  'current_page': page, 'last_page': last,
  'ranking_basis': 'SPONSORED_ID_ORDER_NOT_PERFORMANCE',
  'client_telemetry_billable': false,
  'placements': rows,
  if (total != null) 'total': total,
  if (perPage != null) 'per_page': perPage,
};

Widget sponsorApp(PromotionApi api, {bool arabic = false,
  List<int> stations = const [42], bool detail = false,
  VoidCallback? onExpired}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: Scaffold(body: SingleChildScrollView(child: detail
    ? StationPromotions(api: api, stationId: stations.first, country: 'AE',
        onExpired: onExpired ?? () {})
    : StationPromotions.discovery(api: api, discoveryStations: stations,
        onExpired: onExpired ?? () {}))),
);

void main() {
  for (final arabic in [false, true]) {
    testWidgets('exact eligible sponsor page and source disclosure ($arabic)', (tester) async {
      final api = PromotionApi();
      addTearDown(api.close);
      api.overridePage = (q) => q['page'] == '1'
        ? placementPage(1, 2, [sponsored(1), sponsored(2)], total: 3, perPage: 2)
        : placementPage(2, 2, [sponsored(3)], total: 3, perPage: 2);
      await tester.pumpWidget(sponsorApp(api, arabic: arabic));
      await tester.pumpAndSettle();
      final disclosure = find.text(arabic
        ? 'إعلان مدفوع، وليس تأكيداً للتوفر أو التوافق.'
        : 'Paid placement, not verified availability or compatibility.');
      expect(disclosure, findsOneWidget);
      expect(Directionality.of(tester.element(disclosure)),
        arabic ? TextDirection.rtl : TextDirection.ltr);
      final source = arabic ? 'برعاية · المحطة رقم 42' : 'Sponsored · Station #42';
      expect(find.text(source), findsNWidgets(2));
      final next = find.text(arabic ? 'العروض التالية' : 'Next promotions');
      await tester.tap(next);
      await tester.pumpAndSettle();
      expect(api.calls.last['page'], '2');
      expect(find.text(arabic ? 'عرض رقم 3' : 'Offer #3'), findsOneWidget);
      expect(find.text(arabic ? 'عرض رقم 1' : 'Offer #1'), findsNothing);
      expect(find.text(source), findsOneWidget);
      expect(next, findsNothing);
      expect(tester.takeException(), isNull);
    });
  }

  for (final kind in ['duplicate', 'foreign', 'count', 'partial-meta', 'claim']) {
    testWidgets('reject unsupported sponsor evidence: $kind', (tester) async {
      final api = PromotionApi();
      addTearDown(api.close);
      api.overridePage = (_) => switch (kind) {
        'duplicate' => placementPage(1, 1, [sponsored(1), sponsored(1)]),
        'foreign' => placementPage(1, 1, [sponsored(1, station: 99)]),
        'count' => placementPage(1, 1, [sponsored(1)], total: 2, perPage: 25),
        'partial-meta' => placementPage(1, 1, [sponsored(1)], total: 1),
        _ => placementPage(1, 1, [{...sponsored(1), 'availability_verified': true}]),
      };
      await tester.pumpWidget(sponsorApp(api));
      await tester.pumpAndSettle();
      expect(find.text('Offer #1'), findsNothing);
      expect(find.text('Retry promotions'), findsOneWidget);
      expect(api.calls, hasLength(1));
    });
  }

  testWidgets('retired sponsor session hides rows and blocks cached paging', (tester) async {
    final api = PromotionApi();
    addTearDown(api.close);
    api.overridePage = (_) => placementPage(1, 2, [sponsored(1)]);
    await tester.pumpWidget(sponsorApp(api));
    await tester.pumpAndSettle();
    expect(find.text('Offer #1'), findsOneWidget);
    final previousNext = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Next promotions')).onPressed!;
    api.rotate();
    previousNext();
    await tester.pumpAndSettle();
    expect(find.text('Offer #1'), findsNothing);
    expect(api.calls, hasLength(1));
    await tester.pumpWidget(sponsorApp(api, stations: [99]));
    await tester.pumpAndSettle();
    previousNext();
    expect(api.calls, hasLength(1));
    expect(find.text('Offer #1'), findsNothing);
  });

  testWidgets('invalid station page cannot send a sponsored request', (tester) async {
    final api = PromotionApi();
    addTearDown(api.close);
    for (final ids in <List<int>>[
      [42, 42], [0], List<int>.generate(26, (i) => i + 1),
    ]) {
      await tester.pumpWidget(sponsorApp(api, stations: ids));
      await tester.pumpAndSettle();
      expect(api.calls, isEmpty);
      expect(find.text('Sponsored promotions'), findsNothing);
    }
  });

  testWidgets('late denial after source account rotation cannot expire new login', (tester) async {
    final api = PromotionApi();
    addTearDown(api.close);
    var expirations = 0;
    final pending = Completer<void>();
    api.nextResponse = pending.future;
    await tester.pumpWidget(sponsorApp(api, onExpired: () => expirations++));
    await tester.pump();
    expect(api.calls, hasLength(1));
    api.rotate();
    pending.completeError(const ApiException(401, 'Retired actor'));
    await tester.pumpAndSettle();
    expect(expirations, 0);
    expect(find.text('Synthetic offer'), findsNothing);
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets(
    'station promotions reread authority on reopen and exclude withdrawn offers',
    (tester) async {
      final api = PromotionApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: StationPromotions(
              api: api,
              stationId: 42,
              country: 'AE',
              onExpired: () {},
            ),
          ),
        ),
      );
      final toggle = find.text('Sponsored promotions');
      expect(api.calls, isEmpty);
      await tester.tap(toggle);
      await tester.pumpAndSettle();
      expect(api.calls.single, {
        'country': 'AE',
        'station_id': '42',
        'placement': 'station_detail',
        'page': '1',
      });
      expect(find.text('Synthetic offer'), findsOneWidget);
      await tester.tap(toggle);
      await tester.pumpAndSettle();
      expect(find.text('Synthetic offer'), findsNothing);
      api.eligible = false;
      await tester.tap(toggle);
      await tester.pumpAndSettle();
      expect(api.calls, hasLength(2));
      expect(find.text('Synthetic offer'), findsNothing);
      expect(find.text('No promotions on this page'), findsOneWidget);
    },
  );

  testWidgets('discovery placements use only the current station page', (
    tester,
  ) async {
    final api = PromotionApi();
    addTearDown(api.close);
    Widget page(List<int> ids) => MaterialApp(
      home: Scaffold(
        body: StationPromotions.discovery(
          api: api,
          discoveryStations: ids,
          onExpired: () {},
        ),
      ),
    );
    await tester.pumpWidget(page([42, 43]));
    await tester.pumpAndSettle();
    expect(api.calls.single, {
      'station_ids[0]': '42',
      'station_ids[1]': '43',
      'placement': 'discovery_banner',
      'page': '1',
    });
    expect(find.text('Synthetic offer'), findsOneWidget);
    api.eligible = false;
    await tester.pumpWidget(page([99]));
    await tester.pumpAndSettle();
    expect(api.calls.last['station_ids[0]'], '99');
    expect(api.calls.last.containsKey('station_ids[1]'), false);
    expect(find.text('Synthetic offer'), findsNothing);
  });

  testWidgets('background hides placements and starts a fresh discovery read', (
    tester,
  ) async {
    final api = PromotionApi();
    addTearDown(api.close);
    final oldResponse = Completer<void>();
    api.nextResponse = oldResponse.future;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StationPromotions.discovery(
            api: api,
            discoveryStations: const [42],
            onExpired: () {},
          ),
        ),
      ),
    );
    expect(api.calls, hasLength(1));

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    oldResponse.complete();
    await tester.pump();
    expect(find.text('Synthetic offer'), findsNothing);
    expect(api.calls, hasLength(1));

    api.eligible = false;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.calls, hasLength(2));
    expect(find.text('Synthetic offer'), findsNothing);
  });

  testWidgets('only an expiration still owned by this session is delivered', (
    tester,
  ) async {
    final api = PromotionApi();
    addTearDown(api.close);
    var expirationIsCurrent = false;
    var expirations = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StationPromotions(
            api: api,
            stationId: 42,
            country: 'AE',
            onExpired: () => expirations++,
          ),
        ),
      ),
    );

    final error = ApiException(
      401,
      'Expired',
      expirationIsCurrent: () => expirationIsCurrent,
    );
    final toggle = find.text('Sponsored promotions');
    final stale = Completer<void>();
    api.nextResponse = stale.future;
    await tester.tap(toggle);
    await tester.pump();
    stale.completeError(error);
    await tester.pumpAndSettle();
    expect(expirations, 0);

    final current = Completer<void>();
    api.nextResponse = current.future;
    expirationIsCurrent = true;
    await tester.tap(find.text('Retry promotions'));
    await tester.pump();
    current.completeError(error);
    await tester.pumpAndSettle();
    expect(expirations, 1);
  });
  for (final arabic in [false,true]) {
    testWidgets('withdrawn sponsored pages retain an explicit way back ($arabic)',(tester)async{
      final api=PromotionApi();
      addTearDown(api.close);
      var withdrawn=false;
      api.overridePage=(query){
        final page=int.parse(query['page']!);
        return withdrawn?placementPage(page,1,[],total:0,perPage:1):
          placementPage(page,2,[sponsored(page)],total:2,perPage:1);
      };
      await tester.pumpWidget(sponsorApp(api,arabic:arabic));
      await tester.pumpAndSettle();
      final next=find.widgetWithText(TextButton,arabic?'العروض التالية':'Next promotions');
      final stale=tester.widget<TextButton>(next).onPressed!;
      withdrawn=true;
      await tester.tap(next);await tester.pumpAndSettle();
      expect(find.text(arabic?'لا توجد عروض في هذه الصفحة':'No promotions on this page'),findsOneWidget);
      final previous=find.text(arabic?'العروض السابقة':'Previous promotions');
      expect(previous,findsOneWidget);
      stale();await tester.pumpAndSettle();
      expect(api.calls.length,2);
      await tester.tap(previous);await tester.pumpAndSettle();
      expect(api.calls.map((q)=>q['page']),['1','2','1']);
      expect(previous,findsNothing);
      expect(find.text(arabic?'عرض رقم 1':'Offer #1'),findsNothing);
      expect(tester.takeException(),isNull);
    });
  }

}
