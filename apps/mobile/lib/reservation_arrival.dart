import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

String? reservationExpiryText(Object? outcome, {required bool arabic}) =>
    switch (outcome) {
      'unused_without_reported_arrival' => arabic
          ? 'انتهى الحجز دون استخدام ولم يُسجّل وصول ذاتي. رسوم عدم الحضور صفر.'
          : 'Reservation expired unused with no recorded arrival report. The no-show fee is zero.',
      'unused_after_reported_arrival' => arabic
          ? 'انتهى الحجز دون استخدام بعد تسجيل وصول ذاتي. رسوم عدم الحضور صفر.'
          : 'Reservation expired unused after a self-reported arrival. The no-show fee is zero.',
      'expired_before_dispatch' => arabic
          ? 'انتهى الحجز قبل إرسال طلب بدء الشحن.'
          : 'Reservation expired before the charging start request was sent.',
      _ => null,
    };

class ReservationArrivalButton extends StatefulWidget {
  final EvApi api;
  final int reservationId;
  final Future<void> Function() onChanged;
  final VoidCallback onExpired;
  final bool disabled;
  const ReservationArrivalButton({
    super.key,
    required this.api,
    required this.reservationId,
    required this.onChanged,
    required this.onExpired,
    this.disabled = false,
  });
  @override
  State<ReservationArrivalButton> createState() =>
      _ReservationArrivalButtonState();
}

class _ReservationArrivalButtonState extends State<ReservationArrivalButton>
    with WidgetsBindingObserver {
  bool busy = false, foreground = true;
  Object? error;
  int generation = 0;
  late bool Function() authorized;
  StreamSubscription<int>? sessionSubscription;
  bool confirming = false;
  void subscribeSession() {
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(widget.api,api) && !authorized()) {
        setState(() { busy = false; error = null; confirming = false; });
      }
    });
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribeSession();
  }

  @override
  void didUpdateWidget(covariant ReservationArrivalButton oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(widget.api, oldWidget.api) ||
        widget.reservationId != oldWidget.reservationId ||
        !authorized()) {
      generation++;
      if (!identical(oldWidget.api,widget.api)) {
        unawaited(sessionSubscription?.cancel());
        authorized = widget.api.sessionFence();
        subscribeSession();
      }
      busy = false;
      confirming = false;
      error = null;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    foreground = resumed;
    generation++;
    if (mounted) {
      setState(() {
        busy = false;
        confirming = false;
        error = null;
      });
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    super.dispose();
  }

  bool scoped(
    EvApi api, bool Function() fence, int reservation, int epoch,
  ) => mounted && foreground && generation == epoch &&
      identical(widget.api, api) &&
      identical(authorized, fence) &&
      widget.reservationId == reservation && fence();

  Future<void> checkIn() async {
    final api = widget.api;
    final fence = authorized;
    final reservation = widget.reservationId;
    final confirmationEpoch = generation;
    final onChanged = widget.onChanged;
    final onExpired = widget.onExpired;
    bool consentValid() => scoped(api, fence, reservation, confirmationEpoch);
    if (widget.disabled || busy || confirming || !consentValid()) return;
    setState(() => confirming = true);
    final yes = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: Text(arrivalText(dialog, 'title')),
        content: Text(arrivalText(dialog, 'consent')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: Text(EvStrings.of(dialog).t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialog, true),
            child: Text(EvStrings.of(dialog).t('confirm')),
          ),
        ],
      ),
    );
    // Explicit approval belongs to the exact original reservation and
    // authenticated customer, never an account that replaced the widget
    // while the operating-system dialog or Flutter route was open.
    if (!consentValid()) {
      if (mounted && generation == confirmationEpoch &&
          identical(widget.api, api) && identical(authorized, fence) &&
          widget.reservationId == reservation && !fence()) {
        // Repaint the retired button after a credential change without
        // granting the replacement account access to the former consent.
        setState(() {
          generation++;
          busy = false;
          confirming = false;
          error = null;
        });
      }
      return;
    }
    setState(() => confirming = false);
    if (yes != true || widget.disabled || busy) return;
    final operationEpoch = ++generation;
    bool current() => scoped(api, fence, reservation, operationEpoch);
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await api.request(
        'reservations/$reservation/check-in',
        method: 'POST',
        body: {'self_reported_arrival': true},
      );
      if (current()) await onChanged();
    } catch (e) {
      if (current()) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status)) {
          onExpired();
        }
      } else if (mounted && foreground && generation == operationEpoch &&
          identical(widget.api,api) && identical(authorized,fence) &&
          widget.reservationId == reservation && e is ApiException && e.endsCurrentSession) {
        onExpired();
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!foreground || !authorized()) return const SizedBox.shrink();
    final api = widget.api;
    final fence = authorized;
    final reservation = widget.reservationId;
    final epoch = generation;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        FilledButton.tonal(
          onPressed: busy || confirming || widget.disabled ? null : () {
            if (scoped(api, fence, reservation, epoch)) checkIn();
          },
          child: Text(arrivalText(context, 'title')),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
      ],
    );
  }
}

String arrivalText(BuildContext context, String key) =>
    (Localizations.localeOf(context).languageCode == 'ar'
        ? arrivalAr
        : arrivalEn)[key] ??
    key;
const arrivalEn = {
  'title': 'Check in',
  'consent':
      'Record your arrival for this reservation? This is self-reported, does not verify physical presence, extend the slot or start charging.',
  'reported': 'Self-reported arrival recorded',
  'unused': 'Reservation window ended without a linked charging session',
};
const arrivalAr = {
  'title': 'تسجيل الوصول',
  'consent':
      'هل تريد تسجيل وصولك لهذا الحجز؟ هذا إبلاغ ذاتي لا يثبت الوجود الفعلي ولا يمدد الموعد ولا يبدأ الشحن.',
  'reported': 'سُجل الوصول بإبلاغ ذاتي',
  'unused': 'انتهى موعد الحجز دون جلسة شحن مرتبطة',
};
