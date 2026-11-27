import 'dart:async';
import 'package:flutter/material.dart';
import 'vendor_forms.dart' show vendorDigits;
import 'package:flutter/services.dart';

import 'api.dart';
import 'financial_operation_store.dart';

String referralTitle(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar'
    ? 'الإحالات'
    : 'Referrals';

class ReferralView extends StatefulWidget {
  const ReferralView({
    super.key,
    required this.api,
    required this.onExpired,
    this.store,
  });

  final EvApi api;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;

  @override
  State<ReferralView> createState() => _ReferralState();
}

class _ReferralState extends State<ReferralView> with WidgetsBindingObserver {
  final code = TextEditingController();
  final invoice = TextEditingController();
  final reason = TextEditingController();
  late bool Function() ownsSession;
  FinancialOperation? operation;
  ApiPage<Map<String, dynamic>>? programmes;
  ApiPage<Map<String, dynamic>>? rewards;
  Map<String, dynamic>? selected;
  Map<String, dynamic>? account;
  Map? ownerReferral;
  bool busy = false;
  bool foreground = true;
  StreamSubscription<int>? subscription;
  int privateEpoch = 0, consentRevision=0;
  bool consent = false;
  bool emailVerified = false;
  int generation = 0;
  String? message;

  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  Map? get draft => operation?.pending?['body'] as Map?;
  Map? get referral => account?['referral'] as Map? ?? ownerReferral;
  bool get active => selected?['active'] == true;
  bool current(int epoch) => mounted && foreground && epoch == generation && ownsSession();
  void redact(){
    privateEpoch++; generation++;
    operation=null;programmes=null;rewards=null;selected=null;account=null;ownerReferral=null;
    emailVerified=false;busy=false;message=null;clearFields();
  }
  void subscribe(){
    final api=widget.api;
    subscription=api.sessionEvents.listen((_){
      if(mounted&&identical(api,widget.api)&&!ownsSession())setState(redact);
    });
  }

  @override
  void initState(){
    super.initState();
    ownsSession=widget.api.sessionFence();
    final state=WidgetsBinding.instance.lifecycleState;
    foreground=state==null||state==AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    if(foreground&&ownsSession())load();
  }
  @override
  void didUpdateWidget(covariant ReferralView oldWidget){
    super.didUpdateWidget(oldWidget);
    if(!identical(oldWidget.api,widget.api)){
      unawaited(subscription?.cancel());
      redact();
      ownsSession=widget.api.sessionFence();
      subscribe();
      if(foreground&&ownsSession())load();
    }else if(!ownsSession()){
      redact();
    }else if(!identical(oldWidget.store,widget.store)){
      redact();
      if(foreground)load();
    }
  }
  @override
  void dispose(){
    privateEpoch++;generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(subscription?.cancel());
    code.dispose();invoice.dispose();reason.dispose();
    super.dispose();
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){
    final resumed=state==AppLifecycleState.resumed;
    if(!mounted||resumed==foreground)return;
    setState((){foreground=resumed;redact();});
    // Durable intent stays in the original account's secure store.
    // Require explicit refresh; never replay a mutation on resume.
  }

  void clearFields() {
    consentRevision++;
    consent = false;
    code.clear();
    invoice.clear();
    reason.clear();
  }

  Map<String, dynamic> programme(Map<String, dynamic> row) {
    if (row['id'] is! int ||
        row['name'] is! String ||
        row['name_ar'] is! String ||
        row['terms'] is! String ||
        row['terms_ar'] is! String ||
        !RegExp(r'^[a-f0-9]{64}$').hasMatch('${row['terms_version']}') ||
        row['active'] is! bool ||
        row['cash_value'] != false ||
        row['money_movement'] != false ||
        row['delivery_verified'] != false ||
        row['fulfillment'] != 'MANUAL_NON_MONETARY') {
      throw const FormatException('Invalid referral programme');
    }
    return row;
  }

  Map<String, dynamic> reward(Map<String, dynamic> row) {
    if (row['id'] is! int ||
        row['reward_name'] is! String ||
        row['reward_name_ar'] is! String ||
        row['points_cost'] != 0 ||
        row['cash_value'] != false ||
        row['delivery_verified'] != false ||
        row['source'] != 'REFERRAL' ||
        row['source_currently_eligible'] is! bool ||
        row['fulfillment'] != 'MANUAL_NON_MONETARY' ||
        !['pending', 'fulfilled', 'rejected'].contains(row['status'])) {
      throw const FormatException('Invalid referral reward');
    }
    return row;
  }

  Map<String, dynamic> checkedAccount(dynamic result, int program) {
    final row = Map<String, dynamic>.from(result as Map);
    if (row['program_id'] != program ||
        row['money_movement'] != false ||
        row['can_accept'] is! bool ||
        row['invited_count'] is! int ||
        row['qualified_count'] is! int) {
      throw const FormatException('Invalid referral account');
    }
    final own = row['own_code'];
    if (own != null &&
        (own is! Map ||
            own['program_id'] != program ||
            !validCode.hasMatch('${own['code']}'))) {
      throw const FormatException('Invalid referral code');
    }
    if (row['referral'] != null) checkReferral(row['referral']);
    return row;
  }

  void checkReferral(dynamic row) {
    if (row is! Map ||
        row['id'] is! int ||
        row['program_id'] is! int ||
        row['cash_value'] != false ||
        row['money_movement'] != false ||
        row['delivery_verified'] != false ||
        ![
          'qualified',
          'withdrawn',
          'expired',
          'accepted',
        ].contains(row['status'])) {
      throw const FormatException('Invalid referral receipt');
    }
  }

  static final validCode = RegExp(r'^REF-[A-F0-9]{20}$');

  bool scoped(EvApi api, bool Function() fence) =>
      mounted && foreground && identical(api, widget.api) &&
      identical(fence, ownsSession) && fence();

  Future<void> run(Future<void> Function(int) action) async {
    final api = widget.api;
    final fence = ownsSession;
    if (!scoped(api, fence) || busy) return;
    final epoch = ++generation, scopeEpoch=privateEpoch;
    setState(() {
      busy = true;
      message = null;
    });
    try {
      await action(epoch);
    } catch (error) {
      if(mounted&&foreground&&identical(api,widget.api)&&identical(fence,ownsSession)&&
          (privateEpoch==scopeEpoch||!fence())&&error is ApiException&&error.endsCurrentSession){
        widget.onExpired();
      }else if(current(epoch)&&scoped(api,fence)){
        setState(()=>message=t(
          'Referral request could not be confirmed. Check the status or retry a pending request.',
          'تعذر تأكيد طلب الإحالة. تحقق من الحالة أو أعد محاولة الطلب المعلق.'));
      }
    } finally {
      if (current(epoch) && scoped(api, fence)) {
        setState(() => busy = false);
      }
    }
  }

  Future<void> load() => run((epoch) async {
    final me = await widget.api.request('me');
    if (!current(epoch)) return;
    if (me['id'] is! int || me['id'] <= 0 || me['role'] != 'customer') {
      throw const FormatException('Invalid customer account');
    }
    final api=widget.api,fence=ownsSession,scopeEpoch=privateEpoch;
    final op = FinancialOperation(
      widget.store ?? DeviceFinancialOperationStore(),
      '${api.base.origin}:${me['id']}:loyalty:referral',
      isCurrent: () => scoped(api,fence)&&privateEpoch==scopeEpoch,
    );
    await op.restore();
    if (!current(epoch)) return;
    setState(() {
      operation = op;
      emailVerified = me['email_verified_at'] != null;
    });
    await readOwnerReferral(epoch);
    if (current(epoch)) await listProgrammes(epoch, 1);
  });

  Future<void> listProgrammes(int epoch, int page) async {
    final result = await widget.api.request(
      'commercial/referral-programs',
      query: {'page': '$page'},
    );
    if (!current(epoch)) return;
    final parsed = ApiPage.fromJson(result, programme);
    setState(() => programmes = parsed);
  }

  Future<void> readOwnerReferral(int epoch) async {
    final result = await widget.api.request('commercial/referrals/account');
    if (!current(epoch)) return;
    if (result['money_movement'] != false) {
      throw const FormatException('Invalid referral account');
    }
    final existing = result['referral'];
    if (existing != null) checkReferral(existing);
    setState(() => ownerReferral = existing as Map?);
  }

  Future<void> readAccount(int epoch, int id) async {
    if(current(epoch))setState((){account=null;consent=false;consentRevision++;});
    final result = await widget.api.request(
      'commercial/referrals/account',
      query: {'program_id': '$id'},
    );
    if (!current(epoch)) return;
    final parsed = checkedAccount(result, id);
    setState(() => account = parsed);
  }

  Future<void> openProgramme(Map<String, dynamic> row) => run((epoch) async {
    if(!(programmes?.items.any((v)=>identical(v,row))??false))return;
    setState(() {
      clearFields();
      selected = row;
      account = null;
    });
    await readAccount(epoch, row['id'] as int);
  });

  Future<void> readRewards(int page) => run((epoch) async {
    final result = await widget.api.request(
      'commercial/referrals/rewards',
      query: {'page': '$page'},
    );
    if (!current(epoch)) return;
    final parsed = ApiPage.fromJson(result, reward);
    setState(() => rewards = parsed);
  });

  Future<void> mutate(Map<String, dynamic> intent) => run((epoch) async {
    final op = operation;
    if (op == null) return;
    final staged = await op.prepare(intent);
    if (!current(epoch)) return;
    final action = staged['action'];
    final program = staged['program_id'] as int;
    final key = staged['idempotency_key'] as String;
    final Map<String, dynamic> body = switch (action) {
      'codes' => {'program_id': program, 'idempotency_key': key},
      'accept' => {
        'code': staged['code'],
        'terms_version': staged['terms_version'],
        'consent': true,
        'idempotency_key': key,
      },
      'qualify' => {'invoice_id': staged['invoice_id'], 'idempotency_key': key},
      'withdraw' => {'reason': staged['reason']},
      _ => throw const FormatException('Invalid referral request'),
    };
    final result = await widget.api.request(
      'commercial/referrals/$action',
      method: 'POST',
      body: body,
    );
    if (!current(epoch)) return;
    if (action == 'codes') {
      if (result['program_id'] != program ||
          !validCode.hasMatch('${result['code']}')) {
        throw const FormatException('Invalid referral code receipt');
      }
    } else {
      checkReferral(result['referral']);
      if (result['referral']['program_id'] != program) {
        throw const FormatException('Referral programme does not match');
      }
    }
    // Preserve the request until current private account records are readable.
    if (selected?['id'] == program) {
      await readAccount(epoch, program);
    } else {
      await readOwnerReferral(epoch);
    }
    if (!current(epoch)) return;
    await op.confirmed();
    if (!current(epoch)) return;
    setState(() {
      clearFields();
      rewards = null;
      message = t('Referral request confirmed.', 'تم تأكيد طلب الإحالة.');
    });
  });

  Future<void> forgetDraft() async {
    final api = widget.api;
    final fence = ownsSession;
    final initialGeneration = generation;
    final original = operation;
    if (!scoped(api, fence) || busy || original == null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(t('Clear the saved request?', 'مسح الطلب المحفوظ؟')),
        content: Text(
          t(
            'Clearing this device does not cancel a request already received by the server.',
            'مسح الطلب من هذا الجهاز لا يلغي طلباً تلقاه الخادم.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(t('Cancel', 'إلغاء')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(t('Clear', 'مسح')),
          ),
        ],
      ),
    );
    if (confirmed != true || busy ||
        generation != initialGeneration ||
        !identical(original, operation) ||
        !scoped(api, fence)) return;
    await run((epoch) async {
      await original.confirmed();
      if (current(epoch) && scoped(api, fence)) setState(clearFields);
    });
  }

  String referralStatus(Object? value) => switch (value) {
    'accepted' => t('Accepted', 'تم القبول'),
    'qualified' => t('Qualified', 'مؤهلة'),
    'withdrawn' => t('Withdrawn', 'تم سحبها'),
    'expired' => t('Expired', 'منتهية'),
    _ => t('Status unavailable', 'الحالة غير متاحة'),
  };

  Widget details(Map<String, dynamic> program) {
    final enabled = !busy && draft == null && operation != null;
    final id = program['id'] as int;
    final api = widget.api;
    final fence = ownsSession;
    final rendered=generation, reviewedAccount=account, reviewedConsent=consentRevision;
    final reviewedCode=code.text.trim().toUpperCase();
    bool sameProgramme() => scoped(api, fence) && generation==rendered &&
        identical(selected,program) && identical(account,reviewedAccount) && !busy;
    void act(Map<String, dynamic> intent) {
      if (sameProgramme()) mutate(intent);
    }
    final existing = referral;
    final ownCode = account?['own_code']?['code'] as String?;
    final accepted = existing?['status'] == 'accepted';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          t(program['name'] as String, program['name_ar'] as String),
          style: Theme.of(context).textTheme.titleLarge,
        ),
        Text(active ? t('Open', 'مفتوح') : t('Unavailable', 'غير متاح')),
        Text(t(program['terms'] as String, program['terms_ar'] as String)),
        if (program['activation_mode'] == 'sandbox')
          Text(t('Sandbox programme', 'برنامج اختباري')),
        if (account != null) ...[
          Text(
            t(
              'Invitations: ${account!['invited_count']} · Qualified: ${account!['qualified_count']}',
              'الدعوات: ${account!['invited_count']} · المؤهلة: ${account!['qualified_count']}',
            ),
          ),
          if (!emailVerified)
            Text(
              t(
                'Verify your email to participate.',
                'تحقق من بريدك الإلكتروني للمشاركة.',
              ),
            ),
          if (ownCode != null) ...[
            SelectableText(ownCode),
            TextButton(
              onPressed: busy
                  ? null
                  : () {
                      if (sameProgramme()) {
                        Clipboard.setData(ClipboardData(text: ownCode));
                      }
                    },
              child: Text(
                t('Copy my referral code', 'نسخ رمز الإحالة الخاص بي'),
              ),
            ),
          ] else if (active && emailVerified)
            OutlinedButton(
              onPressed: enabled
                  ? () => act({'action': 'codes', 'program_id': id})
                  : null,
              child: Text(
                t('Create my referral code', 'إنشاء رمز الإحالة الخاص بي'),
              ),
            ),
          if (account!['can_accept'] == true) ...[
            TextField(
              controller: code,
              enabled: enabled,
              textCapitalization: TextCapitalization.characters,
              maxLength: 24,
              decoration: InputDecoration(
                labelText: t('Referral code', 'رمز الإحالة'),
              ),
              onChanged: (_) {if(sameProgramme())setState(() {consentRevision++;consent=false;});},
            ),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(
                t(
                  'I accept the programme terms shown above.',
                  'أوافق على شروط البرنامج المعروضة أعلاه.',
                ),
              ),
              value: consent,
              onChanged: enabled
                  ? (value) {if(sameProgramme()&&reviewedConsent==consentRevision&&reviewedCode==code.text.trim().toUpperCase())setState(() => consent = value == true);}
                  : null,
            ),
            FilledButton(
              onPressed:
                  enabled &&
                      consent &&
                      validCode.hasMatch(code.text.trim().toUpperCase())
                  ? () {
                      if(!consent||reviewedConsent!=consentRevision||reviewedCode!=code.text.trim().toUpperCase())return;
                      act({
                        'action':'accept',
                        'program_id':id,
                        'code':reviewedCode,
                        'terms_version':program['terms_version'],
                      });
                    }
                  : null,
              child: Text(t('Accept referral', 'قبول الإحالة')),
            ),
          ],
          if (existing != null) ...[
            Text(referralStatus(existing['status'])),
            if (accepted && existing['program_id'] == id) ...[
              TextField(
                controller: invoice,
                enabled: enabled,
                keyboardType: TextInputType.number,
                maxLength: 16,
                decoration: InputDecoration(
                  labelText: t(
                    'First paid charging invoice ID',
                    'معرف أول فاتورة شحن مدفوعة',
                  ),
                ),
                onChanged: (_) => setState(() {}),
              ),
              FilledButton(
                onPressed: enabled && active && invoiceId != null
                    ? () => act({
                        'action': 'qualify',
                        'program_id': id,
                        'invoice_id': invoiceId,
                      })
                    : null,
                child: Text(
                  t('Check invoice eligibility', 'التحقق من أهلية الفاتورة'),
                ),
              ),
              TextField(
                controller: reason,
                enabled: enabled,
                maxLength: 500,
                decoration: InputDecoration(
                  labelText: t('Reason for withdrawal', 'سبب السحب'),
                ),
                onChanged: (_) => setState(() {}),
              ),
              OutlinedButton(
                onPressed: enabled && reason.text.trim().length >= 5
                    ? () => act({
                        'action': 'withdraw',
                        'program_id': id,
                        'reason': reason.text.trim(),
                      })
                    : null,
                child: Text(
                  t('Withdraw referral consent', 'سحب الموافقة على الإحالة'),
                ),
              ),
            ],
          ],
        ],
        TextButton(
          onPressed: busy ? null : () {
            if (sameProgramme()) run((epoch) => readAccount(epoch, id));
          },
          child: Text(t('Refresh referral status', 'تحديث حالة الإحالة')),
        ),
      ],
    );
  }

  int? get invoiceId {
    final value=vendorDigits(invoice.text.trim());
    if (!RegExp(r'^[1-9][0-9]{0,15}$').hasMatch(value))return null;
    final id = int.parse(value);
    return id <= 9007199254740991 ? id : null;
  }

  Widget pageButtons(int page, int last, void Function(int) select) => Row(
    children: [
      if (page > 1)
        TextButton(
          onPressed: busy ? null : () => select(page - 1),
          child: Text(t('Previous', 'السابق')),
        ),
      if (page < last)
        TextButton(
          onPressed: busy ? null : () => select(page + 1),
          child: Text(t('Next', 'التالي')),
        ),
    ],
  );

  Widget rewardRow(Map<String, dynamic> row) => ListTile(
    title: Text(
      t(row['reward_name'] as String, row['reward_name_ar'] as String),
    ),
    subtitle: Text(
      [
        switch (row['status']) {
          'fulfilled' => t(
            'Manual fulfillment recorded',
            'تم تسجيل التسليم اليدوي',
          ),
          'rejected' => t('Rejected', 'مرفوضة'),
          _ => t('Pending manual fulfillment', 'بانتظار التسليم اليدوي'),
        },
        if (row['source_currently_eligible'] == false)
          t('Source no longer eligible', 'المصدر غير مؤهل حالياً'),
      ].join(' · '),
    ),
  );

  @override
  Widget build(BuildContext context) {
    if (!ownsSession()||!foreground) return Scaffold(appBar:AppBar(title:Text(referralTitle(context))),body:Center(child:Text(!ownsSession()?t('Account changed. Reopen referrals.','تغير الحساب. أعد فتح الإحالات.'):t('Private referrals paused.','الإحالات الخاصة متوقفة مؤقتاً.'))));
    final api = widget.api;
    final fence = ownsSession;
    final pending = draft;
    final rendered=generation;
    bool authorized()=>scoped(api,fence)&&generation==rendered&&!busy;
    return Scaffold(
      appBar: AppBar(title: Text(referralTitle(context))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (busy) const LinearProgressIndicator(),
          if (message != null)
            Semantics(liveRegion: true, child: Text(message!)),
          Text(
            t(
              'Referral rewards have no cash value and require manual fulfillment.',
              'مكافآت الإحالة ليست نقدية وتتطلب التسليم اليدوي.',
            ),
          ),
          if (pending != null) ...[
            Text(
              t(
                'A referral request is pending on this device.',
                'يوجد طلب إحالة معلق على هذا الجهاز.',
              ),
            ),
            FilledButton(
              onPressed: busy
                  ? null
                  : () {
                      if (authorized() && identical(draft, pending)) {
                        mutate(Map<String, dynamic>.from(pending));
                      }
                    },
              child: Text(
                t('Retry saved request', 'إعادة محاولة الطلب المحفوظ'),
              ),
            ),
            TextButton(
              onPressed: busy ? null : () {
                if (authorized()) forgetDraft();
              },
              child: Text(t('Clear saved request', 'مسح الطلب المحفوظ')),
            ),
          ],
          if (programmes == null && !busy)
            TextButton(
              onPressed: () {
                if (authorized()) load();
              },
              child: Text(t('Retry programmes', 'إعادة تحميل البرامج')),
            ),
          if (programmes case final list?) ...[
            if (list.items.isEmpty)
              Text(t('No referral programmes.', 'لا توجد برامج إحالة.')),
            for (final row in list.items)
              OutlinedButton(
                onPressed: busy ? null : () {
                  if (authorized()) openProgramme(row);
                },
                child: Text(t(row['name'] as String, row['name_ar'] as String)),
              ),
            pageButtons(
              list.current,
              list.last,
              (number) {
                if (authorized()) {
                  run((epoch) => listProgrammes(epoch, number));
                }
              },
            ),
          ],
          if (selected case final currentProgram?)
            details(currentProgram)
          else if (ownerReferral case final existing?) ...[
            Text(t('My referral status', 'حالة الإحالة الخاصة بي')),
            Text(referralStatus(existing['status'])),
            TextButton(
              onPressed: busy ? null : () {
                if (authorized()) run(readOwnerReferral);
              },
              child: Text(t('Refresh referral status', 'تحديث حالة الإحالة')),
            ),
          ],
          const Divider(),
          TextButton(
            onPressed: busy ? null : () {
              if (authorized()&&operation!=null) readRewards(1);
            },
            child: Text(t('My referral rewards', 'مكافآت الإحالة الخاصة بي')),
          ),
          if (rewards case final history?) ...[
            if (history.items.isEmpty)
              Text(t('No referral rewards.', 'لا توجد مكافآت إحالة.')),
            for (final row in history.items) rewardRow(row),
            pageButtons(history.current, history.last, (number) {
              if (authorized()) readRewards(number);
            }),
          ],
        ],
      ),
    );
  }
}
