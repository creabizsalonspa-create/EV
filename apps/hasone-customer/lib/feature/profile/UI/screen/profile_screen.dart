import 'dart:ui';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:evcharg/feature/profile/UI/screen/edit_profile_screen.dart';
import 'package:evcharg/feature/profile/UI/screen/My_Vehicle_screen.dart';
import 'package:evcharg/feature/profile/UI/screen/Saved_Locations_screen.dart';
import 'package:evcharg/feature/Notification/UI/screen/Notification_screen.dart';
import 'package:evcharg/feature/Privacy Policy/UI/screen/Privacy_Policy_screen.dart';
import 'package:evcharg/feature/Privacy Policy/UI/screen/Help_Center_FAQ_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

import 'Favourite_Station_screen.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  late final ProfileController _controller;

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<ProfileController>()
        ? Get.find<ProfileController>()
        : Get.put(ProfileController());
    // Always refresh profile data when screen opens
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _controller.getProfile();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: SingleChildScrollView(
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
                          child: Icon(Icons.arrow_back, color: R.color.darkNavy, size: 22.sp),
                        ),
                        Expanded(
                          child: Center(
                            child: WText(text: 'Profile', color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
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
              child: Obx(() {
                final p = _controller.profile.value;
                final isLoading = _controller.loading.value;

                // Show loading spinner while profile is being fetched
                if (isLoading && p == null) {
                  return SizedBox(
                    height: 300.h,
                    child: Center(
                      child: CircularProgressIndicator(color: R.color.mintGreen),
                    ),
                  );
                }

                return Column(
                  children: [
                    // ── Profile Card (359 x 184) ──
                    Container(
                      width: 359.w,
                      padding: EdgeInsets.all(16.w),
                      decoration: BoxDecoration(
                        color: R.color.offWhite,
                        borderRadius: BorderRadius.circular(12.r),
                        border: Border.all(color: R.color.lightGray, width: 1),
                      ),
                      child: Column(
                        children: [
                          // ── Avatar + Name + Edit ──
                          Row(
                            children: [
                              // Avatar
                              CircleAvatar(
                                radius: 28.r,
                                backgroundColor: R.color.lightGray,
                                backgroundImage: (p?.avatar != null && p!.avatar!.isNotEmpty)
                                    ? NetworkImage(p.avatar!)
                                    : null,
                                child: (p?.avatar == null || p!.avatar!.isEmpty)
                                    ? Icon(Icons.person, size: 28.sp, color: R.color.neutralGray)
                                    : null,
                              ),
                              SizedBox(width: 12.w),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    WText(text: p?.fullName ?? 'User', color: R.color.darkNavy, fontSize: 15.sp, fontWeight: FontWeight.w700),
                                    SizedBox(height: 2.h),
                                    WText(text: p?.email ?? '', color: R.color.neutralGray, fontSize: 12.sp, fontWeight: FontWeight.w400),
                                  ],
                                ),
                              ),
                              GestureDetector(
                                onTap: () async {
                                  final result = await Get.to(() => const EditProfileScreen());
                                  if (result == true) {
                                    _controller.getProfile();
                                  }
                                },
                                child: Container(
                                  width: 32.w,
                                  height: 32.w,
                                  decoration: BoxDecoration(
                                    color: R.color.lightBackground,
                                    shape: BoxShape.circle,
                                  ),
                                  child: Icon(Icons.mode_edit_outline_outlined, size: 20.sp, color: R.color.neutralGray),
                                ),
                              ),
                            ],
                          ),
                          SizedBox(height: 24.h),

                          // ── Stats card (327 x 84) ──
                          Container(
                            width: 327.w,
                            height: 84.h,
                            padding: EdgeInsets.all(16.w),
                            decoration: BoxDecoration(
                              color: const Color(0xFFEBFBF2),
                              borderRadius: BorderRadius.circular(8.r),
                              border: Border.all(color: R.color.lightGray, width: 1),
                            ),
                            child: IntrinsicHeight(
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  _statItem('${p?.sessions ?? 0}', 'Sessions'),
                                  VerticalDivider(color: R.color.lightGray, width: 1.w, thickness: 1),
                                  _statItem('${p?.kwhUsed?.toStringAsFixed(1) ?? '0'}', 'kwh used'),
                                  VerticalDivider(color: R.color.lightGray, width: 1.w, thickness: 1),
                                  _statItem('${p?.favourites ?? 0}', 'Favourites'),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    SizedBox(height: 16.h),

                    // ── Menu List Card (359 x 436) ──
                    Container(
                      width: 359.w,
                      padding: EdgeInsets.all(16.w),
                      decoration: BoxDecoration(
                        color: R.color.white,
                        borderRadius: BorderRadius.circular(12.r),
                        border: Border.all(color: R.color.lightGray, width: 1),
                      ),
                      child: Column(
                        children: [
                          _menuItem(Icons.directions_car_outlined, 'My Vehicle', onTap: () => Get.to(() => const MyVehicleScreen())),
                          _menuDivider(),
                          _menuItem(Icons.bookmark_border_outlined, 'Saved Locations', onTap: () => Get.to(() => const SavedLocationsScreen())),
                          _menuDivider(),
                          _menuItem(Icons.favorite_border, 'Favourite Station', onTap: () => Get.to(() => const FavouriteStationScreen())),
                          _menuDivider(),
                          _menuItem(Icons.notifications_none_outlined, 'Notification', onTap: () => Get.to(() => const NotificationScreen())),
                          _menuDivider(),
                          _menuItem(Icons.privacy_tip_outlined, 'Privacy Policy', onTap: () => Get.to(() => const PrivacyPolicyScreen())),
                          _menuDivider(),
                          _menuItem(Icons.help_outline, 'Help Center', onTap: () => Get.to(() => const HelpCenterFaqScreen())),
                          _menuDivider(),
                          _menuItem(Icons.share_outlined, 'Share App', onTap: () {}),
                        ],
                      ),
                    ),
                    SizedBox(height: 16.h),

                    // ── Logout button ──
                    WButton(
                      label: 'Logout',
                      buttonColor: const Color(0xFFFFE5E5),
                      textColor: R.color.darkNavy,
                      decorationType: DecorationType.solid,
                      onPressed: () => _controller.logout(),
                    ),
                    SizedBox(height: 16.h),
                  ],
                );
              }),
            ),
          ],
        ),
      ),
    );
  }

  Widget _statItem(String value, String label) {
    return Expanded(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          WText(text: value, color: R.color.darkNavy, fontSize: 18.sp, fontWeight: FontWeight.w700),
          SizedBox(height: 4.h),
          WText(text: label, color: R.color.neutralGray, fontSize: 11.sp, fontWeight: FontWeight.w400),
        ],
      ),
    );
  }

  Widget _menuItem(IconData icon, String title, {VoidCallback? onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Padding(
        padding: EdgeInsets.symmetric(vertical: 14.h),
        child: Row(
          children: [
            Icon(icon, size: 22.sp, color: R.color.darkNavy),
            SizedBox(width: 14.w),
            Expanded(
              child: WText(text: title, color: R.color.darkNavy, fontSize: 14.sp, fontWeight: FontWeight.w500),
            ),
            Icon(Icons.chevron_right, size: 20.sp, color: R.color.neutralGray),
          ],
        ),
      ),
    );
  }

  Widget _menuDivider() {
    return Divider(color: R.color.lightGray, height: 1.h, thickness: 1);
  }
}
