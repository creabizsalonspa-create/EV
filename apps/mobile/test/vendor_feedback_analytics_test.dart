import 'dart:convert';
import 'dart:io';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_feedback_analytics.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('vendor sees source-qualified published feedback without customers', (tester) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final requests = <Uri>[];
    final subscription = server.listen((request) async {
      requests.add(request.uri);
      request.response.headers.contentType = ContentType.json;
      request.response.write(jsonEncode({
        'days': int.parse(request.uri.queryParameters['days'] ?? '30'),
        'total': 2, 'average_rating': 4.0,
        'star_distribution': {'1':0,'2':0,'3':0,'4':2,'5':0},
        'evidence_counts': {'SIMULATED_APP_ONLY':2,'SIMULATED_SOURCE_OBSERVED':0},
        'stations': [
          {'station_id': 7,'station_name':'Simulator Plaza','total':2,'average_rating':4.0},
        ],
        'source':'MODERATED_SIMULATOR_REVIEWS_ONLY',
        'physical_charging_verified':false,
      }));
      await request.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:' + server.port.toString()));
    try {
      await tester.pumpWidget(MaterialApp(home: VendorFeedbackAnalyticsView(
        api: api, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(requests.single.queryParameters['days'], '30');
      expect(find.text('Published reviews: 2'), findsOneWidget);
      expect(find.text('Average rating: 4.0 / 5'), findsOneWidget);
      expect(find.text('Simulator Plaza'), findsOneWidget);
      expect(find.textContaining('does not verify real charging'), findsOneWidget);
      expect(find.textContaining('user_id'), findsNothing);
    } finally {
      api.close();
      await subscription.cancel();
      await server.close(force:true);
    }
  });
}
