import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/app_settings/app_settings_service.dart';
import 'package:evcharg/core/service/utils/currency_helper.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:evcharg/feature/profile/model/favourite_Station_model.dart' as fav_model;
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class FavouriteStationScreen extends StatefulWidget {
  const FavouriteStationScreen({super.key});

  @override
  State<FavouriteStationScreen> createState() => _FavouriteStationScreenState();
}

class _FavouriteStationScreenState extends State<FavouriteStationScreen> {
  late final ProfileController _controller;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<ProfileController>()
        ? Get.find<ProfileController>()
        : Get.put(ProfileController());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _controller.getFavouriteStations();
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
                        child: Icon(Icons.arrow_back_ios_new, color: R.color.darkNavy, size: 20.sp),
                      ),
                      Expanded(
                        child: Center(
                          child: WText(text: 'Favourite Station', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
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
              // Observe global currency so list rebuilds when admin changes currency
              final _ = Get.isRegistered<AppSettingsService>()
                  ? AppSettingsService.to.currencySymbol.value
                  : '';
              if (_controller.favouriteStationsLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              if (_controller.favouriteStations.isEmpty) {
                return Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.favorite_outline, size: 48.sp, color: R.color.neutralGray),
                      SizedBox(height: 12.h),
                      WText(
                        text: 'No favourite stations yet.',
                        color: R.color.neutralGray,
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w500,
                      ),
                    ],
                  ),
                );
              }
              return RefreshIndicator(
                onRefresh: _controller.getFavouriteStations,
                child: ListView.separated(
                  padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
                  itemCount: _controller.favouriteStations.length,
                  separatorBuilder: (_, __) => SizedBox(height: 12.h),
                  itemBuilder: (context, index) {
                    final s = _controller.favouriteStations[index];
                    return _buildStationCard(s);
                  },
                ),
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _buildStationCard(fav_model.Data s) {
    final bool isAvailable = (s.status ?? '').toLowerCase() == 'available';
    final String priceText = s.pricePerHour != null ? '${s.pricePerHour}${getCurrencySymbol()}/hr' : '';

    return Container(
      width: 358.w,
      padding: EdgeInsets.all(16.w),
      decoration: BoxDecoration(
        color: R.color.white,
        borderRadius: BorderRadius.circular(12.r),
        border: Border.all(color: R.color.lightGray, width: 1),
      ),
      child: Row(
        children: [
          // ── Station image with heart overlay ──
          Stack(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8.r),
                child: (s.image != null && s.image!.isNotEmpty)
                    ? Image.network(
                        s.image!,
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
              // ── Heart icon top-right ──
              Positioned(
                top: 4.h,
                right: 4.w,
                child: GestureDetector(
                  onTap: () => _controller.toggleFavouriteStationApi(
                    stationId: s.stationId ?? s.sId ?? '',
                    stationName: s.stationName ?? '',
                    address: s.address ?? '',
                    image: s.image,
                    pricePerHour: s.pricePerHour,
                    status: s.status,
                    latitude: s.latitude,
                    longitude: s.longitude,
                  ),
                  child: Container(
                    width: 22.w,
                    height: 22.w,
                    decoration: BoxDecoration(
                      color: R.color.white.withValues(alpha: 0.85),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(Icons.favorite_rounded, size: 13.sp, color: Colors.red),
                  ),
                ),
              ),
            ],
          ),
          SizedBox(width: 12.w),

          // ── Name + Address + Available ──
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                WText(
                  text: s.stationName ?? '',
                  color: R.color.darkNavy,
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w700,
                ),
                SizedBox(height: 4.h),
                WText(
                  text: s.address ?? '',
                  color: R.color.neutralGray,
                  fontSize: 11.sp,
                  fontWeight: FontWeight.w400,
                ),
                SizedBox(height: 8.h),
                Row(
                  children: [
                    Container(
                      width: 7.w,
                      height: 7.w,
                      decoration: BoxDecoration(
                        color: isAvailable ? R.color.mintGreen : const Color(0xFFEF4444),
                        shape: BoxShape.circle,
                      ),
                    ),
                    SizedBox(width: 4.w),
                    WText(
                      text: s.status ?? 'Available',
                      color: isAvailable ? R.color.mintGreen : const Color(0xFFEF4444),
                      fontSize: 11.sp,
                      fontWeight: FontWeight.w500,
                    ),
                  ],
                ),
              ],
            ),
          ),

          // ── Price + Navigate ──
          Column(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              if (priceText.isNotEmpty)
                WText(
                  text: priceText,
                  color: R.color.mintGreen,
                  fontSize: 12.sp,
                  fontWeight: FontWeight.w700,
                ),
              SizedBox(height: 24.h),
              Container(
                width: 32.w,
                height: 32.w,
                decoration: BoxDecoration(color: R.color.mintGreen, shape: BoxShape.circle),
                child: Icon(Icons.navigation_rounded, size: 16.sp, color: R.color.white),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
