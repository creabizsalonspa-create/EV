import 'dart:ui';
import '../../history/source_history_screen.dart';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/booking_details.dart';
import 'package:evcharg/feature/Booking/UI/screen/navigate_station_screen.dart';
import 'package:evcharg/feature/Booking/UI/screen/rebooking_screen.dart';
import 'package:evcharg/feature/Booking/model/All_booking_model.dart';
import 'package:evcharg/feature/Booking/model/bookingdetailsMOdel.dart' as detail_model;
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class BookingScreen extends StatefulWidget {
  const BookingScreen({super.key});

  @override
  State<BookingScreen> createState() => _BookingScreenState();
}

class _BookingScreenState extends State<BookingScreen> {
  late final AllBookingController _controller;
  bool _searchOpen = false;
  final TextEditingController _searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _controller.fetchBookings();
    });
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  void _openFilterSheet() {
    showModalBottomSheet(
      context: context,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20.r)),
      ),
      backgroundColor: Colors.white,
      builder: (_) {
        final filters = ['All', 'Upcoming', 'Completed', 'Cancelled'];
        return Obx(() => Padding(
              padding: EdgeInsets.symmetric(horizontal: 20.w, vertical: 20.h),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Center(
                    child: Container(
                      width: 40.w,
                      height: 4.h,
                      decoration: BoxDecoration(
                        color: const Color(0xFFE5E7EB),
                        borderRadius: BorderRadius.circular(4.r),
                      ),
                    ),
                  ),
                  SizedBox(height: 16.h),
                  WText(
                    text: 'Filter by Status',
                    color: R.color.darkNavy,
                    fontSize: 16.sp,
                    fontWeight: FontWeight.w700,
                  ),
                  SizedBox(height: 16.h),
                  ...filters.map((f) {
                    final isSelected = _controller.selectedFilter.value == f;
                    return GestureDetector(
                      onTap: () {
                        _controller.selectedFilter.value = f;
                        Navigator.pop(context);
                      },
                      child: Container(
                        margin: EdgeInsets.only(bottom: 10.h),
                        padding: EdgeInsets.symmetric(
                            horizontal: 16.w, vertical: 12.h),
                        decoration: BoxDecoration(
                          color: isSelected
                              ? R.color.mintGreen.withValues(alpha: 0.12)
                              : const Color(0xFFF9FAFB),
                          borderRadius: BorderRadius.circular(10.r),
                          border: Border.all(
                            color: isSelected
                                ? R.color.mintGreen
                                : const Color(0xFFE5E7EB),
                            width: 1.5,
                          ),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            WText(
                              text: f,
                              color: isSelected
                                  ? R.color.mintGreen
                                  : R.color.darkNavy,
                              fontSize: 14.sp,
                              fontWeight: isSelected
                                  ? FontWeight.w700
                                  : FontWeight.w500,
                            ),
                            if (isSelected)
                              Icon(Icons.check_circle,
                                  color: R.color.mintGreen, size: 18.sp),
                          ],
                        ),
                      ),
                    );
                  }),
                  SizedBox(height: 8.h),
                ],
              ),
            ));
      },
    );
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
              Container(
                  width: double.infinity,
                  height: 150.h,
                  color: const Color(0xFF7CFFBC)),
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
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          WText(
                              text: 'Booking',
                              color: R.color.darkNavy,
                              fontSize: 18.sp,
                              textAlign: TextAlign.center,
                              fontWeight: FontWeight.w700),
                          Row(
                            children: [
                              GestureDetector(
                                onTap: () {
                                  setState(() {
                                    _searchOpen = !_searchOpen;
                                    if (!_searchOpen) {
                                      _searchCtrl.clear();
                                      _controller.searchQuery.value = '';
                                    }
                                  });
                                },
                                child: Icon(Icons.search,
                                    color: R.color.darkNavy, size: 22.sp),
                              ),
                              SizedBox(width: 14.w),
                              Obx(() {
                                final isFiltered =
                                    _controller.selectedFilter.value != 'All';
                                return GestureDetector(
                                  onTap: _openFilterSheet,
                                  child: Stack(
                                    clipBehavior: Clip.none,
                                    children: [
                                      Icon(Icons.tune,
                                          color: R.color.darkNavy,
                                          size: 22.sp),
                                      if (isFiltered)
                                        Positioned(
                                          top: -4,
                                          right: -4,
                                          child: Container(
                                            width: 8.w,
                                            height: 8.w,
                                            decoration: BoxDecoration(
                                              color: R.color.mintGreen,
                                              shape: BoxShape.circle,
                                            ),
                                          ),
                                        ),
                                    ],
                                  ),
                                );
                              }),
                            ],
                          ),
                        ],
                      ),
                      if (_searchOpen) ...[
                        SizedBox(height: 8.h),
                        Container(
                          height: 38.h,
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.85),
                            borderRadius: BorderRadius.circular(10.r),
                          ),
                          child: TextField(
                            controller: _searchCtrl,
                            autofocus: true,
                            style: TextStyle(
                                fontSize: 13.sp, color: R.color.darkNavy),
                            decoration: InputDecoration(
                              hintText: 'Search by station or address...',
                              hintStyle: TextStyle(
                                  fontSize: 12.sp,
                                  color: R.color.neutralGray),
                              prefixIcon: Icon(Icons.search,
                                  size: 16.sp, color: R.color.neutralGray),
                              suffixIcon: GestureDetector(
                                onTap: () {
                                  _searchCtrl.clear();
                                  _controller.searchQuery.value = '';
                                },
                                child: Icon(Icons.close,
                                    size: 16.sp, color: R.color.neutralGray),
                              ),
                              border: InputBorder.none,
                              contentPadding:
                                  EdgeInsets.symmetric(vertical: 10.h),
                            ),
                            onChanged: (v) =>
                                _controller.searchQuery.value = v,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),

          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: TextButton.icon(
              icon: const Icon(Icons.history),
              label: const Text('Charging history / \u0633\u062c\u0644 \u0627\u0644\u0634\u062d\u0646'),
              onPressed: () => Get.to(() => const SourceHistoryScreen()),
            ),
          ),

          // ── Active filter chip ──
          Obx(() {
            final f = _controller.selectedFilter.value;
            if (f == 'All') return const SizedBox.shrink();
            return Container(
              width: double.infinity,
              padding:
                  EdgeInsets.symmetric(horizontal: 16.w, vertical: 8.h),
              color: R.color.offWhite,
              child: Row(
                children: [
                  WText(
                      text: 'Filter: ',
                      color: R.color.neutralGray,
                      fontSize: 12.sp,
                      fontWeight: FontWeight.w400),
                  Container(
                    padding: EdgeInsets.symmetric(
                        horizontal: 10.w, vertical: 4.h),
                    decoration: BoxDecoration(
                      color: R.color.mintGreen.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(20.r),
                      border: Border.all(color: R.color.mintGreen, width: 1),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        WText(
                            text: f,
                            color: R.color.mintGreen,
                            fontSize: 12.sp,
                            fontWeight: FontWeight.w600),
                        SizedBox(width: 6.w),
                        GestureDetector(
                          onTap: () =>
                              _controller.selectedFilter.value = 'All',
                          child: Icon(Icons.close,
                              size: 12.sp, color: R.color.mintGreen),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }),

          // ── Booking list ──
          Expanded(
            child: Obx(() {
              if (_controller.loading.value) {
                return const Center(child: CircularProgressIndicator());
              }
              final items = _controller.filteredBookings;
              if (items.isEmpty) {
                return Center(
                  child: WText(
                    text: _controller.bookings.isEmpty
                        ? 'No bookings found.'
                        : 'No bookings match your filter.',
                    color: R.color.neutralGray,
                    fontSize: 14.sp,
                    fontWeight: FontWeight.w500,
                  ),
                );
              }
              return RefreshIndicator(
                onRefresh: _controller.fetchBookings,
                child: SingleChildScrollView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: EdgeInsets.symmetric(
                      horizontal: 16.w, vertical: 16.h),
                  child: Column(
                    children:
                        items.map((item) => _buildCard(item)).toList(),
                  ),
                ),
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _buildCard(Data item) {
    final isUpcoming = item.status == 'Upcoming';
    return GestureDetector(
      onTap: () {
        if (item.status == 'Upcoming') _onCardTap(item);
      },
      child: Container(
      width: 358.w,
      margin: EdgeInsets.only(bottom: 16.h),
      padding: EdgeInsets.all(16.w),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16.r),
        border: Border.all(color: const Color(0xFFE5E7EB), width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Station name + status badge ──
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: WText(
                  text: item.stationName ?? '',
                  color: R.color.darkNavy,
                  fontSize: 15.sp,
                  fontWeight: FontWeight.w700,
                ),
              ),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Paid / Unpaid badge
                  Container(
                    padding: EdgeInsets.symmetric(horizontal: 8.w, vertical: 4.h),
                    decoration: BoxDecoration(
                      color: (item.isPaid == true)
                          ? const Color(0xFF2563EB).withValues(alpha: 0.12)
                          : const Color(0xFFE7000B).withValues(alpha: 0.10),
                      borderRadius: BorderRadius.circular(20.r),
                    ),
                    child: WText(
                      text: (item.isPaid == true) ? 'Paid' : 'Unpaid',
                      color: (item.isPaid == true)
                          ? const Color(0xFF2563EB)
                          : const Color(0xFFE7000B),
                      fontSize: 10.sp,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  SizedBox(width: 6.w),
                  // Status badge
                  Container(
                    padding: EdgeInsets.symmetric(horizontal: 10.w, vertical: 4.h),
                    decoration: BoxDecoration(
                      color: _statusColor(item.status).withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(20.r),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.circle,
                            size: 7.sp, color: _statusColor(item.status)),
                        SizedBox(width: 4.w),
                        WText(
                          text: item.status ?? '',
                          color: _statusColor(item.status),
                          fontSize: 11.sp,
                          fontWeight: FontWeight.w600,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ],
          ),
          SizedBox(height: 6.h),

          // ── Address ──
          Row(
            children: [
              Icon(Icons.location_on, color: R.color.mintGreen, size: 14.sp),
              SizedBox(width: 4.w),
              Expanded(
                child: WText(
                  text: item.address ?? '',
                  color: R.color.neutralGray,
                  fontSize: 12.sp,
                  fontWeight: FontWeight.w400,
                ),
              ),
            ],
          ),
          SizedBox(height: 12.h),

          // ── Date / Time / Charger pill ──
          Container(
            width: 326.w,
            padding:
                EdgeInsets.symmetric(horizontal: 12.w, vertical: 12.h),
            decoration: BoxDecoration(
              color: const Color(0xFFF9FAFB),
              borderRadius: BorderRadius.circular(14.r),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.calendar_today_outlined,
                        color: R.color.neutralGray, size: 14.sp),
                    SizedBox(width: 6.w),
                    WText(
                        text: item.date ?? item.bookingDate ?? '',
                        color: R.color.darkNavy,
                        fontSize: 12.sp,
                        fontWeight: FontWeight.w500),
                    SizedBox(width: 16.w),
                    Icon(Icons.access_time,
                        color: R.color.neutralGray, size: 14.sp),
                    SizedBox(width: 6.w),
                    WText(
                        text: item.time ?? item.chargingSessionTiming ?? '',
                        color: R.color.darkNavy,
                        fontSize: 12.sp,
                        fontWeight: FontWeight.w500),
                  ],
                ),
                SizedBox(height: 8.h),
                Row(
                  children: [
                    Icon(Icons.electric_bolt,
                        color: R.color.neutralGray, size: 14.sp),
                    SizedBox(width: 4.w),
                    WText(
                        text: item.chargerType ?? '',
                        color: R.color.neutralGray,
                        fontSize: 12.sp,
                        fontWeight: FontWeight.w400),
                  ],
                ),
                if ((item.slotStart ?? '').isNotEmpty ||
                    (item.slotEnd ?? '').isNotEmpty) ...[
                  SizedBox(height: 4.h),
                  Row(
                    children: [
                      Icon(Icons.schedule,
                          color: R.color.neutralGray, size: 14.sp),
                      SizedBox(width: 4.w),
                      WText(
                        text:
                            '${item.slotStart ?? ''} – ${item.slotEnd ?? ''}',
                        color: R.color.neutralGray,
                        fontSize: 12.sp,
                        fontWeight: FontWeight.w400,
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),

          // ── Reschedule/Cancel buttons only for Upcoming ──
          if (isUpcoming) ...[
            SizedBox(height: 14.h),
            Row(
              children: [
                WButton(
                  label: 'Reschedule',
                  width: 153.w,
                  height: 42.h,
                  radius: 8,
                  horizontalPadding: 16,
                  verticalPadding: 12,
                  decorationType: DecorationType.stroke,
                  buttonColor: const Color(0xFFE5E7EB),
                  textColor: R.color.darkNavy,
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w600,
                  iconWidget: Icon(Icons.edit_outlined,
                      size: 14.sp, color: R.color.darkNavy),
                  iconPosition: IconPosition.left,
                  onPressed: () =>
                      Get.to(() => RebookingScreen(booking: item)),
                ),
                SizedBox(width: 12.w),
                WButton(
                  label: 'Cancel',
                  width: 153.w,
                  height: 42.h,
                  radius: 8,
                  horizontalPadding: 16,
                  verticalPadding: 12,
                  decorationType: DecorationType.stroke,
                  buttonColor: const Color(0xFFE5E7EB),
                  textColor: const Color(0xFFE7000B),
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w600,
                  iconWidget: Icon(Icons.cancel_outlined,
                      size: 14.sp, color: const Color(0xFFE7000B)),
                  iconPosition: IconPosition.left,
                  onPressed: () => _showCancelDialog(item.sId ?? item.bookingId ?? ''),
                ),
              ],
            ),
          ],
        ],
      ),
      ), // GestureDetector
    );
  }

  /// Tap on card — unpaid → BookingDetails, paid → NavigateStationScreen
  void _onCardTap(Data item) {
    if (item.isPaid == true) {
      Get.to(() => NavigateStationScreen(bookingId: item.bookingId ?? item.sId ?? ''));
    } else {
      // Convert All_booking_model.Data → bookingdetailsMOdel.Data
      final detailData = detail_model.Data(
        bookingId: item.bookingId ?? item.sId,
        vehicleName: item.vehicleName,
        vehiclePlate: item.vehiclePlate,
        vehicleImage: item.vehicleImage,
        stationName: item.stationName,
        address: item.address,
        stationImage: item.stationImage,
        connectorType: item.connectorType,
        energyKwh: item.energyKwh,
        chargingSlot: item.chargingSlot,
        chargerType: item.chargerType,
        bookingDate: item.bookingDate ?? item.date,
        chargingDuration: item.chargingDuration,
        chargingSessionTiming: item.chargingSessionTiming ?? item.time,
        slotStart: item.slotStart,
        slotEnd: item.slotEnd,
        amountEstimation: item.amountEstimation,
        tax: item.tax,
        totalAmount: item.totalAmount,
        isPaid: item.isPaid,
        status: item.status,
        currencyCode: item.currencyCode,
        currencySymbol: item.currencySymbol,
        currencyIcon: item.currencyIcon,
      );
      Get.to(() => BookingDetails(bookingData: detailData));
    }
  }

  Color _statusColor(String? status) {
    switch (status) {
      case 'Upcoming':
        return R.color.mintGreen;
      case 'Completed':
        return const Color(0xFF2563EB);
      case 'Cancelled':
        return const Color(0xFFE7000B);
      default:
        return Colors.grey;
    }
  }

  void _showCancelDialog(String bookingId) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16.r)),
        title: WText(
            text: 'Cancel Booking',
            color: R.color.darkNavy,
            fontSize: 16.sp,
            fontWeight: FontWeight.w700),
        content: WText(
          text: 'Are you sure you want to cancel this booking?',
          color: R.color.neutralGray,
          fontSize: 13.sp,
          fontWeight: FontWeight.w400,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: WText(
                text: 'No',
                color: R.color.darkNavy,
                fontSize: 14.sp,
                fontWeight: FontWeight.w600),
          ),
          TextButton(
            onPressed: () async {
              Navigator.pop(context);
              final success =
                  await _controller.cancelBooking(bookingId);
              if (!mounted) { return; }
              if (success) {
                Get.snackbar('Cancelled', 'Booking has been cancelled.',
                    snackPosition: SnackPosition.TOP);
              } else {
                Get.snackbar('Booking update', _controller.bookingMutationMessage.value,
                    snackPosition: SnackPosition.TOP);
              }
            },
            child: WText(
                text: 'Yes, Cancel',
                color: Colors.red,
                fontSize: 14.sp,
                fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

