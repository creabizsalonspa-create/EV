import 'package:flutter/material.dart';
import 'api.dart';

/// Vendor-scoped aggregate feedback. Never a physical charging verification.
class VendorFeedbackAnalyticsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;

  const VendorFeedbackAnalyticsView({
    super.key,
    required this.api,
    required this.onExpired,
  });

  @override
  State<VendorFeedbackAnalyticsView> createState() => _VendorFeedbackAnalyticsState();
}

class _VendorFeedbackAnalyticsState extends State<VendorFeedbackAnalyticsView> {
  Map<String, dynamic>? report;
  int days = 30, generation = 0;
  bool loading = false;
  String? error;

  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    refresh();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> refresh([int? period]) async {
    if (period != null) days = period;
    final ticket = ++generation;
    final fence = widget.api.sessionFence();
    setState(() { loading = true; error = null; report = null; });
    try {
      final data = await widget.api.request(
        'vendor/feedback-analytics', query: {'days': days.toString()},
      );
      final result = Map<String, dynamic>.from(data as Map);
      if (result['physical_charging_verified'] != false ||
          result['source'] != 'MODERATED_SIMULATOR_REVIEWS_ONLY' ||
          result['total'] is! int || result['stations'] is! List ||
          result['star_distribution'] is! Map ||
          result['evidence_counts'] is! Map) {
        throw const FormatException('Unexpected feedback analytics source');
      }
      if (!mounted || ticket != generation || !fence()) return;
      setState(() => report = result);
    } catch (e) {
      if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
      if (mounted && ticket == generation) setState(() => error = e.toString());
    } finally {
      if (mounted && ticket == generation) setState(() => loading = false);
    }
  }

  String evidence(String key) => switch (key) {
    'SIMULATED_APP_ONLY' => t('App simulator', 'محاكاة التطبيق'),
    'SIMULATED_SOURCE_OBSERVED' => t('Observed simulator', 'محاكاة مرصودة'),
    _ => key,
  };

  @override
  Widget build(BuildContext context) {
    final data = report;
    return Scaffold(
      appBar: AppBar(title: Text(t('Customer feedback analytics', 'تحليلات ملاحظات العملاء'))),
      body: SafeArea(child: ListView(padding: const EdgeInsets.all(16), children: [
        Text(t(
          'Only published, moderated simulator feedback. This does not verify real charging, live availability or customer identities.',
          'ملاحظات محاكاة منشورة وخضعت للمراجعة فقط؛ لا تثبت الشحن الحقيقي أو التوفر المباشر أو هوية العملاء.',
        )),
        DropdownButtonFormField<int>(
          key: ValueKey('feedback-days-' + days.toString()),
          initialValue: days,
          decoration: InputDecoration(labelText: t('Recent review events', 'أحداث الملاحظات الأخيرة')),
          items: [7,30,90].map((v) => DropdownMenuItem<int>(
            value: v, child: Text(v.toString() + t(' days', ' يومًا')),
          )).toList(),
          onChanged: loading ? null : (v) { if (v != null) refresh(v); },
        ),
        if (loading) const LinearProgressIndicator(),
        if (error != null)
          Semantics(liveRegion: true, child: Text(error!, key: const Key('feedback-analytics-error'))),
        TextButton.icon(
          onPressed: loading ? null : () => refresh(),
          icon: const Icon(Icons.refresh), label: Text(t('Refresh', 'تحديث')),
        ),
        if (data != null) ...[
          Text(t('Published reviews: ', 'الملاحظات المنشورة: ') + data['total'].toString()),
          Text(t('Average rating: ', 'متوسط التقييم: ') +
              (data['average_rating'] == null ? '—' : data['average_rating'].toString() + ' / 5')),
          const SizedBox(height: 12),
          Text(t('Rating distribution', 'توزيع التقييمات')),
          for (var stars = 5; stars >= 1; stars--)
            Builder(builder: (_) {
              final dist = data['star_distribution'] as Map;
              final raw = dist[stars.toString()] ?? dist[stars] ?? 0;
              final count = raw is int ? raw : 0;
              final total = data['total'] as int;
              return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(stars.toString() + t(' stars: ', ' نجوم: ') + count.toString()),
                LinearProgressIndicator(value: total > 0 ? count / total : 0),
                const SizedBox(height: 8),
              ]);
            }),
          Text(t('Source evidence', 'أدلة المصدر')),
          for (final key in ['SIMULATED_APP_ONLY','SIMULATED_SOURCE_OBSERVED'])
            Text(evidence(key) + ': ' + ((data['evidence_counts'] as Map)[key] ?? 0).toString()),
          const SizedBox(height: 12),
          Text(t('Stations with published feedback', 'المحطات ذات الملاحظات المنشورة')),
          if ((data['stations'] as List).isEmpty)
            Text(t('No published feedback in this window.', 'لا توجد ملاحظات منشورة في هذه الفترة.')),
          for (final raw in data['stations'] as List)
            Builder(builder: (_) {
              final station = Map<String, dynamic>.from(raw as Map);
              return Card(child: ListTile(
                title: Text(station['station_name'].toString()),
                subtitle: Text(station['total'].toString() +
                    t(' reviews · ', ' ملاحظات · ') +
                    station['average_rating'].toString() + ' / 5'),
              ));
            }),
          Text(t('Historical moderated reviews, not live charger reliability.', 'ملاحظات تاريخية خضعت للمراجعة وليست قياسًا لاعتمادية الشواحن المباشرة.')),
        ],
      ])),
    );
  }
}
