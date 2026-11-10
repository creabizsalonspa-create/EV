import 'dart:ui';
import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class AddVehicleConfirmScreen extends StatefulWidget {
  final String modelId;

  const AddVehicleConfirmScreen({
    super.key,
    required this.modelId,
  });

  @override
  State<AddVehicleConfirmScreen> createState() => _AddVehicleConfirmScreenState();
}

class _AddVehicleConfirmScreenState extends State<AddVehicleConfirmScreen> {
  late final ProfileController _controller;

  // Default values — user can change these before adding
  String _connectorType = 'CCS';
  int _batteryCapacityKwh = 75;

  final List<String> _connectorTypes = ['CCS', 'CHAdeMO', 'Type 2', 'Type 1', 'Tesla'];
  final List<int> _batteryOptions = [40, 50, 60, 75, 80, 100, 120];

  @override
  void initState() {
    super.initState();
    _controller = Get.find<ProfileController>();
    _controller.getVehicleDetail(widget.modelId);
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
                      WText(text: 'My Vehicle', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
                    ],
                  ),
                ),
              ),
            ],
          ),

          Expanded(
            child: Obx(() {
              if (_controller.detailLoading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              final detail = _controller.vehicleDetail.value;
              if (detail == null) {
                return Center(
                  child: WText(text: 'Failed to load vehicle details.', color: R.color.neutralGray, fontSize: 14.sp, fontWeight: FontWeight.w500),
                );
              }

              final brandName = detail['brandName'] ?? '';
              final modelName = detail['modelName'] ?? '';
              final imageUrl = detail['image'] as String? ?? '';

              return Padding(
                padding: EdgeInsets.symmetric(horizontal: 16.w),
                child: Column(
                  children: [
                    SizedBox(height: 24.h),

                    // ── Car image card ──
                    Container(
                      width: double.infinity,
                      height: 200.h,
                      decoration: BoxDecoration(
                        color: R.color.veryLightGray,
                        borderRadius: BorderRadius.circular(16.r),
                        border: Border.all(color: R.color.lightGray, width: 1),
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(16.r),
                        child: imageUrl.isNotEmpty
                            ? Image.network(
                                imageUrl,
                                fit: BoxFit.contain,
                                errorBuilder: (_, __, ___) => Image.asset(Assets.My_Vehicle, fit: BoxFit.contain),
                              )
                            : Image.asset(Assets.My_Vehicle, fit: BoxFit.contain),
                      ),
                    ),

                    SizedBox(height: 24.h),

                    // ── Brand name ──
                    WText(
                      text: brandName,
                      fontSize: 18.sp,
                      fontWeight: FontWeight.w700,
                      color: R.color.darkNavy,
                      textAlign: TextAlign.center,
                    ),

                    SizedBox(height: 6.h),

                    // ── Model name ──
                    WText(
                      text: modelName,
                      fontSize: 14.sp,
                      fontWeight: FontWeight.w400,
                      color: R.color.neutralGray,
                      textAlign: TextAlign.center,
                    ),

                    SizedBox(height: 24.h),

                    // ── Connector Type dropdown ──
                    _buildDropdownRow(
                      label: 'Connector Type',
                      value: _connectorType,
                      items: _connectorTypes,
                      onChanged: (v) => setState(() => _connectorType = v!),
                    ),

                    SizedBox(height: 16.h),

                    // ── Battery Capacity dropdown ──
                    _buildDropdownRow(
                      label: 'Battery (kWh)',
                      value: _batteryCapacityKwh,
                      items: _batteryOptions,
                      onChanged: (v) => setState(() => _batteryCapacityKwh = v!),
                    ),

                    const Spacer(),

                    // ── Add This Vehicle button ──
                    Obx(() => WButton(
                      label: _controller.addingVehicle.value ? 'Adding...' : 'Add This Vehicle',
                      height: 52.h,
                      radius: 12,
                      buttonColor: R.color.mintGreen,
                      textColor: R.color.white,
                      fontSize: 16.sp,
                      fontWeight: FontWeight.w600,
                      decorationType: DecorationType.solid,
                      onPressed: _controller.addingVehicle.value ? null : _onAdd,
                    )),

                    SizedBox(height: 32.h),
                  ],
                ),
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _buildDropdownRow<T>({
    required String label,
    required T value,
    required List<T> items,
    required ValueChanged<T?> onChanged,
  }) {
    return Row(
      children: [
        WText(text: label, fontSize: 13.sp, fontWeight: FontWeight.w600, color: R.color.darkNavy),
        const Spacer(),
        Container(
          padding: EdgeInsets.symmetric(horizontal: 12.w),
          decoration: BoxDecoration(
            border: Border.all(color: R.color.lightGray),
            borderRadius: BorderRadius.circular(8.r),
          ),
          child: DropdownButton<T>(
            value: value,
            underline: const SizedBox.shrink(),
            style: TextStyle(fontSize: 13.sp, color: R.color.darkNavy, fontFamily: 'Nunito'),
            items: items.map((e) => DropdownMenuItem<T>(value: e, child: Text('$e'))).toList(),
            onChanged: onChanged,
          ),
        ),
      ],
    );
  }

  Future<void> _onAdd() async {
    final success = await _controller.addVehicle(
      modelId: widget.modelId,
      connectorType: _connectorType,
      batteryCapacityKwh: _batteryCapacityKwh,
    );
    if (success) {
      Get.back(result: true);
      Get.snackbar('Success', 'Vehicle added successfully.',
          snackPosition: SnackPosition.TOP);
    }
  }
}
