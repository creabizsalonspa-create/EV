import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

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

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didUpdateWidget(covariant ReservationArrivalButton oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(widget.api, oldWidget.api) ||
        widget.reservationId != oldWidget.reservationId ||
        !authorized()) {
      generation++;
      authorized = widget.api.sessionFence();
      busy = false;
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
        error = null;
      });
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
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
    if (widget.disabled || busy || !consentValid()) return;
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
    if (yes != true) return;
    if (!consentValid()) {
      // The active account may rotate while its confirmation dialog is open.
      // Rebuild the original widget closed, without executing a stale POST or
      // touching a replacement reservation/API bound to this route.
      if (mounted && foreground && identical(widget.api, api) &&
          widget.reservationId == reservation && !fence()) {
        setState(() {
          generation++;
          busy = false;
          error = null;
        });
      }
      return;
    }
    if (widget.disabled || busy) return;
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
          onPressed: busy || widget.disabled ? null : () {
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
