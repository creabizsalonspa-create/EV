import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

/// Garage selection describes compatibility, not charging authorization.
class ReservationVehiclePicker extends StatefulWidget {
  final EvApi api;
  final String connector;
  final VoidCallback onExpired;
  const ReservationVehiclePicker({
    super.key,
    required this.api,
    required this.connector,
    required this.onExpired,
  });
  @override
  State<ReservationVehiclePicker> createState() =>
      _ReservationVehiclePickerState();
}

class _ReservationVehiclePickerState extends State<ReservationVehiclePicker> with WidgetsBindingObserver {
  final vehicles = <Map<String, dynamic>>[];
  int page = 0, last = 1, generation = 0;
  bool loading = false;
  Object? error;
  late bool Function() accountFence;
  bool _backgroundHidden = false, _expiredNotified = false;
  bool get _sameSession => accountFence();
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;

  void _clearPrivate({bool background = false}) {
    generation++;
    if (!mounted) return;
    setState(() {
      vehicles.clear();
      page = 0;
      last = 1;
      loading = false;
      error = null;
      _backgroundHidden = background;
    });
  }

  void _notifyExpired() {
    if (_expiredNotified) return;
    _expiredNotified = true;
    widget.onExpired();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      _clearPrivate(background: true);
    } else if (!_sameSession) {
      _clearPrivate();
      _notifyExpired();
    }
  }
  @override
  void initState() {
    super.initState();
    accountFence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load(1);
  }

  @override
  void didUpdateWidget(covariant ReservationVehiclePicker oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (identical(oldWidget.api, widget.api) &&
        oldWidget.connector == widget.connector &&
        accountFence()) return;
    // The dialog State can be reused for a different account or connector.
    // Never show the prior actor's garage while a new GET is pending.
    generation++;
    accountFence = widget.api.sessionFence();
    vehicles.clear();
    page = 0;
    last = 1;
    loading = false;
    error = null;
    _backgroundHidden = false;
    _expiredNotified = false;
    load(1);
  }

  Future<void> load(int target) async {
    if (!mounted) return;
    if (!_sameSession) {
      _clearPrivate();
      _notifyExpired();
      return;
    }
    final request = ++generation;
    final fence = accountFence;
    setState(() {
      loading = true;
      error = null;
      _backgroundHidden = false;
      if (target == 1) {
        vehicles.clear();
        page = 0;
        last = 1;
      }
    });
    try {
      final response = await widget.api.request(
        'vehicles',
        query: {'connector_type': widget.connector, 'page': '$target'},
      );
      if (!mounted || request != generation || _backgroundHidden) return;
      if (!fence() || !_sameSession) {
        _clearPrivate();
        _notifyExpired();
        return;
      }
      final data = ApiPage.fromJson(response, (row) => row);
      setState(() {
        if (target == 1) vehicles.clear();
        vehicles.addAll(
          data.items.where((v) => v['connector_type'] == widget.connector),
        );
        page = data.current;
        last = data.last;
      });
    } catch (e) {
      if (!mounted || request != generation || _backgroundHidden) return;
      if (!fence() || !_sameSession ||
          (e is ApiException && [401, 419].contains(e.status))) {
        _clearPrivate();
        _notifyExpired();
        return;
      }
      setState(() => error = e);
    } finally {
      if (mounted && request == generation && !_backgroundHidden && fence()) {
        setState(() => loading = false);
      }
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    vehicles.clear();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final showPrivate = _sameSession && !_backgroundHidden;
    return AlertDialog(
    title: Text(t('Choose your vehicle', 'اختر مركبتك')),
    content: SizedBox(
      width: 420,
      height: MediaQuery.sizeOf(context).height * .45,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t(
              'Garage connector must match ${widget.connector}. Charging requires separate authorization.',
              'يجب أن يتوافق موصل المركبة مع ${widget.connector}. يتطلب الشحن تفويضاً منفصلاً.',
            ),
          ),
          if (!_sameSession)
            Text(t('Session changed. Reopen your garage.', 'تغيرت الجلسة. افتح مرآبك من جديد.')),
          if (_backgroundHidden)
            Text(t('Garage hidden. Refresh to reload.', 'تم إخفاء المرآب. حدّث لإعادة التحميل.')),
          if (_backgroundHidden && _sameSession)
            TextButton(
              onPressed: () => load(1),
              child: Text(t('Refresh garage', 'تحديث المرآب')),
            ),
          if (loading && showPrivate) const LinearProgressIndicator(),
          if (error != null && showPrivate) Text('$error'),
          if (showPrivate && !loading && vehicles.isEmpty)
            Text(
              t(
                'No compatible vehicle in your garage.',
                'لا توجد مركبة متوافقة في مرآبك.',
              ),
            ),
          Expanded(
            child: ListView(
              children: [
                if (showPrivate) for (final vehicle in vehicles)
                  ListTile(
                    title: Text('${vehicle['name']}'),
                    subtitle: Text('${vehicle['connector_type']}'),
                    onTap: loading || !showPrivate
                        ? null
                        : () {
                            if (_sameSession && !_backgroundHidden) Navigator.pop(context, vehicle);
                          },
                  ),
              ],
            ),
          ),
          if (error != null && showPrivate)
            TextButton(
              onPressed: loading ? null : () => load(page == 0 ? 1 : page + 1),
              child: Text(t('Retry vehicles', 'إعادة محاولة تحميل المركبات')),
            ),
          if (showPrivate && page < last)
            TextButton(
              onPressed: loading ? null : () => load(page + 1),
              child: Text(t('More vehicles', 'المزيد من المركبات')),
            ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: loading || !showPrivate
            ? null
            : () {
                if (_sameSession && !_backgroundHidden) Navigator.pop(context, {'add_vehicle': true});
              },
        child: Text(t('Open garage', 'فتح المرآب')),
      ),
      TextButton(
        onPressed: !showPrivate
            ? null
            : () {
                if (_sameSession && !_backgroundHidden) Navigator.pop(context, {'unassigned': true});
              },
        child: Text(t('Continue without a vehicle', 'المتابعة دون ربط مركبة')),
      ),
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text(t('Cancel', 'إلغاء')),
      ),
    ],
  );
  }
}
