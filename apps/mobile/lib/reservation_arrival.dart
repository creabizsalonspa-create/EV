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

class _ReservationArrivalButtonState extends State<ReservationArrivalButton> {
  bool busy = false;
  Object? error;
  Future<void> checkIn() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(arrivalText(context, 'title')),
        content: Text(arrivalText(context, 'consent')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(EvStrings.of(context).t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(EvStrings.of(context).t('confirm')),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        'reservations/${widget.reservationId}/check-in',
        method: 'POST',
        body: {'self_reported_arrival': true},
      );
      if (mounted) await widget.onChanged();
    } catch (e) {
      if (mounted) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      FilledButton.tonal(
        onPressed: busy || widget.disabled ? null : checkIn,
        child: Text(arrivalText(context, 'title')),
      ),
      if (busy) const LinearProgressIndicator(),
      if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
    ],
  );
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
