import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/winput_text.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';
import 'package:evcharg/feature/Auth/UI/screen/forgot_otp_verify_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class ForgotpasswordScreen extends StatefulWidget {
  const ForgotpasswordScreen({super.key});

  @override
  State<ForgotpasswordScreen> createState() => _ForgotpasswordScreenState();
}

class _ForgotpasswordScreenState extends State<ForgotpasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _emailController = TextEditingController();
  late final AuthController _auth;

  @override
  void initState() {
    super.initState();
    _auth = Get.find<AuthController>();
  }

  @override
  void dispose() {
    _emailController.dispose();
    super.dispose();
  }

  String? _validateEmail(String? v) {
    if (v == null || v.trim().isEmpty) return 'Email is required';
    final re = RegExp(r'^[\w\.-]+@[\w\.-]+\.\w{2,}$');
    if (!re.hasMatch(v.trim())) return 'Enter a valid email';
    return null;
  }

  Future<void> _onNext() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final email = _emailController.text.trim();
    final success = await _auth.forgotPassword(email);
    if (success) {
      Get.to(() => ForgotOtpVerifyScreen(email: email));
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
                  text: 'Forgot Password',
                  fontSize: 22.sp,
                  fontWeight: FontWeight.w700,
                  color: R.color.darkNavy,
                  textAlign: TextAlign.center,
                ),
                SizedBox(height: 10.h),
                WText(
                  text: 'Please enter your email',
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.neutralGray,
                  textAlign: TextAlign.center,
                ),
                SizedBox(height: 2.h),
                WText(
                  text: 'One OTP will send to your Email',
                  fontSize: 13.sp,
                  fontWeight: FontWeight.w400,
                  color: R.color.neutralGray,
                  textAlign: TextAlign.center,
                ),

                SizedBox(height: 32.h),

                // ── Email input ──
                InputFieldText(
                  textEditingController: _emailController,
                  hintText: 'Email Address',
                  keyboardType: TextInputType.emailAddress,
                  fillColor: R.color.white,
                  fieldBorderRadius: 12,
                  fieldBorderColor: const Color(0xFF8A8A8A),
                  enabledBorderColor: const Color(0xFFE5E7EB),
                  focusedBorderColor: const Color(0xFFE5E7EB),
                  prefixIcon: Icon(Icons.email_outlined, color: R.color.neutralGray),
                  validator: _validateEmail,
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
