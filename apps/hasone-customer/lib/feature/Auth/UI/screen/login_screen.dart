import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/winput_text.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';
import 'package:evcharg/feature/Auth/UI/screen/sign_in_screen.dart';
import 'package:evcharg/feature/Auth/UI/screen/verify_otp_screen.dart';
import 'package:evcharg/feature/Auth/UI/screen/forgotpassword_screen.dart';
import 'package:evcharg/feature/main_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _emailController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  bool _rememberMe = false;

  late final AuthController _auth;

  @override
  void initState() {
    super.initState();
    _auth = Get.put(AuthController());
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
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

  Future<void> _onContinue() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final success = await _auth.login(
      _emailController.text.trim(),
      _passwordController.text,
    );
    if (success) {
      Get.offAll(() => const MainScreen());
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
                            SizedBox(height: 12.h),

                            // ── Remember Me / Forgot ──
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                GestureDetector(
                                  onTap: () => setState(() => _rememberMe = !_rememberMe),
                                  child: Row(
                                    children: [
                                      SizedBox(
                                        width: 20.w,
                                        height: 20.w,
                                        child: Checkbox(
                                          value: _rememberMe,
                                          onChanged: (v) => setState(() => _rememberMe = v ?? false),
                                          activeColor: R.color.mintGreen,
                                          shape: RoundedRectangleBorder(
                                              borderRadius: BorderRadius.circular(4.r)),
                                          side: BorderSide(color: R.color.neutralGray),
                                        ),
                                      ),
                                      SizedBox(width: 6.w),
                                      WText(text: 'Remember Me', fontSize: 13.sp, fontWeight: FontWeight.w400, color: R.color.neutralGray),
                                    ],
                                  ),
                                ),
                                GestureDetector(
                                  onTap: () => Get.to(() => const ForgotpasswordScreen()),
                                  child: WText(text: 'Forgot Password?', fontSize: 13.sp, fontWeight: FontWeight.w500, color: R.color.neutralGray),
                                ),
                              ],
                            ),
                            SizedBox(height: 24.h),

                            // ── Continue button ──
                            Obx(() => WButton(
                              label: 'Continue',
                              isLoading: _auth.loading.value,
                              onPressed: _auth.loading.value ? null : _onContinue,
                              height: 52.h,
                              radius: 12,
                              buttonColor: R.color.mintGreen,
                              textColor: R.color.white,
                              fontSize: 16.sp,
                              fontWeight: FontWeight.w600,
                              decorationType: DecorationType.solid,
                            )),
                            SizedBox(height: 24.h),


                            // ── Sign up link ──
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                WText(text: "Don't have an account? ", fontSize: 12.sp, fontWeight: FontWeight.w400, color: R.color.neutralGray),
                                GestureDetector(
                                  onTap: () => Get.to(() => const SignInScreen()),
                                  child: WText(text: 'Sign up', fontSize: 12.sp, color: R.color.mintGreen),
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
