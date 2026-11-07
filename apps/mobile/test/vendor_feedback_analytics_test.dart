import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_feedback_analytics.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Deterministic moderated-feedback view fixture, not a socket-based widget test.
class _FeedbackApi extends EvApi {
  _FeedbackApi() : super(Uri.parse('https://example.invalid'));
  final requests = <Map<String, String>>[];
  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path != 'vendor/feedback-analytics' || method != 'GET') {
      throw ApiException(404, 'Unexpected test endpoint: $path');
    }
    requests.add(Map<String, String>.from(query));
    return {
      'days': int.parse(query['days'] ?? '30'),
      'total': 2, 'average_rating': 4.0,
      'star_distribution': {'1': 0, '2': 0, '3': 0, '4': 2, '5': 0},
      'evidence_counts': {'SIMULATED_APP_ONLY': 2, 'SIMULATED_SOURCE_OBSERVED': 0},
      'stations': [
        {'station_id': 7, 'station_name': 'Simulator Plaza', 'total': 2,
          'average_rating': 4.0},
      ],
      'source': 'MODERATED_SIMULATOR_REVIEWS_ONLY',
      'physical_charging_verified': false,
    };
  }
}

void main() {
  testWidgets('vendor sees qualified feedback but no customer identities',
      (tester) async {
    final api = _FeedbackApi();
    try {
      await tester.pumpWidget(MaterialApp(home: VendorFeedbackAnalyticsView(
        api: api, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(api.requests.single['days'], '30');
      expect(find.text('Published reviews: 2'), findsOneWidget);
      expect(find.text('Average rating: 4.0 / 5'), findsOneWidget);
      expect(find.text('Simulator Plaza'), findsOneWidget);
      expect(find.textContaining('does not verify real charging'), findsOneWidget);
      expect(find.textContaining('user_id'), findsNothing);
    } finally {
      api.close();
    }
  });
}
