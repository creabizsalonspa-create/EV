import 'dart:async';
import 'dart:ui' as ui;

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/model/confirm_arrive_navigation_model.dart' as arrive_model;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_polyline_points/flutter_polyline_points.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:get/get.dart';

import 'package:evcharg/feature/Booking/UI/screen/Charging_Station_screen.dart';

class NavigateStationScreen extends StatefulWidget {
  final String bookingId;

  const NavigateStationScreen({super.key, required this.bookingId});

  @override
  State<NavigateStationScreen> createState() => _NavigateStationScreenState();
}

class _NavigateStationScreenState extends State<NavigateStationScreen> {
  late final AllBookingController _controller;
  Timer? _locationTimer;
  GoogleMapController? _mapController;
  LatLng? _userLatLng;
  LatLng? _stationLatLng;

  // Road-following polyline points from Directions API
  List<LatLng> _roadPolylinePoints = [];
  static const String _googleApiKey = 'AIzaSyCElkUva1jaYxMwnBLXjqukwUksFm5H4L8';

  // Flags — only fit bounds & fetch polyline once
  bool _boundsInitialized = false;
  bool _polylineFetched = false;
  bool _arrivalConfirmed = false; // prevent duplicate auto-arrival popups
  bool _locationUpdateInProgress = false; // prevent overlapping requests

  // Custom marker icons
  BitmapDescriptor _stationIcon = BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueGreen);
  BitmapDescriptor _carIcon = BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueAzure);

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());

    _loadMarkerIcons();

    // Fetch initial navigation data
    _controller.fetchNavigation(widget.bookingId).then((navData) {
      if (navData != null && navData.latitude != null && navData.longitude != null) {
        if (!mounted) return;
        setState(() {
          _stationLatLng = LatLng(navData.latitude!, navData.longitude!);
        });
        // Only fetch polyline from here if _sendLocationUpdate hasn't done it yet
        if (!_polylineFetched) {
          Future.delayed(const Duration(milliseconds: 800), () {
            if (mounted && !_polylineFetched) _fetchRoadPolyline();
          });
        }
      }
    });

    // Start periodic location updates every 15 seconds
    _startLocationUpdates();
  }

  @override
  void dispose() {
    _locationTimer?.cancel();
    super.dispose();
  }

  /// Load custom marker icons: SVG for station, PNG for car
  Future<void> _loadMarkerIcons() async {
    try {
      // ── Station icon from SVG ──
      const int size = 90; // 30.sp equivalent in pixels
      final svgString = await rootBundle.loadString(Assets.activeMapIcon);
      final pictureInfo = await vg.loadPicture(SvgStringLoader(svgString), null);
      final recorder = ui.PictureRecorder();
      final canvas = Canvas(recorder);
      final scaleX = size / pictureInfo.size.width;
      final scaleY = size / pictureInfo.size.height;
      canvas.scale(scaleX, scaleY);
      canvas.drawPicture(pictureInfo.picture);
      pictureInfo.picture.dispose();
      final img = await recorder.endRecording().toImage(size, size);
      final bytes = await img.toByteData(format: ui.ImageByteFormat.png);
      if (bytes != null && mounted) {
        setState(() {
          _stationIcon = BitmapDescriptor.bytes(bytes.buffer.asUint8List());
        });
      }
    } catch (_) {
      // Keep default green marker if SVG fails
    }

    try {
      // ── Car icon from PNG ──
      final ImageConfiguration config = ImageConfiguration(
        devicePixelRatio: View.of(context).devicePixelRatio,
      );
      final carIcon = await BitmapDescriptor.asset(
        config,
        Assets.carScreen,
        width: 30,
        height: 30,
      );
      if (mounted) {
        setState(() {
          _carIcon = carIcon;
        });
      }
    } catch (_) {
      // Keep default blue marker if PNG fails
    }
  }

  void _startLocationUpdates() {
    _sendLocationUpdate(); // initial update
    _locationTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      _sendLocationUpdate();
    });
  }

  Future<void> _sendLocationUpdate() async {
    if (_locationUpdateInProgress) return; // skip if previous still running
    _locationUpdateInProgress = true;
    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
      );
      if (!mounted) return;
      setState(() {
        _userLatLng = LatLng(position.latitude, position.longitude);
      });

      final result = await _controller.updateLocation(
        bookingId: widget.bookingId,
        latitude: position.latitude,
        longitude: position.longitude,
      );

      if (!mounted) return;

      // Update station coords from response if available
      if (result != null && result.stationLat != null && result.stationLng != null) {
        setState(() {
          _stationLatLng = LatLng(result.stationLat!, result.stationLng!);
        });
      }

      // Auto-confirm arrival when within 20 meters of station
      if (!_arrivalConfirmed &&
          result != null &&
          (result.distanceM ?? 999) <= 20) {
        _arrivalConfirmed = true;
        _locationTimer?.cancel(); // stop further location updates
        if (mounted) {
          final arriveData = await _controller.confirmArrival(
            widget.bookingId,
            latitude: position.latitude,
            longitude: position.longitude,
          );
          if (arriveData == null) {
            // Server rejected (>20m by Haversine) — allow retry next cycle
            _arrivalConfirmed = false;
            _startLocationUpdates();
          } else if (mounted) {
            _showArrivalPopup(arriveData);
          }
        }
      }

      // Fetch road polyline only once
      if (!_polylineFetched) {
        await _fetchRoadPolyline();
      }

      // Fit bounds only once on first location received
      if (!_boundsInitialized) {
        _fitMapBounds();
      }
    } catch (_) {
      // Location not available — skip this update
    } finally {
      _locationUpdateInProgress = false;
    }
  }

  /// Fetch road-following polyline from Google Directions API
  Future<void> _fetchRoadPolyline() async {
    if (_userLatLng == null || _stationLatLng == null) return;

    try {
      final polylinePoints = PolylinePoints();
      final result = await polylinePoints.getRouteBetweenCoordinates(
        googleApiKey: _googleApiKey,
        request: PolylineRequest(
          origin: PointLatLng(_userLatLng!.latitude, _userLatLng!.longitude),
          destination: PointLatLng(_stationLatLng!.latitude, _stationLatLng!.longitude),
          mode: TravelMode.driving,
        ),
      );

      if (!mounted) return;

      if (result.points.isNotEmpty) {
        final newPoints = result.points
            .map((p) => LatLng(p.latitude, p.longitude))
            .toList();
        if (mounted) {
          setState(() {
            _roadPolylinePoints = newPoints;
            _polylineFetched = true;
          });
        }
      }
    } catch (_) {
      // Directions API failed — keep straight line fallback
      if (mounted) _polylineFetched = true; // don't retry endlessly
    }
  }

  void _fitMapBounds() {
    if (_mapController == null || !mounted) return;

    try {
      if (_userLatLng != null && _stationLatLng != null) {
        final bounds = LatLngBounds(
          southwest: LatLng(
            _userLatLng!.latitude < _stationLatLng!.latitude ? _userLatLng!.latitude : _stationLatLng!.latitude,
            _userLatLng!.longitude < _stationLatLng!.longitude ? _userLatLng!.longitude : _stationLatLng!.longitude,
          ),
          northeast: LatLng(
            _userLatLng!.latitude > _stationLatLng!.latitude ? _userLatLng!.latitude : _stationLatLng!.latitude,
            _userLatLng!.longitude > _stationLatLng!.longitude ? _userLatLng!.longitude : _stationLatLng!.longitude,
          ),
        );
        _mapController!.animateCamera(CameraUpdate.newLatLngBounds(bounds, 80));
      } else if (_stationLatLng != null) {
        _mapController!.animateCamera(CameraUpdate.newLatLngZoom(_stationLatLng!, 15));
      } else if (_userLatLng != null) {
        _mapController!.animateCamera(CameraUpdate.newLatLngZoom(_userLatLng!, 15));
      }
      _boundsInitialized = true;
    } catch (_) {
      // Map not ready yet
    }
  }

  Set<Marker> get _markers {
    final markers = <Marker>{};
    if (_stationLatLng != null) {
      markers.add(Marker(
        markerId: const MarkerId('station'),
        position: _stationLatLng!,
        infoWindow: InfoWindow(
          title: _controller.navigationData.value?.stationName ?? 'Station',
        ),
        icon: _stationIcon,
      ));
    }
    if (_userLatLng != null) {
      markers.add(Marker(
        markerId: const MarkerId('user'),
        position: _userLatLng!,
        infoWindow: const InfoWindow(title: 'You'),
        icon: _carIcon,
        flat: true,
        anchor: const Offset(0.5, 0.5),
      ));
    }
    return markers;
  }

  Set<Polyline> get _polylines {
    if (_userLatLng == null || _stationLatLng == null) return {};

    // Use road-following points if available, else straight line fallback
    final points = _roadPolylinePoints.isNotEmpty
        ? _roadPolylinePoints
        : [_userLatLng!, _stationLatLng!];

    return {
      Polyline(
        polylineId: const PolylineId('route'),
        points: points,
        color: const Color(0xFF3BD37E),
        width: 5,
        startCap: Cap.roundCap,
        endCap: Cap.roundCap,
        jointType: JointType.round,
      ),
    };
  }

  /// Show arrival popup directly from data (used by auto-arrival, no extra API call)
  void _showArrivalPopup(arrive_model.Data arriveData) {
    if (!mounted) return;
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      isDismissible: false,
      enableDrag: false,
      backgroundColor: Colors.transparent,
      builder: (context) => Container(
        width: 390.w,
        padding: EdgeInsets.only(top: 32.h, right: 16.w, bottom: 32.h, left: 16.w),
        decoration: BoxDecoration(
          color: R.color.white,
          borderRadius: BorderRadius.only(
            topLeft: Radius.circular(24.r),
            topRight: Radius.circular(24.r),
          ),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            WText(
              text: arriveData.popupTitle ?? 'Arrival Confirm',
              color: R.color.darkNavy,
              fontSize: 18.sp,
              fontWeight: FontWeight.w700,
            ),
            SizedBox(height: 16.h),
            Divider(color: R.color.lightGray, height: 1.h, thickness: 1),
            SizedBox(height: 16.h),
            Row(
              children: [
                WText(text: 'Arrival Status:', color: R.color.neutralGray, fontSize: 14.sp, fontWeight: FontWeight.w400),
                SizedBox(width: 8.w),
                WText(
                  text: arriveData.arrivalStatus ?? 'Confirmed',
                  color: R.color.mintGreen,
                  fontSize: 14.sp,
                  fontWeight: FontWeight.w600,
                ),
              ],
            ),
            SizedBox(height: 12.h),
            Row(
              children: [
                WText(text: 'Station Name:', color: R.color.neutralGray, fontSize: 14.sp, fontWeight: FontWeight.w400),
                SizedBox(width: 8.w),
                Expanded(
                  child: WText(
                    text: arriveData.stationName ?? '',
                    color: R.color.darkNavy,
                    fontSize: 14.sp,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
            SizedBox(height: 16.h),
            WText(
              text: arriveData.description ?? "You've arrived at the charging station. Plug in your vehicle to start charging.",
              color: R.color.neutralGray,
              fontSize: 12.sp,
              fontWeight: FontWeight.w400,
            ),
            SizedBox(height: 24.h),
            WButton(
              label: 'Start Charging',
              height: 48.h,
              radius: 8,
              buttonColor: R.color.mintGreen,
              textColor: R.color.white,
              fontSize: 14.sp,
              fontWeight: FontWeight.w600,
              decorationType: DecorationType.solid,
              onPressed: () {
                Navigator.pop(context);
                Get.off(() => ChargingStationScreen(bookingId: widget.bookingId));
              },
            ),
          ],
        ),
      ),
    );
  }

  void _showArrivalConfirmation() async {
    // Get fresh GPS position for accurate distance check
    double lat;
    double lng;
    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
      );
      lat = position.latitude;
      lng = position.longitude;
    } catch (_) {
      if (_userLatLng == null) {
        Get.snackbar('Error', 'Unable to get your location.',
            snackPosition: SnackPosition.TOP);
        return;
      }
      lat = _userLatLng!.latitude;
      lng = _userLatLng!.longitude;
    }

    final arriveData = await _controller.confirmArrival(
      widget.bookingId,
      latitude: lat,
      longitude: lng,
    );
    if (arriveData == null) {
      Get.snackbar('Error', 'You are too far from the station. Please move within 20 metres.',
          snackPosition: SnackPosition.TOP);
      return;
    }

    if (!mounted) return;
    _arrivalConfirmed = true;
    _locationTimer?.cancel();
    _showArrivalPopup(arriveData);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.white,
      body: Stack(
        children: [
          // ── Google Map (full screen) ──
          SizedBox.expand(
            child: GoogleMap(
              initialCameraPosition: CameraPosition(
                target: _stationLatLng ?? _userLatLng ?? const LatLng(23.8103, 90.4125),
                zoom: 14,
              ),
              onMapCreated: (controller) {
                _mapController = controller;
                Future.delayed(const Duration(milliseconds: 500), () {
                  _fitMapBounds();
                });
              },
              markers: _markers,
              polylines: _polylines,
              mapType: MapType.normal,
              liteModeEnabled: false,
              myLocationEnabled: true,
              myLocationButtonEnabled: false,
              zoomControlsEnabled: false,
              mapToolbarEnabled: false,
            ),
          ),

          // ── Station label floating on map ──
          Positioned(
            top: 280.h,
            left: 0,
            right: 0,
            child: Center(
              child: Obx(() {
                final nav = _controller.navigationData.value;
                final updLoc = _controller.updateLocationData.value;
                final stationName = updLoc?.stationName ?? nav?.stationName ?? '';
                final distanceBanner = updLoc?.distanceBanner ?? nav?.distanceBanner ?? '';
                final stationImage = updLoc?.stationImage ?? nav?.stationImage ?? '';

                if (stationName.isEmpty) return const SizedBox.shrink();

                return Container(
                  padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 10.h),
                  decoration: BoxDecoration(
                    color: R.color.white,
                    borderRadius: BorderRadius.circular(12.r),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFF9C9C9C).withValues(alpha: 0.15),
                        blurRadius: 12,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Column(
                    children: [
                      ClipOval(
                        child: (stationImage.isNotEmpty && stationImage != 'string')
                            ? Image.network(
                                stationImage,
                                width: 48.w,
                                height: 48.w,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => Image.asset(
                                  Assets.charging,
                                  width: 48.w,
                                  height: 48.w,
                                  fit: BoxFit.cover,
                                ),
                              )
                            : Image.asset(
                                Assets.charging,
                                width: 48.w,
                                height: 48.w,
                                fit: BoxFit.cover,
                              ),
                      ),
                      SizedBox(height: 8.h),
                      WText(
                        text: stationName,
                        color: R.color.darkNavy,
                        fontSize: 13.sp,
                        fontWeight: FontWeight.w600,
                      ),
                      SizedBox(height: 2.h),
                      WText(
                        text: distanceBanner,
                        color: R.color.neutralGray,
                        fontSize: 11.sp,
                        fontWeight: FontWeight.w400,
                      ),
                    ],
                  ),
                );
              }),
            ),
          ),

          // ── Top direction card ──
          Positioned(
            top: 66.h,
            left: 16.w,
            right: 16.w,
            child: Obx(() {
              final nav = _controller.navigationData.value;
              final updLoc = _controller.updateLocationData.value;
              final directionText = nav?.directionText ?? '';
              final distanceBanner = updLoc?.distanceBanner ?? nav?.distanceBanner ?? '';
              final stationImage = updLoc?.stationImage ?? nav?.stationImage ?? '';

              if (directionText.isEmpty && _controller.navigationLoading.value) {
                return const SizedBox.shrink();
              }

              return Container(
                width: 358.w,
                constraints: BoxConstraints(minHeight: 64.h),
                decoration: BoxDecoration(
                  color: R.color.white,
                  borderRadius: BorderRadius.circular(8.r),
                  border: Border.all(color: R.color.lightGray, width: 1),
                ),
                child: Row(
                  children: [
                    Stack(
                      clipBehavior: Clip.none,
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.only(
                            topLeft: Radius.circular(8.r),
                            bottomLeft: Radius.circular(8.r),
                          ),
                          child: (stationImage.isNotEmpty && stationImage != 'string')
                              ? Image.network(
                                  stationImage,
                                  width: 64.w,
                                  height: 64.h,
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, __, ___) => Image.asset(
                                    Assets.charging,
                                    width: 64.w,
                                    height: 64.h,
                                    fit: BoxFit.cover,
                                  ),
                                )
                              : Image.asset(
                                  Assets.charging,
                                  width: 64.w,
                                  height: 64.h,
                                  fit: BoxFit.cover,
                                ),
                        ),
                        if (distanceBanner.isNotEmpty)
                          Positioned(
                            bottom: 6.h,
                            left: 0,
                            right: 0,
                            child: Center(
                              child: Container(
                                padding: EdgeInsets.symmetric(horizontal: 6.w, vertical: 2.h),
                                decoration: BoxDecoration(
                                  color: R.color.mintGreen,
                                  borderRadius: BorderRadius.circular(4.r),
                                ),
                                child: WText(
                                  text: distanceBanner,
                                  color: R.color.white,
                                  fontSize: 9.sp,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                          ),
                      ],
                    ),
                    SizedBox(width: 12.w),
                    Expanded(
                      child: Padding(
                        padding: EdgeInsets.symmetric(vertical: 8.h, horizontal: 4.w),
                        child: WText(
                          text: directionText,
                          color: R.color.darkNavy,
                          fontSize: 13.sp,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
              );
            }),
          ),

          // ── Loading overlay ──
          Obx(() {
            if (_controller.navigationLoading.value) {
              return Positioned.fill(
                child: Container(
                  color: Colors.white54,
                  child: const Center(child: CircularProgressIndicator()),
                ),
              );
            }
            return const SizedBox.shrink();
          }),

          // ── Bottom info bar ──
          Positioned(
            bottom: 0,
            left: 0,
            right: 0,
            child: Container(
              height: 84.h,
              padding: EdgeInsets.symmetric(horizontal: 16.w),
              decoration: BoxDecoration(
                color: R.color.white,
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFF9C9C9C).withValues(alpha: 0.15),
                    blurRadius: 20,
                    offset: const Offset(0, -2),
                  ),
                ],
              ),
              child: Obx(() {
                final nav = _controller.navigationData.value;
                final updLoc = _controller.updateLocationData.value;
                final durationMins = updLoc?.durationMins ?? nav?.durationMins ?? 0;
                final distanceKm = nav?.distanceKm ?? 0.0;
                final arrivalTime = updLoc?.eta ?? nav?.arrivalTime ?? '';

                return Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    // ── Close button ──
                    GestureDetector(
                      onTap: () => Get.back(),
                      child: Container(
                        width: 36.w,
                        height: 36.w,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: R.color.lightGray, width: 1.5),
                        ),
                        child: Icon(Icons.close, size: 18.sp, color: R.color.darkNavy),
                      ),
                    ),

                    // ── Center info — tap to confirm arrival ──
                    GestureDetector(
                      onTap: _showArrivalConfirmation,
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          WText(
                            text: '$durationMins Min',
                            color: R.color.darkNavy,
                            fontSize: 16.sp,
                            fontWeight: FontWeight.w700,
                          ),
                          SizedBox(height: 2.h),
                          WText(
                            text: '${distanceKm.toStringAsFixed(1)} KM  |  $arrivalTime',
                            color: R.color.neutralGray,
                            fontSize: 12.sp,
                            fontWeight: FontWeight.w400,
                          ),
                        ],
                      ),
                    ),

                    // ── Refresh button ──
                    GestureDetector(
                      onTap: () => _controller.fetchNavigation(widget.bookingId),
                      child: Container(
                        width: 36.w,
                        height: 36.w,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: R.color.lightGray, width: 1.5),
                        ),
                        child: Icon(Icons.refresh, size: 18.sp, color: R.color.darkNavy),
                      ),
                    ),
                  ],
                );
              }),
            ),
          ),
        ],
      ),
    );
  }
}

