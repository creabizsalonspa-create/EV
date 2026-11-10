import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/winput_text.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';
import 'package:evcharg/feature/Auth/UI/screen/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class CreateNewPasswordScreen extends StatefulWidget {
  final String email;
  const CreateNewPasswordScreen({super.key, this.email = ''});

  @override
  State<CreateNewPasswordScreen> createState() => _CreateNewPasswordScreenState();
}

class _CreateNewPasswordScreenState extends State<CreateNewPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _newPasswordController = TextEditingController();
  final TextEditingController _confirmPasswordController = TextEditingController();
  late final AuthController _auth;

  @override
  void initState() {
    super.initState();
    _auth = Get.find<AuthController>();
  }

  @override
  void dispose() {
    _newPasswordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  String? _validateNewPassword(String? v) {
    if (v == null || v.isEmpty) return 'New password is required';
    if (v.length < 6) return 'Minimum 6 characters';
    return null;
  }

  String? _validateConfirmPassword(String? v) {
    if (v == null || v.isEmpty) return 'Please confirm your password';
    if (v != _newPasswordController.text) return 'Passwords do not match';
    return null;
  }

  Future<void> _onNext() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final email = widget.email.isNotEmpty ? widget.email : _auth.pendingEmail;
    final newPass = _newPasswordController.text;
    final confirmPass = _confirmPasswordController.text;
    final success = await _auth.resetPassword(email, newPass, confirmPass);
    if (success) {
      Get.offAll(() => const LoginScreen());
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.white,
      body: SafeArea(
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: 24.w),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                SizedBox(height: 16.h),

                // ── Back button ──
                Align(
                  alignment: Alignment.centerLeft,
                  child: GestureDetector(
                    onTap: () => Get.back(),
                    child: Container(
                      width: 36.w,
                      height: 36.h,
                      decoration: BoxDecoration(
                        color: R.color.white,
                        borderRadius: BorderRadius.circular(8.r),
                        border: Border.all(color: R.color.lightGray),
                      ),
                      child: Icon(Icons.chevron_left, color: R.color.darkNavy, size: 22.sp),
                    ),
                  ),
                ),

                SizedBox(height: 56.h),

                WText(
                  text: 'Create New Password',
                  fontSize: 22.sp,
                  fontWeight: FontWeight.w700,
                  color: R.color.darkNavy,
                  textAlign: TextAlign.center,
                ),
                SizedBox(height: 10.h),
                WText(
                  text: 'Please create new password',
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.neutralGray,
                  textAlign: TextAlign.center,
                ),

                SizedBox(height: 32.h),

                // ── New Password ──
                InputFieldText(
                  textEditingController: _newPasswordController,
                  hintText: 'New Password',
                  isPassword: true,
                  fillColor: R.color.white,
                  fieldBorderRadius: 12,
                  fieldBorderColor: const Color(0xFF8A8A8A),
                  enabledBorderColor: const Color(0xFFE5E7EB),
                  focusedBorderColor: const Color(0xFFE5E7EB),
                  prefixIcon: Icon(Icons.lock_outline, color: R.color.neutralGray),
                  validator: _validateNewPassword,
                ),

                SizedBox(height: 16.h),

                // ── Confirm New Password ──
                InputFieldText(
                  textEditingController: _confirmPasswordController,
                  hintText: 'Confirm New Password',
                  isPassword: true,
                  fillColor: R.color.white,
                  fieldBorderRadius: 12,
                  fieldBorderColor: const Color(0xFF8A8A8A),
                  enabledBorderColor: const Color(0xFFE5E7EB),
                  focusedBorderColor: const Color(0xFFE5E7EB),
                  prefixIcon: Icon(Icons.lock_outline, color: R.color.neutralGray),
                  validator: _validateConfirmPassword,
                ),

                SizedBox(height: 24.h),

                // ── Next button ──
                Obx(() => WButton(
                  label: 'Next',
                  isLoading: _auth.loading.value,
                  onPressed: _auth.loading.value ? null : _onNext,
                  height: 52.h,
                  radius: 12,
                  buttonColor: R.color.mintGreen,
                  textColor: R.color.white,
                  fontSize: 16.sp,
                  fontWeight: FontWeight.w600,
                  decorationType: DecorationType.solid,
                )),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
