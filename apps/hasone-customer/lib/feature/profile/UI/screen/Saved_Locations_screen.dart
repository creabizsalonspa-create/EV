import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:evcharg/feature/profile/model/save_location_model.dart' as save_model;
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class SavedLocationsScreen extends StatefulWidget {
  const SavedLocationsScreen({super.key});

  @override
  State<SavedLocationsScreen> createState() => _SavedLocationsScreenState();
}

class _SavedLocationsScreenState extends State<SavedLocationsScreen> {
  late final ProfileController _controller;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<ProfileController>()
        ? Get.find<ProfileController>()
        : Get.put(ProfileController());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _controller.getSavedLocations();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── Top green header with blur ──
          Stack(
            children: [
              Container(width: double.infinity, height: 150.h, color: R.color.mintGreenAccent),
              Positioned.fill(
                child: BackdropFilter(
                  filter: ImageFilter.blur(sigmaX: 150, sigmaY: 150),
                  child: Container(color: Colors.transparent),
                ),
              ),
              Container(
                width: double.infinity,
                height: 150.h,
                padding: EdgeInsets.symmetric(horizontal: 16.w),
                child: SafeArea(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      GestureDetector(
                        onTap: () => Get.back(),
                        child: Icon(Icons.arrow_back_ios_new, color: R.color.darkNavy, size: 22.sp),
                      ),
                      Expanded(
                        child: Center(
                          child: WText(text: 'Saved Locations', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
                        ),
                      ),
                      SizedBox(width: 22.w),
                    ],
                  ),
                ),
              ),
            ],
          ),

          Expanded(
            child: Obx(() {
              if (_controller.savedLocationsLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              if (_controller.savedLocations.isEmpty) {
                return Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.bookmark_outline, size: 48.sp, color: R.color.neutralGray),
                      SizedBox(height: 12.h),
                      WText(
                        text: 'No saved locations yet.',
                        color: R.color.neutralGray,
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w500,
                      ),
                    ],
                  ),
                );
              }
              return RefreshIndicator(
                onRefresh: _controller.getSavedLocations,
                child: ListView.separated(
                  padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
                  itemCount: _controller.savedLocations.length,
                  separatorBuilder: (_, __) => SizedBox(height: 12.h),
                  itemBuilder: (context, index) {
                    final loc = _controller.savedLocations[index];
                    return KeyedSubtree(
                      key: ValueKey(loc.sId ?? index),
                      child: _buildLocationCard(loc),
                    );
                  },
                ),
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _buildLocationCard(save_model.Data loc) {
    final bool isAvailable = (loc.status ?? '').toLowerCase() == 'available';

    return Container(
      width: 358.w,
      padding: EdgeInsets.all(16.w),
      decoration: BoxDecoration(
        color: R.color.white,
        borderRadius: BorderRadius.circular(12.r),
        border: Border.all(color: R.color.lightGray, width: 1),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          // ── Station image with bookmark overlay ──
          Stack(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8.r),
                child: (loc.image != null && loc.image!.isNotEmpty)
                    ? Image.network(
                        loc.image!,
                        width: 82.w,
                        height: 82.h,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => Image.asset(
                          Assets.saveLocation,
                          width: 82.w,
                          height: 82.h,
                          fit: BoxFit.cover,
                        ),
                      )
                    : Image.asset(
                        Assets.saveLocation,
                        width: 82.w,
                        height: 82.h,
                        fit: BoxFit.cover,
                      ),
              ),
              // ── Bookmark icon top-right ──
              Positioned(
                top: 4.h,
                right: 4.w,
                child: GestureDetector(
                  onTap: () => _controller.toggleSavedLocationApi(
                    stationId: loc.stationId ?? loc.sId ?? '',
                    stationName: loc.stationName ?? '',
                    address: loc.address ?? '',
                    image: loc.image,
                    status: loc.status,
                    latitude: loc.latitude,
                    longitude: loc.longitude,
                  ),
                  child: Container(
                    width: 22.w,
                    height: 22.w,
                    decoration: BoxDecoration(
                      color: R.color.white.withValues(alpha: 0.85),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(Icons.bookmark, size: 13.sp, color: R.color.mintGreen),
                  ),
                ),
              ),
            ],
          ),
          SizedBox(width: 12.w),

          // ── Name + Address + Status ──
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                WText(
                  text: loc.stationName ?? '',
                  color: R.color.darkNavy,
                  fontSize: 14.sp,
                  fontWeight: FontWeight.w700,
                ),
                SizedBox(height: 4.h),
                WText(
                  text: loc.address ?? '',
                  color: R.color.neutralGray,
                  fontSize: 12.sp,
                  fontWeight: FontWeight.w400,
                ),
                SizedBox(height: 8.h),
                // ── Available badge ──
                Row(
                  children: [
                    Container(
                      width: 8.w,
                      height: 8.w,
                      decoration: BoxDecoration(
                        color: isAvailable ? R.color.mintGreen : const Color(0xFFEF4444),
                        shape: BoxShape.circle,
                      ),
                    ),
                    SizedBox(width: 4.w),
                    WText(
                      text: loc.status ?? 'Available',
                      color: isAvailable ? R.color.mintGreen : const Color(0xFFEF4444),
                      fontSize: 11.sp,
                      fontWeight: FontWeight.w500,
                    ),
                  ],
                ),
              ],
            ),
          ),

          // ── Navigate icon ──
          Container(
            width: 32.w,
            height: 32.w,
            decoration: BoxDecoration(
              color: R.color.mintGreen,
              shape: BoxShape.circle,
            ),
            child: Icon(Icons.navigation_rounded, size: 16.sp, color: R.color.white),
          ),
        ],
      ),
    );
  }
}
