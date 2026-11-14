import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/partner_report_map.dart';

class FakeMapTiles extends TileProvider {
  int requests = 0;

  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    requests++;
    return MemoryImage(base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=',
    ));
  }
}

void main() {
  test('partner map does not invent missing or unsupported coordinates', () {
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': null,
      'longitude': null}), isNull);
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': 89.99,
      'longitude': 55.3}), isNull);
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': double.infinity,
      'longitude': 55.3}), isNull);
    expect(PartnerMapPoint.fromPublishedLocation({'latitude': '25.2',
      'longitude': '-180'})?.longitude, -180);
    final zero = PartnerMapPoint.fromPublishedLocation({
      'latitude': 0, 'longitude': 0, 'name': 'Literal <img src=x>',
    });
    expect(zero, isNotNull);
    expect(zero!.name, 'Literal <img src=x>');
    expect(zero.latitude, 0);
    expect(zero.longitude, 0);
  });

  test('published catalog map points preserve their own evidence and reject malformed sources', () {
    final row=<String,dynamic>{'id':7,'name':'Platform source','latitude':'25.2','longitude':'55.3','evidence':'SIMULATED'};
    final point=PartnerMapPoint.fromPublishedCatalog(row)!;
    expect(point.platformCatalog,true);expect(point.catalogEvidence,'SIMULATED');expect(point.providerLabel,isNull);
    for(final update in <Map<String,dynamic>>[{'id':0},{'latitude':89},{'evidence':'LIVE_VERIFIED'},{'name':null}])expect(PartnerMapPoint.fromPublishedCatalog({...row,...update}),isNull);
  });

  testWidgets('mixed map keeps supplier labels and tile consent independent of source', (tester) async {
    final tiles=FakeMapTiles();
    final platform=PartnerMapPoint.fromPublishedCatalog({'id':7,'name':'Platform fixture','latitude':25.2,'longitude':55.3,'evidence':'SIMULATED'})!;
    final partner=PartnerMapPoint.fromPublishedLocation({'name':'Partner fixture','latitude':25.2,'longitude':55.3})!;
    await tester.pumpWidget(MaterialApp(home:PartnerReportMap(points:[platform,partner],tileProvider:tiles)));
    expect(tiles.requests,0);expect(find.byType(FlutterMap),findsNothing);
    expect(find.text('Published location map (not live)'),findsOneWidget);
    await tester.tap(find.text('Platform fixture'));await tester.pumpAndSettle();
    expect(find.textContaining('Platform catalog · SIMULATED. Availability'),findsOneWidget);
    expect(find.textContaining('Partner-reported place; live availability'),findsNothing);expect(tiles.requests,0);
    await tester.tap(find.byKey(const Key('partner-report-details-close')));await tester.pumpAndSettle();
    await tester.tap(find.text('Partner fixture'));await tester.pumpAndSettle();
    expect(find.textContaining('Partner-reported place; live availability'),findsOneWidget);expect(tiles.requests,0);
  });

  testWidgets('consent gates every partner map tile and map has no charging controls',
      (tester) async {
    final tiles = FakeMapTiles();
    final point = PartnerMapPoint.fromPublishedLocation({
      'latitude': '25.2',
      'longitude': '55.3',
      'name': 'Literal <img src=x>',
    }, providerLabel: 'Unverified network', reportedAgeMinutes: 1600)!;
    await tester.pumpWidget(MaterialApp(
      home: PartnerReportMap(points: [point], tileProvider: tiles),
    ));
    expect(tiles.requests, 0);
    expect(find.byType(FlutterMap), findsNothing);
    expect(find.text('Enable report map'), findsOneWidget);
    expect(find.text('Start charging'), findsNothing);
    expect(find.text('Reserve connector'), findsNothing);
    await tester.tap(find.byKey(const Key('partner-map-enable')));
    await tester.pumpAndSettle();
    expect(tiles.requests, greaterThan(0));
    await tester.tap(find.byTooltip('Literal <img src=x>'));
    await tester.pumpAndSettle();
    expect(find.text('Literal <img src=x>'), findsWidgets);
    expect(find.textContaining('Partner-reported place; live availability'),
        findsOneWidget);
    expect(find.text('Reserve connector'), findsNothing);
    await tester.tap(find.byKey(const Key('partner-report-details-close')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('partner-map-disable')));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
