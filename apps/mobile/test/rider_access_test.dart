import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/rider_access.dart';
import 'test_support.dart';

class RiderApi extends FixtureApi {
  final bodies = <Map<String, dynamic>>[];
  bool loseResponse = true;
  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'rider-access/tokens');
    if (method == 'POST') {
      bodies.add(Map<String, dynamic>.from(body!));
      if (loseResponse) throw const ApiException(503, 'PRIVATE TRANSPORT');
      // A committed request replay cannot reveal the identifier again.
      return {'created': false, 'token': {'id': 1}};
    }
    return {'data': <Map<String, dynamic>>[], 'current_page': 1, 'last_page': 1, 'total': 0};
  }
}

void main() {
  testWidgets('uncertain registration waits for explicit retry and keeps its original request identity', (tester) async {
    final api = RiderApi();
    await tester.pumpWidget(MaterialApp(home: RiderAccessView(api: api, onExpired: () {})));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Register charging access'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first, 'My private card');
    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    expect(api.bodies, hasLength(1));
    expect(find.textContaining('PRIVATE TRANSPORT'), findsNothing);
    await tester.pump(const Duration(seconds: 5));
    expect(api.bodies, hasLength(1));
    api.loseResponse = false;
    await tester.tap(find.text('Retry saved request'));
    await tester.pumpAndSettle();
    expect(api.bodies, hasLength(2));
    expect(api.bodies[1], api.bodies[0]);
    expect(find.text('Show identifier'), findsNothing);
    expect(find.text('Register charging access'), findsOneWidget);
  });
}
