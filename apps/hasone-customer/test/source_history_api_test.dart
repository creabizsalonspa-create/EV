import 'dart:io';

import 'package:dio/dio.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Booking/history/source_history.dart';
import 'package:flutter_test/flutter_test.dart';

/// Opt-in real disposable API check. The harness supplies synthetic, short-lived
/// JWTs in the child process environment; none are logged or persisted here.
void main() {
  final url = Platform.environment['AGENT2_HISTORY_API_URL'];
  test(
    'actual private Express history is consumed by the Flutter API model',
    () async {
      final uri = Uri.parse(url!);
      if (uri.scheme != 'http' ||
          uri.host != '127.0.0.1' ||
          uri.path.isNotEmpty ||
          uri.hasQuery ||
          uri.hasFragment ||
          uri.userInfo.isNotEmpty) {
        throw StateError('Disposable loopback API required');
      }
      final token = Platform.environment['AGENT2_HISTORY_TOKEN']!;
      final foreignToken =
          Platform.environment['AGENT2_HISTORY_FOREIGN_TOKEN']!;
      final expected = Platform.environment['AGENT2_HISTORY_EXPECTED_SESSION']!;
      var authorization = token;
      final dio = Dio(BaseOptions(baseUrl: url, validateStatus: (_) => true));
      final client = NetworkClient(
        dio: dio,
        onUnAuthorize: () {},
        commonHeaders: () => {'Authorization': authorization},
      );
      try {
        final api = SourceHistoryApi(client);
        final seen = <String>{};
        String? cursor;
        SourceHistoryEntry? matched;
        do {
          final page = await api.load(cursor);
          for (final entry in page.entries) {
            expect(seen.add(entry.sessionId), true);
            if (entry.sessionId == expected) matched = entry;
          }
          cursor = page.nextCursor;
          expect(seen.length, lessThan(500));
        } while (cursor != null);
        expect(matched, isNotNull);
        expect(matched!.energyWh, 7000);
        expect(matched.state, 'ended');
        expect(matched.totalFils, 735);
        authorization = foreignToken;
        final foreign = await api.load(null);
        expect(
          foreign.entries.any((entry) => seen.contains(entry.sessionId)),
          false,
        );
        authorization = '';
        await expectLater(api.load(null), throwsFormatException);
      } finally {
        dio.close(force: true);
      }
    },
    skip: url == null
        ? 'Requires the isolated Express/Mongo/OCPP harness'
        : false,
  );
}
