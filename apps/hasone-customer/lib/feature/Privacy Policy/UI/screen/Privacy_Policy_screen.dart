import 'dart:ui';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class PrivacyPolicyScreen extends StatefulWidget {
  const PrivacyPolicyScreen({super.key});

  @override
  State<PrivacyPolicyScreen> createState() => _PrivacyPolicyScreenState();
}

class _PrivacyPolicyScreenState extends State<PrivacyPolicyScreen> {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
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
                            child: WText(text: 'Privacy & Policy', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
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
              padding: EdgeInsets.symmetric(horizontal: 16.w),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _sectionTitle('Privacy Policy'),
                  SizedBox(height: 8.h),
                  _bodyText(
                    'Your privacy is important to us. This app collects minimal personal information, such as location data, to help you find nearby EV charging stations and provide accurate directions. We do not share your personal information with third parties without your consent.',
                  ),
                  SizedBox(height: 24.h),

                  _sectionTitle('Data We Collect'),
                  SizedBox(height: 8.h),
                  _bodyText('Location data to show nearby charging stations.\nApp usage data to improve features and performance.'),
                  SizedBox(height: 24.h),

                  _sectionTitle('How We Use Your Data'),
                  SizedBox(height: 8.h),
                  _bodyText('To display charging stations near you.\nTo improve app functionality and user experience.\nTo send relevant notifications or updates.'),
                  SizedBox(height: 24.h),

                  _sectionTitle('Security'),
                  SizedBox(height: 8.h),
                  _bodyText('We use industry-standard measures to protect your data from unauthorized access.'),
                  SizedBox(height: 24.h),

                  _sectionTitle('Your Choices'),
                  SizedBox(height: 8.h),
                  _bodyText('You can choose to disable location access, opt out of notifications, or delete your account at any time.'),
                  SizedBox(height: 24.h),

                  _sectionTitle('Updates'),
                  SizedBox(height: 8.h),
                  _bodyText('We may update this policy occasionally. Changes will be posted in the app.'),
                  SizedBox(height: 24.h),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _sectionTitle(String text) {
    return WText(text: text, color: R.color.darkNavy, fontSize: 16.sp, fontWeight: FontWeight.w700);
  }

  Widget _bodyText(String text) {
    return WText(text: text, color: R.color.neutralGray, fontSize: 13.sp, fontWeight: FontWeight.w400);
  }
}
