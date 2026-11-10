import 'package:evcharg/feature/Auth/model/user_model.dart';
import 'package:get/get.dart' hide FormData, MultipartFile;

import '../../../../core/service/api_service/api_service.dart';
import '../../../../core/service/shared_preferance/shared_prefarance.dart';
import '../../../../core/service/network/network_client.dart';

// ── AuthController ────────────────────────────────────────────────────────────
class AuthController extends GetxController {
  final NetworkClient _net;
  final SharedPrefs _prefs;
  final void Function(String title, String message)? _authFeedback;

  /// Real UI uses GetX; race tests can inject a notification sink without
  /// starting overlay timers or depending on a mounted navigation tree.
  AuthController({
    NetworkClient? client,
    SharedPrefs? prefs,
    void Function(String title, String message)? authFeedback,
  }) : _net = client ?? NetworkService().client,
       _prefs = prefs ?? SharedPrefs(),
       _authFeedback = authFeedback;

  void _notifyAuthFeedback(String title, String message) {
    if (_authFeedback != null) {
      _authFeedback(title, message);
      return;
    }
    Get.snackbar(title, message, snackPosition: SnackPosition.TOP);
  }

  // Error feedback must not turn a handled authentication/storage failure
  // into an uncaught exception when no navigator/overlay is mounted.
  void _notifyAuthFailure(String title, String message) {
    try {
      _notifyAuthFeedback(title, message);
    } catch (_) {
      // Secure credential cleanup has already been attempted. UI feedback is
      // best-effort; the caller must still receive a failed login result.
    }
  }

  // Shared across controller instances: whichever sign-in or OTP verification
  // starts most recently is the only attempt permitted to publish credentials.
  static int _credentialAttempt = 0;
  // Credential authority is global, but each controller owns its loading UI.
  // Superseding an attempt in another controller must not strand this spinner.
  int _localCredentialAttempt = 0;
  int _resendAttempt = 0;

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
    String name,
    String email,
    String phone,
    String password,
  ) => _runNonCredentialForm(
    path: '/api/v1/Register',
    body: {
      'fullName': name,
      'phone': phone,
      'email': email,
      'password': password,
    },
    successTitle: 'OTP Sent',
    successMessage: 'OTP has been sent to your email!',
    failureTitle: 'Registration Failed',
    failureMessage: 'Something went wrong',
    pendingEmailOnSuccess: email,
  );

  // Non-credential forms share the same local loading indicator as login/OTP.
  // Do not let an older response overwrite pendingEmail, show stale feedback,
  // or dismiss the spinner for a newer attempt. No bearer is saved here.
  Future<bool> _runNonCredentialForm({
    required String path,
    required Map<String, dynamic> body,
    required String successTitle,
    required String successMessage,
    required String failureTitle,
    required String failureMessage,
    String? pendingEmailOnSuccess,
  }) async {
    final attempt = ++_localCredentialAttempt;
    loading.value = true;
    try {
      final res = await _net.postRequest(path, body: body);
      if (attempt != _localCredentialAttempt) return false;
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        if (pendingEmailOnSuccess != null) pendingEmail = pendingEmailOnSuccess;
        _notifyAuthFailure(
          successTitle,
          res.responseData?['message']?.toString() ?? successMessage,
        );
        return true;
      }
      _notifyAuthFailure(failureTitle, res.errorMessage ?? failureMessage);
      return false;
    } catch (_) {
      if (attempt == _localCredentialAttempt) {
        _notifyAuthFailure(failureTitle, failureMessage);
      }
      return false;
    } finally {
      if (attempt == _localCredentialAttempt) loading.value = false;
    }
  }

  // ── VERIFY OTP ───────────────────────────────────────────────────────────────
  /// POST /api/v1/VerifyOTP
  /// body: { email, otp }
  /// response: { status: "Success", message: "...", token: "..." }
  Future<bool> verifyOtp(String email, String otp) async {
    final attempt = ++_credentialAttempt;
    final localAttempt = ++_localCredentialAttempt;
    int? committedGeneration;
    String? committedToken;
    loading.value = true;
    try {
      // Authentication is a new identity boundary. Clear the prior token
      // first; leave it blocked if secure deletion cannot be confirmed.
      final clearing = _prefs.clear();
      final clearedGeneration = SharedPrefs.credentialGeneration;
      await clearing;
      if (attempt != _credentialAttempt ||
          clearedGeneration != SharedPrefs.credentialGeneration) {
        return false;
      }
      final res = await _net.postRequest(
        '/api/v1/VerifyOTP',
        body: {'email': email, 'otp': otp},
      );
      if (attempt != _credentialAttempt ||
          clearedGeneration != SharedPrefs.credentialGeneration) {
        return false;
      }
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        final token = res.responseData?['token']?.toString();
        if (token == null || token.trim().isEmpty) {
          _notifyAuthFeedback(
            'Verification incomplete',
            'The server did not return a valid session.',
          );
          return false;
        }
        final saving = _prefs.saveToken(token);
        final savedGeneration = SharedPrefs.credentialGeneration;
        await saving;
        if (attempt != _credentialAttempt ||
            savedGeneration != SharedPrefs.credentialGeneration) {
          return false;
        }
        committedGeneration = savedGeneration;
        committedToken = token;
        _notifyAuthFeedback(
          'Success',
          res.responseData?['message']?.toString() ??
              'OTP verified successfully',
        );
        return true;
      }
      _notifyAuthFeedback(
        'Invalid OTP',
        res.errorMessage ?? 'The OTP you entered is incorrect',
      );
      return false;
    } catch (_) {
      if (attempt == _credentialAttempt) {
        if (committedGeneration != null) {
          try {
            await _prefs.clearIfCurrent(
              expectedGeneration: committedGeneration,
              expectedToken: committedToken,
            );
          } catch (_) {
            // This failed completion must never surface the committed token;
            // clear() immediately invalidates memory and persists a vault
            // block before attempting platform credential deletion.
          }
        }
        if (attempt == _credentialAttempt) {
          _notifyAuthFailure(
            'Verification unavailable',
            'Could not safely save this session.',
          );
        }
      }
      return false;
    } finally {
      if (localAttempt == _localCredentialAttempt) loading.value = false;
    }
  }

  // ── LOGIN ────────────────────────────────────────────────────────────────────
  /// POST /api/v1/Login
  /// body: { email, password }
  /// response: { status, message, token, userId, name, email }
  Future<bool> login(String email, String password) async {
    final attempt = ++_credentialAttempt;
    final localAttempt = ++_localCredentialAttempt;
    // Profile persistence and user-model decoding are part of login's
    // completion boundary. If either fails after vault commit, revoke only
    // this attempt's token before reporting failure.
    int? committedGeneration;
    String? committedToken;
    user.value = null;
    loading.value = true;
    try {
      // Supersede earlier login responses and invalidate any old cached
      // account before the new request. A failed login stays logged out.
      final clearing = _prefs.clear();
      final clearedGeneration = SharedPrefs.credentialGeneration;
      await clearing;
      if (attempt != _credentialAttempt ||
          clearedGeneration != SharedPrefs.credentialGeneration) {
        return false;
      }
      final res = await _net.postRequest(
        '/api/v1/Login',
        body: {'email': email, 'password': password},
      );
      if (attempt != _credentialAttempt ||
          clearedGeneration != SharedPrefs.credentialGeneration) {
        return false;
      }
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        final token = res.responseData?['token']?.toString();
        if (token == null || token.trim().isEmpty) {
          _notifyAuthFeedback(
            'Login incomplete',
            'The server did not return a valid session.',
          );
          return false;
        }
        final saving = _prefs.saveToken(token);
        final savedGeneration = SharedPrefs.credentialGeneration;
        await saving;
        if (attempt != _credentialAttempt ||
            savedGeneration != SharedPrefs.credentialGeneration) {
          return false;
        }
        committedGeneration = savedGeneration;
        committedToken = token;
        final userData = <String, dynamic>{
          'fullName': res.responseData?['name']?.toString(),
          'email': res.responseData?['email']?.toString(),
          'userId': res.responseData?['userId']?.toString(),
        };
        await _prefs.saveUser(userData);
        if (attempt != _credentialAttempt ||
            savedGeneration != SharedPrefs.credentialGeneration) {
          return false;
        }
        user.value = UserModel.fromJson(userData);
        pendingEmail = email;
        _notifyAuthFeedback(
          'Success',
          res.responseData?['message']?.toString() ?? 'Login successful',
        );
        return true;
      }
      _notifyAuthFeedback(
        'Login Failed',
        res.errorMessage ?? 'Invalid credentials',
      );
      return false;
    } catch (_) {
      if (attempt == _credentialAttempt) {
        if (committedGeneration != null) {
          try {
            await _prefs.clearIfCurrent(
              expectedGeneration: committedGeneration,
              expectedToken: committedToken,
            );
          } catch (_) {
            // clear() invalidates the in-memory token before the platform
            // deletion and persists its fail-closed marker when available.
            // A newer login must never be revoked by this old failure.
          }
        }
        // Another controller may have begun a new identity while Keychain
        // deletion was suspended; suppress obsolete UI error notifications.
        if (attempt == _credentialAttempt) {
          user.value = null;
          _notifyAuthFailure(
            'Sign-in unavailable',
            'Could not safely save this session.',
          );
        }
      }
      return false;
    } finally {
      if (localAttempt == _localCredentialAttempt) loading.value = false;
    }
  }

  // ── RESEND OTP ───────────────────────────────────────────────────────────────
  /// POST /api/v1/ResendOTP
  /// body: { email }
  /// response: { status: "Success", message: "New OTP has been sent to your email!" }
  Future<void> resendOtp(String email) async {
    final attempt = ++_resendAttempt;
    resendLoading.value = true;
    try {
      final res = await _net.postRequest(
        '/api/v1/ResendOTP',
        body: {'email': email},
      );
      if (attempt != _resendAttempt) return;
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        _notifyAuthFailure(
          'OTP Resent',
          res.responseData?['message']?.toString() ??
              'New OTP has been sent to your email!',
        );
      } else {
        _notifyAuthFailure('Error', res.errorMessage ?? 'Failed to resend OTP');
      }
    } catch (_) {
      if (attempt == _resendAttempt) {
        _notifyAuthFailure('Error', 'Failed to resend OTP');
      }
    } finally {
      if (attempt == _resendAttempt) resendLoading.value = false;
    }
  }

  // ── FORGOT PASSWORD ──────────────────────────────────────────────────────────
  /// POST /api/v1/ForgotPassword
  /// body: { email }
  /// response: { status: "Success", message: "OTP has been sent to your email!" }
  Future<bool> forgotPassword(String email) => _runNonCredentialForm(
    path: '/api/v1/ForgotPassword',
    body: {'email': email},
    successTitle: 'OTP Sent',
    successMessage: 'OTP has been sent to your email!',
    failureTitle: 'Error',
    failureMessage: 'Failed to send OTP',
    pendingEmailOnSuccess: email,
  );

  // ── VERIFY FORGOT OTP ────────────────────────────────────────────────────────
  /// POST /api/v1/VerifyForgotOTP
  Future<bool> verifyForgotOtp(String email, String otp) =>
      _runNonCredentialForm(
        path: '/api/v1/VerifyForgotOTP',
        body: {'email': email, 'otp': otp},
        successTitle: 'Verified',
        successMessage: 'OTP verified. You can now reset your password.',
        failureTitle: 'Invalid OTP',
        failureMessage: 'The OTP you entered is incorrect',
      );

  // ── RESET PASSWORD ───────────────────────────────────────────────────────────
  /// POST /api/v1/ResetPassword
  Future<bool> resetPassword(
    String email,
    String newPassword,
    String confirmPassword,
  ) => _runNonCredentialForm(
    path: '/api/v1/ResetPassword',
    body: {
      'email': email,
      'newPassword': newPassword,
      'confirmPassword': confirmPassword,
    },
    successTitle: 'Success',
    successMessage: 'Password reset successfully. Please login.',
    failureTitle: 'Error',
    failureMessage: 'Failed to reset password',
  );

  // ── INIT SESSION (auto-login if token exists) ─────────────────────────────
  /// Called from SplashScreen — if token exists, skip auth and go to main screen
  Future<bool> initSession() async {
    final token = await _prefs.getToken();
    return token != null && token.isNotEmpty;
  }
}
