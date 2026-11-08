import 'package:flutter/material.dart';
import '../../../../core/service/api_service/api_service.dart';
import '../../../../core/service/session/session.dart';
import '../../model/reservation_chargers.dart';

class ReservationChargerPicker extends StatefulWidget {
  final String stationId;
  final ValueChanged<String?> onChanged;
  final Future<ReservationChargers> Function()? load;
  final String? Function()? identity;
  const ReservationChargerPicker({
    super.key,
    required this.stationId,
    required this.onChanged,
    this.load,
    this.identity,
  });
  @override
  State<ReservationChargerPicker> createState() =>
      _ReservationChargerPickerState();
}

class _ReservationChargerPickerState extends State<ReservationChargerPicker>
    with WidgetsBindingObserver {
  List<ReservationCharger> _choices = const [];
  String? _selected, _owner;
  bool _loading = false, _failed = false, _foreground = true;
  int _generation = 0;
  String? get _identity => (widget.identity ?? (() => Session.accessToken))();
  String _t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    _owner = _identity;
    WidgetsBinding.instance.addObserver(this);
    _read();
  }

  Future<void> _read() async {
    if (_loading || !_foreground) return;
    final generation = ++_generation;
    _loading = true;
    _failed = false;
    _selected = null;
    _choices = const [];
    widget.onChanged(null);
    if (mounted) setState(() {});
    try {
      if (_owner != _identity) throw StateError('Account changed');
      final result =
          await (widget.load?.call() ??
                  loadReservationChargers(
                    NetworkService().client,
                    widget.stationId,
                  ))
              .timeout(const Duration(seconds: 15));
      if (!mounted || !_foreground || generation != _generation) return;
      if (_owner != _identity || result.stationId != widget.stationId) {
        throw StateError('Catalog identity changed');
      }
      _choices = result.choices;
      if (_choices.length == 1) _selected = _choices.single.id;
      widget.onChanged(_selected);
    } catch (_) {
      if (mounted && _foreground && generation == _generation) _failed = true;
    } finally {
      if (mounted && _foreground && generation == _generation) {
        setState(() {
          _loading = false;
        });
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _generation++;
    _loading = false;
    _foreground = state == AppLifecycleState.resumed;
    _choices = const [];
    _selected = null;
    widget.onChanged(null);
    if (mounted) setState(() {});
    if (_foreground) _read();
  }

  @override
  void dispose() {
    _generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_loading)
          Text(_t('Loading approved chargers…', 'جارٍ تحميل الشواحن المعتمدة…'))
        else if (_failed || _choices.isEmpty) ...[
          Text(
            _t(
              'Approved chargers unavailable. Try again or contact the operator.',
              'الشواحن المعتمدة غير متاحة. حاول مجدداً أو تواصل مع المشغل.',
            ),
          ),
          TextButton(
            onPressed: _read,
            child: Text(_t('Retry charger list', 'إعادة تحميل قائمة الشواحن')),
          ),
        ] else ...[
          DropdownButtonFormField<String>(
            initialValue: _selected,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: _t('Choose charger', 'اختر الشاحن'),
            ),
            items: _choices
                .map(
                  (c) => DropdownMenuItem(
                    value: c.id,
                    child: Text(c.id, overflow: TextOverflow.ellipsis),
                  ),
                )
                .toList(),
            onChanged: (value) {
              setState(() {
                _selected = value;
              });
              widget.onChanged(value);
            },
          ),
          Text(
            _t(
              'Live connector availability is unverified.',
              'توفر الموصل المباشر غير متحقق.',
            ),
          ),
        ],
      ],
    ),
  );
}
