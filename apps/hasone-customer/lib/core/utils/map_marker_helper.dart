import 'dart:ui' as ui;

import 'package:flutter/services.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../app/assets.dart';

/// Caches and provides custom SVG-based BitmapDescriptors for map markers.
class MapMarkerHelper {
  MapMarkerHelper._();

  static BitmapDescriptor? _activeIcon;
  static BitmapDescriptor? _inactiveIcon;

  static BitmapDescriptor? get activeIcon => _activeIcon;
  static BitmapDescriptor? get inactiveIcon => _inactiveIcon;

  /// Call once (e.g. in initState) to load both icons.
  /// Safe to call multiple times — skips if already loaded.
  static Future<void> loadIcons() async {
    if (_activeIcon != null && _inactiveIcon != null) return;

    // Design size is 36x36. Render at 2.5x for crisp display on mobile.
    const double renderSize = 25 * 2; // 90px

    _activeIcon ??=
        await _svgToBitmapDescriptor(Assets.activeMapIcon, renderSize);
    _inactiveIcon ??=
        await _svgToBitmapDescriptor(Assets.inactiveMapIcon, renderSize);
  }

  /// Returns the correct icon for a station's availability status.
  static BitmapDescriptor iconForStatus(String? status) {
    final isAvailable = status == 'Available';
    if (isAvailable) {
      return _activeIcon ??
          BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueGreen);
    }
    return _inactiveIcon ??
        BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueRed);
  }

  /// Converts an SVG asset into a [BitmapDescriptor].
  /// [targetWidth] is the desired width in pixels.
  static Future<BitmapDescriptor> _svgToBitmapDescriptor(
      String assetPath, double targetWidth) async {
    final rawSvg = await rootBundle.loadString(assetPath);
    final pictureInfo = await vg.loadPicture(SvgStringLoader(rawSvg), null);

    final double srcWidth = pictureInfo.size.width;
    final double srcHeight = pictureInfo.size.height;
    final double scale = targetWidth / srcWidth;
    final int width = targetWidth.ceil();
    final int height = (srcHeight * scale).ceil();

    final recorder = ui.PictureRecorder();
    final canvas = ui.Canvas(recorder);
    canvas.scale(scale);
    canvas.drawPicture(pictureInfo.picture);
    pictureInfo.picture.dispose();

    final image = await recorder.endRecording().toImage(width, height);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();

    return BitmapDescriptor.bytes(bytes!.buffer.asUint8List());
  }
}

