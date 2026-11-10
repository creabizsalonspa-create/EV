import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:evcharg/feature/profile/UI/screen/select_brand_screen.dart';
import 'package:evcharg/feature/profile/model/my_vehicle_model.dart' as vehicle_model;
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class MyVehicleScreen extends StatefulWidget {
  const MyVehicleScreen({super.key});

  @override
  State<MyVehicleScreen> createState() => _MyVehicleScreenState();
}

class _MyVehicleScreenState extends State<MyVehicleScreen> {
  late final ProfileController _controller;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<ProfileController>()
        ? Get.find<ProfileController>()
        : Get.put(ProfileController());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _controller.getVehicles();
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
                          child: WText(text: 'My Vehicle', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
                        ),
                      ),
                      // ── + add button ──
                      GestureDetector(
                        onTap: () async {
                          final result = await Get.to(() => const SelectBrandScreen());
                          if (result == true) {
                            _controller.getVehicles();
                          }
                        },
                        child: Icon(Icons.add, color: R.color.darkNavy, size: 24.sp),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),

          // ── Vehicle list ──
          Expanded(
            child: Obx(() {
              if (_controller.vehiclesLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              if (_controller.vehicles.isEmpty) {
                return Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.directions_car_outlined, size: 48.sp, color: R.color.neutralGray),
                      SizedBox(height: 12.h),
                      WText(
                        text: 'No vehicles added yet.',
                        color: R.color.neutralGray,
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w500,
                      ),
                    ],
                  ),
                );
              }
              return RefreshIndicator(
                onRefresh: _controller.getVehicles,
                child: ListView.separated(
                  padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
                  itemCount: _controller.vehicles.length,
                  separatorBuilder: (_, __) => SizedBox(height: 12.h),
                  itemBuilder: (context, index) {
                    final v = _controller.vehicles[index];
                    return _buildCard(v);
                  },
                ),
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _buildCard(vehicle_model.Data v) {
    final bool isActive = v.isActive ?? false;
    final String id = v.sId ?? '';

    return Obx(() {
      final isToggling = _controller.togglingId.value == id;
      final isDeleting = _controller.deletingId.value == id;

      return Container(
        width: 358.w,
        padding: EdgeInsets.only(top: 12.h, right: 12.w, bottom: 12.h, left: 4.w),
        decoration: BoxDecoration(
          color: R.color.white,
          borderRadius: BorderRadius.circular(12.r),
          border: Border.all(
            color: isActive ? R.color.mintGreen.withValues(alpha: 0.4) : R.color.lightGray,
            width: 1,
          ),
        ),
        child: Row(
          children: [
            // ── Vehicle image ──
            ClipRRect(
              borderRadius: BorderRadius.circular(8.r),
              child: (v.image != null && v.image!.isNotEmpty)
                  ? Image.network(
                      v.image!,
                      width: 80.w,
                      height: 52.h,
                      fit: BoxFit.contain,
                      errorBuilder: (_, __, ___) => Image.asset(
                        Assets.My_Vehicle,
                        width: 80.w,
                        height: 52.h,
                        fit: BoxFit.contain,
                      ),
                    )
                  : Image.asset(
                      Assets.My_Vehicle,
                      width: 80.w,
                      height: 52.h,
                      fit: BoxFit.contain,
                    ),
            ),
            SizedBox(width: 10.w),

            // ── Name + Model + Connector ──
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  WText(
                    text: '${v.name ?? ''} ${v.model ?? ''}',
                    color: R.color.darkNavy,
                    fontSize: 13.sp,
                    fontWeight: FontWeight.w700,
                  ),
                  SizedBox(height: 2.h),
                  Row(
                    children: [
                      Icon(Icons.electric_bolt, size: 11.sp, color: R.color.mintGreen),
                      SizedBox(width: 2.w),
                      WText(
                        text: '${v.connectorType ?? ''} · ${v.batteryCapacityKwh ?? 0} kWh',
                        color: R.color.neutralGray,
                        fontSize: 10.sp,
                        fontWeight: FontWeight.w400,
                      ),
                    ],
                  ),
                ],
              ),
            ),

            // ── Active/Disable toggle badge ──
            GestureDetector(
              onTap: isToggling || isDeleting ? null : () => _controller.toggleVehicle(id),
              child: isToggling
                  ? SizedBox(
                      width: 52.w,
                      height: 26.h,
                      child: const Center(
                        child: SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    )
                  : Container(
                      padding: EdgeInsets.symmetric(horizontal: 10.w, vertical: 4.h),
                      decoration: BoxDecoration(
                        color: isActive ? R.color.mintGreen : R.color.veryLightGray,
                        borderRadius: BorderRadius.circular(6.r),
                      ),
                      child: WText(
                        text: isActive ? 'Active' : 'Disable',
                        color: isActive ? R.color.white : R.color.neutralGray,
                        fontSize: 11.sp,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
            ),
            SizedBox(width: 8.w),

            // ── Delete icon ──
            GestureDetector(
              onTap: isDeleting || isToggling ? null : () => _showDeleteDialog(id),
              child: isDeleting
                  ? SizedBox(
                      width: 28.w,
                      height: 28.w,
                      child: const Center(
                        child: SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    )
                  : Container(
                      width: 28.w,
                      height: 28.w,
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFE5E5),
                        borderRadius: BorderRadius.circular(6.r),
                      ),
                      child: Icon(Icons.delete_outline, size: 16.sp, color: Colors.red),
                    ),
            ),
            SizedBox(width: 4.w),
          ],
        ),
      );
    });
  }

  void _showDeleteDialog(String vehicleId) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16.r)),
        title: WText(
          text: 'Delete Vehicle',
          color: R.color.darkNavy,
          fontSize: 16.sp,
          fontWeight: FontWeight.w700,
        ),
        content: WText(
          text: 'Are you sure you want to delete this vehicle?',
          color: R.color.neutralGray,
          fontSize: 13.sp,
          fontWeight: FontWeight.w400,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: WText(text: 'Cancel', color: R.color.darkNavy, fontSize: 14.sp, fontWeight: FontWeight.w600),
          ),
          TextButton(
            onPressed: () {
              Navigator.pop(context);
              _controller.deleteVehicle(vehicleId);
            },
            child: WText(text: 'Delete', color: Colors.red, fontSize: 14.sp, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
