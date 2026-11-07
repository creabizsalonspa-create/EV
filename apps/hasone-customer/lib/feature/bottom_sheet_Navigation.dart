import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../app/resource.dart';

class BottomSheetNavigation extends StatefulWidget {
  final int currentIndex;
  final ValueChanged<int> onTap;

  const BottomSheetNavigation({
    super.key,
    required this.currentIndex,
    required this.onTap,
  });

  @override
  State<BottomSheetNavigation> createState() => _BottomSheetNavigationState();
}

class _BottomSheetNavigationState extends State<BottomSheetNavigation> {
  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 70.h + 10.h, // extra space for the raised FAB
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          // ── White bar ───────────────────────────────────
          Positioned(
            bottom: 0,
            left: 0,
            right: 0,
            child: Container(
              height: 80.h,
              decoration: BoxDecoration(
                color: R.color.white,
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.08),
                    blurRadius: 16,
                    offset: const Offset(0, -2),
                  ),
                ],
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  _navItem(index: 0, svgIcon: Assets.home, label: 'Home'),
                  _navItem(index: 1, svgIcon: Assets.maps, label: 'Map'),
                  // Empty space for the FAB
                  SizedBox(width: 60.w),
                  _navItem(index: 3, svgIcon: Assets.booking, label: 'Booking'),
                  _navItem(index: 4, svgIcon: Assets.profile, label: 'Profile'),
                ],
              ),
            ),
          ),

          // ── Raised center FAB ────────────────────────────
          Positioned(
            top: -20,
            left: 0,
            right: 0,
            child: Center(
              child: GestureDetector(
                onTap: () => widget.onTap(2),
                child: Container(
                  width: 52.w,
                  height: 52.w,
                  padding: EdgeInsets.all(14.w),
                  decoration: BoxDecoration(
                    color: R.color.mintGreen,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: R.color.white,
                      width: 4,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: R.color.brightGreen.withValues(alpha: 0.35),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: SvgPicture.asset(
                    Assets.charge,
                    fit: BoxFit.contain,
                    colorFilter:  ColorFilter.mode(
                      R.color.white,
                      BlendMode.srcIn,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _navItem({
    required int index,
    required String svgIcon,
    required String label,
  }) {
    final isSelected = widget.currentIndex == index;
    return GestureDetector(
      onTap: () => widget.onTap(index),
      behavior: HitTestBehavior.opaque,
      child: SizedBox(
        width: 60.w,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Squircle background for active item
            Container(
              alignment: Alignment.center,
              child: SvgPicture.asset(
                svgIcon,
                width: 24.sp,
                height: 24.sp,
                colorFilter: ColorFilter.mode(
                  isSelected
                      ? R.color.mintGreen
                      : R.color.grayShade,
                  BlendMode.srcIn,
                ),
              ),
            ),
            SizedBox(height: 2.h),
            WText(
              text: label,
              fontSize: 10.sp,
              fontWeight: isSelected ? FontWeight.w600 : FontWeight.w400,
              color: isSelected
                  ? R.color.mintGreen
                  : R.color.grayShade,
            ),
          ],
        ),
      ),
    );
  }
}

