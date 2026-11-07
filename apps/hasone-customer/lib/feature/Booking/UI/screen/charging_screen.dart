import 'dart:async';
import 'dart:ui';

import 'package:evcharg/app/assets.dart';
import 'package:evcharg/core/service/session/session.dart';
import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/utils/currency_helper.dart';
import 'package:evcharg/feature/Booking/UI/controller/all_booking_controller.dart';
import 'package:evcharg/feature/Booking/UI/screen/Charging_Stopped_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

class ChargingScreen extends StatefulWidget {
  final String bookingId;
  final String sessionId;
  final int initialBatteryPercent;
  final int initialTimeRemainingMs;
  final String chargingDuration;
  final Duration statusReadTimeout;
  final String? Function()? accountIdentity;

  const ChargingScreen({
    super.key,
    required this.bookingId,
    required this.sessionId,
    this.initialBatteryPercent = 0,
    this.initialTimeRemainingMs = 0,
    this.chargingDuration = '',
    this.statusReadTimeout = const Duration(seconds: 15),
    this.accountIdentity,
  });

  @override
  State<ChargingScreen> createState() => _ChargingScreenState();
}

class _ChargingScreenState extends State<ChargingScreen>
    with WidgetsBindingObserver {
  late final AllBookingController _controller;
  Timer? _pollTimer;

  int? _batteryPercent;
  String _timeRemaining = 'Unavailable';
  double? _kwhUsed;
  String? _costText;
  String _meterNote = 'Meter receipt time unavailable';
  String _chargingStatus = 'Source status unverified';
  bool _canStop = false;
  bool _canExtend = false;
  bool _fetching = false;
  bool _foreground = true;
  int _generation = 0, _request = 0;
  late final String? _owner;
  String _readState = 'loading';
  String? get _identity =>
      (widget.accountIdentity ?? (() => Session.accessToken))();
  bool get _sameOwner => _owner == _identity;
  String _t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  String get _recoveryNote => switch (_readState) {
    'loading' => _t('Checking source status…', 'جارٍ التحقق من حالة المصدر…'),
    'unavailable' => _t(
      'Status could not be read. Charging may still be active. Retry only checks status.',
      'تعذر قراءة الحالة. قد يستمر الشحن. إعادة المحاولة تتحقق من الحالة فقط.',
    ),
    'unverified' => _t(
      'No verified source status is available. Ask the operator to review this session.',
      'لا تتوفر حالة مصدر متحققة. اطلب من المشغل مراجعة الجلسة.',
    ),
    'account_changed' => _t(
      'Sign-in changed. Reopen this session from your bookings.',
      'تغير تسجيل الدخول. أعد فتح الجلسة من حجوزاتك.',
    ),
    _ => _t(
      'Status at last check. Refresh does not send a charger command.',
      'الحالة عند آخر تحقق. التحديث لا يرسل أمراً إلى الشاحن.',
    ),
  };

  void _clearTelemetry() {
    _batteryPercent = null;
    _timeRemaining = 'Unavailable';
    _kwhUsed = null;
    _costText = null;
    _meterNote = 'Meter receipt time unavailable';
    _chargingStatus = 'Source status unverified';
    _canStop = false;
    _canExtend = false;
  }

  @override
  void initState() {
    super.initState();
    _owner = _identity;
    _controller = Get.isRegistered<AllBookingController>()
        ? Get.find<AllBookingController>()
        : Get.put(AllBookingController());

    WidgetsBinding.instance.addObserver(this);
    _startPolling();
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _fetchStatus();
    _pollTimer = Timer.periodic(
      const Duration(seconds: 10),
      (_) => _fetchStatus(),
    );
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _generation++;
    _request++;
    _fetching = false;
    _foreground = state == AppLifecycleState.resumed;
    _pollTimer?.cancel();
    if (mounted) {
      setState(() {
        _clearTelemetry();
        _readState = 'loading';
      });
    }
    if (_foreground) _startPolling();
  }

  Future<void> _fetchStatus() async {
    if (_fetching || !_foreground || widget.sessionId.isEmpty) {
      return;
    }
    if (!_sameOwner) {
      _pollTimer?.cancel();
      setState(() {
        _clearTelemetry();
        _readState = 'account_changed';
      });
      return;
    }
    _fetching = true;
    final request = ++_request;
    setState(() {
      _readState = 'loading';
    });
    final generation = _generation;
    try {
      final result = await _controller
          .fetchChargingStatus(widget.sessionId)
          .timeout(widget.statusReadTimeout);
      if (!mounted ||
          !_foreground ||
          generation != _generation ||
          request != _request) {
        return;
      }
      setState(() {
        _clearTelemetry();
        _readState = !_sameOwner
            ? 'account_changed'
            : result == null
            ? 'unavailable'
            : 'unverified';
        if (_sameOwner &&
            result?.hasSourceProof == true &&
            result!.sessionId == widget.sessionId &&
            result.bookingId == widget.bookingId) {
          _readState = 'ready';
          _batteryPercent = result.batteryPercent;
          _kwhUsed = result.hasVerifiedMeter ? result.kwhUsed : null;
          _chargingStatus = result.chargingStatus ?? 'Source status unverified';
          final cost = result.provisionalCostFils ?? result.finalCostFils;
          if (cost != null) {
            final amount =
                '${cost ~/ 100}.${(cost % 100).toString().padLeft(2, '0')}';
            _costText =
                '${result.provisionalCostFils == null ? 'Final calculated amount' : 'Calculated so far'}: AED $amount (not collected)';
          }
          if (result.meterReceiptAgeSeconds != null) {
            _meterNote = result.meterFreshness == 'stale'
                ? 'Meter reading stale at last check (${result.meterReceiptAgeSeconds}s)'
                : 'At last check: meter received ${result.meterReceiptAgeSeconds}s ago';
          }
        }
      });
    } catch (_) {
      if (mounted &&
          _foreground &&
          generation == _generation &&
          request == _request) {
        setState(() {
          _clearTelemetry();
          _readState = _sameOwner ? 'unavailable' : 'account_changed';
        });
      }
    } finally {
      if (mounted && generation == _generation && request == _request) {
        setState(() {
          _fetching = false;
        });
      }
    }
  }

  @override
  void didUpdateWidget(covariant ChargingScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.sessionId != widget.sessionId ||
        oldWidget.bookingId != widget.bookingId) {
      _generation++;
      _request++;
      _fetching = false;
      _clearTelemetry();
      _readState = 'loading';
      if (_foreground) _startPolling();
    }
  }

  @override
  void dispose() {
    _generation++;
    _pollTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // ── Extend Session bottom sheet ──
  void _showExtendSheet() {
    // Show bottom sheet directly with fallback options
    _showExtendSheetUI([]);
  }

  void _showExtendSheetUI(List<dynamic> apiOptions) {
    // Build options list: from API or fallback
    final List<({String label, int mins, int cost})> options;
    if (apiOptions.isNotEmpty) {
      options = apiOptions
          .map<({String label, int mins, int cost})>(
            (o) => (
              label:
                  (o.label as String?) ??
                  'Extend For ${(o.mins as int?) ?? 0} Min',
              mins: (o.mins as int?) ?? 0,
              cost: (o.cost as int?) ?? 0,
            ),
          )
          .toList();
    } else {
      options = [
        (label: 'Extend For 10 Min', mins: 10, cost: 40),
        (label: 'Extend For 20 Min', mins: 20, cost: 70),
        (label: 'Extend For 30 Min', mins: 30, cost: 100),
        (label: 'Extend For 50 Min', mins: 50, cost: 120),
      ];
    }

    int selected = options.length > 2 ? 2 : 0;
    bool isLoading = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Container(
          width: 390.w,
          height: 571.h,
          padding: EdgeInsets.only(
            top: 32.h,
            right: 16.w,
            bottom: 32.h,
            left: 16.w,
          ),
          decoration: BoxDecoration(
            color: R.color.offWhite,
            borderRadius: BorderRadius.only(
              topLeft: Radius.circular(32.r),
              topRight: Radius.circular(32.r),
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              WText(
                text: 'Charging Not Complete Yet?',
                color: R.color.darkNavy,
                fontSize: 18.sp,
                fontWeight: FontWeight.w700,
              ),
              SizedBox(height: 6.h),
              WText(
                text:
                    'Your session is about to end. Extend it to keep\ncharging smoothly.',
                color: R.color.neutralGray,
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
              ),
              SizedBox(height: 20.h),

              // ── Option cards ──
              ...List.generate(options.length, (i) {
                final isSelected = selected == i;
                return GestureDetector(
                  onTap: () => setSheet(() => selected = i),
                  child: Container(
                    width: 358.w,
                    height: 56.h,
                    margin: EdgeInsets.only(bottom: 12.h),
                    padding: EdgeInsets.symmetric(horizontal: 16.w),
                    decoration: BoxDecoration(
                      color: R.color.white,
                      borderRadius: BorderRadius.circular(8.r),
                      border: Border.all(
                        color: isSelected
                            ? R.color.mintGreen
                            : R.color.lightGrayShade,
                        width: isSelected ? 1.5 : 1,
                      ),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Container(
                              width: 20.w,
                              height: 20.w,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                border: Border.all(
                                  color: isSelected
                                      ? R.color.mintGreen
                                      : R.color.lightGray,
                                  width: 2,
                                ),
                              ),
                              child: isSelected
                                  ? Center(
                                      child: Container(
                                        width: 10.w,
                                        height: 10.w,
                                        decoration: BoxDecoration(
                                          color: R.color.mintGreen,
                                          shape: BoxShape.circle,
                                        ),
                                      ),
                                    )
                                  : null,
                            ),
                            SizedBox(width: 12.w),
                            WText(
                              text: options[i].label,
                              color: R.color.darkNavy,
                              fontSize: 13.sp,
                              fontWeight: FontWeight.w500,
                            ),
                          ],
                        ),
                        WText(
                          text: 'Est. ${getCurrencySymbol()}${options[i].cost}',
                          color: R.color.neutralGray,
                          fontSize: 13.sp,
                          fontWeight: FontWeight.w500,
                        ),
                      ],
                    ),
                  ),
                );
              }),

              SizedBox(height: 8.h),
              WText(
                text:
                    "If you don't extend, the session will stop automatically.\nReconnection may require availability confirmation.",
                color: R.color.neutralGray,
                fontSize: 11.sp,
                fontWeight: FontWeight.w400,
              ),
              const Spacer(),
              WButton(
                label: isLoading ? 'Extending...' : 'Extend Session',
                height: 52.h,
                radius: 12,
                buttonColor: R.color.mintGreen,
                textColor: R.color.white,
                fontSize: 15.sp,
                fontWeight: FontWeight.w600,
                decorationType: DecorationType.solid,
                onPressed: isLoading
                    ? null
                    : () async {
                        setSheet(() => isLoading = true);
                        final selectedMins = options[selected].mins;

                        // Directly call extend API - no payment now
                        final extendResult = await _controller.extendSession(
                          widget.sessionId,
                          selectedMins,
                        );

                        if (!mounted || !ctx.mounted) return;
                        setSheet(() => isLoading = false);
                        Navigator.pop(ctx);

                        if (extendResult != null) {
                          // Update time remaining from API response
                          if (extendResult.newTimeRemainingMs != null &&
                              extendResult.newTimeRemainingMs! > 0) {
                            final totalSecs =
                                extendResult.newTimeRemainingMs! ~/ 1000;
                            final h = totalSecs ~/ 3600;
                            final m = (totalSecs % 3600) ~/ 60;
                            final s = totalSecs % 60;
                            setState(() {
                              _timeRemaining =
                                  '${h.toString().padLeft(2, '0')}:${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
                            });
                          }
                          Get.snackbar(
                            'Success',
                            'Session extended by $selectedMins minutes.',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.green.shade100,
                            colorText: Colors.green.shade900,
                            margin: EdgeInsets.all(16.w),
                          );
                        } else {
                          Get.snackbar(
                            'Error',
                            'Failed to extend session. Please try again.',
                            snackPosition: SnackPosition.BOTTOM,
                            backgroundColor: Colors.red.shade100,
                            colorText: Colors.red.shade900,
                            margin: EdgeInsets.all(16.w),
                          );
                        }
                      },
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Stop Charging bottom sheet ──
  void _showStopSheet() {
    bool isLoading = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Container(
          width: 390.w,
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
            mainAxisSize: MainAxisSize.min,
            children: [
              WText(
                text: 'Stop Charging?',
                color: R.color.darkNavy,
                fontSize: 18.sp,
                fontWeight: FontWeight.w700,
              ),
              SizedBox(height: 20.h),

              // Stop charging image
              Image.asset(
                Assets.stopCharging,
                width: 200.w,
                height: 168.h,
                fit: BoxFit.contain,
              ),
              SizedBox(height: 20.h),

              WText(
                text: 'Are you sure you want to stop charging?',
                color: R.color.darkNavy,
                fontSize: 14.sp,
                fontWeight: FontWeight.w600,
                textAlign: TextAlign.center,
              ),
              SizedBox(height: 8.h),
              WText(
                text:
                    'Remote controls are unavailable. Source telemetry does not establish payment collection.',
                color: R.color.neutralGray,
                fontSize: 12.sp,
                fontWeight: FontWeight.w400,
                textAlign: TextAlign.center,
              ),
              SizedBox(height: 24.h),

              if (isLoading)
                Padding(
                  padding: EdgeInsets.symmetric(vertical: 12.h),
                  child: CircularProgressIndicator(color: R.color.mintGreen),
                )
              else
                Row(
                  children: [
                    Expanded(
                      child: WButton(
                        label: 'Stop Charging',
                        height: 48.h,
                        radius: 8,
                        buttonColor: R.color.mintGreen,
                        textColor: R.color.white,
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w600,
                        decorationType: DecorationType.solid,
                        onPressed: () async {
                          setSheet(() => isLoading = true);
                          _pollTimer?.cancel();

                          final result = await _controller.stopCharging(
                            widget.sessionId,
                          );

                          if (!mounted || !ctx.mounted) return;
                          setSheet(() => isLoading = false);
                          Navigator.pop(ctx);

                          if (result != null) {
                            Get.off(
                              () => ChargingStoppedScreen(
                                sessionId: widget.sessionId,
                                popupTitle:
                                    result.popupTitle ?? 'Charging Stopped',
                                subTitle:
                                    result.subTitle ??
                                    'Your booking is confirmed!',
                                energyDelivered: result.energyDelivered ?? 0,
                                costPerKwh: result.costPerKwh ?? 0,
                                extendSessionCharge:
                                    result.extendSessionCharge ?? 0,
                                totalAmount: result.totalAmount ?? 0,
                                needsPayment: result.needsPayment ?? false,
                                currencySymbol: result.currencySymbol,
                              ),
                            );
                          } else {
                            Get.snackbar(
                              'Stop unconfirmed',
                              'No source-confirmed stop was received. Refresh the session.',
                            );
                          }
                        },
                      ),
                    ),
                    SizedBox(width: 12.w),
                    Expanded(
                      child: WButton(
                        label: 'Continue Charging',
                        height: 48.h,
                        radius: 8,
                        buttonColor: R.color.mintGreen,
                        textColor: R.color.mintGreen,
                        fontSize: 14.sp,
                        fontWeight: FontWeight.w600,
                        decorationType: DecorationType.stroke,
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ),
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      body: Column(
        children: [
          // ── Top green blur header ──
          Stack(
            children: [
              Container(
                width: double.infinity,
                height: 150.h,
                color: R.color.mintGreenAccent,
              ),
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
                        child: Icon(
                          Icons.arrow_back,
                          color: R.color.darkNavy,
                          size: 22.sp,
                        ),
                      ),
                      Expanded(
                        child: Center(
                          child: WText(
                            text: 'Charging status',
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
            ],
          ),

          Expanded(
            child: SingleChildScrollView(
              child: Column(
                children: [
                  SizedBox(height: 16.h),
                  Stack(
                    alignment: Alignment.center,
                    children: [
                      SizedBox(
                        width: 320.w,
                        height: 260.h,
                        child: CustomPaint(
                          painter: _CornerPainter(color: R.color.mintGreen),
                        ),
                      ),
                      Image.asset(
                        Assets.modern_car,
                        width: 280.w,
                        height: 220.h,
                        fit: BoxFit.contain,
                      ),
                    ],
                  ),
                  SizedBox(height: 24.h),

                  Padding(
                    padding: EdgeInsets.symmetric(horizontal: 16.w),
                    child: Container(
                      width: 358.w,
                      padding: EdgeInsets.all(20.w),
                      decoration: BoxDecoration(
                        color: R.color.softMint.withValues(alpha: 0.4),
                        borderRadius: BorderRadius.circular(16.r),
                        border: Border.all(color: R.color.lightMint, width: 1),
                      ),
                      child: Column(
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(
                                Icons.bolt,
                                color: R.color.mintGreen,
                                size: 18.sp,
                              ),
                              SizedBox(width: 4.w),
                              Flexible(
                                child: WText(
                                  text: _chargingStatus,
                                  textAlign: TextAlign.center,
                                  color: R.color.mintGreen,
                                  fontSize: 14.sp,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                          SizedBox(height: 10.h),
                          Text(_recoveryNote, textAlign: TextAlign.center),
                          TextButton.icon(
                            onPressed: _fetching || !_foreground || !_sameOwner
                                ? null
                                : _fetchStatus,
                            icon: const Icon(Icons.refresh),
                            label: Text(_t('Refresh status', 'تحديث الحالة')),
                          ),
                          WText(
                            text: _batteryPercent == null
                                ? 'Unavailable'
                                : '$_batteryPercent%',
                            color: R.color.darkNavy,
                            fontSize: 40.sp,
                            fontWeight: FontWeight.w700,
                          ),
                          SizedBox(height: 10.h),
                          if (_batteryPercent != null)
                            ClipRRect(
                              borderRadius: BorderRadius.circular(8.r),
                              child: LinearProgressIndicator(
                                value: _batteryPercent! / 100,
                                minHeight: 8.h,
                                backgroundColor: R.color.lightGray,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  R.color.mintGreen,
                                ),
                              ),
                            ),
                          SizedBox(height: 12.h),
                          WText(
                            text: 'Time Remaining:  $_timeRemaining',
                            color: R.color.neutralGray,
                            fontSize: 13.sp,
                            fontWeight: FontWeight.w400,
                          ),
                          if (_kwhUsed != null) ...[
                            SizedBox(height: 8.h),
                            WText(
                              text: 'Energy Used:  $_kwhUsed kWh',
                              color: R.color.neutralGray,
                              fontSize: 13.sp,
                              fontWeight: FontWeight.w400,
                            ),
                            SizedBox(height: 8.h),
                            WText(
                              text: _costText ?? 'Calculated cost unavailable',
                              color: R.color.neutralGray,
                              fontSize: 13.sp,
                              fontWeight: FontWeight.w400,
                            ),
                            SizedBox(height: 8.h),
                            WText(
                              text: _meterNote,
                              color: R.color.neutralGray,
                              fontSize: 12.sp,
                              fontWeight: FontWeight.w400,
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  SizedBox(height: 24.h),
                ],
              ),
            ),
          ),

          // ── Buttons ──
          Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 16.h),
            child: Column(
              children: [
                WButton(
                  label: 'Stop Charging',
                  height: 52.h,
                  radius: 12,
                  buttonColor: _canStop ? R.color.mintGreen : R.color.lightGray,
                  textColor: R.color.white,
                  fontSize: 15.sp,
                  fontWeight: FontWeight.w600,
                  decorationType: DecorationType.solid,
                  onPressed: _canStop ? _showStopSheet : null,
                ),
                SizedBox(height: 12.h),
                WButton(
                  label: 'Extend Session',
                  height: 52.h,
                  radius: 12,
                  buttonColor: _canExtend
                      ? R.color.mintGreen
                      : R.color.lightGray,
                  textColor: _canExtend ? R.color.mintGreen : R.color.lightGray,
                  fontSize: 15.sp,
                  fontWeight: FontWeight.w600,
                  decorationType: DecorationType.stroke,
                  onPressed: _canExtend ? _showExtendSheet : null,
                ),
                SizedBox(height: 16.h),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _CornerPainter extends CustomPainter {
  final Color color;
  const _CornerPainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 3
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    const len = 28.0;
    canvas.drawLine(Offset(0, len), const Offset(0, 0), paint);
    canvas.drawLine(const Offset(0, 0), Offset(len, 0), paint);
    canvas.drawLine(Offset(size.width - len, 0), Offset(size.width, 0), paint);
    canvas.drawLine(Offset(size.width, 0), Offset(size.width, len), paint);
    canvas.drawLine(
      Offset(0, size.height - len),
      Offset(0, size.height),
      paint,
    );
    canvas.drawLine(Offset(0, size.height), Offset(len, size.height), paint);
    canvas.drawLine(
      Offset(size.width - len, size.height),
      Offset(size.width, size.height),
      paint,
    );
    canvas.drawLine(
      Offset(size.width, size.height - len),
      Offset(size.width, size.height),
      paint,
    );
  }

  @override
  bool shouldRepaint(_CornerPainter old) => old.color != color;
}
