import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_REVIEWS_FIXTURE'];
  test(
    'native actual Laravel feedback consent, replay, moderation, withdrawal and scope',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      Future<void> login(String role) async {
        await api.signIn(
          f['accounts'][role]['email'],
          f['accounts'][role]['password'],
        );
      }

      await login('customer');
      final eligible = await api.request('charging-reviews/eligible');
      expect(eligible['data'].first['can_review'], true);
      final body = {
        'request_key': operationKey(),
        'rating': 5,
        'body': 'Synthetic native feedback',
        'consent': true,
        'terms_version': eligible['terms_version'],
      };
      final row = await api.request(
        'charging-reviews/sessions/${f['session_id']}',
        method: 'POST',
        body: body,
      );
      expect(row['state'], 'pending');
      expect(row['evidence'], 'SIMULATED_APP_ONLY');
      expect(row['physical_charging_verified'], false);
      final replay = await api.request(
        'charging-reviews/sessions/${f['session_id']}',
        method: 'POST',
        body: body,
      );
      expect(replay['id'], row['id']);
      final endpoint = 'charging-reviews/${row['id']}';
      await api.signOut();
      await login('vendor');
      await expectLater(
        api.request(
          '$endpoint/moderate',
          method: 'POST',
          body: {
            'request_key': operationKey(),
            'expected_version': 1,
            'action': 'publish',
            'reason': 'Vendor cannot publish own feedback',
          },
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
      );
      await api.signOut();
      await login('admin');
      final published = await api.request(
        '$endpoint/moderate',
        method: 'POST',
        body: {
          'request_key': operationKey(),
          'expected_version': 1,
          'action': 'publish',
          'reason': 'Independent synthetic moderation reviewed',
        },
      );
      expect(published['state'], 'published');
      final public = await api.request('stations/${f['station_id']}/reviews');
      expect(public['groups'][1]['count'], 1);
      expect(jsonEncode(public).contains('session_id'), false);
      expect(jsonEncode(public).contains('user_id'), false);
      await api.signOut();
      await login('other');
      await expectLater(
        api.request('$endpoint/history'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await login('customer');
      await api.request(
        endpoint,
        method: 'DELETE',
        body: {
          'request_key': operationKey(),
          'expected_version': published['version'],
        },
      );
      expect(
        (await api.request('stations/${f['station_id']}/reviews'))['total'],
        0,
      );
      expect((await api.request('$endpoint/history'))['total'], 3);
      await api.signOut();
    },
    skip: path == null
        ? 'Requires private local APP simulator fixture; physical acceptance is unverified.'
        : false,
  );
}
