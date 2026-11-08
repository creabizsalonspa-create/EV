import 'package:flutter_test/flutter_test.dart';
import 'package:evcharg/core/service/api_service/api_config.dart';

void main() {
  String valid(String origin, {bool release = false, bool demo = false}) =>
      ApiConfig.validateOrigin(
        origin, isRelease: release, localDemo: demo,
      );

  test('HTTPS origin remains usable in development and release', () {
    expect(
      valid('https://api.hasone.io', release: true),
      'https://api.hasone.io',
    );
    expect(
      valid('https://api.hasone.io', release: false),
      'https://api.hasone.io',
    );
  });

  test('local demo needs exact loopback, opt-in and nonrelease mode', () {
    expect(
      valid('http://127.0.0.1:8000', demo: true),
      'http://127.0.0.1:8000',
    );
    expect(
      valid('http://localhost:8000', demo: true),
      'http://localhost:8000',
    );
    for (final origin in [
      'http://127.0.0.1:8000',
      'http://localhost:8000',
    ]) {
      expect(
        () => valid(origin, release: true, demo: true),
        throwsStateError,
      );
      expect(
        () => valid(origin, release: false, demo: false),
        throwsStateError,
      );
    }
  });

  test('remote HTTP is rejected even if demo enabled', () {
    for (final origin in [
      'http://example.test',
      'http://localhost.evil.test:8000',
      'http://192.168.1.9:8000',
      'http://10.0.2.2:8000',
      'http://0.0.0.0:8000',
    ]) {
      expect(
        () => valid(origin, demo: true),
        throwsStateError,
        reason: origin,
      );
    }
  });

  test('credential-bearing and path-shaped origins cannot be configured', () {
    for (final origin in [
      'https://name:secret@api.hasone.io',
      'https://api.hasone.io/api/v1',
      'https://api.hasone.io/?token=secret',
      'https://api.hasone.io/#fragment',
      'https://',
      'file:///tmp/backend',
    ]) {
      expect(
        () => valid(origin, release: true),
        throwsStateError,
        reason: origin,
      );
    }
  });
}
