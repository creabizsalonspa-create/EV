import 'dart:async';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';
import 'package:evcharg/feature/Auth/UI/screen/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class VerifyOtpScreen extends StatefulWidget {
  final String email;

  /// true = came from Register → go to VehicleModelScreen after verify
  /// false = came from Login → go to MainScreen after verify
  final bool fromRegister;

  const VerifyOtpScreen({
    super.key,
    this.email = '',
    this.fromRegister = false,
  });

  @override
  State<VerifyOtpScreen> createState() => _VerifyOtpScreenState();
}

class _VerifyOtpScreenState extends State<VerifyOtpScreen> {
  // 6 boxes for 6-digit OTP
  final List<TextEditingController> _controllers =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _focusNodes = List.generate(6, (_) => FocusNode());

  int _secondsLeft = 90;
  Timer? _timer;
  late final AuthController _auth;

  @override
  void initState() {
    super.initState();
    _auth = Get.find<AuthController>();
    _startTimer();
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_secondsLeft > 0) {
        setState(() => _secondsLeft--);
      } else {
        t.cancel();
      }
    });
  }

  String get _timerText {
    final m = _secondsLeft ~/ 60;
    final s = _secondsLeft % 60;
    return '$m:${s.toString().padLeft(2, '0')}';
  }

  String get _enteredOtp => _controllers.map((c) => c.text).join();

  @override
  void dispose() {
    _timer?.cancel();
    for (final c in _controllers) { c.dispose(); }
    for (final f in _focusNodes) { f.dispose(); }
    super.dispose();
  }

  Future<void> _onVerify() async {
    final otp = _enteredOtp;
    if (otp.length < 6) {
      Get.snackbar('Invalid', 'Please enter the full 6-digit OTP',
          snackPosition: SnackPosition.TOP);
      return;
    }
    final email = widget.email.isNotEmpty ? widget.email : _auth.pendingEmail;
    final success = await _auth.verifyOtp(email, otp);
    if (success) {
      Get.offAll(() => const LoginScreen());
    }
  }

  Future<void> _onResend() async {
    final email = widget.email.isNotEmpty ? widget.email : _auth.pendingEmail;
    await _auth.resendOtp(email);
    setState(() => _secondsLeft = 90);
    _startTimer();
    // Clear OTP boxes
    for (final c in _controllers) { c.clear(); }
    _focusNodes.first.requestFocus();
  }

  @override
  Widget build(BuildContext context) {
    final displayEmail =
        widget.email.isNotEmpty ? widget.email : _auth.pendingEmail;

    return Scaffold(
      backgroundColor: R.color.white,
      body: SafeArea(
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: 24.w),
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

              SizedBox(height: 48.h),

              // ── Title ──
              WText(
                text: 'OTP Verification',
                fontSize: 22.sp,
                fontWeight: FontWeight.w700,
                color: R.color.darkNavy,
                textAlign: TextAlign.center,
              ),

              SizedBox(height: 12.h),

              WText(
                text: 'An authentication code has been sent to',
                fontSize: 14.sp,
                fontWeight: FontWeight.w400,
                color: R.color.neutralGray,
                textAlign: TextAlign.center,
              ),
              SizedBox(height: 4.h),
              WText(
                text: displayEmail,
                fontSize: 14.sp,
                fontWeight: FontWeight.w600,
                color: R.color.darkNavy,
                textAlign: TextAlign.center,
              ),

              SizedBox(height: 40.h),

              // ── 6 OTP boxes ──
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(6, (index) {
                  return Padding(
                    padding: EdgeInsets.symmetric(horizontal: 5.w),
                    child: _OtpBox(
                      controller: _controllers[index],
                      focusNode: _focusNodes[index],
                      onChanged: (val) {
                        if (val.isNotEmpty && index < 5) {
                          _focusNodes[index + 1].requestFocus();
                        } else if (val.isEmpty && index > 0) {
                          _focusNodes[index - 1].requestFocus();
                        }
                      },
                    ),
                  );
                }),
              ),

              SizedBox(height: 32.h),

              // ── Timer / Resend ──
              if (_secondsLeft > 0)
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    WText(
                      text: "Didn't receive code? ",
                      fontSize: 13.sp,
                      fontWeight: FontWeight.w400,
                      color: R.color.neutralGray,
                    ),
                    WText(
                      text: _timerText,
                      fontSize: 13.sp,
                      fontWeight: FontWeight.w600,
                      color: R.color.darkNavy,
                    ),
                  ],
                )
              else
                Obx(() => _auth.resendLoading.value
                    ? SizedBox(
                        width: 20.w,
                        height: 20.w,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: R.color.mintGreen,
                        ),
                      )
                    : GestureDetector(
                        onTap: _onResend,
                        child: WText(
                          text: 'Resend Code',
                          fontSize: 13.sp,
                          fontWeight: FontWeight.w600,
                          color: R.color.mintGreen,
                        ),
                      ),
                ),

              const Spacer(),

              // ── Verify Code button ──
              Obx(() => WButton(
                label: 'Verify Code',
                isLoading: _auth.loading.value,
                onPressed: _auth.loading.value ? null : _onVerify,
                height: 52.h,
                radius: 12,
                buttonColor: R.color.mintGreen,
                textColor: R.color.white,
                fontSize: 16.sp,
                fontWeight: FontWeight.w600,
                decorationType: DecorationType.solid,
              )),

              SizedBox(height: 32.h),
            ],
          ),
        ),
      ),
    );
  }
}

class _OtpBox extends StatelessWidget {
  final TextEditingController controller;
  final FocusNode focusNode;
  final ValueChanged<String> onChanged;

  const _OtpBox({
    required this.controller,
    required this.focusNode,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 46.w,
      height: 52.h,
      decoration: BoxDecoration(
        color: R.color.white,
        borderRadius: BorderRadius.circular(10.r),
        border: Border.all(color: R.color.lightGray, width: 1.5),
      ),
      child: TextFormField(
        controller: controller,
        focusNode: focusNode,
        textAlign: TextAlign.center,
        keyboardType: TextInputType.number,
        maxLength: 1,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        style: TextStyle(
          fontSize: 20.sp,
          fontWeight: FontWeight.w700,
          color: R.color.darkNavy,
        ),
        decoration: const InputDecoration(
          counterText: '',
          border: InputBorder.none,
          enabledBorder: InputBorder.none,
          focusedBorder: InputBorder.none,
        ),
        onChanged: onChanged,
      ),
    );
  }
}
