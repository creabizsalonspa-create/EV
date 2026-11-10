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

class _ReservationVehiclePickerState extends State<ReservationVehiclePicker> {
  final vehicles = <Map<String, dynamic>>[];
  int page = 0, last = 1, generation = 0;
  bool loading = false;
  Object? error;
  late bool Function() accountFence;
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  @override
  void initState() {
    super.initState();
    accountFence = widget.api.sessionFence();
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
    load(1);
  }

  Future<void> load(int target) async {
    final request = ++generation, fence = widget.api.sessionFence();
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final response = await widget.api.request(
        'vehicles',
        query: {'connector_type': widget.connector, 'page': '$target'},
      );
      final data = ApiPage.fromJson(response, (row) => row);
      if (!mounted || request != generation || !fence()) return;
      setState(() {
        if (target == 1) vehicles.clear();
        vehicles.addAll(
          data.items.where((v) => v['connector_type'] == widget.connector),
        );
        page = data.current;
        last = data.last;
      });
    } catch (e) {
      if (!mounted || request != generation || !fence()) return;
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
      else
        setState(() => error = e);
    } finally {
      if (mounted && request == generation && fence()) setState(() => loading = false);
    }
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
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
          if (loading) const LinearProgressIndicator(),
          if (error != null) Text('$error'),
          if (!loading && vehicles.isEmpty)
            Text(
              t(
                'No compatible vehicle in your garage.',
                'لا توجد مركبة متوافقة في مرآبك.',
              ),
            ),
          Expanded(
            child: ListView(
              children: [
                for (final vehicle in vehicles)
                  ListTile(
                    title: Text('${vehicle['name']}'),
                    subtitle: Text('${vehicle['connector_type']}'),
                    onTap: loading
                        ? null
                        : () {
                            if (accountFence()) Navigator.pop(context, vehicle);
                          },
                  ),
              ],
            ),
          ),
          if (error != null)
            TextButton(
              onPressed: loading ? null : () => load(page == 0 ? 1 : page + 1),
              child: Text(t('Retry vehicles', 'إعادة محاولة تحميل المركبات')),
            ),
          if (page < last)
            TextButton(
              onPressed: loading ? null : () => load(page + 1),
              child: Text(t('More vehicles', 'المزيد من المركبات')),
            ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: loading
            ? null
            : () {
                if (accountFence()) Navigator.pop(context, {'add_vehicle': true});
              },
        child: Text(t('Open garage', 'فتح المرآب')),
      ),
      TextButton(
        onPressed: () {
          if (accountFence()) Navigator.pop(context, {'unassigned': true});
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
