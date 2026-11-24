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
        id.length <= 'geoapify:'.length || id.length > 100 ||
        label.trim().isEmpty ||
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
  RouteEstimate._(this.meters,this.seconds,this.segments);
  factory RouteEstimate.fromJson(Map<String, dynamic> json) {
    final distance = json['distance_meters'], duration = json['duration_seconds'];
    final raw = json['segments'];
    if (json['provider'] != 'geoapify' ||
        json['evidence'] != 'PROVIDER_ROUTE_ESTIMATE' ||
        json['traffic_verified'] != false ||
        json['charging_time_included'] != false ||
        distance is! num || !distance.isFinite || distance < 0 || distance > 40000000 ||
        duration is! num || !duration.isFinite || duration < 0 || duration > 31536000 ||
        raw is! List || raw.isEmpty || raw.length > 100) {
      throw const FormatException('Invalid provider route evidence.');
    }
    var count = 0;
    // Bound the entire raw geometry before allocating typed points.
    for (final line in raw) {
      if (line is! List || line.length < 2 || (count += line.length) > 10000) {
        throw const FormatException('Invalid route geometry budget.');
      }
    }
    final segments = <List<GeoPoint>>[];
    for (final line in raw) {
      final points = <GeoPoint>[];
      for (final value in line as List) {
        if (value is! Map || value['latitude'] is! num || value['longitude'] is! num) {
          throw const FormatException('Invalid route point.');
        }
        points.add(GeoPoint(
          (value['latitude'] as num).toDouble(),
          (value['longitude'] as num).toDouble(),
        ));
      }
      segments.add(List<GeoPoint>.unmodifiable(points));
    }
    return RouteEstimate._(distance.toDouble(),duration.toDouble(),
      List<List<GeoPoint>>.unmodifiable(segments));
  }
}

class DiscoveryClient {
  final DiscoveryRequest request;
  // A client is tied to the authenticated EvApi epoch that created it.
  // Reusing the object after sign-out/sign-in must not let in-flight private
  // route responses or previously approved third-party navigation through.
  final bool Function() currentSession;
  final Stream<int>? sessionEvents;
  static bool _unfencedTestClient() => true;
  factory DiscoveryClient(EvApi api, {void Function()? onExpired}) {
    final fence = api.sessionFence();
    return DiscoveryClient.withRequest((path, method, body) async {
      // The caller can keep an old DiscoveryClient alive after EvApi rotates
      // its credential jar. Never let it submit private coordinates under
      // the next actor, re-display an earlier result, or sign out that actor.
      if (!fence()) throw StateError('Discovery session changed');
      try {
        final reply = await api.request(
          'discovery/$path',
          method: method,
          body: body,
        );
        if (!fence()) throw StateError('Discovery session changed');
        return reply;
      } on ApiException catch (error) {
        if (error.endsCurrentSession || (fence() && [401, 419].contains(error.status))) {
          onExpired?.call();
        }
        rethrow;
      }
    }, currentSession: fence, sessionEvents: api.sessionEvents);
  }
  DiscoveryClient.withRequest(this.request, {bool Function()? currentSession, this.sessionEvents})
    : currentSession = currentSession ?? _unfencedTestClient;
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
    final places = (json['places'] as List)
        .map((p) => GeoPlace.fromJson(Map<String, dynamic>.from(p as Map)))
        .toList(growable: false);
    if (places.map((p) => p.id).toSet().length != places.length) {
      throw const FormatException('Duplicate address evidence.');
    }
    return places;
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
