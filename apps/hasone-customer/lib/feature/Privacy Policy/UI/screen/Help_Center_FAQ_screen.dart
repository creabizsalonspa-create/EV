import 'dart:ui';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class HelpCenterFaqScreen extends StatefulWidget {
  const HelpCenterFaqScreen({super.key});

  @override
  State<HelpCenterFaqScreen> createState() => _HelpCenterFaqScreenState();
}

class _HelpCenterFaqScreenState extends State<HelpCenterFaqScreen> {
  final List<Map<String, String>> _faqs = [
    {
      'q': 'How do I find my first EV charging station?',
      'a': 'Open the app and enable location to see nearby EV charging stations on the map. Select a station to view details, availability, and pricing. Follow in-app directions to reach the station and start your charging session.',
    },
    {'q': 'How do I track my charging progress?', 'a': 'Once charging starts, you can track real-time progress including battery percentage and estimated completion time on the Charging screen.'},
    {'q': 'What is the average charging time?', 'a': 'Charging time depends on your vehicle and charger type. Fast chargers typically take 30–60 minutes, while standard chargers may take several hours.'},
    {'q': 'Can I modify my charging plan?', 'a': 'Yes, you can extend or stop your charging session at any time from the Charging screen.'},
    {'q': 'What if I miss a charging session?', 'a': 'If you miss a session, you can rebook from the Booking screen. Your previous booking details will be available for quick rebooking.'},
    {'q': 'How do I change my charging goals?', 'a': 'Go to Profile > Settings to update your charging preferences and goals.'},
    {'q': 'How do I cancel my subscription?', 'a': 'You can cancel your subscription from Profile > Settings > Subscription Management.'},
  ];

  int _expandedIndex = 0;

  void _showContactPopup() {
    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: Colors.transparent,
        child: Container(
          width: 358.w,
          height: 192.h,
          padding: EdgeInsets.all(16.w),
          decoration: BoxDecoration(
            color: R.color.offWhite,
            borderRadius: BorderRadius.circular(16.r),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // ── Email row ──
              Container(
                width: double.infinity,
                padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 12.h),
                decoration: BoxDecoration(
                  color: R.color.white,
                  borderRadius: BorderRadius.circular(8.r),
                  border: Border.all(color: R.color.lightGray, width: 1),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 32.w,
                      height: 32.w,
                      decoration: BoxDecoration(
                        color: R.color.mintGreen,
                        borderRadius: BorderRadius.circular(8.r),
                      ),
                      child: Icon(Icons.email_outlined, size: 16.sp, color: R.color.white),
                    ),
                    SizedBox(width: 12.w),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        WText(text: 'Email', color: R.color.neutralGray, fontSize: 11.sp, fontWeight: FontWeight.w400),
                        SizedBox(height: 2.h),
                        WText(text: 'hello.naasmind@gmail.com', color: R.color.darkNavy, fontSize: 13.sp, fontWeight: FontWeight.w600),
                      ],
                    ),
                  ],
                ),
              ),
              SizedBox(height: 16.h),

              // ── Phone row ──
              Container(
                width: double.infinity,
                padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 12.h),
                decoration: BoxDecoration(
                  color: R.color.white,
                  borderRadius: BorderRadius.circular(8.r),
                  border: Border.all(color: R.color.lightGray, width: 1),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 32.w,
                      height: 32.w,
                      decoration: BoxDecoration(
                        color: R.color.mintGreen,
                        borderRadius: BorderRadius.circular(8.r),
                      ),
                      child: Icon(Icons.phone_outlined, size: 16.sp, color: R.color.white),
                    ),
                    SizedBox(width: 12.w),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        WText(text: 'Customer Service', color: R.color.neutralGray, fontSize: 11.sp, fontWeight: FontWeight.w400),
                        SizedBox(height: 2.h),
                        WText(text: '01772-337656', color: R.color.darkNavy, fontSize: 13.sp, fontWeight: FontWeight.w600),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: SingleChildScrollView(
        padding: EdgeInsets.zero,
        child: Column(
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
                            child: WText(text: 'Help Center/FAQ', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
                          ),
                        ),
                        SizedBox(width: 22.w),
                      ],
                    ),
                  ),
                ),
              ],
            ),

            Padding(
              padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
              child: Column(
                children: [
                  // ── FAQ items ──
                  ...List.generate(_faqs.length, (i) {
                    final isExpanded = _expandedIndex == i;
                    return Column(
                      children: [
                        GestureDetector(
                          onTap: () => setState(() => _expandedIndex = isExpanded ? -1 : i),
                          child: Container(
                            width: 358.w,
                            constraints: BoxConstraints(minHeight: 56.h),
                            padding: EdgeInsets.all(16.w),
                            decoration: BoxDecoration(
                              color: R.color.white,
                              borderRadius: BorderRadius.circular(4.r),
                              border: Border.all(color: R.color.lightGray, width: 1),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Expanded(
                                      child: WText(text: _faqs[i]['q']!, color: R.color.darkNavy, fontSize: 13.sp, fontWeight: FontWeight.w600),
                                    ),
                                    Icon(
                                      isExpanded ? Icons.keyboard_arrow_up : Icons.keyboard_arrow_down,
                                      size: 20.sp,
                                      color: R.color.neutralGray,
                                    ),
                                  ],
                                ),
                                if (isExpanded) ...[
                                  SizedBox(height: 12.h),
                                  WText(text: _faqs[i]['a']!, color: R.color.neutralGray, fontSize: 12.sp, fontWeight: FontWeight.w400),
                                ],
                              ],
                            ),
                          ),
                        ),
                        SizedBox(height: 12.h),
                      ],
                    );
                  }),

                  SizedBox(height: 8.h),

                  // ── Still need help card (358 x 233) ──
                  Container(
                    width: 358.w,
                    padding: EdgeInsets.all(16.w),
                    decoration: BoxDecoration(
                      color: const Color(0xFFEBFBF2),
                      borderRadius: BorderRadius.circular(8.r),
                      border: Border.all(color: R.color.mintGreen, width: 1),
                    ),
                    child: Column(
                      children: [
                        SizedBox(height: 16.h),
                        // ── Chat icon ──
                        Container(
                          width: 48.w,
                          height: 48.w,
                          decoration: BoxDecoration(
                            color: R.color.mintGreen,
                            shape: BoxShape.circle,
                          ),
                          child: Icon(Icons.chat_bubble_outline_rounded, size: 22.sp, color: R.color.white),
                        ),
                        SizedBox(height: 16.h),
                        WText(text: 'Still need help?', color: R.color.darkNavy, fontSize: 16.sp, fontWeight: FontWeight.w700, textAlign: TextAlign.center),
                        SizedBox(height: 6.h),
                        WText(text: 'Our support team is here to assist you 24/7', color: R.color.neutralGray, fontSize: 12.sp, fontWeight: FontWeight.w400, textAlign: TextAlign.center),
                        SizedBox(height: 24.h),
                        WButton(
                          label: 'Contact Support',
                          height: 48.h,
                          radius: 12,
                          buttonColor: R.color.mintGreen,
                          textColor: R.color.white,
                          fontSize: 15.sp,
                          fontWeight: FontWeight.w600,
                          decorationType: DecorationType.solid,
                          onPressed: _showContactPopup,
                        ),
                      ],
                    ),
                  ),
                  SizedBox(height: 16.h),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
