import 'dart:ui';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:evcharg/feature/profile/UI/screen/select_model_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class SelectBrandScreen extends StatefulWidget {
  const SelectBrandScreen({super.key});

  @override
  State<SelectBrandScreen> createState() => _SelectBrandScreenState();
}

class _SelectBrandScreenState extends State<SelectBrandScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final ProfileController _controller;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<ProfileController>()
        ? Get.find<ProfileController>()
        : Get.put(ProfileController());
    _controller.getBrands();
    _searchController.addListener(() {
      setState(() => _query = _searchController.text.toLowerCase());
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.white,
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
                      SizedBox(width: 12.w),
                      WText(text: 'Select Brand', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
                    ],
                  ),
                ),
              ),
            ],
          ),

          // ── Search bar ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 12.h),
            child: Container(
              height: 44.h,
              decoration: BoxDecoration(
                color: R.color.veryLightGray,
                borderRadius: BorderRadius.circular(8.r),
              ),
              child: TextField(
                controller: _searchController,
                style: TextStyle(fontSize: 14.sp, color: R.color.darkNavy, fontFamily: 'Nunito'),
                decoration: InputDecoration(
                  hintText: 'Search',
                  hintStyle: TextStyle(fontSize: 14.sp, color: R.color.neutralGray, fontFamily: 'Nunito'),
                  prefixIcon: Icon(Icons.search, color: R.color.neutralGray, size: 20.sp),
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  contentPadding: EdgeInsets.symmetric(vertical: 12.h),
                ),
              ),
            ),
          ),

          // ── Brand list ──
          Expanded(
            child: Obx(() {
              if (_controller.brandsLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              final filtered = _controller.brands
                  .where((b) => (b.name ?? '').toLowerCase().contains(_query))
                  .toList();
              if (filtered.isEmpty) {
                return Center(
                  child: WText(text: 'No brands found.', color: R.color.neutralGray, fontSize: 14.sp, fontWeight: FontWeight.w500),
                );
              }
              return ListView.separated(
                padding: EdgeInsets.symmetric(horizontal: 16.w).copyWith(bottom: 24.h),
                itemCount: filtered.length,
                separatorBuilder: (_, __) => Divider(color: R.color.lightGray, height: 1),
                itemBuilder: (context, index) {
                  final brand = filtered[index];
                  return ListTile(
                    contentPadding: EdgeInsets.symmetric(horizontal: 4.w, vertical: 4.h),
                    leading: (brand.image != null && brand.image!.isNotEmpty)
                        ? ClipRRect(
                            borderRadius: BorderRadius.circular(6.r),
                            child: Image.network(
                              brand.image!,
                              width: 36.w,
                              height: 36.w,
                              fit: BoxFit.contain,
                              errorBuilder: (_, __, ___) => Icon(Icons.directions_car, size: 28.sp, color: R.color.neutralGray),
                            ),
                          )
                        : Icon(Icons.directions_car, size: 28.sp, color: R.color.neutralGray),
                    title: WText(text: brand.name ?? '', fontSize: 14.sp, fontWeight: FontWeight.w500, color: R.color.darkNavy),
                    trailing: Icon(Icons.chevron_right, color: R.color.neutralGray, size: 20.sp),
                    onTap: () async {
                      final result = await Get.to(() => SelectModelScreen(
                            brandId: brand.sId ?? '',
                            brandName: brand.name ?? '',
                          ));
                      if (result == true) {
                        Get.back(result: true);
                      }
                    },
                  );
                },
              );
            }),
          ),
        ],
      ),
    );
  }
}
