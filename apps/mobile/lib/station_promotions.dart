import 'dart:async';
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
  late bool Function() ownsSession;
  StreamSubscription<int>? sessionSubscription;
  late bool foreground;
  List<int> sourceStations = const [];

  bool get discovery => widget.stationId == null;

  @override
  void initState() {
    super.initState();
    final binding = WidgetsBinding.instance;
    final lifecycle = binding.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    binding.addObserver(this);
    bindSession();
    reset();
  }

  void bindSession() {
    final api = widget.api;
    ownsSession = api.sessionFence();
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api, widget.api) && !ownsSession()) {
        // Retire display and actions synchronously. Do not change the request
        // generation here: the actual API may still deliver a current 401.
        setState(() {
          placements = null;
          expanded = false;
          loading = false;
          failed = false;
          currentSession = null;
        });
      }
    });
  }

  @override
  void didUpdateWidget(covariant StationPromotions oldWidget) {
    super.didUpdateWidget(oldWidget);
    final changedApi = !identical(oldWidget.api, widget.api);
    if (changedApi) {
      unawaited(sessionSubscription?.cancel());
      bindSession();
    }
    if (changedApi || oldWidget.stationId != widget.stationId ||
        oldWidget.country != widget.country ||
        (discovery && !listEquals(sourceStations, widget.discoveryStations))) {
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
    generation++;
    unawaited(sessionSubscription?.cancel());
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
    sourceStations = List<int>.unmodifiable(discovery
        ? widget.discoveryStations : [widget.stationId!]);

    if (foreground && ownsSession() && discovery && validSource()) {
      final api = widget.api;
      final fence = ownsSession;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && foreground && generation == generationAtReset &&
            identical(api, widget.api) && identical(fence, ownsSession) && fence()) {
          load(1);
        }
      });
    }
  }

  bool validSource() => sourceStations.isNotEmpty && sourceStations.length <= 25 &&
      sourceStations.every((id) => id > 0) &&
      sourceStations.toSet().length == sourceStations.length &&
      (discovery || (widget.country != null &&
          RegExp(r'^[A-Z]{2}$').hasMatch(widget.country!)));

  Future<void> load(int requestedPage) async {
    if (!mounted || !foreground || !expanded || !ownsSession() || loading ||
        !validSource() || requestedPage < 1 || requestedPage > 100000) return;
    final request = ++generation;
    final api = widget.api;
    final scope = ownsSession;
    final fence = api.sessionFence();
    final stations = sourceStations;
    final country = widget.country;
    final isDiscovery = discovery;
    setState(() {
      placements = null;
      failed = false;
      loading = true;
      page = requestedPage;
      currentSession = fence;
    });
    bool sameRequest() => mounted && foreground && request == generation &&
        identical(api, widget.api) && identical(scope, ownsSession);
    bool current() => sameRequest() && scope() && fence();
    try {
      final result = await api.request(
        'commercial/campaign-placements',
        query: {
          if (isDiscovery)
            for (var i = 0; i < stations.length; i++)
              'station_ids[$i]': stations[i].toString()
          else ...{
            'country': country!,
            'station_id': stations.single.toString(),
          },
          'placement': isDiscovery ? 'discovery_banner' : 'station_detail',
          'page': requestedPage.toString(),
        },
      );
      if (!current()) return;
      if (result is! Map ||
          result['client_telemetry_billable'] != false ||
          result['ranking_basis'] != 'SPONSORED_ID_ORDER_NOT_PERFORMANCE' ||
          result['current_page'] is! int ||
          result['current_page'] != requestedPage ||
          result['last_page'] is! int ||
          (result['last_page'] as int) < 1 ||
          (result['last_page'] as int) > 100000 ||
          result['placements'] is! List ||
          (result['placements'] as List).length > 25 ||
          (requestedPage > result['last_page'] &&
              (result['placements'] as List).isNotEmpty)) {
        throw const FormatException('Invalid station promotions');
      }
      final rows = <Map<String, dynamic>>[];
      final ids = <int>{};
      // New eligible-first endpoint returns exact totals. Retain compatibility
      // with older canonical responses that omit BOTH pagination fields.
      if (result.containsKey('total') || result.containsKey('per_page')) {
        final total = result['total'], size = result['per_page'];
        if (total is! int || total < 0 || total > 1000 ||
            size is! int || size < 1 || size > 25) {
          throw const FormatException('Invalid sponsored pagination');
        }
        final last = total == 0 ? 1 : (total + size - 1) ~/ size;
        final remaining = total - (requestedPage - 1) * size;
        final count = remaining < 0 ? 0 : remaining > size ? size : remaining;
        if (result['last_page'] != last ||
            (result['placements'] as List).length != count) {
          throw const FormatException('Inconsistent sponsored pagination');
        }
      }
      for (final row in result['placements'] as List) {
        if (row is! Map ||
            row['campaign_id'] is! int || row['campaign_id'] <= 0 ||
            !ids.add(row['campaign_id'] as int) ||
            row['station_id'] is! int || !stations.contains(row['station_id']) ||
            row['sponsored'] != true ||
            row['compatibility_verified'] != false ||
            row['availability_verified'] != false ||
            row['capability_override'] != false ||
            ![
              'title',
              'title_ar',
              'description',
              'description_ar',
            ].every((key) => row[key] is String &&
                (row[key] as String).trim().isNotEmpty &&
                (row[key] as String).length <= (key.startsWith('title') ? 120 : 500))) {
          throw const FormatException('Invalid sponsored placement');
        }
        rows.add(Map<String, dynamic>.from(row));
      }
      setState(() {
        placements = rows;
        lastPage = result['last_page'] as int;
      });
    } on ApiException catch (error) {
      if (sameRequest() && error.endsCurrentSession) {
        widget.onExpired();
      } else if (current()) {
        setState(() => failed = true);
      }
    } catch (_) {
      if (current()) setState(() => failed = true);
    } finally {
      if (sameRequest()) {
        setState(() {
          loading = false;
          if (!scope()) {
            placements = null;
            currentSession = null;
            expanded = false;
            failed = false;
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final arabic = Localizations.localeOf(context).languageCode == 'ar';
    String label(String en, String ar) => arabic ? ar : en;
    if (!foreground || !ownsSession()) return const SizedBox.shrink();
    final epoch = generation;
    final scope = ownsSession;
    final api = widget.api;
    bool active() => mounted && foreground && epoch == generation &&
        identical(api, widget.api) && identical(scope, ownsSession) && scope();
    final visible = expanded && (currentSession?.call() ?? false);
    if (discovery &&
        (!visible || (placements?.isEmpty == true && page == 1 && lastPage == 1))) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (discovery && placements?.isNotEmpty == true)
          Text(label('Sponsored promotions', 'عروض ترويجية برعاية')),
        if (!discovery)
          TextButton(
            onPressed: validSource()
                ? () {
                    if (!active()) return;
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
        if (visible && loading) Semantics(
          liveRegion: true,
          label: label('Loading sponsored promotions', 'جارٍ تحميل العروض برعاية'),
          child: const LinearProgressIndicator(),
        ),
        if (visible && failed)
          TextButton(
            onPressed: () { if (active()) load(page); },
            child: Text(label('Retry promotions', 'إعادة تحميل العروض')),
          ),
        if (visible && placements != null) ...[
          if (placements!.isNotEmpty)
            Text(label('Paid placement, not verified availability or compatibility.',
                'إعلان مدفوع، وليس تأكيداً للتوفر أو التوافق.')),
          for (final row in placements!)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(row[arabic ? 'title_ar' : 'title'] as String),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label('Sponsored · Station #${row['station_id']}',
                      'برعاية · المحطة رقم ${row['station_id']}')),
                  Text(row[arabic ? 'description_ar' : 'description'] as String),
                ],
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
                  onPressed: () { if (active()) load(page > lastPage ? lastPage : page - 1); },
                  child: Text(label('Previous promotions', 'العروض السابقة')),
                ),
              if (page < lastPage)
                TextButton(
                  onPressed: () { if (active()) load(page + 1); },
                  child: Text(label('Next promotions', 'العروض التالية')),
                ),
            ],
          ),
        ],
      ],
    );
  }
}
