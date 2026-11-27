import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'financial_operation_store.dart';
import 'vendor_forms.dart' show vendorDigits;

Map<String, dynamic> nativeCampaign(
  dynamic value,
  int owner,
  String role, {
  int? id,
}) {
  final row = Map<String, dynamic>.from(value as Map);
  final revision = row['control_revision'];
  if (revision is String && RegExp(r'^[1-9][0-9]{0,18}$').hasMatch(revision)) {
    row['control_revision'] = int.tryParse(revision);
  }
  if (role != 'vendor' ||
      owner < 1 ||
      row.containsKey('request_key') ||
      row.containsKey('fingerprint') ||
      row['id'] is! int ||
      row['id'] <= 0 ||
      (id != null && row['id'] != id) ||
      row['vendor_id'] is! int ||
      (role == 'vendor' && row['vendor_id'] != owner) ||
      row['control_revision'] is! int ||
      row['control_revision'] < 1 ||
      row['unit'] != 'PREPAID_PACKAGE_DELIVERY_UNIT' ||
      row['additional_cash_charge'] != false ||
      row['human_impressions_verified'] != false ||
      row['title'] is! String ||
      ![
        'draft',
        'submitted',
        'approved',
        'rejected',
        'paused',
        'suspended',
        'cancelled',
      ].contains(row['status'])) {
    throw const FormatException('Unsupported campaign receipt.');
  }
  return row;
}

Map<String, dynamic> nativeCampaignIntent(dynamic value) {
  final body = Map<String, dynamic>.from(value as Map);
  const keys = {
    'invoice_id',
    'station_id',
    'title',
    'title_ar',
    'description',
    'description_ar',
    'country',
    'region',
    'placement',
    'budget_units',
    'starts_at',
    'ends_at',
  };
  if (body.length != keys.length ||
      body.keys.any((key) => !keys.contains(key)) ||
      body['invoice_id'] is! int ||
      body['invoice_id'] < 1 ||
      body['station_id'] is! int ||
      body['station_id'] < 1 ||
      body['budget_units'] is! int ||
      body['budget_units'] < 1 ||
      body['budget_units'] > 1000000 ||
      body['country'] is! String ||
      !RegExp(r'^[A-Z]{2}$').hasMatch(body['country'] as String) ||
      !['discovery_banner', 'station_detail'].contains(body['placement']) ||
      (body['region'] != null &&
          (body['region'] is! String ||
              (body['region'] as String).length > 100)))
    throw const FormatException('Invalid saved campaign request.');
  for (final key in ['title', 'title_ar', 'description', 'description_ar']) {
    final text = body[key];
    if (text is! String ||
        text.trim().isEmpty ||
        text.length > (key.startsWith('title') ? 120 : 500))
      throw const FormatException('Invalid saved creative.');
  }
  if (!campaignUtc(
    body['ends_at'] as String,
  ).isAfter(campaignUtc(body['starts_at'] as String)))
    throw const FormatException('Invalid campaign interval.');
  return body;
}

DateTime campaignUtc(String input) {
  final value = input.trim();
  if (!RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$').hasMatch(value))
    throw const FormatException('Use exact UTC seconds.');
  final time = DateTime.parse(value);
  if (time.toIso8601String().replaceFirst('.000Z', 'Z') != value)
    throw const FormatException('Invalid UTC date.');
  return time;
}

DateTime campaignReceiptUtc(dynamic raw) {
  final value = raw as String;
  // Only the documented database UTC and explicit ISO UTC forms.
  final db = RegExp(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$').firstMatch(value);
  if (db != null && db.end == value.length) {
    return campaignUtc('${value.replaceFirst(' ', 'T')}Z');
  }
  final iso = RegExp(r'^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.0{1,6})?Z$').firstMatch(value);
  if (iso == null || iso.end != value.length) {
    throw const FormatException('Invalid source UTC receipt.');
  }
  return campaignUtc('${iso.group(1)}Z');
}

bool campaignMatchesIntent(
  Map<String, dynamic> row,
  Map<String, dynamic> body,
) {
  for (final field in [
    'invoice_id',
    'station_id',
    'title',
    'title_ar',
    'description',
    'description_ar',
    'country',
    'region',
    'placement',
    'budget_units',
  ]) {
    if (row[field] != body[field]) return false;
  }
  try {
    return campaignReceiptUtc(row['starts_at']) ==
            campaignUtc(body['starts_at'] as String) &&
        campaignReceiptUtc(row['ends_at']) == campaignUtc(body['ends_at'] as String);
  } catch (_) {
    return false;
  }
}

/// The control revision is the global event ID, not a per-campaign
/// counter. Other campaigns may consume IDs between decisions.
bool campaignConfirmsDecision(Map<String,dynamic> previous,
    Map<String,dynamic> receipt,String action) {
  final target = {
    'submit':'submitted','pause':'paused','resume':'approved','cancel':'cancelled',
  }[action];
  final before = previous['control_revision'], after = receipt['control_revision'];
  if (target == null || previous['status'] == target ||
      receipt['status'] != target ||
      before is! int || after is! int || after <= before ||
      previous['id'] != receipt['id'] ||
      previous['vendor_id'] != receipt['vendor_id']) return false;
  try {
    final intent = nativeCampaignIntent({
      for (final key in ['invoice_id','station_id','title','title_ar',
        'description','description_ar','country','region','placement','budget_units'])
        key:previous[key],
      'starts_at':campaignReceiptUtc(previous['starts_at']).toIso8601String().replaceFirst('.000Z','Z'),
      'ends_at':campaignReceiptUtc(previous['ends_at']).toIso8601String().replaceFirst('.000Z','Z'),
    });
    return campaignMatchesIntent(receipt,intent);
  } catch (_) {
    return false;
  }
}

/// All management endpoints use Laravel's 25-row paginator. Do not accept
/// an unrelated page, duplicate identity or impossible count as private data.
ApiPage<Map<String,dynamic>> campaignPage(dynamic raw, int requested,
    String identity, Map<String,dynamic> Function(Map<String,dynamic>) parse) {
  final page = ApiPage.fromJson(raw, parse);
  if (requested < 1 || requested > 100000 || page.current != requested ||
      page.last < 1 || page.total < 0 ||
      page.last != (page.total == 0 ? 1 : (page.total + 24) ~/ 25) ||
      page.items.length > 25 || requested > page.last ||
      page.items.length != (requested < page.last ? 25 : page.total - (requested - 1)*25)) {
    throw const FormatException('Invalid campaign page.');
  }
  final seen = <int>{};
  for (final row in page.items) {
    final id = row[identity];
    if (id is! int || id < 1 || !seen.add(id))
      throw const FormatException('Invalid campaign page identity.');
  }
  return page;
}

/// Project only approved receipt fields. Older API versions may also return
/// publisher request keys and fingerprints; these never reach the view.
Map<String,dynamic> campaignDelivery(Map<String,dynamic> row, int campaignId) {
  final id = row['id'], parent = row['parent_delivery_id'], kind = row['kind'];
  if (id is! int || id < 1 || row['campaign_id'] is! int ||
      row['campaign_id'] != campaignId ||
      !['delivery','click'].contains(kind) ||
      (kind == 'delivery' && parent != null) ||
      (kind == 'click' && (parent is! int || parent < 1 || parent >= id))) {
    throw const FormatException('Invalid publisher receipt.');
  }
  final occurred = campaignReceiptUtc(row['occurred_at']);
  final created = campaignReceiptUtc(row['created_at']);
  return {'id':id,'kind':kind,'occurred_at':occurred,'created_at':created};
}

class VendorCampaignsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final FinancialOperationStore? operationStore;
  const VendorCampaignsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.operationStore,
  });
  @override
  State<VendorCampaignsView> createState() => _VendorCampaignsState();
}

class _VendorCampaignsState extends State<VendorCampaignsView>
    with WidgetsBindingObserver {
  final fields = {
    for (final key in [
      'title',
      'title_ar',
      'description',
      'description_ar',
      'region',
      'budget_units',
      'starts_at',
      'ends_at',
    ])
      key: TextEditingController(),
  };
  final reason = TextEditingController();
  late bool Function() authorized;
  StreamSubscription<int>? sessionSubscription;
  int intentRevision = 0;
  void invalidateConsent() { consent = false; intentRevision++; }
  VoidCallback guardIntent(VoidCallback callback) {
    final revision = intentRevision;
    return guard(() { if (revision == intentRevision && consent) callback(); });
  }
  bool get active => mounted && foreground && authorized();
  VoidCallback guard(VoidCallback callback) {
    final ticket = generation, api = widget.api;
    return () { if (current(ticket) && identical(api,widget.api) && !busy) callback(); };
  }
  late FinancialOperationStore store;
  FinancialOperation? operation;
  ApiPage<Map<String, dynamic>>? records, packages, stations, deliveries;
  Map<String, dynamic>? detail;
  List<dynamic> events = [];
  int? owner, invoiceId, stationId;
  String role = '', placement = 'discovery_banner';
  int generation = 0, pageNumber = 1, packagePage = 1, stationPage = 1, deliveryPage = 1;
  bool confirming = false;
  bool busy = false,
      foreground = true,
      creating = false,
      enabled = false,
      consent = false;
  String? error, notice;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int request) =>
      mounted && foreground && authorized() && generation == request;
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    store = widget.operationStore ?? DeviceFinancialOperationStore();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    if (active) load();
  }

  void subscribe() {
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api,widget.api) && !authorized()) {
        setState(() => clearPrivate(invalidateRequest: false));
      }
    });
  }

  void clearPrivate({bool invalidateRequest = true}) {
    FocusManager.instance.primaryFocus?.unfocus();
    if (invalidateRequest) generation++;
    invalidateConsent();
    creating = false;
    pageNumber = packagePage = stationPage = deliveryPage = 1;
    confirming = false;
    deliveries = null;
    placement = 'discovery_banner';
    records = null;
    packages = null;
    stations = null;
    detail = null;
    events = [];
    operation = null;
    owner = null;
    role = '';
    enabled = false;
    consent = false;
    busy = false;
    error = null;
    notice = null;
    invoiceId = null;
    stationId = null;
    for (final controller in fields.values) {
      controller.clear();
    }
    reason.clear();
  }

  @override
  void didUpdateWidget(covariant VendorCampaignsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api,widget.api)) {
      unawaited(sessionSubscription?.cancel());
      clearPrivate();
      authorized = widget.api.sessionFence();
      store = widget.operationStore ?? DeviceFinancialOperationStore();
      subscribe();
    } else if (!authorized()) {
      clearPrivate();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    foreground = state == AppLifecycleState.resumed;
    setState(() {
      if (!foreground) clearPrivate();
    });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    for (final controller in fields.values) {
      controller.dispose();
    }
    reason.dispose();
    super.dispose();
  }

  void failure(Object e, int request) {
    if (!current(request)) {
      if (mounted && foreground && generation == request &&
          e is ApiException && e.endsCurrentSession) widget.onExpired();
      return;
    }
    if (e is ApiException && [401, 419].contains(e.status)) {
      widget.onExpired();
      return;
    }
    setState(
      () => error = t(
        'Request not confirmed. Refresh source history or resolve the saved creation before another intent.',
        'لم يتم تأكيد الطلب. حدّث سجل المصدر أو عالج طلب الإنشاء المحفوظ قبل إنشاء نية أخرى.',
      ),
    );
  }

  Future<void> load({int? id, int receiptPage = 1}) async {
    if (!active) return;
    final request = ++generation, api = widget.api;
    setState(() {
      busy = true;
      error = null;
      detail = null;
      deliveries = null;
      confirming = false;
      records = null;
      packages = null;
      stations = null;
      owner = null;
      role = '';
      operation = null;
      enabled = false;
      events = [];
      consent = false;
      reason.clear();
    });
    try {
      final me = await api.request('me') as Map;
      if (!current(request)) return;
      if (me['id'] is! int || (me['id'] as int) < 1 || me['role'] != 'vendor')
        throw const FormatException('Vendor access required.');
      final actor = me['id'] as int, actorRole = me['role'] as String;
      FinancialOperation? journal;
      if (actorRole == 'vendor') {
        final fence = authorized;
        final sourceApi = widget.api;
        journal = FinancialOperation(
          store,
          '${widget.api.base.origin}:${widget.api.base.path}:$actor:campaign-create',
          isCurrent: () =>
              fence() && foreground && identical(sourceApi, widget.api),
        );
        await journal.restore();
        if (!current(request)) return;
        if (journal.pending != null)
          nativeCampaignIntent(journal.pending!['body']);
      }
      final raw = await api.request(
        'commercial/campaigns',
        query: {'page': '$pageNumber'},
      );
      if (!current(request)) return;
      final list = campaignPage(raw, pageNumber, 'id',
        (row) => nativeCampaign(row, actor, actorRole));
      if (raw['adapter_enabled'] is! bool)
        throw const FormatException('Unconfirmed campaign capability.');
      Map<String, dynamic>? selected;
      List<dynamic> audit = [];
      ApiPage<Map<String, dynamic>>? availablePackages, ownedStations, publisherReceipts;
      if (id != null) {
        final receipt =
            await api.request('commercial/campaigns/$id', query: {'page':'$receiptPage'}) as Map;
        if (!current(request)) return;
        selected = nativeCampaign(
          receipt['campaign'],
          actor,
          actorRole,
          id: id,
        );
        if (receipt['currently_eligible'] is! bool)
          throw const FormatException('Unconfirmed placement eligibility.');
        selected['currently_eligible'] = receipt['currently_eligible'];
        if (receipt['events'] is! List)
          throw const FormatException('Invalid campaign history.');
        audit = receipt['events'] as List;
        publisherReceipts = campaignPage(receipt['deliveries'], receiptPage, 'id',
            (row) => campaignDelivery(row,id));
      }
      if (creating && actorRole == 'vendor') {
        availablePackages = campaignPage(
          await api.request(
            'commercial/campaign-packages',
            query: {'page': '$packagePage'},
          ),
          packagePage, 'invoice_id', (row) {
            if (row['invoice_id'] is! int ||
                row['vendor_id'] != actor ||
                row['campaign_eligible'] is! bool)
              throw const FormatException('Foreign package.');
            return row;
          },
        );
        if (!current(request)) return;
        ownedStations = campaignPage(
          await api.request(
            'commercial/campaign-stations',
            query: {'page': '$stationPage'},
          ),
          stationPage, 'id', (row) {
            if (row['id'] is! int ||
                row['name'] is! String ||
                row['country'] is! String)
              throw const FormatException('Invalid station.');
            return row;
          },
        );
      }
      if (!current(request)) return;
      setState(() {
        owner = actor;
        role = actorRole;
        operation = journal;
        enabled = (raw as Map)['adapter_enabled'] == true;
        records = list;
        detail = selected;
        events = audit;
        deliveries = publisherReceipts;
        deliveryPage = receiptPage;
        packages = availablePackages;
        stations = ownedStations;
        if (!(packages?.items.any(
              (row) =>
                  row['invoice_id'] == invoiceId &&
                  row['campaign_eligible'] == true,
            ) ??
            false))
          invoiceId = null;
        if (!(stations?.items.any((row) => row['id'] == stationId) ?? false))
          stationId = null;
      });
    } catch (e) {
      failure(e, request);
    } finally {
      if (mounted && generation == request) setState(() => busy = false);
    }
  }

  Future<void> create({bool resolve = false}) async {
    final journal = operation;
    if (busy || !active || !creating ||
        role != 'vendor' ||
        journal == null ||
        !consent ||
        (!resolve && !enabled))
      return;
    Map<String, dynamic> body;
    try {
      if (journal.pending != null) {
        body = nativeCampaignIntent(journal.pending!['body']);
      } else {
        if (resolve || packages == null || stations == null ||
            !packages!.items.any((row) => row['invoice_id'] == invoiceId && row['campaign_eligible'] == true)) return;
        final station = stations!.items.firstWhere(
          (row) => row['id'] == stationId,
        );
        if (invoiceId == null) throw const FormatException('Select package.');
        final values = {
          for (final entry in fields.entries)
            entry.key: entry.value.text.trim(),
        };
        for (final key in [
          'title',
          'title_ar',
          'description',
          'description_ar',
        ]) {
          if (values[key]!.isEmpty ||
              values[key]!.length > (key.startsWith('title') ? 120 : 500))
            throw const FormatException('Complete bilingual creative.');
        }
        final units = int.tryParse(vendorDigits(values['budget_units']!));
        if (units == null || units < 1 || units > 1000000)
          throw const FormatException('Invalid delivery budget.');
        if (!campaignUtc(
          values['ends_at']!,
        ).isAfter(campaignUtc(values['starts_at']!)))
          throw const FormatException('Invalid date interval.');
        body = {
          ...values,
          'invoice_id': invoiceId,
          'station_id': stationId,
          'budget_units': units,
          'country': station['country'],
          'region': values['region']!.isEmpty ? null : values['region'],
          'placement': placement,
        };
      }
    } catch (_) {
      setState(
        () => error = t(
          'Choose a paid package and owned station; complete bilingual text, whole units and exact UTC dates within the package cycle.',
          'اختر باقة مدفوعة ومحطة مملوكة؛ أكمل النص باللغتين والوحدات الصحيحة وتواريخ UTC الدقيقة ضمن دورة الباقة.',
        ),
      );
      return;
    }
    final request = ++generation;
    setState(() {
      busy = true;
      error = null;
      notice = null;
      consent = false;
    });
    try {
      Map<String, dynamic>? accepted;
      if (resolve) {
        final receipt =
            await widget.api.request(
                  'commercial/campaign-intents/cancel',
                  method: 'POST',
                  body: {'idempotency_key': journal.pending!['key']},
                )
                as Map;
        if (!current(request)) return;
        if (receipt['cancelled'] == true && receipt['campaign'] == null) {
          notice = t(
            'Unused creation intent cancelled. Existing campaigns and payments were not cancelled.',
            'أُلغي طلب الإنشاء غير المستخدم. لم تُلغَ الحملات القائمة أو المدفوعات.',
          );
        } else if (receipt['cancelled'] == false &&
            receipt['campaign'] is Map) {
          accepted = nativeCampaign(receipt['campaign'], owner!, role);
        } else {
          throw const FormatException('Unconfirmed intent resolution.');
        }
      } else {
        final prepared = await journal.prepare(nativeCampaignIntent(body));
        if (!current(request)) return;
        final receipt =
            await widget.api.request(
                  'commercial/campaigns',
                  method: 'POST',
                  body: prepared,
                )
                as Map;
        if (!current(request)) return;
        accepted = nativeCampaign(receipt['campaign'], owner!, role);
      }
      if (accepted != null && !campaignMatchesIntent(accepted, body))
        throw const FormatException('Receipt differs from saved intent.');
      if (!current(request)) return;
      await journal.confirmed();
      if (!current(request)) return;
      for (final controller in fields.values) {
        controller.clear();
      }
      creating = false;
      await load(id: accepted?['id'] as int?);
    } catch (e) {
      failure(e, request);
    } finally {
      if (mounted && generation == request) setState(() => busy = false);
    }
  }

  List<String> actions(Map<String, dynamic> row) {
    final state = row['status'];
    return [
      if (state == 'draft') 'submit',
      if (state == 'approved') 'pause',
      if (state == 'paused') 'resume',
      if ([
        'draft',
        'submitted',
        'approved',
        'paused',
        'suspended',
      ].contains(state))
        'cancel',
    ];
  }

  String label(String action) => switch (action) {
    'submit' => t('Submit for review', 'إرسال للمراجعة'),
    'approve' => t('Approve', 'اعتماد'),
    'reject' => t('Reject', 'رفض'),
    'pause' => t('Pause', 'إيقاف مؤقت'),
    'resume' => t('Resume', 'استئناف'),
    'cancel' => t('Cancel campaign', 'إلغاء الحملة'),
    'suspend' => t('Suspend', 'تعليق'),
    'reinstate' => t('Reinstate', 'إعادة التفعيل'),
    _ => action,
  };
  Future<void> act(String action) async {
    final row = detail, note = reason.text.trim();
    if (row == null || !active || creating || !enabled ||
        busy || confirming ||
        !consent ||
        note.length < 5 ||
        note.length > 500 ||
        !actions(row).contains(action))
      return;
    final reviewedGeneration = generation, reviewedIntent = intentRevision, api = widget.api;
    setState(() => confirming = true);
    bool? confirmed;
    try {
    confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(label(action)),
        content: Text(
          t(
            'Apply this reviewed decision to the displayed campaign revision? No additional payment is initiated.',
            'تطبيق القرار المُراجع على مراجعة الحملة المعروضة؟ لا تبدأ دفعة إضافية.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(t('Back', 'رجوع')),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(t('Confirm', 'تأكيد')),
          ),
        ],
      ),
    );
    } finally {
      if (current(reviewedGeneration)) setState(() => confirming = false);
    }
    if (confirmed != true ||
        !mounted ||
        !current(reviewedGeneration) || !identical(widget.api,api) ||
        busy || !enabled || !consent || intentRevision != reviewedIntent || reason.text.trim() != note || creating ||
        detail?['id'] != row['id'] ||
        detail?['control_revision'] != row['control_revision'])
      return;
    final request = ++generation;
    setState(() {
      busy = true;
      error = null;
      consent = false;
      reason.clear();
    });
    try {
      final raw =
          await widget.api.request(
                'commercial/campaigns/${row['id']}/$action',
                method: 'POST',
                body: {
                  'reason': note,
                  'expected_control_revision': row['control_revision'],
                },
              )
              as Map;
      if (!current(request)) return;
      final receipt = nativeCampaign(raw['campaign'], owner!, role, id: row['id'] as int);
      if (!campaignConfirmsDecision(row,receipt,action)) {
        // Do not let a malformed acknowledgement authorize another
        // transition using a stale revision. Require fresh source history.
        setState(() { detail = null; records = null; events = []; deliveries = null; enabled = false; });
        throw const FormatException('Unconfirmed campaign decision.');
      }
      await load(id: row['id'] as int);
    } catch (e) {
      failure(e, request);
    } finally {
      if (mounted && generation == request) setState(() => busy = false);
    }
  }

  Widget pages(
    ApiPage<Map<String, dynamic>> data,
    int value,
    void Function(int) select,
  ) => Row(
    children: [
      TextButton(
        onPressed: !busy && value > 1 ? guard(() => select(value - 1)) : null,
        child: Text(t('Previous', 'السابق')),
      ),
      Text('$value / ${data.last}'),
      TextButton(
        onPressed: !busy && value < data.last ? guard(() => select(value + 1)) : null,
        child: Text(t('Next', 'التالي')),
      ),
    ],
  );
  @override
  Widget build(BuildContext context) {
    final visible = active;
    final renderedGeneration = generation, renderedIntent = intentRevision;
    final renderedApi = widget.api;
    bool editable() => current(renderedGeneration) && identical(renderedApi,widget.api) && !busy;
    final saved = visible ? operation?.pending : null;
    final row = visible ? detail : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Vendor campaigns', 'حملات المورّد'))),
      body: ListView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Paid package delivery units only. A campaign does not create an additional cash charge or prove human impressions. Administration review and current package eligibility remain required.',
              'وحدات تسليم الباقة المدفوعة فقط. لا تُنشئ الحملة رسوماً نقدية إضافية ولا تثبت مشاهدات بشرية. تلزم مراجعة الإدارة وأهلية الباقة الحالية.',
            ),
          ),
          OutlinedButton(
            onPressed: busy || !visible ? null : guard(() { pageNumber = packagePage = stationPage = 1; load(); }),
            child: Text(t('Refresh history', 'تحديث السجل')),
          ),
          if (busy && visible) const LinearProgressIndicator(),
          if (visible && records != null && !enabled)
            Text(
              t(
                'Campaign controls are currently unavailable. Review receipts or resolve a saved creation.',
                'أدوات التحكم بالحملات غير متاحة حالياً. راجع الإيصالات أو عالج طلب إنشاء محفوظاً.',
              ),
            ),
          if (visible && error != null) Text(error!),
          if (visible && notice != null) Text(notice!),
          if (visible && role == 'vendor')
            OutlinedButton(
              onPressed: busy
                  ? null
                  : guard(() {
                      setState(() => creating = !creating);
                      load();
                    }),
              child: Text(
                creating
                    ? t('Back to campaigns', 'العودة للحملات')
                    : t(
                        'Create or recover campaign',
                        'إنشاء حملة أو استعادتها',
                      ),
              ),
            ),
          if (visible && creating && role == 'vendor') ...[
            if (saved != null) ...[
              Text(
                t(
                  'Saved creation is unconfirmed. Review the exact saved request before retrying or resolving it.',
                  'طلب الإنشاء المحفوظ غير مؤكد. راجع الطلب المحفوظ بدقة قبل إعادة المحاولة أو معالجته.',
                ),
              ),
              for (final entry in (saved['body'] as Map).entries)
                Text('${entry.key}: ${entry.value ?? ''}'),
            ] else ...[
              if (packages != null) ...[
                DropdownButtonFormField<int>(
                  isExpanded: true,
                  initialValue: invoiceId,
                  decoration: InputDecoration(
                    labelText: t(
                      'Paid package invoice',
                      'فاتورة الباقة المدفوعة',
                    ),
                  ),
                  items: [
                    for (final package in packages!.items)
                      if (package['campaign_eligible'] == true)
                        DropdownMenuItem(
                          value: package['invoice_id'] as int,
                          child: Text(
                            '#${package['invoice_id']} · ${package['period_start']} — ${package['period_end']} UTC',
                          ),
                        ),
                  ],
                  onChanged: busy
                      ? null
                      : (value) { if (editable()) setState(() { invoiceId = value; invalidateConsent(); }); },
                ),
                pages(packages!, packagePage, (value) {
                  packagePage = value;
                  load();
                }),
              ],
              if (stations != null) ...[
                DropdownButtonFormField<int>(
                  isExpanded: true,
                  initialValue: stationId,
                  decoration: InputDecoration(
                    labelText: t('Owned station', 'المحطة المملوكة'),
                  ),
                  items: [
                    for (final station in stations!.items)
                      DropdownMenuItem(
                        value: station['id'] as int,
                        child: Text(station['name'] as String),
                      ),
                  ],
                  onChanged: busy
                      ? null
                      : (value) { if (editable()) setState(() { stationId = value; invalidateConsent(); }); },
                ),
                pages(stations!, stationPage, (value) {
                  stationPage = value;
                  load();
                }),
              ],
              DropdownButtonFormField<String>(
                isExpanded: true,
                initialValue: placement,
                decoration: InputDecoration(
                  labelText: t('Placement', 'موضع العرض'),
                ),
                items: [
                  DropdownMenuItem(
                    value: 'discovery_banner',
                    child: Text(t('Discovery banner', 'لافتة الاكتشاف')),
                  ),
                  DropdownMenuItem(
                    value: 'station_detail',
                    child: Text(t('Station detail', 'تفاصيل المحطة')),
                  ),
                ],
                onChanged: busy
                    ? null
                    : (value) { if (editable()) setState(() { placement = value!; invalidateConsent(); }); },
              ),
              for (final key in fields.keys)
                TextField(
                  controller: fields[key],
                  enabled: !busy,
                  onChanged: (_) { if (editable()) setState(invalidateConsent); },
                  maxLength: key.startsWith('title')
                      ? 120
                      : key.startsWith('description')
                      ? 500
                      : 100,
                  decoration: InputDecoration(
                    labelText: switch (key) {
                      'title' => t('Title (English)', 'العنوان (الإنجليزية)'),
                      'title_ar' => t('Title (Arabic)', 'العنوان (العربية)'),
                      'description' => t(
                        'Description (English)',
                        'الوصف (الإنجليزية)',
                      ),
                      'description_ar' => t(
                        'Description (Arabic)',
                        'الوصف (العربية)',
                      ),
                      'region' => t('Region (optional)', 'المنطقة (اختيارية)'),
                      'budget_units' => t(
                        'Whole delivery units',
                        'وحدات التسليم الصحيحة',
                      ),
                      'starts_at' => t(
                        'Start UTC: YYYY-MM-DDTHH:mm:ssZ',
                        'البداية UTC: YYYY-MM-DDTHH:mm:ssZ',
                      ),
                      _ => t(
                        'End UTC: YYYY-MM-DDTHH:mm:ssZ',
                        'النهاية UTC: YYYY-MM-DDTHH:mm:ssZ',
                      ),
                    },
                  ),
                ),
            ],
            CheckboxListTile(
              value: consent,
              onChanged: busy
                  ? null
                  : (value) { if (editable() && renderedIntent == intentRevision) setState(() { consent = value == true; intentRevision++; }); },
              title: Text(
                t(
                  'I reviewed this exact campaign intent.',
                  'راجعت طلب الحملة هذا بدقة.',
                ),
              ),
            ),
            FilledButton(
              onPressed: busy || !consent || !enabled ? null : guardIntent(() { create(); }),
              child: Text(
                saved == null
                    ? t('Create draft', 'إنشاء مسودة')
                    : t(
                        'Retry exact saved creation',
                        'إعادة طلب الإنشاء المحفوظ نفسه',
                      ),
              ),
            ),
            if (saved != null)
              OutlinedButton(
                onPressed: busy || !consent
                    ? null
                    : guardIntent(() { create(resolve: true); }),
                child: Text(
                  t(
                    'Resolve saved creation: recover receipt or cancel unused intent',
                    'معالجة الطلب المحفوظ: استعادة الإيصال أو إلغاء الطلب غير المستخدم',
                  ),
                ),
              ),
          ],
          if (row != null && !creating) ...[
            Text(row['title'] as String),
            Text('${row['title_ar']}'),
            Text('${row['status']} · #${row['id']}'),
            Text(
              row['currently_eligible'] == true
                  ? t(
                      'Currently eligible for supported placement; human impressions remain unverified.',
                      'مؤهلة حالياً لموضع العرض المدعوم؛ تظل المشاهدات البشرية غير متحققة.',
                    )
                  : t(
                      'Not currently eligible for placement.',
                      'غير مؤهلة حالياً للعرض.',
                    ),
            ),
            Text(
              '${t('Delivery units', 'وحدات التسليم')}: ${row['delivered_units']} / ${row['budget_units']}',
            ),
            Text(
              '${t('Server-verified clicks', 'نقرات موثقة بالخادم')}: ${row['verified_server_clicks']}',
            ),
            for (final event in events)
              Text(
                '${event['created_at']} UTC · ${event['action']} · ${event['reason']}',
              ),
            if (deliveries != null && deliveries!.items.isNotEmpty) ...[
              Text(t('Server publisher receipts','إيصالات الناشر من الخادم')),
              for (final receipt in deliveries!.items)
                ListTile(
                  key: ValueKey('campaign-delivery-${receipt['id']}'),
                  title: Text(receipt['kind'] == 'delivery'
                    ? t('Recorded delivery unit','وحدة تسليم مسجلة')
                    : t('Server-verified click','نقرة موثقة بالخادم')),
                  subtitle: Text(
                    '${(receipt['occurred_at'] as DateTime).toIso8601String()} UTC',
                    textDirection: TextDirection.ltr),
                ),
              pages(deliveries!, deliveryPage, (number) {
                load(id: row['id'] as int, receiptPage: number);
              }),
            ],
            TextField(
              controller: reason,
              onChanged: (_) { if (editable()) setState(invalidateConsent); },
              maxLength: 500,
              enabled: !busy,
              decoration: InputDecoration(
                labelText: t(
                  'Decision reason (5–500 characters)',
                  'سبب القرار (5–500 حرف)',
                ),
              ),
            ),
            CheckboxListTile(
              value: consent,
              onChanged: busy
                  ? null
                  : (value) { if (editable() && renderedIntent == intentRevision) setState(() { consent = value == true; intentRevision++; }); },
              title: Text(
                t(
                  'I reviewed this campaign decision.',
                  'راجعت قرار الحملة هذا.',
                ),
              ),
            ),
            for (final action in actions(row))
              OutlinedButton(
                onPressed: busy || !consent || !enabled
                    ? null
                    : guardIntent(() { act(action); }),
                child: Text(label(action)),
              ),
          ],
          if (visible && !creating && records != null) ...[
            if (records!.items.isEmpty)
              Text(t('No campaigns.', 'لا توجد حملات.')),
            for (final campaign in records!.items)
              ListTile(
                title: Text(campaign['title'] as String),
                subtitle: Text('${campaign['status']}'),
                trailing: TextButton(
                  onPressed: busy
                      ? null
                      : guard(() { load(id: campaign['id'] as int); }),
                  child: Text(t('Review', 'مراجعة')),
                ),
              ),
            pages(records!, pageNumber, (value) {
              pageNumber = value;
              load();
            }),
          ],
        ],
      ),
    );
  }
}
