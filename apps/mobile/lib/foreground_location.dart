import 'dart:async';
import 'package:geolocator/geolocator.dart';

class LocationLookupFailure implements Exception {
  final String reason;
  const LocationLookupFailure(this.reason);
}

class ForegroundFix {
  final double latitude, longitude;
  final double? accuracyMeters;
  const ForegroundFix(this.latitude, this.longitude, this.accuracyMeters);
}

/// One foreground fix only. No streams, background service or stored preference.
class ForegroundLocationReader {
  final GeolocatorPlatform? platform;
  ForegroundLocationReader({this.platform});
  Future<ForegroundFix> read() async {
    final driver = platform ?? GeolocatorPlatform.instance;
    try {
      if (!await driver.isLocationServiceEnabled()) {
        throw const LocationLookupFailure('disabled');
      }
      var permission = await driver.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await driver.requestPermission();
      }
      if (permission == LocationPermission.deniedForever) {
        throw const LocationLookupFailure('deniedForever');
      }
      if (permission != LocationPermission.whileInUse &&
          permission != LocationPermission.always) {
        throw const LocationLookupFailure('denied');
      }
      final position = await driver
          .getCurrentPosition(
            locationSettings: const LocationSettings(
              accuracy: LocationAccuracy.medium,
              timeLimit: Duration(seconds: 10),
            ),
          )
          .timeout(const Duration(seconds: 11));
      if (!position.latitude.isFinite ||
          !position.longitude.isFinite ||
          position.latitude.abs() > 90 ||
          position.longitude.abs() > 180) {
        throw const LocationLookupFailure('invalid');
      }
      final age = DateTime.now().toUtc().difference(position.timestamp.toUtc());
      if (age > const Duration(minutes: 2) ||
          age < const Duration(seconds: -30)) {
        throw const LocationLookupFailure('stale');
      }
      return ForegroundFix(
        position.latitude,
        position.longitude,
        position.accuracy.isFinite && position.accuracy > 0
            ? position.accuracy
            : null,
      );
    } on LocationLookupFailure {
      rethrow;
    } on TimeoutException {
      throw const LocationLookupFailure('timeout');
    } catch (_) {
      throw const LocationLookupFailure('unavailable');
    }
  }
}
