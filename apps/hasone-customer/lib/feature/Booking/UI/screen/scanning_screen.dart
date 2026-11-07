import 'package:evcharg/app/assets.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/charging_screen.dart';
import 'package:evcharg/feature/Booking/model/charging_session_model.dart'
    as session_model;
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

class ScanningScreen extends StatefulWidget {
  final String bookingId;
  final String sessionId;

  const ScanningScreen({
    super.key,
    required this.bookingId,
    required this.sessionId,
  });

  @override
  State<ScanningScreen> createState() => _ScanningScreenState();
}

class _ScanningScreenState extends State<ScanningScreen> {
  late final AllBookingController _controller;
  late final MobileScannerController _scannerController;

  bool _isCallingApi = false;
  bool _hasScanned = false; // prevent multiple scans

  @override
  void initState() {
    super.initState();
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());

    _scannerController = MobileScannerController(
      detectionSpeed: DetectionSpeed.normal,
      facing: CameraFacing.back,
    );
  }

  @override
  void dispose() {
    _scannerController.dispose();
    super.dispose();
  }

  /// Called when QR code is detected by the scanner
  void _onDetect(BarcodeCapture capture) {
    if (_hasScanned || _isCallingApi) return;

    final barcodes = capture.barcodes;
    if (barcodes.isEmpty) return;

    final code = barcodes.first.rawValue;
    if (code == null || code.isEmpty) return;

    // We got a scan! Now call the API
    _hasScanned = true;
    _scannerController.stop(); // stop camera
    _startChargingSession();
  }

  /// Calls POST /api/v1/ChargingSession/{sessionId}/start
  /// On success → shows the success bottom sheet with real data
  /// On failure (e.g. "too early") → shows a snackbar error and resumes scanner
  Future<void> _startChargingSession() async {
    if (_isCallingApi) return;
    setState(() => _isCallingApi = true);

    final result =
        await _controller.startChargingSession(widget.sessionId);

    if (!mounted) return;
    setState(() => _isCallingApi = false);

    if (result != null) {
      _showSuccessSheet(result);
    } else {
      // Show error and let user scan again
      final error =
          _controller.chargingSessionError.value ?? 'Failed to start charging.';
      Get.snackbar(
        'Error',
        error,
        snackPosition: SnackPosition.BOTTOM,
        backgroundColor: Colors.red.shade100,
        colorText: Colors.red.shade900,
        duration: const Duration(seconds: 4),
        margin: EdgeInsets.all(16.w),
      );
      // Resume scanner so user can try again
      _hasScanned = false;
      _scannerController.start();
    }
  }

  void _showSuccessSheet(session_model.Data sessionData) {
    final popupTitle =
        sessionData.popupTitle ?? 'Charging Started Successfully';
    final confirmMsg = sessionData.confirmMessage ??
        'Are you sure you want to Start charging?';
    final detailMsg =
        'Your car is ${sessionData.batteryPercent ?? 0}% charged and has used ${sessionData.kwhUsedSoFar ?? 0} kwh so far.';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      isDismissible: false,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        width: 390.w,
        height: 456.h,
        padding: EdgeInsets.only(
          top: 32.h,
          right: 16.w,
          bottom: 32.h,
          left: 16.w,
        ),
        decoration: BoxDecoration(
          color: R.color.white,
          borderRadius: BorderRadius.only(
            topLeft: Radius.circular(32.r),
            topRight: Radius.circular(32.r),
          ),
        ),
        child: Column(
          children: [
            // ── Title from API ──
            WText(
              text: popupTitle,
              color: R.color.darkNavy,
              fontSize: 18.sp,
              fontWeight: FontWeight.w700,
              textAlign: TextAlign.center,
            ),
            SizedBox(height: 16.h),

            // ── Success image ──
            Image.asset(
              Assets.success,
              width: 200.w,
              height: 200.h,
              fit: BoxFit.contain,
            ),
            SizedBox(height: 16.h),

            // ── Confirm text from API ──
            WText(
              text: confirmMsg,
              color: R.color.darkNavy,
              fontSize: 14.sp,
              fontWeight: FontWeight.w600,
              textAlign: TextAlign.center,
            ),
            SizedBox(height: 8.h),
            WText(
              text: detailMsg,
              color: R.color.neutralGray,
              fontSize: 12.sp,
              fontWeight: FontWeight.w400,
              textAlign: TextAlign.center,
            ),
            const Spacer(),

            // ── Buttons ──
            Row(
              children: [
                Expanded(
                  child: WButton(
                    label: 'Start Charging',
                    height: 48.h,
                    radius: 8,
                    buttonColor: R.color.mintGreen,
                    textColor: R.color.white,
                    fontSize: 14.sp,
                    fontWeight: FontWeight.w600,
                    decorationType: DecorationType.solid,
                    onPressed: () {
                      Navigator.pop(ctx);
                      Get.off(() => ChargingScreen(
                            bookingId: widget.bookingId,
                            sessionId: sessionData.sessionId ?? widget.sessionId,
                            initialBatteryPercent:
                                sessionData.batteryPercent ?? 0,
                            initialTimeRemainingMs:
                                sessionData.timeRemainingMs ?? 0,
                            chargingDuration:
                                sessionData.chargingDuration ?? '',
                          ));
                    },
                  ),
                ),
                SizedBox(width: 12.w),
                // Expanded(
                //   child: WButton(
                //     label: 'Stop Charging',
                //     height: 48.h,
                //     radius: 8,
                //     buttonColor: R.color.mintGreen,
                //     textColor: R.color.mintGreen,
                //     fontSize: 14.sp,
                //     fontWeight: FontWeight.w600,
                //     decorationType: DecorationType.stroke,
                //     onPressed: () {
                //       Navigator.pop(ctx);
                //       Get.back();
                //     },
                //   ),
                // ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final screenW = MediaQuery.of(context).size.width;
    // Scan frame rect (matches green corners position)
    final scanLeft = 24.w;
    final scanTop = 160.h;
    final scanWidth = screenW - 48.w;
    final scanHeight = 280.h;

    return Scaffold(
      backgroundColor: Colors.black,
      body: Column(
        children: [
          // ── Top green bar ──
          Container(
            width: double.infinity,
            height: 90.h,
            padding: EdgeInsets.symmetric(horizontal: 16.w),
            decoration: const BoxDecoration(color: Color(0xFF7CFFBC)),
            child: SafeArea(
              bottom: false,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  GestureDetector(
                    onTap: () => Get.back(),
                    child: Icon(Icons.arrow_back,
                        color: R.color.darkNavy, size: 22.sp),
                  ),
                  Expanded(
                    child: Center(
                      child: WText(
                        text: 'Scanning',
                        color: R.color.darkNavy,
                        fontSize: 18.sp,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  SizedBox(width: 22.w),
                ],
              ),
            ),
          ),

          // ── Camera area (takes remaining space minus bottom bar) ──
          Expanded(
            child: Stack(
              children: [
                // Camera preview fills entire area
                SizedBox.expand(
                  child: MobileScanner(
                    controller: _scannerController,
                    onDetect: _onDetect,
                    fit: BoxFit.cover,
                  ),
                ),

                // Dark overlay with transparent cutout
                SizedBox.expand(
                  child: CustomPaint(
                    size: Size.infinite,
                    painter: _ScanOverlayPainter(
                      scanRect: Rect.fromLTWH(
                        scanLeft,
                        scanTop - 90.h, // offset because top bar is outside this stack
                        scanWidth,
                        scanHeight,
                      ),
                    ),
                  ),
                ),

                // ── QR Code label ──
                Positioned(
                  top: 130.h - 90.h - 10.h,
                  left: 0,
                  right: 0,
                  child: Center(
                    child: WText(
                      text: 'QR Code',
                      color: R.color.white,
                      fontSize: 16.sp,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),

                // ── Green corner brackets ──
                Positioned(
                  top: scanTop - 90.h,
                  left: scanLeft,
                  child: SizedBox(
                    width: scanWidth,
                    height: scanHeight,
                    child: CustomPaint(
                      size: Size(scanWidth, scanHeight),
                      painter: _ScannerCornerPainter(color: R.color.mintGreen),
                    ),
                  ),
                ),

                // ── Loading overlay when calling API ──
                if (_isCallingApi)
                  Positioned.fill(
                    child: Container(
                      color: Colors.black54,
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            CircularProgressIndicator(
                                color: R.color.mintGreen),
                            SizedBox(height: 16.h),
                            WText(
                              text: 'Starting charging session...',
                              color: R.color.white,
                              fontSize: 14.sp,
                              fontWeight: FontWeight.w500,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),

          // ── Bottom green bar ──
          Container(
            color: R.color.mintGreen,
            padding: EdgeInsets.only(
                left: 24.w, right: 24.w, top: 20.h, bottom: 24.h),
            child: SafeArea(
              top: false,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  WText(
                    text: 'Place your QR Code within the frame and take a photo',
                    color: R.color.white,
                    fontSize: 13.sp,
                    fontWeight: FontWeight.w400,
                    textAlign: TextAlign.center,
                  ),
                  SizedBox(height: 20.h),
                  // ── Torch toggle button ──
                  GestureDetector(
                    onTap: () => _scannerController.toggleTorch(),
                    child: Container(
                      width: 60.w,
                      height: 60.w,
                      decoration: BoxDecoration(
                        color: R.color.white,
                        shape: BoxShape.circle,
                        border: Border.all(color: R.color.white, width: 3),
                      ),
                      child: Icon(Icons.flashlight_on,
                          color: R.color.mintGreen, size: 28.sp),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Overlay painter with transparent cutout ──
class _ScanOverlayPainter extends CustomPainter {
  final Rect scanRect;
  const _ScanOverlayPainter({required this.scanRect});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = const Color(0x99000000); // ~60% black

    // Full screen path
    final fullPath = Path()
      ..addRect(Rect.fromLTWH(0, 0, size.width, size.height));
    // Transparent hole path
    final holePath = Path()
      ..addRRect(
          RRect.fromRectAndRadius(scanRect, const Radius.circular(4)));
    // Subtract hole from full
    final overlayPath =
        Path.combine(PathOperation.difference, fullPath, holePath);
    canvas.drawPath(overlayPath, paint);
  }

  @override
  bool shouldRepaint(_ScanOverlayPainter old) => old.scanRect != scanRect;
}

// ── Scanner corner painter ──
class _ScannerCornerPainter extends CustomPainter {
  final Color color;
  const _ScannerCornerPainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 3
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;

    const len = 24.0;

    // Top-left
    canvas.drawLine(Offset(0, len), const Offset(0, 0), paint);
    canvas.drawLine(const Offset(0, 0), Offset(len, 0), paint);
    // Top-right
    canvas.drawLine(Offset(size.width - len, 0), Offset(size.width, 0), paint);
    canvas.drawLine(Offset(size.width, 0), Offset(size.width, len), paint);
    // Bottom-left
    canvas.drawLine(
        Offset(0, size.height - len), Offset(0, size.height), paint);
    canvas.drawLine(
        Offset(0, size.height), Offset(len, size.height), paint);
    // Bottom-right
    canvas.drawLine(Offset(size.width - len, size.height),
        Offset(size.width, size.height), paint);
    canvas.drawLine(Offset(size.width, size.height - len),
        Offset(size.width, size.height), paint);
  }

  @override
  bool shouldRepaint(_ScannerCornerPainter old) => old.color != color;
}

