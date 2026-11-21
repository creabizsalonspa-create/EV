import 'dart:io';

// Real-Laravel contracts must hit the checkout that seeded their private data.
// Permit only an explicitly selected local server; never a remote deployment.
Uri localFixtureOrigin() {
  final uri = Uri.parse(
    Platform.environment['EV_TEST_URL'] ?? 'http://127.0.0.1:8000',
  );
  if (uri.scheme != 'http' ||
      !['localhost', '127.0.0.1'].contains(uri.host) ||
      uri.userInfo.isNotEmpty ||
      !['', '/'].contains(uri.path) ||
      uri.hasQuery ||
      uri.hasFragment) {
    throw StateError('An owned loopback Laravel origin is required.');
  }
  return uri;
}
