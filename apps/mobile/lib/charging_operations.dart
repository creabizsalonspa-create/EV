import 'package:flutter/material.dart';
import 'api.dart';
import 'action_form.dart';
import 'strings.dart';

String opsText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar' ? opsAr : opsEn)[k] ?? k;
const opsEn = {
  'title': 'Operational recovery',
  'notice':
      'Investigate missing source evidence. No command is resent and capacity is never forcibly released. Only authenticated source transitions can finalize sessions. Physical charging is not verified.',
  'open': 'Open recovery case',
  'review': 'Review case',
  'inspect': 'Read authenticated simulator source',
  'refresh': 'Refresh recovery',
  'notes': 'Operator notes',
  'history': 'Case history',
  'none': 'No uncertain commands or recorded cases.',
  'openState': 'Open',
  'investigating': 'Investigating',
  'awaiting_source': 'Awaiting source evidence',
  'source_observed': 'Source evidence observed',
  'resolved': 'Resolved with source evidence',
  'opened': 'Opened',
  'source_inspected': 'Source inspected',
  'next': 'Next',
  'previous': 'Previous',
};
const opsAr = {
  'title': 'الاسترداد التشغيلي',
  'notice':
      'حقق في غياب أدلة المصدر. لا تُعاد الأوامر ولا تُحرر السعة قسراً. انتقالات المصدر الموثقة فقط تنهي الجلسات. لم يُتحقق من الشحن الفعلي.',
  'open': 'فتح حالة استرداد',
  'review': 'مراجعة الحالة',
  'inspect': 'قراءة مصدر المحاكي الموثق',
  'refresh': 'تحديث الاسترداد',
  'notes': 'ملاحظات المشغل',
  'history': 'سجل الحالة',
  'none': 'لا توجد أوامر غير مؤكدة أو حالات مسجلة.',
  'openState': 'مفتوحة',
  'investigating': 'قيد التحقيق',
  'awaiting_source': 'بانتظار أدلة المصدر',
  'source_observed': 'رُصدت أدلة المصدر',
  'resolved': 'عولجت بأدلة المصدر',
  'opened': 'فُتحت',
  'source_inspected': 'فُحص المصدر',
  'next': 'التالي',
  'previous': 'السابق',
};

class ChargingOperationsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const ChargingOperationsView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<ChargingOperationsView> createState() => _OperationsState();
}

class _OperationsState extends State<ChargingOperationsView> {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false;
  int generation = 0;
  String t(String k) => opsText(context, k);
  @override
  void initState() {
    super.initState();
    load();
  }

  void fail(Object e) {
    if (!mounted) return;
    setState(() {
      data = null;
      error = e;
    });
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final value = await widget.api.request(
        'charging-operations',
        query: {'page': '$page'},
      );
      if (mounted && epoch == generation)
        setState(() => data = ApiPage.fromJson(value, (v) => v));
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> edit(Map<String, dynamic> row) async {
    final review = row['case'] != null;
    final result = await apiForm(
      context,
      title: review ? 'recoveryReview' : 'recoveryOpen',
      fields: [
        if (review)
          InputSpec(
            'state',
            'recoveryState',
            initial: 'investigating',
            choices: {
              'investigating': 'recoveryInvestigating',
              'awaiting_source': 'recoveryAwaiting',
              if (row['source_observed'] == true)
                'resolved': 'recoveryResolved',
            },
          ),
        InputSpec(
          'note',
          'recoveryNote',
          maxLength: 2000,
          validate: (value, s) =>
              value.length >= 10 ? null : s.t('recoveryNoteLength'),
        ),
      ],
      submit: (v) => widget.api.request(
        'charging-operations/${row['id']}/case',
        method: review ? 'PATCH' : 'POST',
        body: v,
      ),
      onExpired: widget.onExpired,
    );
    if (mounted && result != null) await load(page: data?.current ?? 1);
  }

  Future<void> inspect(Map<String, dynamic> row) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        'charging-operations/${row['id']}/inspect-source',
        method: 'POST',
        body: {},
      );
      if (mounted) await load(page: data?.current ?? 1);
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(t('title'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(s.t(errorKey(error!))),
          TextButton(
            onPressed: busy ? null : () => load(page: data?.current ?? 1),
            child: Text(t('refresh')),
          ),
          if (data != null && data!.items.isEmpty) Text(t('none')),
          for (final row in data?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('#${row['id']} · ${s.t(row['status'])}'),
                    Text('${s.t(row['action'])} · #${row['station_id']}'),
                    if (row['case'] != null) ...[
                      Text(
                        t(
                          row['case']['state'] == 'open'
                              ? 'openState'
                              : row['case']['state'],
                        ),
                      ),
                      Text(row['case']['operator_note'] as String),
                    ],
                    FilledButton(
                      onPressed: busy ? null : () => edit(row),
                      child: Text(t(row['case'] == null ? 'open' : 'review')),
                    ),
                    if (row['case'] != null) ...[
                      TextButton(
                        onPressed: busy || row['can_inspect'] != true
                            ? null
                            : () => inspect(row),
                        child: Text(t('inspect')),
                      ),
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => Navigator.of(context).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => _CaseHistory(
                                    api: widget.api,
                                    id: row['id'] as int,
                                    onExpired: widget.onExpired,
                                  ),
                                ),
                              ),
                        child: Text(t('history')),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          if (data != null)
            Wrap(
              children: [
                if (data!.current > 1)
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => load(page: data!.current - 1),
                    child: Text(t('previous')),
                  ),
                if (data!.current < data!.last)
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => load(page: data!.current + 1),
                    child: Text(t('next')),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

class _CaseHistory extends StatefulWidget {
  final EvApi api;
  final int id;
  final VoidCallback onExpired;
  const _CaseHistory({
    required this.api,
    required this.id,
    required this.onExpired,
  });
  @override
  State<_CaseHistory> createState() => _HistoryState();
}

class _HistoryState extends State<_CaseHistory> {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load({int page = 1}) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final value = await widget.api.request(
        'charging-operations/${widget.id}/case',
        query: {'page': '$page'},
      );
      if (mounted)
        setState(() => data = ApiPage.fromJson(value['events'], (v) => v));
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e;
          data = null;
        });
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(opsText(c, 'history'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        TextButton(
          onPressed: busy ? null : () => load(page: data?.current ?? 1),
          child: Text(opsText(c, 'refresh')),
        ),
        for (final event in data?.items ?? <Map<String, dynamic>>[])
          ListTile(
            title: Text(opsText(c, event['action'])),
            subtitle: Text('${event['note']}\n${event['created_at']} UTC'),
          ),
        if (data != null && data!.current < data!.last)
          TextButton(
            onPressed: busy ? null : () => load(page: data!.current + 1),
            child: Text(opsText(c, 'next')),
          ),
      ],
    ),
  );
}
