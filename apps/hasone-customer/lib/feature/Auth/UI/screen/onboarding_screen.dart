import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import '../../../../app/resource.dart';
import 'login_screen.dart';

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key});

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  // Swipe tracking for Get Started button
  double _dragOffset = 0;
  double _maxDragWidth = 0;
  bool _navigated = false;

  void _goToLogin() {
    if (_navigated) return;
    _navigated = true;
    Get.off(() => const LoginScreen());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          // Fullscreen background image
          Positioned.fill(
            child: Image.asset(
              Assets.Onboarding,
              fit: BoxFit.cover,
            ),
          ),

          // Dark gradient overlay for text readability
          Positioned.fill(
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Colors.black.withValues(alpha: 0.35),
                    Colors.black.withValues(alpha: 0.05),
                    Colors.black.withValues(alpha: 0.1),
                    Colors.black.withValues(alpha: 0.5),
                  ],
                  stops: const [0.0, 0.3, 0.6, 1.0],
                ),
              ),
            ),
          ),

          // Text content
          Positioned(
            top: 100.h,
            left: 24.w,
            right: 24.w,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                WText(
                  text: 'Find Charging\nInstantly.',
                  fontSize: 40.sp,
                  fontWeight: FontWeight.w700,
                ),
                SizedBox(height: 16.h),
                WText(text: 'Discover nearby EV charging stations in\nreal time—no stress, no guesswork.', )
              ],
            ),
          ),

          // Bottom white bar with sliding Get Started button & arrows
          Positioned(
            bottom: 40.h,
            left: 20.w,
            right: 20.w,
            child: LayoutBuilder(
              builder: (context, constraints) {
                final barWidth = constraints.maxWidth;
                final buttonWidth = 150.w;
                final maxDrag = barWidth - buttonWidth - 12.w;
                _maxDragWidth = maxDrag;

                return Container(
                  height: 60.h,
                  decoration: BoxDecoration(
                    color: R.color.white,
                    borderRadius: BorderRadius.circular(35.r),
                  ),
                  child: Stack(
                    children: [
                      // Green arrows >>> (static, right side)
                      Positioned(
                        right: 20.w,
                        top: 0,
                        bottom: 0,
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(Icons.chevron_right, color: R.color.softMint),
                            Icon(Icons.chevron_right, color: R.color.lightMint, size: 22.sp),
                            Icon(Icons.chevron_right, color: R.color.mintGreen, size: 22.sp),
                          ],
                        ),
                      ),

                      // Slidable Get Started button
                      Positioned(
                        left: _dragOffset,
                        top: 0,
                        bottom: 0,
                        child: GestureDetector(
                          onHorizontalDragUpdate: (details) {
                            setState(() {
                              _dragOffset += details.delta.dx;
                              _dragOffset = _dragOffset.clamp(0.0, _maxDragWidth);
                            });
                          },
                          onHorizontalDragEnd: (details) {
                            if (_dragOffset >= _maxDragWidth * 0.7) {
                              // Animate to end and navigate
                              setState(() {
                                _dragOffset = _maxDragWidth;
                              });
                              _goToLogin();
                            } else {
                              // Snap back
                              setState(() {
                                _dragOffset = 0;
                              });
                            }
                          },
                          child: Container(
                            margin: EdgeInsets.all(6.w),
                            padding: EdgeInsets.symmetric(horizontal: 28.w),
                            decoration: BoxDecoration(
                              color: R.color.mintGreen,
                              borderRadius: BorderRadius.circular(15.r),
                            ),
                            alignment: Alignment.center,
                            child:
                            WText(text: 'Get Started', fontSize: 16.sp, fontWeight: FontWeight.w700)

                          ),
                        ),
                      ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
