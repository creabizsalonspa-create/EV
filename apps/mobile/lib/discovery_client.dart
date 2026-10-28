import 'api.dart';

typedef DiscoveryRequest =
    Future<dynamic> Function(
      String path,
      String method,
      Map<String, dynamic>? body,
    );

class GeoPoint {
  final double latitude, longitude;
  const GeoPoint._(this.latitude, this.longitude);
  factory GeoPoint(double latitude, double longitude) {
    if (!latitude.isFinite ||
        !longitude.isFinite ||
        latitude.abs() > 90 ||
        longitude.abs() > 180)
      throw const FormatException('Invalid geographic point.');
    return GeoPoint._(latitude, longitude);
  }
  factory GeoPoint.fromJson(Map<String, dynamic> json) => GeoPoint(
    (json['latitude'] as num).toDouble(),
    (json['longitude'] as num).toDouble(),
  );
  Map<String, double> toJson() => {
    'latitude': latitude,
    'longitude': longitude,
  };
  String get coordinate =>
      '${latitude.toStringAsFixed(6)},${longitude.toStringAsFixed(6)}';
}

class GeoPlace {
  final String id, label;
  final GeoPoint point;
  GeoPlace.fromJson(Map<String, dynamic> json)
    : id = json['id'] as String,
      label = json['label'] as String,
      point = GeoPoint.fromJson(json) {
    if (!id.startsWith('geoapify:') ||
        id.length > 100 ||
        label.isEmpty ||
        label.length > 600)
      throw const FormatException('Invalid address result.');
  }
}

class DiscoveryCapabilities {
  final bool geocoding, routing;
  DiscoveryCapabilities.fromJson(Map<String, dynamic> json)
    : geocoding = json['geocoding'] as bool,
      routing = json['routing'] as bool {
    if (json['provider'] != 'geoapify' || json['max_charging_stops'] != 3)
      throw const FormatException('Unknown discovery provider.');
  }
}

class RouteEstimate {
  final double meters, seconds;
  final List<List<GeoPoint>> segments;
  RouteEstimate.fromJson(Map<String, dynamic> json)
    : meters = (json['distance_meters'] as num).toDouble(),
      seconds = (json['duration_seconds'] as num).toDouble(),
      segments = (json['segments'] as List)
          .map(
            (line) => (line as List)
                .map(
                  (p) => GeoPoint.fromJson(Map<String, dynamic>.from(p as Map)),
                )
                .toList(),
          )
          .toList() {
    if (json['provider'] != 'geoapify' ||
        json['evidence'] != 'PROVIDER_ROUTE_ESTIMATE' ||
        json['traffic_verified'] != false ||
        json['charging_time_included'] != false ||
        !meters.isFinite ||
        meters < 0 ||
        meters > 40000000 ||
        !seconds.isFinite ||
        seconds < 0 ||
        seconds > 31536000 ||
        segments.isEmpty ||
        segments.length > 100 ||
        segments.any((s) => s.length < 2) ||
        segments.fold<int>(0, (n, s) => n + s.length) > 10000)
      throw const FormatException('Invalid provider route evidence.');
  }
}

class DiscoveryClient {
  final DiscoveryRequest request;
  DiscoveryClient(EvApi api)
    : request = ((path, method, body) =>
          api.request('discovery/$path', method: method, body: body));
  DiscoveryClient.withRequest(this.request);
  Future<DiscoveryCapabilities> capabilities() async =>
      DiscoveryCapabilities.fromJson(
        Map<String, dynamic>.from(
          await request('capabilities', 'GET', null) as Map,
        ),
      );
  Future<List<GeoPlace>> addresses(
    String query,
    String language, {
    String? country,
  }) async {
    final json = Map<String, dynamic>.from(
      await request('addresses', 'POST', {
            'consent': true,
            'query': query,
            'language': language,
            if (country?.isNotEmpty ?? false) 'country': country,
          })
          as Map,
    );
    if (json['provider'] != 'geoapify' ||
        json['evidence'] != 'GEOCODING_RESULT' ||
        json['places'] is! List ||
        (json['places'] as List).length > 5)
      throw const FormatException('Invalid address evidence.');
    return (json['places'] as List)
        .map((p) => GeoPlace.fromJson(Map<String, dynamic>.from(p as Map)))
        .toList();
  }

  Future<RouteEstimate> route(List<GeoPoint> points, String language) async {
    if (points.length < 2 || points.length > 5)
      throw ArgumentError(
        'Two endpoints and at most three charging stops are supported.',
      );
    return RouteEstimate.fromJson(
      Map<String, dynamic>.from(
        await request('route', 'POST', {
              'consent': true,
              'language': language,
              'points': points.map((p) => p.toJson()).toList(),
            })
            as Map,
      ),
    );
  }
}

Uri navigationUri(GeoPoint origin, GeoPoint destination, List<GeoPoint> stops) {
  if (stops.length > 3) throw ArgumentError('At most three charging stops.');
  return Uri.https('www.google.com', '/maps/dir/', {
    'api': '1',
    'origin': origin.coordinate,
    'destination': destination.coordinate,
    'travelmode': 'driving',
    if (stops.isNotEmpty) 'waypoints': stops.map((p) => p.coordinate).join('|'),
  });
}
