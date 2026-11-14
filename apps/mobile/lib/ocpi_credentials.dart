import 'package:flutter/material.dart';
import 'api.dart';

String partnerCredentialsTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'بيانات اعتماد الشريك'
    : 'Partner credentials';

class OcpiCredentialsView extends StatefulWidget {
  final EvApi api;
  final int providerId;
  final VoidCallback onExpired;
  const OcpiCredentialsView({
    super.key,
    required this.api,
    required this.providerId,
    required this.onExpired,
  });
  @override
  State<OcpiCredentialsView> createState() => _OcpiCredentialsState();
}

class _OcpiCredentialsState extends State<OcpiCredentialsView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? status;
  ApiPage<Map<String, dynamic>>? operations;
  String? message;
  bool busy = false, uncertain = false;
  int generation = 0;
  bool foreground = true;
  late bool Function() authorized;
  bool current(int epoch) =>
      mounted && foreground && authorized() && epoch == generation;
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get path => 'ocpi/providers/${widget.providerId}/credentials';
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  void clearPrivate() {
    generation++;
    status = null;
    operations = null;
    message = null;
    busy = false;
    uncertain = false;
  }

  @override
  void didUpdateWidget(covariant OcpiCredentialsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.providerId != widget.providerId) {
      clearPrivate();
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted)
      setState(() {
        if (!foreground) clearPrivate();
      });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      status = null;
      operations = null;
      message = null;
    });
    try {
      final value = Map<String, dynamic>.from(
        await widget.api.request(path, query: {'page': '$page'}),
      );
      final history = ApiPage<Map<String, dynamic>>.fromJson(
        value['operations'],
        (j) => j,
      );
      if (value['charging_commands_enabled'] != false ||
          value['billing_eligible'] != false)
        throw const FormatException('Unexpected partner authority');
      if (current(epoch))
        setState(() {
          status = value;
          operations = history;
          uncertain = false;
        });
    } catch (e) {
      fail(e, epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  void fail(Object e, int epoch) {
    if (!current(epoch)) return;
    setState(() {
      status = null;
      operations = null;
      message = uncertain
          ? tr(
              'Outcome uncertain. No automatic retry. Refresh the recorded operation before further action.',
              'النتيجة غير مؤكدة. لن تتكرر العملية تلقائيًا. حدّث سجل العملية قبل أي إجراء آخر.',
            )
          : tr(
              'Partner credentials unavailable.',
              'بيانات اعتماد الشريك غير متاحة.',
            );
    });
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> exchange(String action) async {
    if (!foreground || !authorized() || busy || uncertain) return;
    final decision = generation;
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => _ApprovalNote(
        title: actionTitle(action),
        ar: Localizations.localeOf(context).languageCode == 'ar',
        authorized: () => current(decision),
      ),
    );
    if (!current(decision) || reason == null || busy || uncertain) return;
    final body = {
      'action': action,
      'reason': reason,
      'idempotency_key': operationKey(),
    };
    final epoch = ++generation;
    setState(() {
      busy = true;
      message = null;
    });
    try {
      await widget.api.request(path, method: 'POST', body: body);
      if (current(epoch)) await load();
    } catch (e) {
      if (current(epoch)) {
        setState(() => uncertain = true);
        fail(e, epoch);
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  String stateTitle(String state) => switch (state) {
    'awaiting_registration' || 'awaiting_peer' => tr(
      'Awaiting approved partner enrollment',
      'في انتظار تسجيل الشريك المعتمد',
    ),
    'enrollment_expired' => tr(
      'Enrollment window expired',
      'انتهت مهلة التسجيل',
    ),
    'superseded' => tr('Enrollment replaced', 'تم استبدال التسجيل'),
    'active' => tr('Credentials active', 'بيانات الاعتماد نشطة'),
    'not_exchanged' => tr(
      'Credentials not exchanged',
      'لم يتم تبادل بيانات الاعتماد',
    ),
    'dispatching' => tr(
      'Exchange awaiting acknowledgement',
      'تبادل البيانات في انتظار التأكيد',
    ),
    'acknowledged' => tr('Exchange acknowledged', 'تم تأكيد التبادل'),
    'outcome_unknown' => tr(
      'Exchange outcome uncertain',
      'نتيجة التبادل غير مؤكدة',
    ),
    'revoked' => tr('Credentials revoked', 'تم إلغاء بيانات الاعتماد'),
    'unregistered' => tr(
      'Credentials unregistered',
      'تم إلغاء تسجيل بيانات الاعتماد',
    ),
    'configuration_changed' || 'ownership_changed' => tr(
      'Configuration or ownership requires review',
      'تتطلب التهيئة أو الملكية مراجعة',
    ),
    _ => tr('Credentials require review', 'تتطلب بيانات الاعتماد مراجعة'),
  };

  String actionTitle(String action) => switch (action) {
    'prepare_incoming' => tr(
      'Prepare incoming enrollment',
      'تهيئة تسجيل الشريك الوارد',
    ),
    'peer_register' => tr('Peer enrolled', 'تم تسجيل الشريك'),
    'register' => tr(
      'Exchange partner credentials',
      'تبادل بيانات اعتماد الشريك',
    ),
    'rotate' => tr('Rotate partner credentials', 'تدوير بيانات اعتماد الشريك'),
    'unregister' => tr(
      'Unregister partner credentials',
      'إلغاء تسجيل بيانات اعتماد الشريك',
    ),
    'peer_rotate' => tr(
      'Peer rotated credentials',
      'قام الشريك بتدوير بيانات الاعتماد',
    ),
    'peer_revoke' => tr(
      'Peer revoked credentials',
      'قام الشريك بإلغاء بيانات الاعتماد',
    ),
    _ => tr('Credential operation', 'عملية بيانات الاعتماد'),
  };

  @override
  Widget build(BuildContext context) {
    if (!foreground || !authorized())
      return Scaffold(
        appBar: AppBar(title: Text(partnerCredentialsTitle(context))),
      );
    return Scaffold(
      appBar: AppBar(title: Text(partnerCredentialsTitle(context))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            tr(
              'Tokens stay on the server. Exchanges are one-shot. Unknown outcomes require partner reconciliation. No charging or billing authority is granted.',
              'تبقى الرموز على الخادم. يُنفذ التبادل مرة واحدة. تتطلب النتائج غير المعروفة مطابقة مع الشريك. لا تُمنح صلاحية الشحن أو الفوترة.',
            ),
          ),
          TextButton(
            onPressed: busy ? null : () => load(page: operations?.current ?? 1),
            child: Text(tr('Refresh', 'تحديث')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (message != null) Text(message!),
          if (status != null) ...[
            Text(stateTitle('${status!['state']}')),
            for (final entry in [
              [
                'configured_receiver_modules',
                tr('Configured partner reception', 'استقبال الشريك المهيأ'),
              ],
              [
                'active_receiver_modules',
                tr('Active credential reception', 'استقبال التفويض النشط'),
              ],
            ])
              if (status![entry[0]] is List)
                Text(
                  '${entry[1]}: ${(status![entry[0]] as List).where((m) => ['locations', 'tariffs', 'sessions', 'cdrs'].contains(m)).join(', ')}',
                ),

            if (status!['configured'] != true)
              Text(
                tr(
                  'Approved managed credentials configuration unavailable.',
                  'تهيئة بيانات الاعتماد المُدارة المعتمدة غير متاحة.',
                ),
              ),
            if (status!['bootstrap_expires_at'] != null)
              Text(
                '${tr('Enrollment expires (UTC)', 'تنتهي مهلة التسجيل (UTC)')}: ${status!['bootstrap_expires_at']}',
              ),
            if (status!['exchanged_at'] != null)
              Text('${status!['exchanged_at']}'),
            for (final action in (status!['available_actions'] as List? ?? []))
              if ([
                'register',
                'rotate',
                'unregister',
                'prepare_incoming',
              ].contains(action))
                TextButton(
                  onPressed: busy || uncertain
                      ? null
                      : () => exchange(action as String),
                  child: Text(actionTitle(action as String)),
                ),
          ],
          if (operations != null) ...[
            for (final op in operations!.items)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '#${op['id']} · ${actionTitle('${op['action']}')} · ${stateTitle('${op['state']}')}',
                      ),
                      Text('${op['attempted_at'] ?? op['created_at']}'),
                    ],
                  ),
                ),
              ),
            Row(
              children: [
                TextButton(
                  onPressed: busy || operations!.current <= 1
                      ? null
                      : () => load(page: operations!.current - 1),
                  child: Text(tr('Previous', 'السابق')),
                ),
                Text('${operations!.current} / ${operations!.last}'),
                TextButton(
                  onPressed: busy || operations!.current >= operations!.last
                      ? null
                      : () => load(page: operations!.current + 1),
                  child: Text(tr('Next', 'التالي')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _ApprovalNote extends StatefulWidget {
  final String title;
  final bool ar;
  final bool Function() authorized;
  const _ApprovalNote({
    required this.title,
    required this.ar,
    required this.authorized,
  });
  @override
  State<_ApprovalNote> createState() => _ApprovalNoteState();
}

class _ApprovalNoteState extends State<_ApprovalNote>
    with WidgetsBindingObserver {
  final note = TextEditingController();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      FocusScope.of(context).unfocus();
      setState(note.clear);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: TextField(
      controller: note,
      maxLength: 2000,
      onChanged: (_) => setState(() {}),
      decoration: InputDecoration(
        labelText: widget.ar
            ? 'ملاحظة اعتماد المشغّل'
            : 'Operator approval note',
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text(widget.ar ? 'إلغاء' : 'Cancel'),
      ),
      TextButton(
        onPressed: !widget.authorized() || note.text.trim().length < 10
            ? null
            : () => Navigator.pop(context, note.text.trim()),
        child: Text(widget.ar ? 'تأكيد' : 'Confirm'),
      ),
    ],
  );
}
