import 'dart:async';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/map/model/nearest_station_model.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../Home/UI/controller/home_controller.dart';
import '../../../viewdetails/UI/screen/view_details_screen.dart';
import '../../../../core/utils/distance_helper.dart';
import '../../../../core/utils/map_marker_helper.dart';
import '../controller/map_controller.dart';

class MapScreen extends StatefulWidget {
  const MapScreen({super.key});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final MapController _mapController;
  late final HomeController _homeController;
  final Completer<GoogleMapController> _mapCompleter = Completer();

  Set<Marker> _markers = {};

  @override
  void initState() {
    super.initState();
    _homeController = Get.find<HomeController>();
    _mapController = Get.find<MapController>();
    _loadIconsAndBuildMarkers();
  }

  Future<void> _loadIconsAndBuildMarkers() async {
    await MapMarkerHelper.loadIcons();
    _buildMarkers();
    if (mounted) setState(() {});
  }

  void _buildMarkers() {
    final stations = _homeController.stations;
    final newMarkers = <Marker>{};
    for (int i = 0; i < stations.length; i++) {
      final station = stations[i];
      if (station.latitude == null || station.longitude == null) continue;
      newMarkers.add(
        Marker(
          markerId: MarkerId(station.sId ?? '$i'),
          position: LatLng(station.latitude!, station.longitude!),
          infoWindow: InfoWindow(
            title: station.name ?? '',
            snippet: station.address ?? '',
          ),
          icon: MapMarkerHelper.iconForStatus(station.status),
          onTap: () => _mapController.selectStation(i),
        ),
      );
    }
    _markers = newMarkers;
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final pos = _homeController.currentPosition.value;
    final initialPos = pos != null
        ? LatLng(pos.latitude, pos.longitude)
        : const LatLng(23.8103, 90.4125);

    return Scaffold(
      body: Stack(
        children: [
          // ── Full-screen Google Map (same pattern as MapUpdateScreen) ──
          GoogleMap(
            initialCameraPosition: CameraPosition(
              target: initialPos,
              zoom: 14,
            ),
            markers: _markers,
            mapType: MapType.normal,
            liteModeEnabled: false,
            myLocationEnabled: true,
            myLocationButtonEnabled: false,
            zoomControlsEnabled: false,
            onMapCreated: (GoogleMapController controller) {
              if (!_mapCompleter.isCompleted) {
                _mapCompleter.complete(controller);
              }
              _mapController.googleMapController = controller;
            },
            onTap: (_) => _mapController.selectStation(null),
          ),

          // ── Search bar + suggestions ────────────────────
          Positioned(
            top: 62.h,
            left: 16.w,
            right: 16.w,
            child: Column(
              children: [
                _buildSearchBar(),
                // ── Search suggestions dropdown ──
                Obx(() {
                  final suggestions = _mapController.searchSuggestions;
                  if (suggestions.isEmpty) return const SizedBox.shrink();
                  return Container(
                    margin: EdgeInsets.only(top: 4.h),
                    constraints: BoxConstraints(maxHeight: 220.h),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(8.r),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.1),
                          blurRadius: 12,
                          offset: const Offset(0, 4),
                        ),
                      ],
                    ),
                    child: ListView.separated(
                      shrinkWrap: true,
                      padding: EdgeInsets.symmetric(vertical: 6.h),
                      itemCount: suggestions.length,
                      separatorBuilder: (_, __) => Divider(
                        height: 1,
                        color: Colors.grey.shade200,
                      ),
                      itemBuilder: (context, index) {
                        final station = suggestions[index];
                        final isAvailable = station.status == 'Available';
                        return InkWell(
                          onTap: () {
                            _searchController.clear();
                            _mapController.clearSearch();
                            FocusScope.of(context).unfocus();
                            _mapController.goToStation(station);
                          },
                          child: Padding(
                            padding: EdgeInsets.symmetric(
                              horizontal: 12.w,
                              vertical: 10.h,
                            ),
                            child: Row(
                              children: [
                                Icon(
                                  Icons.ev_station,
                                  size: 20.sp,
                                  color: isAvailable
                                      ? R.color.mintGreen
                                      : const Color(0xFFEF4444),
                                ),
                                SizedBox(width: 10.w),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      WText(
                                        text: station.name ?? '',
                                        fontSize: 13.sp,
                                        fontWeight: FontWeight.w600,
                                        color: R.color.darkNavy,
                                      ),
                                      SizedBox(height: 2.h),
                                      WText(
                                        text: station.address ?? '',
                                        fontSize: 11.sp,
                                        fontWeight: FontWeight.w400,
                                        color: R.color.mediumGray,
                                      ),
                                    ],
                                  ),
                                ),
                                Container(
                                  width: 8.w,
                                  height: 8.w,
                                  decoration: BoxDecoration(
                                    color: isAvailable
                                        ? R.color.mintGreen
                                        : const Color(0xFFEF4444),
                                    shape: BoxShape.circle,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                  );
                }),
              ],
            ),
          ),

          // ── My location button ────────────────────────────
          Positioned(
            bottom: 24.h,
            right: 16.w,
            child: Obx(() {
              if (_mapController.selectedStation.value != null) {
                return const SizedBox.shrink();
              }
              return _buildMyLocationButton();
            }),
          ),

          // ── Station info card ─────────────────────────────
          Obx(() {
            final idx = _mapController.selectedStation.value;
            if (idx == null || idx >= _homeController.stations.length) {
              return const SizedBox.shrink();
            }
            return Positioned(
              bottom: 16.h,
              left: 16.w,
              right: 16.w,
              child: _buildStationCard(_homeController.stations[idx]),
            );
          }),
        ],
      ),
    );
  }

  // ── Search bar ──────────────────────────────────────────────
  Widget _buildSearchBar() {
    return Container(
      height: 58.h,
      padding: EdgeInsets.all(8.w),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8.r),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.08),
            blurRadius: 12,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        children: [
          Expanded(
            child: Container(
              height: 42.h,
              padding: EdgeInsets.symmetric(horizontal: 8.w),
              decoration: BoxDecoration(
                color: const Color(0xFFF3F4F6),
                borderRadius: BorderRadius.circular(8.r),
              ),
              child: Row(
                children: [
                  Icon(Icons.search, size: 20.sp, color: R.color.neutralGray),
                  SizedBox(width: 8.w),
                  Expanded(
                    child: TextField(
                      controller: _searchController,
                      onChanged: (value) {
                        _mapController.searchQuery.value = value;
                      },
                      style: TextStyle(
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w400,
                        color: R.color.darkNavy,
                      ),
                      decoration: InputDecoration(
                        hintText: 'Search Station',
                        hintStyle: TextStyle(
                          fontSize: 14.sp,
                          fontWeight: FontWeight.w400,
                          color: R.color.neutralGray,
                        ),
                        border: InputBorder.none,
                        isDense: true,
                        contentPadding: EdgeInsets.zero,
                      ),
                    ),
                  ),
                  // Clear button
                  Obx(() {
                    if (_mapController.searchQuery.value.isEmpty) {
                      return const SizedBox.shrink();
                    }
                    return GestureDetector(
                      onTap: () {
                        _searchController.clear();
                        _mapController.clearSearch();
                        FocusScope.of(context).unfocus();
                      },
                      child: Icon(Icons.close,
                          size: 18.sp, color: R.color.neutralGray),
                    );
                  }),
                ],
              ),
            ),
          ),
          SizedBox(width: 8.w),
          Container(
            width: 42.w,
            height: 42.h,
            padding: EdgeInsets.all(13.w),
            decoration: BoxDecoration(
              color: const Color(0xFFF3F4F6),
              borderRadius: BorderRadius.circular(8.r),
            ),
            child: Icon(Icons.tune, size: 16.sp, color: R.color.mintGreen),
          ),
        ],
      ),
    );
  }

  // ── My location FAB ─────────────────────────────────────────
  Widget _buildMyLocationButton() {
    return Container(
      width: 48.w,
      height: 48.w,
      decoration: BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.12),
            blurRadius: 10,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Icon(Icons.my_location, size: 22.sp, color: R.color.mintGreen),
    );
  }

  // ── Station info card ───────────────────────────────────────
  Widget _buildStationCard(Data station) {
    final isAvailable = (station.status ?? 'Available') == 'Available';
    final name = station.name ?? 'Charging Station';
    final address = station.address ?? '';

    // Calculate distance & duration from user's current location
    final userPos = _homeController.currentPosition.value;
    final distance = DistanceHelper.distanceText(
      userPosition: userPos,
      stationLat: station.latitude,
      stationLng: station.longitude,
    );
    final time = DistanceHelper.durationText(
      userPosition: userPos,
      stationLat: station.latitude,
      stationLng: station.longitude,
    );

    final rating = station.rating?.toStringAsFixed(1) ?? '0.0';
    final reviews = '${station.reviewCount ?? 0} reviews';

    return Container(
      width: 358.w,
      padding: EdgeInsets.all(16.w),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16.r),
        boxShadow: const [
          BoxShadow(
            color: Color(0x999C9C9C),
            blurRadius: 50,
            spreadRadius: -14,
            offset: Offset(0, 1),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Title + direction icon
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: WText(
                  text: name,
                  fontSize: 16.sp,
                  fontWeight: FontWeight.w700,
                  color: R.color.darkNavy,
                ),
              ),
              Container(
                width: 36.w,
                height: 36.w,
                decoration: BoxDecoration(
                  color: R.color.mintGreen,
                  borderRadius: BorderRadius.circular(10.r),
                ),
                child: Icon(Icons.navigation_rounded,
                    color: Colors.white, size: 18.sp),
              ),
            ],
          ),
          SizedBox(height: 6.h),

          // Address
          WText(
            text: address,
            fontSize: 12.sp,
            fontWeight: FontWeight.w400,
            color: R.color.mediumGray,
          ),
          SizedBox(height: 10.h),

          // Distance + Time
          Row(
            children: [
              if (distance.isNotEmpty) ...[
                Icon(Icons.location_on_outlined,
                    size: 14.sp, color: R.color.mintGreen),
                SizedBox(width: 3.w),
                WText(
                  text: distance,
                  fontSize: 12.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.mediumGray,
                ),
                SizedBox(width: 14.w),
              ],
              if (time.isNotEmpty) ...[
                Icon(Icons.directions_car_outlined,
                    size: 14.sp, color: R.color.mintGreen),
                SizedBox(width: 3.w),
                WText(
                  text: time,
                  fontSize: 12.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.mediumGray,
                ),
              ],
            ],
          ),
          SizedBox(height: 8.h),

          // Rating
          Row(
            children: [
              ...List.generate(5, (i) {
                final ratingVal = station.rating ?? 0.0;
                if (i < ratingVal.floor()) {
                  return Icon(Icons.star, size: 16.sp, color: const Color(0xFFFFC107));
                } else if (i < ratingVal) {
                  return Icon(Icons.star_half, size: 16.sp, color: const Color(0xFFFFC107));
                } else {
                  return Icon(Icons.star_border, size: 16.sp, color: const Color(0xFFFFC107));
                }
              }),
              SizedBox(width: 4.w),
              WText(
                text: rating,
                fontSize: 12.sp,
                fontWeight: FontWeight.w600,
                color: R.color.darkNavy,
              ),
              SizedBox(width: 4.w),
              WText(
                text: '($reviews)',
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
                color: R.color.mediumGray,
              ),
            ],
          ),
          SizedBox(height: 8.h),

          // Availability status
          Row(
            children: [
              Container(
                width: 8.w,
                height: 8.w,
                decoration: BoxDecoration(
                  color: isAvailable
                      ? R.color.mintGreen
                      : const Color(0xFFEF4444),
                  shape: BoxShape.circle,
                ),
              ),
              SizedBox(width: 6.w),
              WText(
                text: isAvailable ? 'Available' : 'Unavailable',
                fontSize: 12.sp,
                fontWeight: FontWeight.w500,
                color: isAvailable
                    ? R.color.mintGreen
                    : const Color(0xFFEF4444),
              ),
              if (!isAvailable &&
                  station.availableIn != null &&
                  station.availableIn!.isNotEmpty) ...[
                SizedBox(width: 8.w),
                WText(
                  text: 'Available in ${station.availableIn} mins',
                  fontSize: 12.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.mediumGray,
                ),
              ],
            ],
          ),
          SizedBox(height: 14.h),

          // View Details button
          WButton(
            label: 'View Details',
            onPressed: () async {
              final stationId = station.sId;
              if (stationId == null || stationId.isEmpty) return;

              final details =
                  await _mapController.getStationDetails(stationId);
              if (details != null) {
                Get.to(() => ViewDetailsScreen(stationData: details));
              }
            },
            height: 44.h,
          ),
        ],
      ),
    );
  }
}
