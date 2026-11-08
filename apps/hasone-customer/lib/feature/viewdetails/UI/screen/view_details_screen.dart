import 'package:evcharg/feature/Booking/UI/screen/reservation_charger_picker.dart';
import 'package:evcharg/core/utils/reservation_date.dart';
import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/utils/distance_helper.dart';
import 'package:evcharg/feature/Booking/UI/controller/booking_controller.dart';
import 'package:evcharg/feature/Home/UI/controller/home_controller.dart';
import 'package:evcharg/feature/map/model/station_details_model.dart';
import 'package:evcharg/feature/map/ui/controller/map_controller.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

import '../../../Booking/UI/screen/booking_details.dart';

class ViewDetailsScreen extends StatefulWidget {
  final StationData? stationData;

  const ViewDetailsScreen({super.key, this.stationData});

  @override
  State<ViewDetailsScreen> createState() => _ViewDetailsScreenState();
}

class _ViewDetailsScreenState extends State<ViewDetailsScreen> {
  DateTime _selectedDate = DateTime.now();
  int? _selectedSlotIndex;
  String? _selectedChargerId;
  late final ProfileController _profileController;
  late final MapController _mapController;

  @override
  void initState() {
    super.initState();
    _profileController = Get.isRegistered<ProfileController>()
        ? Get.find<ProfileController>()
        : Get.put(ProfileController());
    _mapController = Get.isRegistered<MapController>()
        ? Get.find<MapController>()
        : Get.put(MapController());

    // Initialize reactive dateSlots from the initial station data
    _mapController.dateSlots.value = station?.slots ?? [];
  }

  StationData? get station => widget.stationData;

  String _formatDate(DateTime date) {
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
    ];
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return '${date.day.toString().padLeft(2, '0')} ${months[date.month - 1]}, ${days[date.weekday - 1]}';
  }

  Future<void> _showDatePicker() async {
    final DateTime? picked = await showDialog<DateTime>(
      context: context,
      builder: (context) => _CustomCalendarDialog(
        selectedDate: _selectedDate,
      ),
    );
    if (picked != null) {
      setState(() {
        _selectedDate = picked;
        _selectedSlotIndex = null; // Reset slot selection on date change
      });
      // Fetch fresh slots for the selected date
      final stationId = station?.sId ?? '';
      if (stationId.isNotEmpty) {
        _mapController.fetchSlotsForDate(stationId, reservationDay(picked));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          Expanded(
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ── Top Image with overlay icons ──
                  Stack(
                    children: [
                      // Station image from API or fallback
                      if (station?.images != null && station!.images!.isNotEmpty)
                        Image.network(
                          station!.images!.first,
                          width: 390.w,
                          height: 276.h,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => Image.asset(
                            Assets.viewdetails,
                            width: 390.w,
                            height: 276.h,
                            fit: BoxFit.cover,
                          ),
                        )
                      else
                        Image.asset(
                          Assets.viewdetails,
                          width: 390.w,
                          height: 276.h,
                          fit: BoxFit.cover,
                        ),
                      // Back button
                      Positioned(
                        top: 48.h,
                        left: 16.w,
                        child: GestureDetector(
                          onTap: () => Navigator.pop(context),
                          child: Container(
                            width: 40.w,
                            height: 40.w,
                            decoration: BoxDecoration(
                              color: R.color.neutralGray,
                              shape: BoxShape.circle,
                            ),
                            child: Icon(
                              Icons.arrow_back,
                              color: R.color.white,
                              size: 20.sp,
                            ),
                          ),
                        ),
                      ),
                      // Right side icons: bookmark + share
                      Positioned(
                        top: 48.h,
                        right: 16.w,
                        child: Row(
                          children: [
                            // ── Save Location (bookmark) ──
                            Obx(() {
                              final stationId = station?.sId ?? '';
                              final isSaved = _profileController.savedLocationIds.contains(stationId);
                              return GestureDetector(
                                onTap: () => _profileController.toggleSavedLocationApi(
                                  stationId: stationId,
                                  stationName: station?.name ?? '',
                                  address: station?.address ?? '',
                                  image: station?.images?.isNotEmpty == true ? station!.images!.first : '',
                                  status: station?.status ?? 'Available',
                                  latitude: station?.latitude,
                                  longitude: station?.longitude,
                                ),
                                child: Container(
                                  width: 40.w,
                                  height: 40.w,
                                  decoration: BoxDecoration(
                                    color: R.color.neutralGray,
                                    shape: BoxShape.circle,
                                  ),
                                  child: Icon(
                                    isSaved ? Icons.bookmark : Icons.bookmark_border,
                                    color: isSaved ? R.color.mintGreen : R.color.white,
                                    size: 20.sp,
                                  ),
                                ),
                              );
                            }),
                            SizedBox(width: 10.w),
                            // ── Favourite Station (heart) ──
                            Obx(() {
                              final stationId = station?.sId ?? '';
                              final isFavourite = _profileController.favouriteStationIds.contains(stationId);
                              return GestureDetector(
                                onTap: () => _profileController.toggleFavouriteStationApi(
                                  stationId: stationId,
                                  stationName: station?.name ?? '',
                                  address: station?.address ?? '',
                                  image: station?.images?.isNotEmpty == true ? station!.images!.first : '',
                                  pricePerHour: station?.pricePerHour ?? '',
                                  status: station?.status ?? 'Available',
                                  latitude: station?.latitude,
                                  longitude: station?.longitude,
                                ),
                                child: Container(
                                  width: 40.w,
                                  height: 40.w,
                                  decoration: BoxDecoration(
                                    color: R.color.neutralGray,
                                    shape: BoxShape.circle,
                                  ),
                                  child: Icon(
                                    isFavourite ? Icons.favorite : Icons.favorite_border,
                                    color: isFavourite ? Colors.red : R.color.white,
                                    size: 20.sp,
                                  ),
                                ),
                              );
                            }),
                          ],
                        ),
                      ),
                    ],
                  ),

                  Padding(
                    padding: EdgeInsets.symmetric(horizontal: 16.w),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(height: 16.h),

                        // ── Station Name & Direction Icon ──
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Expanded(
                              child: WText(
                                text: station?.name ?? 'Charging Station',
                                color: R.color.darkNavy,
                                fontSize: 20.sp,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            Container(
                              width: 36.w,
                              height: 36.w,
                              decoration: BoxDecoration(
                                color: R.color.mintGreen,
                                borderRadius: BorderRadius.circular(20.r),
                              ),
                              child: Icon(
                                Icons.near_me,
                                color: R.color.white,
                                size: 20.sp,
                              ),
                            ),
                          ],
                        ),
                        SizedBox(height: 4.h),

                        // ── Address ──
                        WText(
                          text: station?.address ?? '',
                          color: R.color.neutralGray,
                          fontSize: 13.sp,
                          fontWeight: FontWeight.w400,
                        ),
                        SizedBox(height: 8.h),

                        // ── Distance & Time ──
                        Builder(builder: (context) {
                          final userPos = Get.find<HomeController>()
                              .currentPosition
                              .value;
                          final distText = DistanceHelper.distanceText(
                            userPosition: userPos,
                            stationLat: station?.latitude,
                            stationLng: station?.longitude,
                          );
                          final durText = DistanceHelper.durationText(
                            userPosition: userPos,
                            stationLat: station?.latitude,
                            stationLng: station?.longitude,
                          );
                          return Row(
                            children: [
                              if (distText.isNotEmpty) ...[
                                Icon(Icons.location_on,
                                    color: R.color.mintGreen, size: 14.sp),
                                SizedBox(width: 4.w),
                                WText(
                                  text: distText,
                                  color: R.color.neutralGray,
                                  fontSize: 12.sp,
                                  fontWeight: FontWeight.w400,
                                ),
                                SizedBox(width: 12.w),
                              ],
                              if (durText.isNotEmpty) ...[
                                Icon(Icons.access_time,
                                    color: R.color.neutralGray, size: 14.sp),
                                SizedBox(width: 4.w),
                                WText(
                                  text: durText,
                                  color: R.color.neutralGray,
                                  fontSize: 12.sp,
                                  fontWeight: FontWeight.w400,
                                ),
                              ],
                            ],
                          );
                        }),
                        SizedBox(height: 6.h),

                        // ── Rating ──
                        Row(
                          children: [
                            ...List.generate(5, (i) {
                              final ratingVal = station?.rating ?? 0.0;
                              if (i < ratingVal.floor()) {
                                return Icon(Icons.star, color: Colors.amber, size: 14.sp);
                              } else if (i < ratingVal) {
                                return Icon(Icons.star_half, color: Colors.amber, size: 14.sp);
                              } else {
                                return Icon(Icons.star_border, color: Colors.amber, size: 14.sp);
                              }
                            }),
                            SizedBox(width: 4.w),
                            WText(
                              text: station?.rating?.toStringAsFixed(1) ?? '0.0',
                              color: R.color.darkNavy,
                              fontSize: 12.sp,
                              fontWeight: FontWeight.w600,
                            ),
                            SizedBox(width: 4.w),
                            WText(
                              text: '(${station?.reviewCount ?? 0} reviews)',
                              color: R.color.neutralGray,
                              fontSize: 12.sp,
                              fontWeight: FontWeight.w400,
                            ),
                          ],
                        ),
                        SizedBox(height: 6.h),

                        // ── Available ──
                        Row(
                          children: [
                            Icon(
                              Icons.circle,
                              color: (station?.status == 'Available')
                                  ? R.color.mintGreen
                                  : const Color(0xFFEF4444),
                              size: 10.sp,
                            ),
                            SizedBox(width: 6.w),
                            WText(
                              text: station?.status ?? 'Available',
                              color: (station?.status == 'Available')
                                  ? R.color.mintGreen
                                  : const Color(0xFFEF4444),
                              fontSize: 12.sp,
                              fontWeight: FontWeight.w600,
                            ),
                            if (station?.status != 'Available' &&
                                station?.availableIn != null) ...[
                              SizedBox(width: 8.w),
                              WText(
                                text: 'Available in ${station!.availableIn} mins',
                                color: R.color.neutralGray,
                                fontSize: 12.sp,
                                fontWeight: FontWeight.w400,
                              ),
                            ],
                          ],
                        ),
                        SizedBox(height: 20.h),

                        // ── About ──
                        WText(
                          text: 'About',
                          color: R.color.darkNavy,
                          fontSize: 16.sp,
                          fontWeight: FontWeight.w700,
                        ),
                        SizedBox(height: 8.h),
                        WText(
                          text: station?.about ?? 'No description available.',
                          color: R.color.neutralGray,
                          fontSize: 12.sp,
                          fontWeight: FontWeight.w400,
                        ),
                        SizedBox(height: 20.h),

                        // ── Amenities ──
                        WText(
                          text: 'Amenities',
                          color: R.color.darkNavy,
                          fontSize: 16.sp,
                          fontWeight: FontWeight.w700,
                        ),
                        SizedBox(height: 12.h),
                        if (station?.amenities != null &&
                            station!.amenities!.isNotEmpty)
                          Wrap(
                            spacing: 40.w,
                            runSpacing: 12.h,
                            children: station!.amenities!.map((amenity) {
                              return _buildAmenity(
                                _amenityIcon(amenity.icon),
                                amenity.label ?? '',
                              );
                            }).toList(),
                          )
                        else
                          WText(
                            text: 'No amenities listed',
                            color: R.color.neutralGray,
                            fontSize: 12.sp,
                            fontWeight: FontWeight.w400,
                          ),
                        SizedBox(height: 20.h),

                        // ── Select Date ──
                        WText(
                          text: 'Select Date',
                          color: R.color.darkNavy,
                          fontSize: 16.sp,
                          fontWeight: FontWeight.w700,
                        ),
                        SizedBox(height: 10.h),
                        GestureDetector(
                          onTap: _showDatePicker,
                          child: Container(
                            padding: EdgeInsets.symmetric(horizontal: 12.w, vertical: 12.h),
                            decoration: BoxDecoration(
                              border: Border.all(color: const Color(0xFFE5E7EB)),
                              borderRadius: BorderRadius.circular(8.r),
                              color: R.color.white,
                            ),
                            child: Row(
                              children: [
                                Icon(Icons.calendar_today_outlined, color: R.color.darkGray1, size: 18.sp),
                                SizedBox(width: 10.w),
                                WText(
                                  text: _formatDate(_selectedDate),
                                  color: R.color.darkNavy,
                                  fontSize: 14.sp,
                                  fontWeight: FontWeight.w500,
                                ),
                              ],
                            ),
                          ),
                        ),
                        SizedBox(height: 20.h),

                        // ── Select Slot ──
                        WText(
                          text: 'Select Slot',
                          color: R.color.darkNavy,
                          fontSize: 16.sp,
                          fontWeight: FontWeight.w700,
                        ),
                        SizedBox(height: 10.h),
                        Obx(() {
                          if (_mapController.slotsLoading.value) {
                            return const Center(child: CircularProgressIndicator());
                          }
                          final slots = _mapController.dateSlots;
                          if (slots.isNotEmpty) {
                            return GridView.builder(
                              shrinkWrap: true,
                              physics: const NeverScrollableScrollPhysics(),
                              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                                crossAxisCount: 2,
                                crossAxisSpacing: 12.w,
                                mainAxisSpacing: 12.h,
                                childAspectRatio: 3.5,
                              ),
                              itemCount: slots.length,
                              itemBuilder: (context, index) {
                                final slot = slots[index];
                                final isBooked = slot.isBooked ?? false;
                                final isSelected = _selectedSlotIndex == index;
                                final slotText =
                                    '${slot.startTime ?? ''} to ${slot.endTime ?? ''}';
                                return GestureDetector(
                                  onTap: isBooked
                                      ? null
                                      : () {
                                          setState(() {
                                            _selectedSlotIndex = index;
                                          });
                                        },
                                  child: Container(
                                    decoration: BoxDecoration(
                                      color: isBooked
                                          ? Colors.grey.shade200
                                          : isSelected
                                              ? R.color.mintGreen
                                                  .withValues(alpha: 0.1)
                                              : R.color.white,
                                      border: Border.all(
                                        color: isBooked
                                            ? Colors.grey.shade300
                                            : isSelected
                                                ? R.color.mintGreen
                                                : const Color(0xFFE5E7EB),
                                      ),
                                      borderRadius: BorderRadius.circular(8.r),
                                    ),
                                    child: Row(
                                      mainAxisAlignment: MainAxisAlignment.center,
                                      children: [
                                        Icon(Icons.access_time,
                                            color: isBooked
                                                ? Colors.grey
                                                : R.color.neutralGray,
                                            size: 14.sp),
                                        SizedBox(width: 4.w),
                                        Flexible(
                                          child: WText(
                                            text: slotText,
                                            color: isBooked
                                                ? Colors.grey
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
                          } else {
                            return WText(
                              text: 'No slots available for this date',
                              color: R.color.neutralGray,
                              fontSize: 12.sp,
                              fontWeight: FontWeight.w400,
                            );
                          }
                        }),
                        SizedBox(height: 20.h),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),

          ReservationChargerPicker(
            key: ValueKey(station?.sId ?? ''),
            stationId: station?.sId ?? '',
            onChanged: (value) { if (mounted) _selectedChargerId = value; },
          ),

          // ── Book Now Button ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: Obx(() {
              final bookingCtrl = Get.find<BookingController>();
              return WButton(
                label: bookingCtrl.loading.value ? 'Booking...' : 'Book Now',
                height: 52.h,
                radius: 12,
                buttonColor: R.color.mintGreen,
                textColor: R.color.white,
                fontSize: 16.sp,
                fontWeight: FontWeight.w600,
                decorationType: DecorationType.solid,
                onPressed: bookingCtrl.loading.value
                    ? null
                    : () async {
                        final slots = _mapController.dateSlots;
                        // Validate slot selection
                        if (_selectedSlotIndex == null || slots.isEmpty || _selectedSlotIndex! >= slots.length) {
                          Get.snackbar('Error', 'Please select a time slot',
                              snackPosition: SnackPosition.BOTTOM);
                          return;
                        }

                        if (_selectedChargerId == null) {
                          Get.snackbar('Booking update', 'Choose an approved charger before booking.', snackPosition: SnackPosition.BOTTOM);
                          return;
                        }
                        final stationId = station?.sId;
                        if (stationId == null || stationId.isEmpty) return;

                        final slot = slots[_selectedSlotIndex!];
                        final dateStr = reservationDay(_selectedDate);

                        final result = await bookingCtrl.createBooking(
                          stationId: stationId,
                          date: dateStr,
                          reservedChargerId: _selectedChargerId,
                          slotStart: slot.startTime ?? '',
                          slotEnd: slot.endTime ?? '',
                        );

                        if (!mounted) { return; }
                        if (result != null) {
                          Get.to(() => BookingDetails(bookingData: result));
                        } else {
                          Get.snackbar(
                              'Booking update', bookingCtrl.bookingError.value,
                              snackPosition: SnackPosition.BOTTOM);
                        }
                      },
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _buildAmenity(IconData icon, String label) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color:R.color.mintGreen, size: 18.sp),
        SizedBox(width: 6.w),
        WText(
          text: label,
          color: R.color.darkGray1,
          fontSize: 14.sp,
          fontWeight: FontWeight.w500,
        ),
      ],
    );
  }

  /// Map icon string from API to Flutter IconData
  IconData _amenityIcon(String? icon) {
    switch (icon?.toLowerCase()) {
      case 'restaurant':
        return Icons.restaurant;
      case 'wifi':
      case 'wi-fi':
        return Icons.wifi;
      case 'maintenance':
        return Icons.build_outlined;
      case 'shop':
      case 'shopping':
        return Icons.shopping_bag_outlined;
      case 'parking':
        return Icons.local_parking;
      case 'restroom':
      case 'toilet':
        return Icons.wc;
      case 'cafe':
      case 'coffee':
        return Icons.coffee;
      case 'atm':
        return Icons.atm;
      default:
        return Icons.check_circle_outline;
    }
  }
}

// ── Custom Calendar Dialog ──
class _CustomCalendarDialog extends StatefulWidget {
  final DateTime selectedDate;

  const _CustomCalendarDialog({required this.selectedDate});

  @override
  State<_CustomCalendarDialog> createState() => _CustomCalendarDialogState();
}

class _CustomCalendarDialogState extends State<_CustomCalendarDialog> {
  late DateTime _currentMonth;
  late DateTime _selectedDate;

  @override
  void initState() {
    super.initState();
    _selectedDate = widget.selectedDate;
    _currentMonth = DateTime(_selectedDate.year, _selectedDate.month, 1);
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

  @override
  Widget build(BuildContext context) {
    const months = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];

    final firstDayOfMonth = DateTime(_currentMonth.year, _currentMonth.month, 1);
    final daysInMonth = DateTime(_currentMonth.year, _currentMonth.month + 1, 0).day;
    final startWeekday = firstDayOfMonth.weekday % 7; // Sunday = 0

    return Dialog(
      backgroundColor: Colors.transparent,
      child: Container(
        padding: EdgeInsets.all(16.w),
        decoration: BoxDecoration(
          color: R.color.white,
          borderRadius: BorderRadius.circular(16.r),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // ── Month Header ──
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                WText(
                  text: '${months[_currentMonth.month - 1]} ${_currentMonth.year}',
                  color: Colors.black,
                  fontSize: 16.sp,
                  fontWeight: FontWeight.w700,
                ),
                Row(
                  children: [
                    GestureDetector(
                      onTap: _previousMonth,
                      child: Icon(Icons.chevron_left, color: Colors.black54, size: 24.sp),
                    ),
                    SizedBox(width: 8.w),
                    GestureDetector(
                      onTap: _nextMonth,
                      child: Icon(Icons.chevron_right, color: Colors.black54, size: 24.sp),
                    ),
                  ],
                ),
              ],
            ),
            SizedBox(height: 16.h),

            // ── Weekday Headers ──
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: ['S', 'M', 'T', 'W', 'T', 'F', 'S']
                  .map((d) => SizedBox(
                        width: 32.w,
                        child: Center(
                          child: WText(
                            text: d,
                            color: R.color.neutralGray,
                            fontSize: 12.sp,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ))
                  .toList(),
            ),
            SizedBox(height: 8.h),

            // ── Calendar Grid ──
            ...List.generate(6, (weekIndex) {
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

                    return GestureDetector(
                      onTap: () {
                        setState(() {
                          _selectedDate = thisDate;
                        });
                        Navigator.pop(context, thisDate);
                      },
                      child: Container(
                        width: 32.w,
                        height: 32.w,
                        decoration: BoxDecoration(
                          color: isSelected ? R.color.mintGreen : Colors.transparent,
                          borderRadius: BorderRadius.circular(8.r),
                        ),
                        child: Center(
                          child: WText(
                            text: '$dayNumber',
                            color: isSelected ? R.color.white : Colors.black,
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
    );
  }
}
