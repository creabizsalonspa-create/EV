import 'dart:io';
import 'dart:ui';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/winput_text.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/profile/UI/controller/profile_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _emailController = TextEditingController();
  final _dobController = TextEditingController();
  final _imagePicker = ImagePicker();

  late final ProfileController _controller;

  File? _pickedImage;
  String? _avatarUrl;

  @override
  void initState() {
    super.initState();
    _controller = Get.find<ProfileController>();
    // Pre-fill from profile data
    final p = _controller.profile.value;
    _nameController.text = p?.fullName ?? '';
    _phoneController.text = p?.phone ?? '';
    _emailController.text = p?.email ?? '';
    _dobController.text = p?.dateOfBirth ?? '';
    _avatarUrl = p?.avatar;
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    _dobController.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime(2000, 1, 11),
      firstDate: DateTime(1950),
      lastDate: DateTime.now(),
      builder: (ctx, child) => Theme(
        data: Theme.of(ctx).copyWith(
          colorScheme: ColorScheme.light(primary: R.color.mintGreen),
        ),
        child: child!,
      ),
    );
    if (picked != null) {
      setState(() {
        _dobController.text = '${picked.day.toString().padLeft(2, '0')}/${picked.month.toString().padLeft(2, '0')}/${picked.year}';
      });
    }
  }

  Future<void> _pickImage() async {
    showModalBottomSheet(
      context: context,
      backgroundColor: R.color.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16.r)),
      ),
      builder: (_) => SafeArea(
        child: Wrap(
          children: [
            ListTile(
              leading: Icon(Icons.camera_alt_outlined, color: R.color.darkNavy),
              title: WText(text: 'Camera', color: R.color.darkNavy, fontSize: 14.sp),
              onTap: () {
                Navigator.pop(context);
                _getImage(ImageSource.camera);
              },
            ),
            ListTile(
              leading: Icon(Icons.photo_library_outlined, color: R.color.darkNavy),
              title: WText(text: 'Gallery', color: R.color.darkNavy, fontSize: 14.sp),
              onTap: () {
                Navigator.pop(context);
                _getImage(ImageSource.gallery);
              },
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _getImage(ImageSource source) async {
    final picked = await _imagePicker.pickImage(
      source: source,
      maxWidth: 800,
      maxHeight: 800,
      imageQuality: 85,
    );
    if (picked != null) {
      setState(() {
        _pickedImage = File(picked.path);
      });
    }
  }

  Future<void> _onSave() async {
    // If a new image was picked, upload to ImgBB first
    String? avatarToSend = _avatarUrl ?? '';
    if (_pickedImage != null) {
      final uploadedUrl = await _controller.uploadAvatar(_pickedImage!);
      if (uploadedUrl == null) return; // upload failed
      avatarToSend = uploadedUrl;
    }

    final success = await _controller.updateProfile(
      fullName: _nameController.text.trim(),
      phone: _phoneController.text.trim(),
      dateOfBirth: _dobController.text.trim(),
      avatar: avatarToSend,
    );
    if (success) {
      Get.back(result: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── scrollable area including header ──
          Expanded(
            child: SingleChildScrollView(
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
                                child: Icon(Icons.arrow_back_ios_new, color: R.color.darkNavy, size: 22.sp),
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
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        SizedBox(height: 8.h),
                        // ── Avatar with edit badge ──
                        GestureDetector(
                          onTap: _pickImage,
                          child: Stack(
                            children: [
                              // Show picked image or network avatar or placeholder
                              if (_pickedImage != null)
                                CircleAvatar(
                                  radius: 48.r,
                                  backgroundColor: R.color.lightGray,
                                  backgroundImage: FileImage(_pickedImage!),
                                )
                              else
                                Obx(() {
                                  final avatar = _controller.profile.value?.avatar;
                                  return CircleAvatar(
                                    radius: 48.r,
                                    backgroundColor: R.color.lightGray,
                                    backgroundImage: (avatar != null && avatar.isNotEmpty)
                                        ? NetworkImage(avatar)
                                        : null,
                                    child: (avatar == null || avatar.isEmpty)
                                        ? Icon(Icons.person, size: 48.sp, color: R.color.neutralGray)
                                        : null,
                                  );
                                }),
                              // Upload loading overlay
                              Obx(() => _controller.uploadingAvatar.value
                                  ? Positioned.fill(
                                      child: Container(
                                        decoration: BoxDecoration(
                                          color: Colors.black26,
                                          shape: BoxShape.circle,
                                        ),
                                        child: Center(
                                          child: SizedBox(
                                            width: 24.w,
                                            height: 24.w,
                                            child: CircularProgressIndicator(
                                              strokeWidth: 2,
                                              color: R.color.white,
                                            ),
                                          ),
                                        ),
                                      ),
                                    )
                                  : const SizedBox.shrink()),
                              // Edit badge
                              Positioned(
                                bottom: 0,
                                right: 0,
                                child: Container(
                                  width: 28.w,
                                  height: 28.w,
                                  decoration: BoxDecoration(
                                    color: R.color.mintGreen,
                                    shape: BoxShape.circle,
                                    border: Border.all(color: R.color.white, width: 2),
                                  ),
                                  child: Icon(Icons.edit, size: 14.sp, color: R.color.white),
                                ),
                              ),
                            ],
                          ),
                        ),
                        SizedBox(height: 32.h),

                        // ── Name field ──
                        _fieldLabel('Name'),
                        SizedBox(height: 6.h),
                        InputFieldText(
                          textEditingController: _nameController,
                          hintText: 'Name',
                          keyboardType: TextInputType.name,
                          fillColor: R.color.white,
                          fieldBorderRadius: 12,
                          fieldBorderColor: R.color.lightGray,
                          enabledBorderColor: R.color.lightGray,
                          focusedBorderColor: R.color.lightGray,
                        ),
                        SizedBox(height: 16.h),

                        // ── Phone Number field ──
                        _fieldLabel('Phone Number'),
                        SizedBox(height: 6.h),
                        InputFieldText(
                          textEditingController: _phoneController,
                          hintText: 'Phone Number',
                          keyboardType: TextInputType.phone,
                          fillColor: R.color.white,
                          fieldBorderRadius: 12,
                          fieldBorderColor: R.color.lightGray,
                          enabledBorderColor: R.color.lightGray,
                          focusedBorderColor: R.color.lightGray,
                        ),
                        SizedBox(height: 16.h),

                        // ── Email field (read-only) ──
                        _fieldLabel('Email'),
                        SizedBox(height: 6.h),
                        InputFieldText(
                          textEditingController: _emailController,
                          hintText: 'Email',
                          keyboardType: TextInputType.emailAddress,
                          readOnly: true,
                          fillColor: R.color.white,
                          fieldBorderRadius: 12,
                          fieldBorderColor: R.color.lightGray,
                          enabledBorderColor: R.color.lightGray,
                          focusedBorderColor: R.color.lightGray,
                        ),
                        SizedBox(height: 16.h),

                        // ── Date of Birth field ──
                        _fieldLabel('Date of Birth'),
                        SizedBox(height: 6.h),
                        InputFieldText(
                          textEditingController: _dobController,
                          hintText: 'DD/MM/YYYY',
                          readOnly: true,
                          onTap: _pickDate,
                          fillColor: R.color.white,
                          fieldBorderRadius: 12,
                          fieldBorderColor: R.color.lightGray,
                          enabledBorderColor: R.color.lightGray,
                          focusedBorderColor: R.color.lightGray,
                          suffixIcon: Icon(Icons.calendar_today_outlined, size: 18.sp, color: R.color.neutralGray),
                        ),
                        SizedBox(height: 24.h),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),

          // ── Save Profile button ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: Obx(() => WButton(
              label: 'Save Profile',
              isLoading: _controller.loading.value,
              height: 52.h,
              radius: 12,
              buttonColor: R.color.mintGreen,
              textColor: R.color.white,
              fontSize: 16.sp,
              fontWeight: FontWeight.w600,
              decorationType: DecorationType.solid,
              onPressed: _controller.loading.value ? null : _onSave,
            )),
          ),
        ],
      ),
    );
  }

  Widget _fieldLabel(String label) {
    return Align(
      alignment: Alignment.centerLeft,
      child: WText(text: label, color: R.color.darkNavy, fontSize: 13.sp, fontWeight: FontWeight.w600),
    );
  }
}
