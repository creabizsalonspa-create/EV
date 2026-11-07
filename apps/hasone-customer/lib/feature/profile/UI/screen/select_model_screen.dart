import 'dart:ui';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:evcharg/feature/profile/UI/screen/add_vehicle_confirm_screen.dart';
import 'package:evcharg/feature/profile/model/select_model.dart' as select_model;
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class SelectModelScreen extends StatefulWidget {
  final String brandId;
  final String brandName;

  const SelectModelScreen({
    super.key,
    required this.brandId,
    required this.brandName,
  });

  @override
  State<SelectModelScreen> createState() => _SelectModelScreenState();
}

class _SelectModelScreenState extends State<SelectModelScreen> {
  late final ProfileController _controller;
  final TextEditingController _searchController = TextEditingController();
  String _query = '';

  @override
  void initState() {
    super.initState();
    _controller = Get.find<ProfileController>();
    _controller.getModels(widget.brandId);
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
                      WText(text: 'Select Model', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
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

          // ── Model list ──
          Expanded(
            child: Obx(() {
              if (_controller.modelsLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              final filtered = _controller.models
                  .where((m) => (m.name ?? '').toLowerCase().contains(_query))
                  .toList();
              if (filtered.isEmpty) {
                return Center(
                  child: WText(text: 'No models found.', color: R.color.neutralGray, fontSize: 14.sp, fontWeight: FontWeight.w500),
                );
              }
              return ListView.separated(
                padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 12.h),
                itemCount: filtered.length,
                separatorBuilder: (_, __) => Divider(color: R.color.lightGray, height: 1),
                itemBuilder: (context, index) {
                  final model = filtered[index];
                  return ListTile(
                    contentPadding: EdgeInsets.symmetric(horizontal: 4.w, vertical: 4.h),
                    leading: (model.image != null && model.image!.isNotEmpty)
                        ? ClipRRect(
                            borderRadius: BorderRadius.circular(6.r),
                            child: Image.network(
                              model.image!,
                              width: 36.w,
                              height: 36.w,
                              fit: BoxFit.contain,
                              errorBuilder: (_, __, ___) => Icon(Icons.directions_car, size: 28.sp, color: R.color.neutralGray),
                            ),
                          )
                        : Icon(Icons.directions_car, size: 28.sp, color: R.color.neutralGray),
                    title: WText(text: model.name ?? '', fontSize: 14.sp, fontWeight: FontWeight.w500, color: R.color.darkNavy),
                    trailing: Icon(Icons.chevron_right, color: R.color.neutralGray, size: 20.sp),
                    onTap: () async {
                      final result = await Get.to(() => AddVehicleConfirmScreen(
                            modelId: model.sId ?? '',
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
