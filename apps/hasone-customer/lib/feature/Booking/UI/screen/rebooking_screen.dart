import 'dart:ui';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/model/All_booking_model.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class RebookingScreen extends StatefulWidget {
  final Data booking;

  const RebookingScreen({super.key, required this.booking});

  @override
  State<RebookingScreen> createState() => _RebookingScreenState();
}

class _RebookingScreenState extends State<RebookingScreen> {
  late DateTime _selectedDate;
  int? _selectedSlotIndex;

  late DateTime _currentMonth;
  late AllBookingController _controller;
  String? _stationId;

  String _formatDate(DateTime date) {
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
    ];
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return '${date.day.toString().padLeft(2, '0')} ${months[date.month - 1]}, ${days[date.weekday - 1]}';
  }

  @override
  void initState() {
    super.initState();
    _selectedDate = DateTime.now();
    _currentMonth = DateTime(_selectedDate.year, _selectedDate.month, 1);
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());

    // Fetch real slots from the station
    if ((widget.booking.stationId ?? '').isNotEmpty) {
      _stationId = widget.booking.stationId;
      final dateStr = _formatDate(_selectedDate);
      _controller.fetchStationSlots(_stationId!, date: dateStr);
    } else {
      // No stationId in booking list — find station by name from loaded stations
      final name = widget.booking.stationName ?? '';
      if (name.isNotEmpty) {
        final dateStr = _formatDate(_selectedDate);
        _controller.fetchSlotsByStationName(name, date: dateStr);
      }
    }
  }

  void _previousMonth() {
    setState(() {
      _currentMonth = DateTime(_currentMonth.year, _currentMonth.month - 1, 1);
    });
  }

  void _nextMonth() {
    setState(() {
      _currentMonth = DateTime(_currentMonth.year, _currentMonth.month + 1, 1);
    });
  }

  Future<void> _onConfirm() async {
    if (_selectedSlotIndex == null) {
      Get.snackbar('Select Slot', 'Please select a time slot.',
          snackPosition: SnackPosition.TOP);
      return;
    }

    final slots = _controller.stationSlots;
    final slot = slots[_selectedSlotIndex!];

    final formattedDate = _formatDate(_selectedDate);

    final success = await _controller.rescheduleBooking(
      bookingId: widget.booking.sId ?? widget.booking.bookingId ?? '',
      date: formattedDate,
      time: slot.startTime ?? '',
      slotStart: slot.startTime ?? '',
      slotEnd: slot.endTime ?? '',
    );

    if (!mounted) { return; }

    if (success) {
      Get.back(result: true);
      Get.snackbar('Success', 'Booking rescheduled successfully.',
          snackPosition: SnackPosition.TOP);
    } else {
      Get.snackbar('Booking update', _controller.bookingMutationMessage.value,
          snackPosition: SnackPosition.TOP);
    }
  }

  @override
  Widget build(BuildContext context) {
    const months = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];

    final firstDayOfMonth = DateTime(_currentMonth.year, _currentMonth.month, 1);
    final daysInMonth = DateTime(_currentMonth.year, _currentMonth.month + 1, 0).day;
    final startWeekday = firstDayOfMonth.weekday % 7;

    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── Top green header ──
          Stack(
            children: [
              Container(width: double.infinity, height: 150.h, color: const Color(0xFF7CFFBC)),
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
                        child: Icon(Icons.arrow_back, color: R.color.darkNavy, size: 22.sp),
                      ),
                      Expanded(
                        child: Center(
                          child: WText(
                            text: 'Re-Booking',
                            color: R.color.darkNavy,
                            fontSize: 18.sp,
                            fontWeight: FontWeight.w700,
                          ),
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
            child: SingleChildScrollView(
              padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 20.h),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ── Station info ──
                  WText(
                    text: widget.booking.stationName ?? '',
                    color: R.color.darkNavy,
                    fontSize: 15.sp,
                    fontWeight: FontWeight.w700,
                  ),
                  SizedBox(height: 4.h),
                  Row(
                    children: [
                      Icon(Icons.location_on, color: R.color.mintGreen, size: 13.sp),
                      SizedBox(width: 4.w),
                      Expanded(
                        child: WText(
                          text: widget.booking.address ?? '',
                          color: R.color.neutralGray,
                          fontSize: 12.sp,
                          fontWeight: FontWeight.w400,
                        ),
                      ),
                    ],
                  ),
                  SizedBox(height: 20.h),

                  // ── Select Date ──
                  WText(
                    text: 'Select date',
                    color: R.color.darkNavy,
                    fontSize: 16.sp,
                    fontWeight: FontWeight.w700,
                  ),
                  SizedBox(height: 12.h),

                  // ── Calendar ──
                  Container(
                    width: double.infinity,
                    padding: EdgeInsets.all(16.w),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(16.r),
                      border: Border.all(color: const Color(0xFFE5E7EB)),
                    ),
                    child: Column(
                      children: [
                        // Month header
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            WText(
                              text: '${months[_currentMonth.month - 1]} ${_currentMonth.year}',
                              color: R.color.darkNavy,
                              fontSize: 15.sp,
                              fontWeight: FontWeight.w700,
                            ),
                            Row(
                              children: [
                                GestureDetector(
                                  onTap: _previousMonth,
                                  child: Container(
                                    width: 28.w, height: 28.w,
                                    decoration: BoxDecoration(
                                      border: Border.all(color: const Color(0xFFE5E7EB)),
                                      borderRadius: BorderRadius.circular(6.r),
                                    ),
                                    child: Icon(Icons.chevron_left, size: 18.sp, color: R.color.darkNavy),
                                  ),
                                ),
                                SizedBox(width: 8.w),
                                GestureDetector(
                                  onTap: _nextMonth,
                                  child: Container(
                                    width: 28.w, height: 28.w,
                                    decoration: BoxDecoration(
                                      border: Border.all(color: const Color(0xFFE5E7EB)),
                                      borderRadius: BorderRadius.circular(6.r),
                                    ),
                                    child: Icon(Icons.chevron_right, size: 18.sp, color: R.color.darkNavy),
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                        SizedBox(height: 16.h),

                        // Weekday headers
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceAround,
                          children: ['S', 'M', 'T', 'W', 'T', 'F', 'S']
                              .map((d) => SizedBox(
                                    width: 32.w,
                                    child: Center(
                                      child: WText(text: d, color: R.color.neutralGray, fontSize: 12.sp, fontWeight: FontWeight.w600),
                                    ),
                                  ))
                              .toList(),
                        ),
                        SizedBox(height: 8.h),

                        // Calendar grid
                        ...List.generate(6, (weekIndex) {
                          final hasAny = List.generate(7, (dayIndex) {
                            final d = weekIndex * 7 + dayIndex - startWeekday + 1;
                            return d >= 1 && d <= daysInMonth;
                          }).any((v) => v);
                          if (!hasAny) return const SizedBox.shrink();
                          return Padding(
                            padding: EdgeInsets.only(bottom: 4.h),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.spaceAround,
                              children: List.generate(7, (dayIndex) {
                                final dayNumber = weekIndex * 7 + dayIndex - startWeekday + 1;
                                if (dayNumber < 1 || dayNumber > daysInMonth) {
                                  return SizedBox(width: 32.w, height: 32.w);
                                }
                                final thisDate = DateTime(_currentMonth.year, _currentMonth.month, dayNumber);
                                final isSelected = _selectedDate.year == thisDate.year &&
                                    _selectedDate.month == thisDate.month &&
                                    _selectedDate.day == thisDate.day;
                                final isPast = thisDate.isBefore(DateTime.now().subtract(const Duration(days: 1)));
                                return GestureDetector(
                                  onTap: isPast ? null : () {
                                  setState(() {
                                    _selectedDate = thisDate;
                                    _selectedSlotIndex = null; // Reset slot selection on date change
                                  });
                                  // Fetch fresh slots for the new date
                                  final dateStr = _formatDate(thisDate);
                                  if (_stationId != null && _stationId!.isNotEmpty) {
                                    _controller.fetchSlotsForDate(_stationId!, dateStr);
                                  } else {
                                    final name = widget.booking.stationName ?? '';
                                    if (name.isNotEmpty) {
                                      _controller.fetchSlotsByStationName(name, date: dateStr);
                                    }
                                  }
                                },
                                  child: Container(
                                    width: 32.w, height: 32.w,
                                    decoration: BoxDecoration(
                                      color: isSelected ? R.color.mintGreen : Colors.transparent,
                                      borderRadius: BorderRadius.circular(8.r),
                                    ),
                                    child: Center(
                                      child: WText(
                                        text: '$dayNumber',
                                        color: isPast
                                            ? R.color.neutralGray.withValues(alpha: 0.4)
                                            : isSelected ? Colors.white : R.color.darkNavy,
                                        fontSize: 13.sp,
                                        fontWeight: isSelected ? FontWeight.w700 : FontWeight.w400,
                                      ),
                                    ),
                                  ),
                                );
                              }),
                            ),
                          );
                        }),
                      ],
                    ),
                  ),
                  SizedBox(height: 24.h),

                  // ── Select Slot ──
                  WText(
                    text: 'Select Slot',
                    color: R.color.darkNavy,
                    fontSize: 16.sp,
                    fontWeight: FontWeight.w700,
                  ),
                  SizedBox(height: 12.h),
                  Obx(() {
                    if (_controller.slotsLoading.value) {
                      return const Center(child: CircularProgressIndicator());
                    }
                    final slots = _controller.stationSlots;
                    if (slots.isEmpty) {
                      return Center(
                        child: WText(
                          text: 'No slots available for this station.',
                          color: R.color.neutralGray,
                          fontSize: 13.sp,
                          fontWeight: FontWeight.w400,
                        ),
                      );
                    }
                    return GridView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 2,
                        crossAxisSpacing: 12.w,
                        mainAxisSpacing: 12.h,
                        childAspectRatio: 3.2,
                      ),
                      itemCount: slots.length,
                      itemBuilder: (context, index) {
                        final slot = slots[index];
                        final isBooked = slot.isBooked ?? false;
                        final isSelected = _selectedSlotIndex == index;
                        final slotLabel =
                            '${slot.startTime ?? ''} – ${slot.endTime ?? ''}';
                        return GestureDetector(
                          onTap: isBooked
                              ? null
                              : () => setState(() => _selectedSlotIndex = index),
                          child: Container(
                            decoration: BoxDecoration(
                              color: isBooked
                                  ? const Color(0xFFF3F4F6)
                                  : isSelected
                                      ? R.color.mintGreen.withValues(alpha: 0.08)
                                      : Colors.white,
                              border: Border.all(
                                color: isBooked
                                    ? const Color(0xFFE5E7EB)
                                    : isSelected
                                        ? R.color.mintGreen
                                        : const Color(0xFFE5E7EB),
                              ),
                              borderRadius: BorderRadius.circular(8.r),
                            ),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(
                                  Icons.access_time,
                                  color: isBooked
                                      ? R.color.neutralGray.withValues(alpha: 0.4)
                                      : R.color.neutralGray,
                                  size: 13.sp,
                                ),
                                SizedBox(width: 4.w),
                                Flexible(
                                  child: WText(
                                    text: slotLabel,
                                    color: isBooked
                                        ? R.color.neutralGray.withValues(alpha: 0.4)
                                        : R.color.darkNavy,
                                    fontSize: 10.sp,
                                    fontWeight: FontWeight.w400,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    );
                  }),
                  SizedBox(height: 24.h),
                ],
              ),
            ),
          ),

          // ── Confirm Button ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: Obx(() => WButton(
              label: _controller.rescheduleLoading.value ? 'Saving...' : 'Confirm Reschedule',
              height: 52.h,
              radius: 12,
              buttonColor: R.color.mintGreen,
              textColor: Colors.white,
              fontSize: 16.sp,
              fontWeight: FontWeight.w600,
              decorationType: DecorationType.solid,
              onPressed: _controller.rescheduleLoading.value ? null : _onConfirm,
            )),
          ),
        ],
      ),
    );
  }
}
