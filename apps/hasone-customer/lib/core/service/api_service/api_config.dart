/// Production defaults to the already deployed V-CHARGE backend.
/// Override the API **origin** per Flutter build using:
///   --dart-define=EV_API_BASE_URL=https://authorized-api.example
///
/// Local debug simulations may explicitly opt in to loopback HTTP with BOTH:
///   --dart-define=EV_LOCAL_DEMO=true
///   --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000
///
/// Never include `/api/v1`: existing request paths already contain it.
class ApiConfig {
  static const String _configuredOrigin = String.fromEnvironment(
    'EV_API_BASE_URL',
    defaultValue: 'https://api.hasone.io',
  );
  static const bool _localDemo = bool.fromEnvironment('EV_LOCAL_DEMO');
  static const bool _release = bool.fromEnvironment('dart.vm.product');

  /// Strict origin validation shared by native production and fixture tests.
  /// Local cleartext is always disabled for release builds, even if a caller
  /// sets EV_LOCAL_DEMO=true. Remote cleartext is never permitted.
  static String validateOrigin(
    String configuredOrigin, {
    required bool isRelease,
    required bool localDemo,
  }) {
    final uri = Uri.tryParse(configuredOrigin.trim());
    if (uri == null) {
      throw StateError('Invalid EV_API_BASE_URL: missing service origin.');
    }
    final loopback =
        uri.host == 'localhost' || uri.host == '127.0.0.1';
    final secure = uri.scheme == 'https';
    final localDebug = !isRelease && localDemo &&
        uri.scheme == 'http' && loopback;
    if (!secure && !localDebug ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        (uri.path.isNotEmpty && uri.path != '/') ||
        (uri.hasPort && uri.port < 1)) {
      throw StateError(
        'Invalid EV_API_BASE_URL. Use an HTTPS origin; '
        'debug HTTP requires EV_LOCAL_DEMO and exact loopback.',
      );
    }
    return uri.origin;
  }

  static String get baseUrl => validateOrigin(
    _configuredOrigin,
    isRelease: _release,
    localDemo: _localDemo,
  );
}
