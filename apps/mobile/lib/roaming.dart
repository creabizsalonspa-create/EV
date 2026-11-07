import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'sandbox_roaming.dart';

/// A partner location cannot grant charging, availability or payment authority.
class RoamingLocation {
  final Map<String, dynamic> location;
  final String? received;
  final int? id;
  final bool available;
  RoamingLocation.fromJson(Map<String, dynamic> json)
    : id = json['id'] as int?,
      available = json['location_available'] != false,
      location = json['location'] is Map
          ? Map<String, dynamic>.from(json['location'] as Map)
          : {},
      received = json['received_at'] as String? {
    if ((available && (json['location'] is! Map || received == null)) ||
        (!available &&
            (id == null ||
                json['is_favorite'] != true ||
                json['location'] != null ||
                received != null)) ||
        (id != null && id! <= 0)) {
      throw const FormatException('Invalid partner location shape.');
    }
    if (json['evidence'] != 'PARTNER_REPORTED_UNVERIFIED' ||
        json['availability'] != 'unknown' ||
        json['remote_control'] != false ||
        json['reservable'] != false) {
      throw const FormatException('Unexpected roaming capability.');
    }
  }
}

typedef RoamingLoader = Future<ApiPage<RoamingLocation>> Function(int page);
typedef RoamingQueryLoader =
    Future<ApiPage<RoamingLocation>> Function(
      Map<String, String> filters,
      int page,
    );

class RoamingView extends StatefulWidget {
  final RoamingLoader loader;
  final RoamingQueryLoader? queryLoader;
  final EvApi? api;
  final int? accountId;
  final VoidCallback? onExpired;
  const RoamingView({
    super.key,
    required this.loader,
    this.queryLoader,
    this.api,
    this.accountId,
    this.onExpired,
  }) : assert(api == null || accountId != null);
  @override
  State<RoamingView> createState() => _RoamingViewState();
}

class _RoamingViewState extends State<RoamingView> {
  ApiPage<RoamingLocation>? data;
  bool loading = true;
  Object? error;
  int generation = 0, target = 1;
  bool savedOnly = false, saving = false, uncertain = false;
  Set<int> saved = {};
  final searchForm = GlobalKey<FormState>();
  final searchText = TextEditingController(),
      countryText = TextEditingController();
  String connector = '';
  Map<String, String> filters = {};
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    load(1);
  }

  @override
  void didUpdateWidget(covariant RoamingView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.api != oldWidget.api ||
        widget.accountId != oldWidget.accountId) {
      savedOnly = false;
      filters = {};
      searchText.clear();
      countryText.clear();
      connector = '';
      load(1);
    }
  }

  Future<void> load(int page) async {
    final epoch = ++generation;
    setState(() {
      loading = true;
      error = null;
      data = null;
      saved = {};
      target = page;
    });
    try {
      final result = savedOnly && widget.api != null
          ? ApiPage<RoamingLocation>.fromJson(
              await widget.api!.request(
                'roaming/favorites',
                query: {'page': '$page'},
              ),
              RoamingLocation.fromJson,
            )
          : widget.queryLoader != null
          ? await widget.queryLoader!(Map.unmodifiable(filters), page)
          : await widget.loader(page);
      if (mounted &&
          epoch == generation &&
          savedOnly &&
          result.items.isEmpty &&
          result.last >= 1 &&
          page > result.last) {
        await load(result.last);
        return;
      }
      final ids = result.items.map((row) => row.id).whereType<int>().toList();
      Set<int> favorites = {};
      if (widget.api != null && ids.isNotEmpty) {
        if (savedOnly) {
          favorites = ids.toSet();
        } else {
          final status = await widget.api!.request(
            'roaming/favorites/status',
            method: 'POST',
            body: {'record_ids': ids},
          );
          favorites = (status['favorite_record_ids'] as List)
              .cast<int>()
              .toSet();
        }
      }
      if (mounted && epoch == generation)
        setState(() {
          data = result;
          saved = favorites;
          uncertain = false;
        });
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() {
          error = e;
          data = null;
          saved = {};
        });
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired?.call();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => loading = false);
    }
  }

  Future<void> sandbox([int? locationId]) async {
    final api = widget.api;
    if (api == null) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => SandboxRoamingView(
          api: api,
          locationId: locationId,
          onExpired: widget.onExpired ?? () {},
        ),
      ),
    );
  }

  Future<void> toggle(RoamingLocation row) async {
    if (saving || loading || uncertain || widget.api == null || row.id == null)
      return;
    final remove = saved.contains(row.id), epoch = generation;
    setState(() => saving = true);
    try {
      await widget.api!.request(
        'roaming/favorites/${row.id}',
        method: remove ? 'DELETE' : 'PUT',
      );
      if (mounted && epoch == generation) await load(data?.current ?? 1);
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() {
          uncertain = true;
          error = e;
          data = null;
          saved = {};
        });
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired?.call();
      }
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  void dispose() {
    generation++;
    searchText.dispose();
    countryText.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    return Scaffold(
      appBar: AppBar(title: Text(s.t('roaming'))),
      body: RefreshIndicator(
        onRefresh: () => load(page?.current ?? target),
        child: ListView(
          padding: const EdgeInsets.all(16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            Text(s.t('roamingDisclaimer')),
            if (widget.api != null)
              TextButton(
                onPressed: () => sandbox(),
                child: Text(
                  tr('Review sandbox history', 'مراجعة سجل التجوال التجريبي'),
                ),
              ),
            if (widget.api != null)
              TextButton(
                onPressed: loading || saving
                    ? null
                    : () {
                        setState(() => savedOnly = !savedOnly);
                        load(1);
                      },
                child: Text(
                  savedOnly
                      ? tr('All partner locations', 'كل مواقع الشركاء')
                      : tr('Saved partner locations', 'مواقع الشركاء المحفوظة'),
                ),
              ),
            if (widget.queryLoader != null && !savedOnly)
              Form(
                key: searchForm,
                child: Column(
                  children: [
                    TextFormField(
                      controller: searchText,
                      maxLength: 80,
                      decoration: InputDecoration(
                        labelText: tr(
                          'Search partner locations',
                          'البحث في مواقع الشركاء',
                        ),
                      ),
                    ),
                    TextFormField(
                      controller: countryText,
                      maxLength: 3,
                      textCapitalization: TextCapitalization.characters,
                      decoration: InputDecoration(
                        labelText: tr(
                          'Country code (e.g. ARE)',
                          'رمز الدولة (مثلاً ARE)',
                        ),
                      ),
                      validator: (value) =>
                          (value ?? '').trim().isEmpty ||
                              RegExp(
                                r'^[a-zA-Z]{3}$',
                              ).hasMatch((value ?? '').trim())
                          ? null
                          : tr(
                              'Use a three-letter country code.',
                              'استخدم رمز دولة من ثلاثة أحرف.',
                            ),
                    ),
                    DropdownButtonFormField<String>(
                      key: ValueKey('partner-connector-${widget.accountId}'),
                      initialValue: connector,
                      isExpanded: true,
                      decoration: InputDecoration(
                        labelText: tr('Connector', 'الموصل'),
                      ),
                      items: [
                        DropdownMenuItem(
                          value: '',
                          child: Text(tr('All connectors', 'كل الموصلات')),
                        ),
                        const DropdownMenuItem(
                          value: 'IEC_62196_T2_COMBO',
                          child: Text('CCS2'),
                        ),
                        const DropdownMenuItem(
                          value: 'IEC_62196_T1_COMBO',
                          child: Text('CCS1'),
                        ),
                        const DropdownMenuItem(
                          value: 'IEC_62196_T2',
                          child: Text('Type 2'),
                        ),
                        const DropdownMenuItem(
                          value: 'CHADEMO',
                          child: Text('CHAdeMO'),
                        ),
                      ],
                      onChanged: loading || saving
                          ? null
                          : (value) => setState(() => connector = value ?? ''),
                    ),
                    TextButton(
                      onPressed: loading || saving
                          ? null
                          : () {
                              if (!(searchForm.currentState?.validate() ??
                                  false))
                                return;
                              filters = {
                                if (searchText.text.trim().isNotEmpty)
                                  'q': searchText.text.trim(),
                                if (countryText.text.trim().isNotEmpty)
                                  'country': countryText.text
                                      .trim()
                                      .toUpperCase(),
                                if (connector.isNotEmpty)
                                  'connector_standard': connector,
                              };
                              load(1);
                            },
                      child: Text(
                        tr(
                          'Search partner locations',
                          'البحث في مواقع الشركاء',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            if (loading) const LinearProgressIndicator(),
            if (uncertain)
              Text(
                tr(
                  "Saved location update uncertain. Refresh to check.",
                  "نتيجة تحديث الموقع المحفوظ غير مؤكدة. حدّث للتحقق.",
                ),
              ),
            if (error != null) ...[
              Text(s.t('genericError'), semanticsLabel: s.t('genericError')),
              OutlinedButton(
                onPressed: loading ? null : () => load(target),
                child: Text(s.t('retry')),
              ),
            ],
            // Do not leave previous-page data visible after a failed refresh.
            if (!loading && error == null && page != null) ...[
              if (page.items.isEmpty)
                Text(
                  savedOnly
                      ? tr(
                          'No saved partner locations',
                          'لا توجد مواقع شركاء محفوظة',
                        )
                      : s.t('roamingEmpty'),
                ),
              for (final row in page.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (widget.api != null && row.id != null)
                          TextButton(
                            onPressed: saving || loading || uncertain
                                ? null
                                : () => toggle(row),
                            child: Text(
                              saved.contains(row.id)
                                  ? tr(
                                      'Remove saved partner location',
                                      'إزالة موقع الشريك المحفوظ',
                                    )
                                  : tr(
                                      'Save partner location',
                                      'حفظ موقع الشريك',
                                    ),
                            ),
                          ),
                        if (widget.api != null &&
                            row.id != null &&
                            row.available)
                          TextButton(
                            onPressed: () => sandbox(row.id),
                            child: Text(
                              tr(
                                'View sandbox offers',
                                'عرض عروض التجوال التجريبي',
                              ),
                            ),
                          ),
                        if (!row.available)
                          Text(
                            tr(
                              'Saved partner location is unavailable',
                              'موقع الشريك المحفوظ غير متاح',
                            ),
                          ),
                        if (row.available) ...[
                          Text(
                            '${row.location['name'] ?? row.location['id']}',
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          Text(
                            '${row.location['address']} · ${row.location['city']} · ${row.location['country']}',
                          ),
                          SelectableText(
                            s.t('roamingCoordinates', {
                              'value':
                                  '${row.location['latitude']}, ${row.location['longitude']}',
                            }),
                          ),
                          Text(
                            s.t('roamingUpdated', {
                              'value': '${row.location['last_updated']}',
                            }),
                          ),
                          if (row.received != null)
                            Text(
                              s.t('roamingReceived', {'value': row.received!}),
                            ),
                          for (final evse
                              in (row.location['evses'] as List? ?? [])) ...[
                            Text(
                              s.t('roamingEvse', {'value': '${evse['uid']}'}),
                            ),
                            Text(
                              s.t('roamingReported', {
                                'value': '${evse['reported_status']}',
                              }),
                            ),
                            for (final connector in evse['connectors'] as List)
                              Text(
                                '${connector['id']} · ${connector['standard']}',
                              ),
                          ],
                          Text(s.t('roamingUnknown')),
                        ],
                      ],
                    ),
                  ),
                ),
              Text(
                s.t('page', {
                  'page': page.current,
                  'last': page.last,
                  'total': page.total,
                }),
              ),
              Wrap(
                spacing: 12,
                children: [
                  OutlinedButton(
                    onPressed: page.current > 1
                        ? () => load(page.current - 1)
                        : null,
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: page.current < page.last
                        ? () => load(page.current + 1)
                        : null,
                    child: Text(s.t('next')),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
