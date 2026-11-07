/// Production defaults to the already deployed V-CHARGE backend.
/// Override the API **origin** per Flutter build using:
///   --dart-define=EV_API_BASE_URL=https://api.hasone.io
/// Do not include `/api/v1`: existing request paths already contain it.
class ApiConfig {
  static const String _configuredOrigin = String.fromEnvironment(
    'EV_API_BASE_URL',
    defaultValue: 'https://api.hasone.io',
  );

  static String get baseUrl {
    final uri = Uri.tryParse(_configuredOrigin.trim());
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        uri.path != '' && uri.path != '/') {
      throw StateError(
        'Invalid EV_API_BASE_URL. Supply an HTTPS origin without /api/v1.',
      );
    }
    return uri.origin;
  }
}
