import 'package:evcharg/feature/Auth/model/user_model.dart';
import 'package:get/get.dart' hide FormData, MultipartFile;

import '../../../../core/service/api_service/api_service.dart';
import '../../../../core/service/shared_preferance/shared_prefarance.dart';

// ── AuthController ────────────────────────────────────────────────────────────
class AuthController extends GetxController {
  final _net = NetworkService().client;
  final _prefs = SharedPrefs();
  // Shared across controller instances: whichever sign-in or OTP verification
  // starts most recently is the only attempt permitted to publish credentials.
  static int _credentialAttempt = 0;

  Rxn<UserModel> user = Rxn<UserModel>();
  RxBool loading = false.obs;
  RxBool resendLoading = false.obs;

  // pending email used between Login → VerifyOTP
  String pendingEmail = '';

  // ── REGISTER ────────────────────────────────────────────────────────────────
  /// POST /api/v1/Register
  /// body: { fullName, phone, email, password }
  /// response: { status: "Success", message: "OTP has been sent to your email!" }
  Future<bool> register(
      String name, String email, String phone, String password) async {
    loading.value = true;

    final res = await _net.postRequest(
      '/api/v1/Register',
      body: {
        'fullName': name,
        'phone': phone,
        'email': email,
        'password': password,
      },
    );

    loading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      pendingEmail = email;
      Get.snackbar(
        'OTP Sent',
        res.responseData?['message']?.toString() ??
            'OTP has been sent to your email!',
        snackPosition: SnackPosition.TOP,
      );
      return true;
    } else {
      Get.snackbar(
        'Registration Failed',
        res.errorMessage ?? 'Something went wrong',
        snackPosition: SnackPosition.TOP,
      );
      return false;
    }
  }

  // ── VERIFY OTP ───────────────────────────────────────────────────────────────
  /// POST /api/v1/VerifyOTP
  /// body: { email, otp }
  /// response: { status: "Success", message: "...", token: "..." }
  Future<bool> verifyOtp(String email, String otp) async {
    final attempt = ++_credentialAttempt;
    loading.value = true;
    try {
      // Authentication is a new identity boundary. Clear the prior token
      // first; leave it blocked if secure deletion cannot be confirmed.
      await _prefs.clear();
      if (attempt != _credentialAttempt) return false;
      final res = await _net.postRequest(
        '/api/v1/VerifyOTP',
        body: {'email': email, 'otp': otp},
      );
      if (attempt != _credentialAttempt) return false;
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        final token = res.responseData?['token']?.toString();
        if (token == null || token.trim().isEmpty) {
          Get.snackbar('Verification incomplete', 'The server did not return a valid session.',
              snackPosition: SnackPosition.TOP);
          return false;
        }
        await _prefs.saveToken(token);
        if (attempt != _credentialAttempt) return false;
        Get.snackbar(
          'Success',
          res.responseData?['message']?.toString() ?? 'OTP verified successfully',
          snackPosition: SnackPosition.TOP,
        );
        return true;
      }
      Get.snackbar(
        'Invalid OTP',
        res.errorMessage ?? 'The OTP you entered is incorrect',
        snackPosition: SnackPosition.TOP,
      );
      return false;
    } catch (_) {
      if (attempt == _credentialAttempt) {
        Get.snackbar('Verification unavailable', 'Could not safely save this session.',
            snackPosition: SnackPosition.TOP);
      }
      return false;
    } finally {
      if (attempt == _credentialAttempt) loading.value = false;
    }
  }

  // ── LOGIN ────────────────────────────────────────────────────────────────────
  /// POST /api/v1/Login
  /// body: { email, password }
  /// response: { status, message, token, userId, name, email }
  Future<bool> login(String email, String password) async {
    final attempt = ++_credentialAttempt;
    loading.value = true;
    try {
      // Supersede earlier login responses and invalidate any old cached
      // account before the new request. A failed login stays logged out.
      await _prefs.clear();
      if (attempt != _credentialAttempt) return false;
      final res = await _net.postRequest(
        '/api/v1/Login',
        body: {'email': email, 'password': password},
      );
      if (attempt != _credentialAttempt) return false;
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        final token = res.responseData?['token']?.toString();
        if (token == null || token.trim().isEmpty) {
          Get.snackbar('Login incomplete', 'The server did not return a valid session.',
              snackPosition: SnackPosition.TOP);
          return false;
        }
        await _prefs.saveToken(token);
        if (attempt != _credentialAttempt) return false;
        final userData = <String, dynamic>{
          'fullName': res.responseData?['name']?.toString(),
          'email': res.responseData?['email']?.toString(),
          'userId': res.responseData?['userId']?.toString(),
        };
        await _prefs.saveUser(userData);
        if (attempt != _credentialAttempt) return false;
        user.value = UserModel.fromJson(userData);
        pendingEmail = email;
        Get.snackbar(
          'Success',
          res.responseData?['message']?.toString() ?? 'Login successful',
          snackPosition: SnackPosition.TOP,
        );
        return true;
      }
      Get.snackbar(
        'Login Failed',
        res.errorMessage ?? 'Invalid credentials',
        snackPosition: SnackPosition.TOP,
      );
      return false;
    } catch (_) {
      if (attempt == _credentialAttempt) {
        Get.snackbar('Sign-in unavailable', 'Could not safely save this session.',
            snackPosition: SnackPosition.TOP);
      }
      return false;
    } finally {
      if (attempt == _credentialAttempt) loading.value = false;
    }
  }

  // ── RESEND OTP ───────────────────────────────────────────────────────────────
  /// POST /api/v1/ResendOTP
  /// body: { email }
  /// response: { status: "Success", message: "New OTP has been sent to your email!" }
  Future<void> resendOtp(String email) async {
    resendLoading.value = true;
    final res = await _net.postRequest(
      '/api/v1/ResendOTP',
      body: {'email': email},
    );
    resendLoading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      Get.snackbar(
        'OTP Resent',
        res.responseData?['message']?.toString() ??
            'New OTP has been sent to your email!',
        snackPosition: SnackPosition.TOP,
      );
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to resend OTP',
          snackPosition: SnackPosition.TOP);
    }
  }

  // ── FORGOT PASSWORD ──────────────────────────────────────────────────────────
  /// POST /api/v1/ForgotPassword
  /// body: { email }
  /// response: { status: "Success", message: "OTP has been sent to your email!" }
  Future<bool> forgotPassword(String email) async {
    loading.value = true;
    final res = await _net.postRequest(
      '/api/v1/ForgotPassword',
      body: {'email': email},
    );
    loading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      pendingEmail = email;
      Get.snackbar(
        'OTP Sent',
        res.responseData?['message']?.toString() ??
            'OTP has been sent to your email!',
        snackPosition: SnackPosition.TOP,
      );
      return true;
    } else {
      Get.snackbar(
        'Error',
        res.errorMessage ?? 'Failed to send OTP',
        snackPosition: SnackPosition.TOP,
      );
      return false;
    }
  }

  // ── VERIFY FORGOT OTP ────────────────────────────────────────────────────────
  /// POST /api/v1/VerifyForgotOTP
  /// body: { email, otp }
  /// response: { status: "Success", message: "OTP verified. You can now reset your password." }
  Future<bool> verifyForgotOtp(String email, String otp) async {
    loading.value = true;
    final res = await _net.postRequest(
      '/api/v1/VerifyForgotOTP',
      body: {'email': email, 'otp': otp},
    );
    loading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      Get.snackbar(
        'Verified',
        res.responseData?['message']?.toString() ??
            'OTP verified. You can now reset your password.',
        snackPosition: SnackPosition.TOP,
      );
      return true;
    } else {
      Get.snackbar(
        'Invalid OTP',
        res.errorMessage ?? 'The OTP you entered is incorrect',
        snackPosition: SnackPosition.TOP,
      );
      return false;
    }
  }

  // ── RESET PASSWORD ───────────────────────────────────────────────────────────
  /// POST /api/v1/ResetPassword
  /// body: { email, newPassword, confirmPassword }
  /// response: { status: "Success", message: "Password reset successfully. Please login." }
  Future<bool> resetPassword(
      String email, String newPassword, String confirmPassword) async {
    loading.value = true;
    final res = await _net.postRequest(
      '/api/v1/ResetPassword',
      body: {
        'email': email,
        'newPassword': newPassword,
        'confirmPassword': confirmPassword,
      },
    );
    loading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      Get.snackbar(
        'Success',
        res.responseData?['message']?.toString() ??
            'Password reset successfully. Please login.',
        snackPosition: SnackPosition.TOP,
      );
      return true;
    } else {
      Get.snackbar(
        'Error',
        res.errorMessage ?? 'Failed to reset password',
        snackPosition: SnackPosition.TOP,
      );
      return false;
    }
  }

  // ── INIT SESSION (auto-login if token exists) ─────────────────────────────
  /// Called from SplashScreen — if token exists, skip auth and go to main screen
  Future<bool> initSession() async {
    final token = await _prefs.getToken();
    return token != null && token.isNotEmpty;
  }

}