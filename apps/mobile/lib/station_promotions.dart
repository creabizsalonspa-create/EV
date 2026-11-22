import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import 'api.dart';

class StationPromotions extends StatefulWidget {
  const StationPromotions({
    super.key,
    required this.api,
    required this.stationId,
    required this.country,
    required this.onExpired,
  }) : discoveryStations = const [];

  const StationPromotions.discovery({
    super.key,
    required this.api,
    required this.discoveryStations,
    required this.onExpired,
  }) : stationId = null,
       country = null;

  final EvApi api;
  final int? stationId;
  final String? country;
  final List<int> discoveryStations;
  final VoidCallback onExpired;

  @override
  State<StationPromotions> createState() => _StationPromotionsState();
}

class _StationPromotionsState extends State<StationPromotions>
    with WidgetsBindingObserver {
  List<Map<String, dynamic>>? placements;
  bool expanded = false;
  bool loading = false;
  bool failed = false;
  int generation = 0;
  int page = 1;
  int lastPage = 1;
  bool Function()? currentSession;
  late bool foreground;

  bool get discovery => widget.stationId == null;

  @override
  void initState() {
    super.initState();
    final binding = WidgetsBinding.instance;
    final lifecycle = binding.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    binding.addObserver(this);
    reset();
  }

  @override
  void didUpdateWidget(covariant StationPromotions oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.stationId != widget.stationId ||
        oldWidget.country != widget.country ||
        !listEquals(oldWidget.discoveryStations, widget.discoveryStations)) {
      reset();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (resumed == foreground) return;

    setState(() {
      foreground = resumed;
      reset();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void reset() {
    final generationAtReset = ++generation;
    placements = null;
    expanded = discovery;
    loading = false;
    failed = false;
    page = 1;
    lastPage = 1;
    currentSession = null;

    if (foreground && discovery && widget.discoveryStations.isNotEmpty) {
      final ownsSession = widget.api.sessionFence();
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && generation == generationAtReset && ownsSession()) {
          load(1);
        }
      });
    }
  }

  Future<void> load(int requestedPage) async {
    if (!foreground || !expanded) return;
    final request = ++generation;
    final fence = widget.api.sessionFence();
    setState(() {
      placements = null;
      failed = false;
      loading = true;
      page = requestedPage;
      currentSession = fence;
    });
    bool current() => mounted && request == generation && fence();
    try {
      final result = await widget.api.request(
        'commercial/campaign-placements',
        query: {
          if (discovery)
            for (var i = 0; i < widget.discoveryStations.length; i++)
              'station_ids[$i]': widget.discoveryStations[i].toString()
          else ...{
            'country': widget.country!,
            'station_id': widget.stationId.toString(),
          },
          'placement': discovery ? 'discovery_banner' : 'station_detail',
          'page': requestedPage.toString(),
        },
      );
      if (!current()) return;
      if (result is! Map ||
          result['client_telemetry_billable'] != false ||
          result['ranking_basis'] != 'SPONSORED_ID_ORDER_NOT_PERFORMANCE' ||
          result['current_page'] != requestedPage ||
          result['last_page'] is! int ||
          (result['last_page'] as int) < 1 ||
          result['placements'] is! List) {
        throw const FormatException('Invalid station promotions');
      }
      final rows = <Map<String, dynamic>>[];
      final stations = discovery
          ? widget.discoveryStations
          : [widget.stationId];
      for (final row in result['placements'] as List) {
        if (row is! Map ||
            !stations.contains(row['station_id']) ||
            row['sponsored'] != true ||
            row['compatibility_verified'] != false ||
            row['availability_verified'] != false ||
            row['capability_override'] != false ||
            ![
              'title',
              'title_ar',
              'description',
              'description_ar',
            ].every((key) => row[key] is String)) {
          throw const FormatException('Invalid sponsored placement');
        }
        rows.add(Map<String, dynamic>.from(row));
      }
      setState(() {
        placements = rows;
        lastPage = result['last_page'] as int;
      });
    } on ApiException catch (error) {
      if (mounted && request == generation && error.endsCurrentSession) {
        widget.onExpired();
      } else if (current()) {
        setState(() => failed = true);
      }
    } catch (_) {
      if (current()) setState(() => failed = true);
    } finally {
      if (mounted && request == generation) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final arabic = Localizations.localeOf(context).languageCode == 'ar';
    String label(String en, String ar) => arabic ? ar : en;
    final visible = foreground && expanded && (currentSession?.call() ?? false);
    if (discovery &&
        (!visible || (placements?.isEmpty == true && lastPage == 1))) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (discovery && placements?.isNotEmpty == true)
          Text(label('Sponsored promotions', 'عروض ترويجية برعاية')),
        if (!discovery)
          TextButton(
            onPressed: foreground
                ? () {
                    if (expanded) {
                      setState(reset);
                    } else {
                      setState(() => expanded = true);
                      load(1);
                    }
                  }
                : null,
            child: Text(label('Sponsored promotions', 'عروض ترويجية برعاية')),
          ),
        if (visible && loading) const LinearProgressIndicator(),
        if (visible && failed)
          TextButton(
            onPressed: () => load(page),
            child: Text(label('Retry promotions', 'إعادة تحميل العروض')),
          ),
        if (visible && placements != null) ...[
          for (final row in placements!)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(row[arabic ? 'title_ar' : 'title'] as String),
              subtitle: Text(
                row[arabic ? 'description_ar' : 'description'] as String,
              ),
            ),
          if (placements!.isEmpty)
            Text(
              label('No promotions on this page', 'لا توجد عروض في هذه الصفحة'),
            ),
          Wrap(
            children: [
              if (page > 1)
                TextButton(
                  onPressed: () => load(page - 1),
                  child: Text(label('Previous promotions', 'العروض السابقة')),
                ),
              if (page < lastPage)
                TextButton(
                  onPressed: () => load(page + 1),
                  child: Text(label('Next promotions', 'العروض التالية')),
                ),
            ],
          ),
        ],
      ],
    );
  }
}
