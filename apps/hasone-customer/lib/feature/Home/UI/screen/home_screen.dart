import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Home/UI/controller/home_controller.dart';
import 'package:evcharg/feature/map/model/nearest_station_model.dart';
import 'package:evcharg/feature/viewdetails/UI/screen/view_details_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../../app/resource.dart';
import '../../../../core/utils/distance_helper.dart';
import '../../../../core/utils/map_marker_helper.dart';
import '../../../main_screen.dart';
import '../../../map/ui/controller/map_controller.dart';
import '../../../profile/UI/controller/profile_controller.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late final ProfileController _profileController;
  late final HomeController _homeController;

  @override
  void initState() {
    super.initState();
    _profileController = Get.find<ProfileController>();
    _homeController = Get.find<HomeController>();
    MapMarkerHelper.loadIcons().then((_) {
      if (mounted) setState(() {});
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: SafeArea(
        child: Stack(
          children: [
            // ── Top green background ───────────────────────
            Positioned(
              top: 0,
              left: -20,
              child: ImageFiltered(
                imageFilter: ImageFilter.blur(sigmaX: 150, sigmaY: 150),
                child: Container(
                  width: 431.w,
                  height: 150.h,
                  color: R.color.mintGreenAccent,
                ),
              ),
            ),

            // ── Scrollable content ─────────────────────────
            SingleChildScrollView(
              padding: EdgeInsets.symmetric(horizontal: 16.w),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(height: 20.h),
                  _buildHeader(),
                  SizedBox(height: 20.h),
                  _buildVehicleCard(),
                  SizedBox(height: 16.h),
                  _buildMapSection(),
                  SizedBox(height: 20.h),
                  WText(
                    text: 'Nearest Station',
                    fontWeight: FontWeight.w500,
                    color: R.color.darkNavy,
                  ),
                  SizedBox(height: 12.h),
                  _buildStationList(),
                  SizedBox(height: 20.h),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ─────────────────────────────────────────────────────────
  Widget _buildHeader() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Obx(() {
          final name = _profileController.profile.value?.fullName ?? 'User';
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              WText(
                text: 'Hello, $name',
                fontSize: 20.sp,
                color: R.color.darkNavy,
              ),
              WText(
                text: 'Ready to charge your EV?',
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
                color: R.color.mediumGray,
              ),
            ],
          );
        }),

      ],
    );
  }

  Widget _iconButton(IconData icon) {
    return Container(
      width: 40.w,
      height: 40.w,
      decoration: BoxDecoration(
        color: R.color.veryLightGray,
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.07),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Icon(icon, size: 20.sp, color: R.color.darkNavy),
    );
  }

  // ─────────────────────────────────────────────────────────
  Widget _buildVehicleCard() {
    return Container(
      width: 358.w,
      height: 174.h,
      child: Image.asset(
        Assets.car,
        fit: BoxFit.contain,
      ),
    );
  }

  // ─────────────────────────────────────────────────────────
  Widget _buildMapSection() {
    return Container(
      width: 358.w,
      height: 240.h,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12.r),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.08),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12.r),
        child: Stack(
          children: [
            // Real Google Map — centers on current GPS location
            SizedBox(
              height: 200.h,
              child: Obx(() {
                // Show shimmer placeholder while initial loading
                if (_homeController.initialLoading.value) {
                  return Container(
                    color: const Color(0xFFE8E8E8),
                    child: Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          CircularProgressIndicator(
                            color: R.color.mintGreen,
                            strokeWidth: 3,
                          ),
                          SizedBox(height: 12.h),
                          WText(
                            text: 'Getting your location...',
                            fontSize: 13.sp,
                            fontWeight: FontWeight.w400,
                            color: R.color.mediumGray,
                          ),
                        ],
                      ),
                    ),
                  );
                }

                final stations = _homeController.stations;
                final pos = _homeController.currentPosition.value;
                final markers = <Marker>{};

                for (final station in stations) {
                  if (station.latitude != null && station.longitude != null) {
                    markers.add(
                      Marker(
                        markerId: MarkerId(station.sId ?? station.name ?? ''),
                        position: LatLng(station.latitude!, station.longitude!),
                        infoWindow: InfoWindow(
                          title: station.name ?? '',
                          snippet: station.address ?? '',
                        ),
                        icon: MapMarkerHelper.iconForStatus(station.status),
                      ),
                    );
                  }
                }

                // Use current GPS position, fallback to Dhaka
                final initialPos = pos != null
                    ? LatLng(pos.latitude, pos.longitude)
                    : const LatLng(23.8103, 90.4125);

                return GoogleMap(
                  initialCameraPosition: CameraPosition(
                    target: initialPos,
                    zoom: 12,
                  ),
                  markers: markers,
                  zoomControlsEnabled: false,
                  myLocationEnabled: true,
                  myLocationButtonEnabled: false,
                  liteModeEnabled: true,
                );
              }),
            ),

            // View Full Map bar
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: GestureDetector(
                onTap: () => MainScreen.switchToMapTab(context),
                child: Container(
                  height: 40.h,
                  decoration: BoxDecoration(
                    color: R.color.mintGreen,
                    borderRadius: const BorderRadius.only(
                      bottomLeft: Radius.circular(12),
                      bottomRight: Radius.circular(12),
                    ),
                  ),
                  padding: EdgeInsets.symmetric(
                      horizontal: 16.w, vertical: 10.h),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      WText(
                        text: 'View Full Map',
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w600,
                        color: R.color.white,
                      ),
                      SizedBox(width: 4.w),
                      Icon(Icons.chevron_right,
                          color: R.color.white, size: 18.sp),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ─────────────────────────────────────────────────────────
  Widget _buildStationList() {
    return Obx(() {
      if (_homeController.initialLoading.value || _homeController.loading.value) {
        return Center(
          child: Padding(
            padding: EdgeInsets.symmetric(vertical: 24.h),
            child: Column(
              children: [
                CircularProgressIndicator(color: R.color.mintGreen),
                SizedBox(height: 12.h),
                WText(
                  text: 'Finding nearest stations...',
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.mediumGray,
                ),
              ],
            ),
          ),
        );
      }
      if (_homeController.stations.isEmpty) {
        return Center(
          child: Padding(
            padding: EdgeInsets.symmetric(vertical: 24.h),
            child: Column(
              children: [
                Icon(Icons.ev_station_outlined,
                    size: 48.sp, color: R.color.mediumGray),
                SizedBox(height: 8.h),
                WText(
                  text: 'No stations found nearby',
                  fontSize: 14.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.mediumGray,
                ),
              ],
            ),
          ),
        );
      }
      return Column(
        children: _homeController.stations
            .map((station) => Padding(
                  padding: EdgeInsets.only(bottom: 16.h),
                  child: _buildStationCard(station),
                ))
            .toList(),
      );
    });
  }

  // ─────────────────────────────────────────────────────────
  Widget _buildStationCard(Data? station) {
    final isAvailable = (station?.status ?? 'Available') == 'Available';
    final name = station?.name ?? 'Charge Point Station';
    final address = station?.address ?? 'Market Street, San Francisco, CA';

    // Calculate distance & duration from user's current location
    final userPos = _homeController.currentPosition.value;
    final distance = DistanceHelper.distanceText(
      userPosition: userPos,
      stationLat: station?.latitude,
      stationLng: station?.longitude,
    );
    final time = DistanceHelper.durationText(
      userPosition: userPos,
      stationLat: station?.latitude,
      stationLng: station?.longitude,
    );
    final rating = station?.rating?.toStringAsFixed(1) ?? '4.8';
    final reviews = station?.reviewCount?.toString() ?? '128';
    final imageUrl = (station?.images?.isNotEmpty == true) ? station!.images!.first : null;

    return Container(
      width: 358.w,
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
      padding: EdgeInsets.all(16.w),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Image ──────────────────────────────────────
          ClipRRect(
            borderRadius: BorderRadius.circular(8.r),
            child: imageUrl != null
                ? Image.network(
                    imageUrl,
                    width: 326.w,
                    height: 162.h,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => _fallbackImage(),
                  )
                : _fallbackImage(),
          ),
          SizedBox(height: 14.h),

          // ── Station Name + Available badge ─────────────
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
                  SizedBox(width: 4.w),
                  WText(
                    text: isAvailable ? 'Available' : 'Unavailable',
                    fontSize: 12.sp,
                    fontWeight: FontWeight.w500,
                    color: isAvailable
                        ? R.color.mintGreen
                        : const Color(0xFFEF4444),
                  ),
                ],
              ),
            ],
          ),
          SizedBox(height: 6.h),

          // ── Address ────────────────────────────────────
          WText(
            text: address,
            fontSize: 12.sp,
            fontWeight: FontWeight.w400,
            color: R.color.mediumGray,
          ),
          SizedBox(height: 8.h),

          // ── Distance + Time row ────────────────────────
          Row(
            children: [
              Icon(Icons.location_on_outlined,
                  size: 14.sp, color: R.color.mintGreen),
              SizedBox(width: 3.w),
              WText(
                text: distance,
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
                color: R.color.mediumGray,
              ),
              SizedBox(width: 12.w),
              Icon(Icons.access_time_outlined,
                  size: 14.sp, color: R.color.mintGreen),
              SizedBox(width: 3.w),
              WText(
                text: time,
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
                color: R.color.mediumGray,
              ),
            ],
          ),
          SizedBox(height: 8.h),

          // ── Rating row ─────────────────────────────────
          Row(
            children: [
              ...List.generate(5, (i) {
                final ratingVal = station?.rating ?? 0.0;
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
                text: '($reviews reviews)',
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
                color: R.color.mediumGray,
              ),
            ],
          ),
          SizedBox(height: 16.h),

          // ── Buttons row ────────────────────────────────
          Row(
            children: [
              Expanded(
                child: WButton(
                  label: 'View Details',
                  onPressed: () async {
                    final stationId = station?.sId;
                    if (stationId == null || stationId.isEmpty) return;
                    final mapCtrl = Get.find<MapController>();
                    final details =
                        await mapCtrl.getStationDetails(stationId);
                    if (details != null) {
                      Get.to(() => ViewDetailsScreen(stationData: details));
                    }
                  },
                  height: 46.h,
                  decorationType: DecorationType.stroke,
                  textColor: R.color.mintGreen,
                  buttonColor: R.color.mintGreen,
                ),
              ),
              SizedBox(width: 12.w),
              Expanded(
                child: WButton(
                  label: 'Book',
                  onPressed: () async {
                    final stationId = station?.sId;
                    if (stationId == null || stationId.isEmpty) return;
                    final mapCtrl = Get.find<MapController>();
                    final details =
                        await mapCtrl.getStationDetails(stationId);
                    if (details != null) {
                      Get.to(() => ViewDetailsScreen(stationData: details));
                    }
                  },
                  height: 46.h,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _fallbackImage() {
    return Image.asset(
      Assets.charging,
      width: 326.w,
      height: 162.h,
      fit: BoxFit.cover,
    );
  }
}


