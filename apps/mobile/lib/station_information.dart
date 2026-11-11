import 'package:flutter/material.dart';
import 'api.dart';

const stationInfoDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const stationInfoDaysEn = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const stationInfoDaysAr = [
  'الإثنين',
  'الثلاثاء',
  'الأربعاء',
  'الخميس',
  'الجمعة',
  'السبت',
  'الأحد',
];
const stationInfoAmenities = {
  'restroom': ['Restroom', 'دورة مياه'],
  'wifi': ['Wi-Fi', 'واي فاي'],
  'cafe': ['Café', 'مقهى'],
  'parking': ['Parking', 'موقف سيارات'],
  'lighting': ['Lighting', 'إضاءة'],
  'shelter': ['Shelter', 'مأوى'],
  'shop': ['Shop', 'متجر'],
  'security': ['Security', 'أمن'],
};
const stationInfoAccess = {
  'step_free': ['Step-free access', 'دخول بدون درجات'],
  'accessible_parking': ['Accessible parking', 'مواقف مهيأة'],
  'accessible_restroom': ['Accessible restroom', 'دورة مياه مهيأة'],
  'wide_bays': ['Wide bays', 'مواقف واسعة'],
};
bool stationInfoArabic(BuildContext c) =>
    Localizations.localeOf(c).languageCode == 'ar';
String stationInfoT(BuildContext c, String en, String ar) =>
    stationInfoArabic(c) ? ar : en;
String stationInfoLocalized(Map<String, dynamic> data, bool ar, String name) {
  final primary = data[name + (ar ? '_ar' : '_en')];
  final other = data[name + (ar ? '_en' : '_ar')];
  if (primary is String && primary.trim().isNotEmpty) return primary.trim();
  return other is String ? other.trim() : '';
}

/// Vendor metadata never implies real-time charger availability.
class StationInformationPanel extends StatelessWidget {
  final Map<String, dynamic>? information;
  final String timezone;
  const StationInformationPanel({
    super.key,
    required this.information,
    required this.timezone,
  });
  @override
  Widget build(BuildContext context) {
    final info = information ?? <String, dynamic>{};
    final ar = stationInfoArabic(context);
    final published = info['source_label'] == 'VENDOR_REPORTED_UNVERIFIED';
    final weekly = info['weekly_hours'] is Map
        ? info['weekly_hours'] as Map
        : <String, dynamic>{};
    // The catalog's numeric scroll offset may already occupy its
    // PageStorage bucket. Scope expansion state to this station's metadata
    // instead of reading that double as a bool and crashing on refresh.
    return KeyedSubtree(
      key: const Key('station-information-panel'),
      child: ExpansionTile(
        key: PageStorageKey<Object>(information ?? Object()),
        title: Text(
          stationInfoT(
            context,
            'Hours, access & facilities',
            'ساعات العمل والدخول والمرافق',
          ),
        ),
        childrenPadding: const EdgeInsets.all(16),
        expandedCrossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (!published)
            Text(
              stationInfoT(
                context,
                'Operator has not published station hours or facilities.',
                'لم ينشر المشغل ساعات العمل أو تفاصيل المرافق.',
              ),
            )
          else ...[
            Text(
              stationInfoT(
                context,
                'Vendor-reported, not independently verified. Live charger availability is unknown.',
                'معلومات أفاد بها المشغل ولم يتم التحقق منها؛ توفر الشاحن غير معروف.',
              ),
            ),
            if (stationInfoLocalized(info, ar, 'description').isNotEmpty)
              Text(stationInfoLocalized(info, ar, 'description')),
            if (stationInfoLocalized(info, ar, 'access').isNotEmpty)
              Text(
                stationInfoT(context, 'Access: ', 'الدخول: ') +
                    stationInfoLocalized(info, ar, 'access'),
              ),
            if (info['notice_active'] == true &&
                stationInfoLocalized(info, ar, 'notice').isNotEmpty) ...[
              Text(
                stationInfoT(
                      context,
                      'Operator access notice: ',
                      'تنبيه دخول من المشغل: ',
                    ) +
                    stationInfoLocalized(info, ar, 'notice'),
              ),
              if (info['notice_until'] is String)
                Text(
                  stationInfoT(
                        context,
                        'Notice expires (UTC): ',
                        'ينتهي التنبيه (UTC): ',
                      ) +
                      info['notice_until'],
                ),
            ],
            Text(
              stationInfoT(context, 'Hours (', 'ساعات العمل (') +
                  timezone +
                  ')',
            ),
            for (var i = 0; i < stationInfoDays.length; i++)
              Builder(
                builder: (_) {
                  final day = weekly[stationInfoDays[i]];
                  final h = day is Map ? day : <String, dynamic>{};
                  final start = h['open'], end = h['close'];
                  final times = start is String && end is String
                      ? (start == end
                            ? stationInfoT(context, '24 hours', '٢٤ ساعة')
                            : start +
                                  '–' +
                                  end +
                                  (start.compareTo(end) > 0
                                      ? stationInfoT(
                                          context,
                                          ' (next day)',
                                          ' (لليوم التالي)',
                                        )
                                      : ''))
                      : stationInfoT(context, 'Closed', 'مغلق');
                  return Text(
                    (ar ? stationInfoDaysAr[i] : stationInfoDaysEn[i]) +
                        ': ' +
                        times,
                  );
                },
              ),
            if (info['closed_dates'] is List &&
                (info['closed_dates'] as List).isNotEmpty)
              Text(
                stationInfoT(
                      context,
                      'Holiday closures: ',
                      'إغلاقات العطلات: ',
                    ) +
                    (info['closed_dates'] as List).join(', '),
              ),
            for (final entry in [
              (
                key: 'amenities',
                choices: stationInfoAmenities,
                en: 'Facilities: ',
                ar: 'المرافق: ',
              ),
              (
                key: 'accessibility',
                choices: stationInfoAccess,
                en: 'Accessibility: ',
                ar: 'إمكانية الوصول: ',
              ),
            ])
              Builder(
                builder: (_) {
                  final names = info[entry.key] is List
                      ? (info[entry.key] as List)
                            .whereType<String>()
                            .where(entry.choices.containsKey)
                            .toList()
                      : <String>[];
                  return names.isEmpty
                      ? const SizedBox.shrink()
                      : Text(
                          stationInfoT(context, entry.en, entry.ar) +
                              names
                                  .map((s) => entry.choices[s]![ar ? 1 : 0])
                                  .join(', '),
                        );
                },
              ),
            Text(
              stationInfoT(
                context,
                'Published hours do not guarantee reservation or charger availability.',
                'الساعات المنشورة لا تضمن الحجز أو توفر الشاحن.',
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// The authenticated API returns only the vendor's own stations; PUT rechecks ownership.
class VendorStationInformationView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const VendorStationInformationView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<VendorStationInformationView> createState() =>
      _VendorStationInfoState();
}

class _VendorStationInfoState extends State<VendorStationInformationView>
    with WidgetsBindingObserver {
  final form = GlobalKey<FormState>();
  final infoText = <String, TextEditingController>{
    for (final key in [
      'description_en',
      'description_ar',
      'access_en',
      'access_ar',
      'notice_en',
      'notice_ar',
    ])
      key: TextEditingController(),
  };
  final starts = <String, TextEditingController>{
    for (final day in stationInfoDays) day: TextEditingController(),
  };
  final ends = <String, TextEditingController>{
    for (final day in stationInfoDays) day: TextEditingController(),
  };
  final holidays = TextEditingController();
  final noticeUntil = TextEditingController();
  final closed = <String>{}, amenities = <String>{}, access = <String>{};
  List<Map<String, dynamic>> owned = [];
  Map<String, dynamic>? chosen, info;
  int generation = 0, page = 1, lastPage = 1;
  late bool Function() accountFence;
  bool busy = false, saving = false, background = false;
  String? error, success;
  String t(String en, String ar) => stationInfoT(context, en, ar);
  @override
  void initState() {
    super.initState();
    accountFence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    loadOwned();
  }

  @override
  void didUpdateWidget(covariant VendorStationInformationView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (identical(oldWidget.api, widget.api) && accountFence()) return;
    // This State can be reused after a vendor/account switch. Empty all
    // tenant-private stations and form controllers before requesting the new
    // identity's data; old asynchronous responses are fenced by generation.
    clearPrivate();
    accountFence = widget.api.sessionFence();
    loadOwned();
  }

  // Invalidate late responses and erase tenant-private editor contents. Never
  // capture the next account's fence while retaining the previous account data.
  void clearPrivate() {
    generation++;
    owned = [];
    chosen = info = null;
    page = lastPage = 1;
    busy = saving = false;
    error = success = null;
    for (final c in [
      ...infoText.values,
      ...starts.values,
      ...ends.values,
      holidays,
      noticeUntil,
    ]) {
      c.clear();
    }
    closed.clear();
    amenities.clear();
    access.clear();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      background = state != AppLifecycleState.resumed;
      clearPrivate();
    });
    // Resuming requires an explicit refresh; do not restore cached details.
  }

  bool rejectStaleAccount() {
    if (!background && accountFence()) return false;
    if (mounted) setState(clearPrivate);
    return true;
  }

  void finish(int ticket, bool Function() fence, {bool writing = false}) {
    if (!mounted || ticket != generation) return;
    setState(() {
      if (!fence()) {
        clearPrivate();
      } else if (writing) {
        saving = false;
      } else {
        busy = false;
      }
    });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    for (final c in [
      ...infoText.values,
      ...starts.values,
      ...ends.values,
      holidays,
      noticeUntil,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  void fail(Object e) {
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted)
      setState(
        () => error = e is ApiException && e.status == 409
            ? t(
                'Information changed. Refresh before trying again.',
                'تغيرت المعلومات. حدّث قبل المحاولة مجدداً.',
              )
            : e.toString(),
      );
  }

  Future<void> loadOwned([int target = 1]) async {
    if (rejectStaleAccount()) return;
    final ticket = ++generation, fence = widget.api.sessionFence();
    setState(() {
      busy = true;
      error = success = null;
      chosen = info = null;
      owned = [];
    });
    try {
      final response = await widget.api.request(
        'vendor/stations',
        query: {'page': target.toString()},
      );
      final result = ApiPage<Map<String, dynamic>>.fromJson(response, (v) => v);
      if (!mounted || ticket != generation || !fence()) return;
      setState(() {
        page = result.current;
        lastPage = result.last;
        owned = result.items;
      });
      if (owned.isNotEmpty) await choose(owned.first);
    } catch (e) {
      if (mounted && ticket == generation && fence()) fail(e);
    } finally {
      finish(ticket, fence);
    }
  }

  Future<void> choose(Map<String, dynamic> station) async {
    if (rejectStaleAccount()) return;
    final ticket = ++generation, fence = widget.api.sessionFence();
    setState(() {
      chosen = station;
      info = null;
      busy = true;
      error = success = null;
    });
    try {
      final response = await widget.api.request(
        'stations/' + station['id'].toString() + '/information',
      );
      if (!mounted || ticket != generation || !fence()) return;
      final data = Map<String, dynamic>.from(response as Map);
      setState(() {
        info = data;
        for (final key in infoText.keys) {
          infoText[key]!.text = (data[key] as String?) ?? '';
        }
        holidays.text = (data['closed_dates'] as List? ?? []).join('\n');
        noticeUntil.text = (data['notice_until'] as String?) ?? '';
        closed.clear();
        final week = data['weekly_hours'] is Map
            ? data['weekly_hours'] as Map
            : <String, dynamic>{};
        for (final day in stationInfoDays) {
          final h = week[day] is Map ? week[day] as Map : <String, dynamic>{};
          starts[day]!.text = (h['open'] as String?) ?? '';
          ends[day]!.text = (h['close'] as String?) ?? '';
          if (h['open'] == null || h['close'] == null) closed.add(day);
        }
        amenities
          ..clear()
          ..addAll(
            (data['amenities'] as List? ?? []).whereType<String>().where(
              stationInfoAmenities.containsKey,
            ),
          );
        access
          ..clear()
          ..addAll(
            (data['accessibility'] as List? ?? []).whereType<String>().where(
              stationInfoAccess.containsKey,
            ),
          );
      });
    } catch (e) {
      if (mounted && ticket == generation && fence()) fail(e);
    } finally {
      finish(ticket, fence);
    }
  }

  Future<void> save() async {
    if (rejectStaleAccount()) return;
    if (chosen == null ||
        info == null ||
        saving ||
        !form.currentState!.validate())
      return;
    final week = <String, Map<String, String?>>{};
    final valid = RegExp(r'^([01][0-9]|2[0-3]):[0-5][0-9]$');
    for (final day in stationInfoDays) {
      if (closed.contains(day)) {
        week[day] = {'open': null, 'close': null};
        continue;
      }
      final start = starts[day]!.text.trim(), end = ends[day]!.text.trim();
      if (!valid.hasMatch(start) || !valid.hasMatch(end)) {
        setState(
          () => error = t(
            'Invalid opening/closing time.',
            'وقت فتح أو إغلاق غير صالح.',
          ),
        );
        return;
      }
      week[day] = {'open': start, 'close': end};
    }
    final noticeText =
        (infoText['notice_en']!.text.trim() +
                infoText['notice_ar']!.text.trim())
            .isNotEmpty;
    final expiry = noticeUntil.text.trim();
    if (noticeText != expiry.isNotEmpty ||
        (expiry.isNotEmpty &&
            !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(expiry))) {
      setState(
        () => error = t(
          'Provide a valid UTC expiry date with a notice, or clear both.',
          'أدخل تاريخ انتهاء صالح بتوقيت UTC مع التنبيه أو احذف الاثنين.',
        ),
      );
      return;
    }
    final dates = holidays.text
        .split(RegExp(r'[\n,]+'))
        .map((s) => s.trim())
        .where((s) => s.isNotEmpty)
        .toSet()
        .toList();
    if (dates.length > 50) {
      setState(
        () => error = t('At most 50 holiday dates.', 'الحد الأقصى 50 تاريخاً.'),
      );
      return;
    }
    final body = <String, dynamic>{
      'revision': info!['revision'],
      for (final key in infoText.keys)
        key: infoText[key]!.text.trim().isEmpty
            ? null
            : infoText[key]!.text.trim(),
      'notice_until': expiry.isEmpty ? null : expiry,
      'weekly_hours': week,
      'closed_dates': dates,
      'amenities': amenities.toList()..sort(),
      'accessibility': access.toList()..sort(),
    };
    final ticket = generation,
        fence = widget.api.sessionFence(),
        stationId = chosen!['id'];
    setState(() {
      saving = true;
      error = success = null;
    });
    try {
      final result = await widget.api.request(
        'stations/' + stationId.toString() + '/information',
        method: 'PUT',
        body: body,
      );
      if (!mounted || ticket != generation || !fence()) return;
      setState(() {
        info = Map<String, dynamic>.from(result as Map);
        success = t('Saved', 'تم الحفظ');
      });
    } catch (e) {
      if (mounted && ticket == generation && fence()) fail(e);
    } finally {
      finish(ticket, fence, writing: true);
    }
  }

  Widget multiline(String key, String en, String ar) => TextFormField(
    key: Key('station-info-' + key),
    controller: infoText[key],
    maxLength: key.startsWith('notice_') ? 300 : 500,
    minLines: 2,
    maxLines: 4,
    decoration: InputDecoration(labelText: t(en, ar)),
  );
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(t('Station hours & facilities', 'ساعات العمل ومرافق المحطة')),
    ),
    body: SafeArea(
      child: background || !accountFence()
          ? Center(
              child: Text(
                t(
                  'Private station details cleared. Return to your account to continue.',
                  'تم مسح تفاصيل المحطة الخاصة. ارجع إلى حسابك للمتابعة.',
                ),
              ),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  t(
                    'Vendor-reported details do not change prices, status or charger control.',
                    'تفاصيل المشغل لا تغير الأسعار أو الحالة أو التحكم بالشاحن.',
                  ),
                ),
                if (busy) const LinearProgressIndicator(),
                if (error != null)
                  Text(error!, key: const Key('station-info-error')),
                if (success != null)
                  Text(success!, key: const Key('station-info-success')),
                if (owned.isEmpty && !busy)
                  Text(t('No registered stations.', 'لا توجد محطات مسجلة.')),
                if (owned.isNotEmpty)
                  DropdownButtonFormField<int>(
                    key: const Key('station-info-picker'),
                    initialValue: chosen?['id'] as int?,
                    decoration: InputDecoration(
                      labelText: t('Station', 'المحطة'),
                    ),
                    items: owned
                        .map(
                          (row) => DropdownMenuItem<int>(
                            value: row['id'] as int,
                            child: Text(row['name'] as String),
                          ),
                        )
                        .toList(),
                    onChanged: saving || busy
                        ? null
                        : (id) {
                            if (id != null)
                              choose(
                                owned.firstWhere((row) => row['id'] == id),
                              );
                          },
                  ),
                if (owned.isNotEmpty)
                  Row(
                    children: [
                      TextButton(
                        onPressed: busy || saving || page <= 1
                            ? null
                            : () => loadOwned(page - 1),
                        child: Text(t('Previous', 'السابق')),
                      ),
                      Text(page.toString() + ' / ' + lastPage.toString()),
                      TextButton(
                        onPressed: busy || saving || page >= lastPage
                            ? null
                            : () => loadOwned(page + 1),
                        child: Text(t('Next', 'التالي')),
                      ),
                    ],
                  ),
                TextButton(
                  onPressed: busy || saving
                      ? null
                      : () {
                          if (chosen == null) {
                            loadOwned(page);
                          } else {
                            choose(chosen!);
                          }
                        },
                  child: Text(t('Refresh from server', 'تحديث من الخادم')),
                ),
                if (info != null)
                  Form(
                    key: form,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        multiline(
                          'description_en',
                          'Description (English)',
                          'الوصف بالإنجليزية',
                        ),
                        multiline(
                          'description_ar',
                          'Description (Arabic)',
                          'الوصف بالعربية',
                        ),
                        multiline(
                          'access_en',
                          'Access instructions (English)',
                          'تعليمات الدخول بالإنجليزية',
                        ),
                        multiline(
                          'access_ar',
                          'Access instructions (Arabic)',
                          'تعليمات الدخول بالعربية',
                        ),
                        multiline(
                          'notice_en',
                          'Temporary access notice (English)',
                          'تنبيه وصول مؤقت بالإنجليزية',
                        ),
                        multiline(
                          'notice_ar',
                          'Temporary access notice (Arabic)',
                          'تنبيه وصول مؤقت بالعربية',
                        ),
                        TextFormField(
                          key: const Key('station-info-notice-until'),
                          controller: noticeUntil,
                          decoration: InputDecoration(
                            labelText: t(
                              'Notice expiry (UTC, YYYY-MM-DD)',
                              'انتهاء التنبيه (UTC، YYYY-MM-DD)',
                            ),
                          ),
                          keyboardType: TextInputType.datetime,
                        ),
                        Text(
                          t(
                                'Opening hours (station local time)',
                                'ساعات العمل بتوقيت المحطة',
                              ) +
                              ' · ' +
                              (chosen?['timezone'] is String &&
                                      (chosen!['timezone'] as String)
                                          .trim()
                                          .isNotEmpty
                                  ? chosen!['timezone'] as String
                                  : t(
                                      'timezone not provided',
                                      'المنطقة الزمنية غير متوفرة',
                                    )),
                        ),
                        for (var i = 0; i < stationInfoDays.length; i++) ...[
                          CheckboxListTile(
                            key: Key(
                              'station-info-closed-' + stationInfoDays[i],
                            ),
                            title: Text(
                              t(
                                stationInfoDaysEn[i] + ' closed',
                                stationInfoDaysAr[i] + ' مغلق',
                              ),
                            ),
                            value: closed.contains(stationInfoDays[i]),
                            onChanged: saving
                                ? null
                                : (v) => setState(() {
                                    if (v == true) {
                                      closed.add(stationInfoDays[i]);
                                    } else {
                                      closed.remove(stationInfoDays[i]);
                                    }
                                  }),
                          ),
                          Row(
                            children: [
                              Expanded(
                                child: TextFormField(
                                  controller: starts[stationInfoDays[i]],
                                  readOnly:
                                      closed.contains(stationInfoDays[i]) ||
                                      saving,
                                  decoration: InputDecoration(
                                    labelText: t('Opens HH:mm', 'يفتح'),
                                  ),
                                  keyboardType: TextInputType.datetime,
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: TextFormField(
                                  controller: ends[stationInfoDays[i]],
                                  readOnly:
                                      closed.contains(stationInfoDays[i]) ||
                                      saving,
                                  decoration: InputDecoration(
                                    labelText: t('Closes HH:mm', 'يغلق'),
                                  ),
                                  keyboardType: TextInputType.datetime,
                                ),
                              ),
                            ],
                          ),
                        ],
                        TextFormField(
                          controller: holidays,
                          key: const Key('station-info-holidays'),
                          minLines: 2,
                          maxLines: 4,
                          maxLength: 550,
                          decoration: InputDecoration(
                            labelText: t(
                              'Holiday dates (YYYY-MM-DD, one per line)',
                              'تواريخ العطلات (تاريخ في كل سطر)',
                            ),
                          ),
                        ),
                        Text(t('Facilities', 'المرافق')),
                        for (final entry in stationInfoAmenities.entries)
                          CheckboxListTile(
                            title: Text(t(entry.value[0], entry.value[1])),
                            value: amenities.contains(entry.key),
                            onChanged: saving
                                ? null
                                : (v) => setState(() {
                                    if (v == true) {
                                      amenities.add(entry.key);
                                    } else {
                                      amenities.remove(entry.key);
                                    }
                                  }),
                          ),
                        Text(t('Accessibility', 'إمكانية الوصول')),
                        for (final entry in stationInfoAccess.entries)
                          CheckboxListTile(
                            title: Text(t(entry.value[0], entry.value[1])),
                            value: access.contains(entry.key),
                            onChanged: saving
                                ? null
                                : (v) => setState(() {
                                    if (v == true) {
                                      access.add(entry.key);
                                    } else {
                                      access.remove(entry.key);
                                    }
                                  }),
                          ),
                        FilledButton(
                          key: const Key('station-info-save'),
                          onPressed: saving || busy ? null : save,
                          child: Text(
                            t('Save station information', 'حفظ معلومات المحطة'),
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
    ),
  );
}
