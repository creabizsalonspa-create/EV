import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

/// Scoped read-only history, with explicit reload after backgrounding.
class RiderHistoryView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int? tokenId, companyId, grantId;
  const RiderHistoryView({
    super.key,
    required this.api,
    required this.onExpired,
    this.tokenId,
    this.companyId,
    this.grantId,
  });
  @override
  State<RiderHistoryView> createState() => _RiderHistoryState();
}

class _RiderHistoryState extends State<RiderHistoryView>
    with WidgetsBindingObserver {
  late bool Function() fence;
  ApiPage<Map<String, dynamic>>? data;
  String kind = 'events';
  bool busy = false, paused = false;
  Object? error;
  int generation = 0;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get path => widget.companyId == null
      ? 'rider-access/tokens/${widget.tokenId}/activity'
      : 'vendor-ops/companies/${widget.companyId}/rider-grants/${widget.grantId}/activity';
  @override
  void initState() {
    super.initState();
    fence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void dispose() {
    generation++;
    data = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      generation++;
      setState(() {
        data = null;
        error = null;
        busy = false;
        paused = true;
      });
    }
  }

  Future<void> load({int page = 1, String? nextKind}) async {
    if (!fence()) {
      widget.onExpired();
      return;
    }
    final epoch = ++generation;
    final selected = nextKind ?? kind;
    setState(() {
      busy = true;
      data = null;
      error = null;
      paused = false;
      kind = selected;
    });
    try {
      final response = await widget.api.request(
        path,
        query: {'kind': selected, 'page': '$page'},
      );
      if (!mounted || epoch != generation || !fence()) return;
      if (response['read_only'] != true ||
          response['kind'] != selected ||
          response['hardware_verified'] != false) {
        throw const ApiException(503, 'Unverified history');
      }
      setState(() => data = ApiPage.fromJson(response, (v) => v));
    } catch (e) {
      if (mounted && epoch == generation && fence()) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  String label(Map<String, dynamic> row) => switch (kind == 'events'
      ? row['action']
      : row['state']) {
    'source.authorization.allowed' => t(
      'Source authorization allowed; transaction not implied',
      'سُمح بتفويض المصدر؛ لا يعني ذلك بدء معاملة',
    ),
    'source.authorization.not_allowed' => t(
      'Source authorization not allowed',
      'لم يسمح بتفويض المصدر',
    ),
    'source.authorization.blocked' => t(
      'Source authorization blocked',
      'تم حظر تفويض المصدر',
    ),
    'source.authorization.expired' => t(
      'Source authorization expired',
      'انتهت صلاحية تفويض المصدر',
    ),
    'token.registered' => t('Token registered', 'تم تسجيل الرمز'),
    'token.revoked' => t('Token revoked', 'تم إبطال الرمز'),
    'token.expiry_changed' => t(
      'Token expiry changed',
      'تم تغيير انتهاء الرمز',
    ),
    'grant.requested' => t(
      'Station approval requested',
      'تم طلب موافقة المحطة',
    ),
    'grant.withdrawn' => t(
      'Station approval withdrawn',
      'تم سحب موافقة المحطة',
    ),
    'grant.approved' => t('Operator approved access', 'وافق المشغل على الوصول'),
    'grant.rejected' => t('Operator rejected access', 'رفض المشغل الوصول'),
    'grant.revoked' => t('Operator revoked access', 'أبطل المشغل الوصول'),
    'source.provisioned' => t(
      'TEST source authorization provisioned',
      'تم إعداد تفويض مصدر الاختبار',
    ),
    'awaiting_source' => t(
      'Awaiting source transaction',
      'بانتظار معاملة المصدر',
    ),
    'closed_without_dispatch' => t(
      'Closed without dispatch',
      'أغلقت دون إرسال',
    ),
    'source_active' when row['source_start_observed'] == true => t(
      'Source start observed',
      'تم رصد بدء المصدر',
    ),
    'source_ended'
        when row['source_start_observed'] == true &&
            row['source_end_observed'] == true =>
      t('Source start and end observed', 'تم رصد بدء ونهاية المصدر'),
    _ => t('Source evidence requires review', 'يتطلب دليل المصدر المراجعة'),
  };
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(t('Charging access activity', 'سجل وصول الشحن')),
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            'Read-only TEST history. Approval is not a source transaction; revocation does not stop an active session. No physical charging or payment is verified.',
            'سجل اختبار للقراءة فقط. الموافقة ليست معاملة مصدر ولا يوقف الإبطال جلسة نشطة. لا يثبت شحناً فعلياً أو دفعاً.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
        if (paused)
          Text(
            t(
              'History hidden. Refresh to reload.',
              'تم إخفاء السجل. حدّث لإعادة تحميله.',
            ),
          ),
        TextButton(
          onPressed: busy ? null : () => load(),
          child: Text(t('Refresh', 'تحديث')),
        ),
        Wrap(
          children: [
            TextButton(
              onPressed: busy ? null : () => load(nextKind: 'events'),
              child: Text(t('Access lifecycle', 'دورة حياة الوصول')),
            ),
            TextButton(
              onPressed: busy ? null : () => load(nextKind: 'sessions'),
              child: Text(
                t('Card charging attempts', 'محاولات الشحن بالبطاقة'),
              ),
            ),
          ],
        ),
        if (fence()) ...[
          if (data != null && data!.items.isEmpty)
            Text(t('No activity recorded', 'لا توجد أنشطة مسجلة')),
          for (final row in data?.items ?? <Map<String, dynamic>>[])
            Card(
              child: ListTile(
                title: Text(label(row)),
                subtitle: Text(
                  '${row['created_at']}\n${kind == 'sessions' ? 'OCPP ${row['protocol']} · #${row['charging_session_id']}' : ''}',
                ),
              ),
            ),
          if ((data?.current ?? 1) > 1)
            TextButton(
              onPressed: busy ? null : () => load(page: data!.current - 1),
              child: Text(t('Previous', 'السابق')),
            ),
          if ((data?.current ?? 1) < (data?.last ?? 1))
            TextButton(
              onPressed: busy ? null : () => load(page: data!.current + 1),
              child: Text(t('Next', 'التالي')),
            ),
        ],
      ],
    ),
  );
}
