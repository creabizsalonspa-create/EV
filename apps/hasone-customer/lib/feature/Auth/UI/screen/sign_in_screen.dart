import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/winput_text.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';
import 'package:evcharg/feature/Auth/UI/screen/login_screen.dart';
import 'package:evcharg/feature/Auth/UI/screen/verify_otp_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';


class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key});

  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _nameController = TextEditingController();
  final TextEditingController _phoneController = TextEditingController();
  final TextEditingController _emailController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();

  late final AuthController _auth;

  @override
  void initState() {
    super.initState();
    _auth = Get.put(AuthController());
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  // ── validators ──
  String? _validateName(String? v) {
    if (v == null || v.trim().isEmpty) return 'Full name is required';
    if (v.trim().length < 2) return 'Name too short';
    return null;
  }

  String? _validatePhone(String? v) {
    if (v == null || v.trim().isEmpty) return 'Phone number is required';
    final re = RegExp(r'^\+?[0-9]{7,15}$');
    if (!re.hasMatch(v.trim())) return 'Enter a valid phone number';
    return null;
  }

  String? _validateEmail(String? v) {
    if (v == null || v.trim().isEmpty) return 'Email is required';
    final re = RegExp(r'^[\w\.-]+@[\w\.-]+\.\w{2,}$');
    if (!re.hasMatch(v.trim())) return 'Enter a valid email';
    return null;
  }

  String? _validatePassword(String? v) {
    if (v == null || v.isEmpty) return 'Password is required';
    if (v.length < 6) return 'Minimum 6 characters';
    return null;
  }

  Future<void> _onRegister() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final success = await _auth.register(
      _nameController.text.trim(),
      _emailController.text.trim(),
      _phoneController.text.trim(),
      _passwordController.text,
    );
    if (success) {
      Get.to(() => VerifyOtpScreen(
            email: _emailController.text.trim(),
            fromRegister: true,
          ));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.white,
      body: SingleChildScrollView(
        child: SizedBox(
          width: double.infinity,
          child: Stack(
            children: [
              // ── Top image ──
              Positioned(
                top: 0,
                left: -2,
                child: Image.asset(
                  Assets.login,
                  width: 394.w,
                  height: 316.h,
                  fit: BoxFit.cover,
                ),
              ),

              // ── White card ──
              Form(
                key: _formKey,
                child: Column(
                  children: [
                    SizedBox(height: 251.h),
                    Container(
                      width: 390.w,
                      constraints: BoxConstraints(minHeight: 593.h),
                      decoration: BoxDecoration(
                        color: R.color.white,
                        borderRadius: BorderRadius.only(
                          topLeft: Radius.circular(30.r),
                          topRight: Radius.circular(30.r),
                        ),
                      ),
                      child: Padding(
                        padding: EdgeInsets.symmetric(horizontal: 24.w),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.center,
                          children: [
                            SizedBox(height: 30.h),

                            // ── Full Name ──
                            InputFieldText(
                              textEditingController: _nameController,
                              hintText: 'Full Name',
                              keyboardType: TextInputType.name,
                              fillColor: R.color.white,
                              fieldBorderRadius: 12,
                              fieldBorderColor: const Color(0xFF8A8A8A),
                              enabledBorderColor: const Color(0xFFE5E7EB),
                              focusedBorderColor: const Color(0xFFE5E7EB),
                              prefixIcon: Icon(Icons.person_outline, color: R.color.neutralGray),
                              validator: _validateName,
                            ),
                            SizedBox(height: 16.h),

                            // ── Phone ──
                            InputFieldText(
                              textEditingController: _phoneController,
                              hintText: 'Phone Number',
                              keyboardType: TextInputType.phone,
                              fillColor: R.color.white,
                              fieldBorderRadius: 12,
                              fieldBorderColor: const Color(0xFF8A8A8A),
                              enabledBorderColor: const Color(0xFFE5E7EB),
                              focusedBorderColor: const Color(0xFFE5E7EB),
                              prefixIcon: Icon(Icons.phone_outlined, color: R.color.neutralGray),
                              validator: _validatePhone,
                            ),
                            SizedBox(height: 16.h),

                            // ── Email ──
                            InputFieldText(
                              textEditingController: _emailController,
                              hintText: 'Email',
                              keyboardType: TextInputType.emailAddress,
                              fillColor: R.color.white,
                              fieldBorderRadius: 12,
                              fieldBorderColor: const Color(0xFF8A8A8A),
                              enabledBorderColor: const Color(0xFFE5E7EB),
                              focusedBorderColor: const Color(0xFFE5E7EB),
                              prefixIcon: Icon(Icons.email_outlined, color: R.color.neutralGray),
                              validator: _validateEmail,
                            ),
                            SizedBox(height: 16.h),

                            // ── Password ──
                            InputFieldText(
                              textEditingController: _passwordController,
                              hintText: 'Password',
                              isPassword: true,
                              fillColor: R.color.white,
                              fieldBorderRadius: 12,
                              fieldBorderColor: const Color(0xFF8A8A8A),
                              enabledBorderColor: const Color(0xFFE5E7EB),
                              focusedBorderColor: const Color(0xFFE5E7EB),
                              prefixIcon: Icon(Icons.lock_outline, color: R.color.neutralGray),
                              validator: _validatePassword,
                            ),
                            SizedBox(height: 24.h),

                            // ── Register button ──
                            Obx(() => WButton(
                              label: 'Continue',
                              isLoading: _auth.loading.value,
                              onPressed: _auth.loading.value ? null : _onRegister,
                              height: 52.h,
                              radius: 12,
                              buttonColor: R.color.mintGreen,
                              textColor: R.color.white,
                              fontSize: 16.sp,
                              fontWeight: FontWeight.w600,
                              decorationType: DecorationType.solid,
                            )),
                            SizedBox(height: 24.h),

                            // ── Login link ──
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                WText(text: 'Already have an account? ', fontSize: 12.sp, fontWeight: FontWeight.w400, color: R.color.neutralGray),
                                GestureDetector(
                                  onTap: () => Get.to(() => const LoginScreen()),
                                  child: WText(text: 'Log in', fontSize: 12.sp, color: R.color.mintGreen),
                                ),
                              ],
                            ),
                            SizedBox(height: 30.h),
                          ],
                        ),
                      ),
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

}
