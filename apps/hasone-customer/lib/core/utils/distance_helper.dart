import 'package:geolocator/geolocator.dart';

/// Calculates distance (km) and estimated driving duration (mins)
/// between the user's current location and a station.
class DistanceHelper {
  DistanceHelper._();

  /// Returns `{ distanceKm: double, durationMins: int }` or `null`
  /// if either position is missing.
  static Map<String, dynamic>? calculate({
    required Position? userPosition,
    required double? stationLat,
    required double? stationLng,
  }) {
    if (userPosition == null || stationLat == null || stationLng == null) {
      return null;
    }

    // Geolocator.distanceBetween uses the Haversine formula → meters
    final meters = Geolocator.distanceBetween(
      userPosition.latitude,
      userPosition.longitude,
      stationLat,
      stationLng,
    );

    final km = meters / 1000.0;

    // Estimate driving time: average city speed ~30 km/h
    final mins = (km / 30.0 * 60.0).ceil();

    return {
      'distanceKm': km,
      'durationMins': mins < 1 ? 1 : mins,
    };
  }

  /// Formatted distance string, e.g. "1.2 km away"
  static String distanceText({
    required Position? userPosition,
    required double? stationLat,
    required double? stationLng,
  }) {
    final result = calculate(
      userPosition: userPosition,
      stationLat: stationLat,
      stationLng: stationLng,
    );
    if (result == null) return '';
    final km = result['distanceKm'] as double;
    return '${km.toStringAsFixed(1)} km away';
  }

  /// Formatted duration string, e.g. "5 mins"
  static String durationText({
    required Position? userPosition,
    required double? stationLat,
    required double? stationLng,
  }) {
    final result = calculate(
      userPosition: userPosition,
      stationLat: stationLat,
      stationLng: stationLng,
    );
    if (result == null) return '';
    final mins = result['durationMins'] as int;
    return '$mins mins';
  }
}

