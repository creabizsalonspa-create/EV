import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'reservation_times.dart';

/// Anonymous moderated feedback; no physical charging verification is inferred.
class ChargingReviewsView extends StatefulWidget {
  final EvApi api;
  final String? role;
  final int? stationId;
  final VoidCallback onExpired;
  const ChargingReviewsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.role,
    this.stationId,
  });
  @override
  State<ChargingReviewsView> createState() => _ReviewsState();
}

class _ReviewsState extends State<ChargingReviewsView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? data, editing, pending;
  String mode = 'mine', terms = '', message = '';
  int generation = 0, page = 1;
  int? historyId, rating;
  bool busy = false, background = false, consent = false, reviewed = false;
  final body = TextEditingController();
  late bool Function() accountFence;
  StreamSubscription<int>? sessionSubscription;
  bool get active => mounted && !background && accountFence();
  bool current(int epoch, EvApi api) => active && epoch == generation && identical(api,widget.api);
  VoidCallback guarded(VoidCallback callback) {
    final epoch = generation, api = widget.api;
    return () { if (current(epoch,api) && !busy) callback(); };
  }
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    background = lifecycle != null && lifecycle != AppLifecycleState.resumed;
    accountFence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    reset();
    if (active) load();
  }

  void subscribe() {
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api,widget.api) && !accountFence()) {
        // Preserve request generation for a real current-session expiration
        // signal, but remove every record and pending write immediately.
        setState(reset);
      }
    });
  }

  void reset() {
    busy = false;
    terms = message = '';
    historyId = null;
    mode = widget.stationId != null
        ? 'public'
        : widget.role == 'customer'
        ? 'mine'
        : 'operations';
    page = 1;
    data = null;
    pending = null;
    editing = null;
    body.clear();
    consent = false;
    rating = null;
    reviewed = false;
  }

  @override
  void didUpdateWidget(covariant ChargingReviewsView old) {
    super.didUpdateWidget(old);
    if (!identical(old.api,widget.api)) {
      generation++;
      unawaited(sessionSubscription?.cancel());
      accountFence = widget.api.sessionFence();
      reset();
      subscribe();
      if (active) load();
    } else if (!accountFence()) {
      generation++;
      reset();
    } else if (old.role != widget.role || old.stationId != widget.stationId) {
      generation++;
      reset();
      if (active) load();
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    body.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final paused = state != AppLifecycleState.resumed;
    if (!mounted || paused == background) return;
    generation++;
    setState(() {
      background = paused;
      if (!accountFence()) {
        reset();
      } else if (background) {
        // Retain the frozen uncertain request only for this same account,
        // without displaying it or replaying it on resume.
        data = null;
        editing = null;
        body.clear();
        consent = false;
        rating = null;
        reviewed = false;
        busy = false;
        message = terms = '';
      }
    });
    if (active) load();
  }

  static const sources=['SIMULATED_SOURCE_OBSERVED','SIMULATED_APP_ONLY'];
  static const states=['pending','published','rejected','hidden','withdrawn'];
  bool positive(dynamic v)=>v is int&&v>0;
  bool content(dynamic v)=>v is String&&v.trim().length>=3&&v.length<=2000;
  bool validRating(dynamic v)=>v is int&&v>=1&&v<=5;
  void contract(Map<String,dynamic> row,{bool private=false}) {
    if (!sources.contains(row['evidence']) ||
        row['physical_charging_verified'] != false ||
        !validRating(row['rating']) || !content(row['body']) ||
        row['public_id'] is! String || (row['public_id'] as String).trim().isEmpty ||
        row['created_at'] is! String ||
        (private&&(!positive(row['id'])||!positive(row['session_id'])||
          !positive(row['station_id'])||!positive(row['version'])||
          !positive(row['revision'])||row['revision']>row['version']||
          !states.contains(row['state'])))) {
      throw const FormatException('Unexpected review evidence');
    }
  }
  void eligibleContract(Map<String,dynamic> row) {
    if(!positive(row['session_id'])||row['physical_charging_verified']!=false||
       row['can_review'] is! bool||
       (row['evidence']=='INELIGIBLE'?row['can_review']!=false:
         (!sources.contains(row['evidence'])||!positive(row['station_id'])||
          row['station_name'] is! String||
          (row['existing_review_id']!=null&&!positive(row['existing_review_id']))||
          (row['can_review']==true&&row['existing_review_id']!=null)))) {
      throw const FormatException('Invalid eligible feedback evidence');
    }
  }
  void historyContract(Map<String,dynamic> row) {
    final action=row['action'];
    if(!['create','revise','withdraw','publish','reject','hide'].contains(action)||
       !positive(row['version'])||!positive(row['revision'])||
       row['revision']>row['version']||row['created_at'] is! String||
       (['create','revise'].contains(action)?
         (!validRating(row['rating'])||!content(row['body'])):
         (row['rating']!=null||row['body']!=null))||
       (['publish','reject','hide'].contains(action)?
          (row['reason'] is! String||(row['reason'] as String).trim().length<5||
            (row['reason'] as String).length>500):row['reason']!=null)) {
      throw const FormatException('Invalid feedback history');
    }
  }
  Map<String,dynamic> validatedPage(dynamic raw) {
    final result=Map<String,dynamic>.from(raw as Map);
    final rows=result['data'], last=result['last_page'], total=result['total'];
    if(rows is! List||rows.length>20||!positive(last)||total is! int||
       total<0||rows.length>total||result['current_page']!=page){
      throw const FormatException('Invalid feedback page');
    }
    if(['mine','eligible'].contains(mode)&&result['terms_version']!='charging-feedback-v1'){
      throw const FormatException('Unrecognized publication terms');
    }
    if(mode=='public'){
      final groups=result['groups'];
      if(result['physical_charging_verified']!=false||result['station_name'] is! String||
         groups is! List||groups.length!=2)throw const FormatException('Invalid public evidence');
      final seen=<String>{};
      var countTotal=0;
      for(final group in groups){
        if(group is! Map||!sources.contains(group['evidence'])||
          !seen.add(group['evidence'] as String))throw const FormatException('Invalid public group');
        final count=group['count'],sum=group['rating_sum'];
        if(count is! int||count<0||sum is! int||sum<count||sum>count*5){
          throw const FormatException('Invalid public rating total');
        }
        countTotal+=count;
      }
      if(countTotal!=total)throw const FormatException('Unreconciled public rating total');
    }
    final seen=<dynamic>{};
    for(final value in rows){
      final row=Map<String,dynamic>.from(value as Map);
      if(mode=='eligible'){
        eligibleContract(row);
        if(!seen.add(row['session_id']))throw const FormatException('Duplicate session');
      }else if(mode=='history'){
        historyContract(row);
        if(!seen.add(row['version']))throw const FormatException('Duplicate history version');
      }else{
        contract(row,private:mode=='mine'||(mode=='operations'&&widget.role=='admin'));
        if(!seen.add(row['public_id']))throw const FormatException('Duplicate feedback');
      }
    }
    return result;
  }
  void failed(Object e) {
    message = tr(
      'Feedback unavailable. Refresh before another action.',
      'الملاحظات غير متاحة. حدّث قبل إجراء آخر.',
    );
    data = null;
    editing = null;
    body.clear();
    consent = false;
    reviewed = false;
    if (e is ApiException && [401, 419].contains(e.status)) {
      pending = null;
      widget.onExpired();
    }
  }

  Future<void> load([String? next]) async {
    if (!active) return;
    if (next != null) {
      mode = next;
      page = 1;
    }
    final epoch = ++generation, api = widget.api;
    setState(() {
      busy = true;
      data = null;
      editing = null;
      body.clear();
      consent = false;
      rating = null;
      reviewed = false;
      message = '';
    });
    try {
      final path = mode == 'public'
          ? 'stations/${widget.stationId}/reviews'
          : mode == 'history'
          ? 'charging-reviews/$historyId/history'
          : 'charging-reviews/$mode';
      final raw = await api.request(path, query: {'page': '$page'});
      if (!current(epoch,api)) return;
      final result=validatedPage(raw);
      setState(() {
        data = result;
        terms = result['terms_version'] as String? ?? terms;
        reviewed = ['mine', 'history'].contains(mode);
      });
    } catch (e) {
      if (current(epoch,api)) {
        setState(() => failed(e));
      } else if (mounted && !background && epoch == generation &&
          identical(api,widget.api) && e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (current(epoch,api)) setState(() => busy = false);
      else if (mounted && epoch == generation && identical(api,widget.api) &&
          !accountFence()) setState(reset);
    }
  }

  Future<void> mutate(
    String path,
    String method,
    Map<String, dynamic> value,
  ) async {
    if (!active || busy || pending != null) return;
    pending = Map<String, dynamic>.unmodifiable({
      'path': path,
      'method': method,
      'body': Map<String, dynamic>.unmodifiable(value),
    });
    reviewed = false;
    await retry();
  }

  Future<void> retry() async {
    if (!active || busy || pending == null) return;
    final frozen = pending!, epoch = ++generation, api = widget.api;
    setState(() {
      busy = true;
      data = null;
      editing = null;
      body.clear();
      consent = false;
      message = '';
    });
    try {
      final row = await api.request(
        frozen['path'] as String,
        method: frozen['method'] as String,
        body: frozen['body'] as Map<String, dynamic>,
      );
      if (!current(epoch,api)) return;
      contract(Map<String,dynamic>.from(row as Map),private:true);
      final target=int.tryParse((frozen['path'] as String).split('/').last);
      final method=frozen['method'], submitted=frozen['body'] as Map;
      if((method=='POST'?row['session_id']!=target:row['id']!=target)||
         (method!='POST'&&(submitted['expected_version'] is! int||row['version']<=submitted['expected_version']))){
        throw const FormatException('Feedback acknowledgement identity mismatch');
      }
      // The service may return a newer review on idempotent replay. Do not
      // claim its text/state equals the older submitted content: read source.
      pending = null;
      await load(widget.role == 'customer' ? 'mine' : 'operations');
    } catch (e) {
      if (current(epoch,api)) {
        setState(() => failed(e));
      } else if (mounted && !background && epoch == generation &&
          identical(api,widget.api) && e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (current(epoch,api)) setState(() => busy = false);
      else if (mounted && epoch == generation && identical(api,widget.api) &&
          !accountFence()) setState(reset);
    }
  }

  void edit(Map<String, dynamic> row) {
    if (!active || busy || pending != null || widget.role != 'customer') return;
    setState(() {
      editing = row;
      body.text = row['body'] as String? ?? '';
      rating = row['rating'] as int?;
      consent = false;
    });
  }

  Widget evidence(String value) => Text(
    value == 'SIMULATED_SOURCE_OBSERVED'
        ? tr(
            'Feedback from an observed simulator session. Physical charging unverified.',
            'ملاحظات من جلسة محاكاة مرصودة. الشحن الفعلي غير متحقق منه.',
          )
        : tr(
            'App simulator feedback. No device or physical charging verification.',
            'ملاحظات محاكاة التطبيق. لا يوجد تحقق من جهاز أو شحن فعلي.',
          ),
  );
  @override
  Widget build(BuildContext context) {
    if (!active) {
      return Scaffold(
        appBar:AppBar(title:Text(tr('Charging feedback','ملاحظات الشحن'))),
        body:Center(child:Text(background
          ? tr('Private view paused.','العرض الخاص متوقف مؤقتاً.')
          : tr('Account changed. Reopen charging feedback.','تغير الحساب. أعد فتح ملاحظات الشحن.'))),
      );
    }
    final result = data;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Charging feedback', 'ملاحظات الشحن'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (background)
            Text(tr('Private view paused.', 'العرض الخاص متوقف مؤقتاً.')),
          if (busy) const LinearProgressIndicator(),
          if (message.isNotEmpty) Text(message),
          if (pending != null) ...[
            Text(
              tr(
                'Response uncertain. Review history or retry the same feedback request.',
                'الاستجابة غير مؤكدة. راجع السجل أو أعد نفس طلب الملاحظات.',
              ),
            ),
            TextButton(
              onPressed: busy || background ? null : guarded(() { retry(); }),
              child: Text(
                tr('Retry same feedback request', 'إعادة نفس طلب الملاحظات'),
              ),
            ),
            if (reviewed)
              TextButton(
                onPressed: busy || background
                    ? null
                    : guarded(() => setState(() => pending = null)),
                child: Text(
                  tr(
                    'Clear pending form after reviewing history',
                    'مسح النموذج المعلق بعد مراجعة السجل',
                  ),
                ),
              ),
          ],
          if (widget.role != null)
            Wrap(
              children: [
                TextButton(
                  onPressed: busy || background ? null : guarded(() { load('mine'); }),
                  child: Text(tr('My feedback', 'ملاحظاتي')),
                ),
                if (widget.role == 'customer')
                  TextButton(
                    onPressed: busy || background
                        ? null
                        : guarded(() { load('eligible'); }),
                    child: Text(
                      tr(
                        'Completed sessions for feedback',
                        'الجلسات المكتملة للملاحظات',
                      ),
                    ),
                  ),
              ],
            ),
          TextButton(
            onPressed: busy || background ? null : guarded(() { load(); }),
            child: Text(tr('Refresh', 'تحديث')),
          ),
          if (mode == 'public' && result != null) ...[
            Text('${result['station_name']}'),
            for (final group in result['groups'] as List) ...[
              evidence(group['evidence'] as String),
              Text(
                '${group['count']} · ${(group['count'] as int) > 0 ? ((group['rating_sum'] as int) / (group['count'] as int)).toStringAsFixed(1) : '—'} / 5',
              ),
            ],
          ],
          if (editing != null && !background) ...editor(),
          if (result != null) ...[
            if ((result['data'] as List).isEmpty)
              Text(
                tr(
                  'No feedback in this view.',
                  'لا توجد ملاحظات في هذا العرض.',
                ),
              ),
            for (final value in result['data'] as List)
              card(Map<String, dynamic>.from(value as Map)),
            Text('${result['current_page']} / ${result['last_page']}'),
            Wrap(
              children: [
                TextButton(
                  onPressed: busy || background || page <= 1
                      ? null
                      : guarded(() {
                          page--;
                          load();
                        }),
                  child: Text(tr('Previous', 'السابق')),
                ),
                TextButton(
                  onPressed:
                      busy || background || page >= (result['last_page'] as int)
                      ? null
                      : guarded(() {
                          page++;
                          load();
                        }),
                  child: Text(tr('Next', 'التالي')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> editor() {
    final row = editing!, epoch = generation, api = widget.api;
    bool editorCurrent() => current(epoch,api) && identical(editing,row) && !busy && pending == null;
    return [
      Text(
        tr('Submit feedback for Admin review', 'إرسال ملاحظات لمراجعة الإدارة'),
      ),
      DropdownButtonFormField<int>(
        initialValue: rating,
        decoration: InputDecoration(labelText: tr('Rating', 'التقييم')),
        items: [
          for (var i = 1; i <= 5; i++)
            DropdownMenuItem(value: i, child: Text('$i / 5')),
        ],
        onChanged: busy || pending != null
            ? null
            : (v) { if (editorCurrent()) setState(() => rating = v); },
      ),
      TextField(
        controller: body,
        maxLength: 2000,
        maxLines: 4,
        decoration: InputDecoration(
          labelText: tr(
            'Feedback (no personal or payment details)',
            'الملاحظات (دون تفاصيل شخصية أو دفع)',
          ),
        ),
      ),
      CheckboxListTile(
        value: consent,
        onChanged: busy || pending != null
            ? null
            : (v) { if (editorCurrent()) setState(() => consent = v == true); },
        title: Text(
          tr(
            'I consent to anonymous public publication after moderation. This is simulator feedback, not physical charging verification.',
            'أوافق على النشر العام دون اسم بعد المراجعة. هذه ملاحظات محاكاة وليست إثبات شحن فعلي.',
          ),
        ),
      ),
      FilledButton(
        onPressed:
            busy || background || pending != null || !consent || rating == null
            ? null
            : () {
                if (!editorCurrent() || !consent || rating == null || body.text.trim().length < 3 || body.text.trim().length > 2000) return;
                mutate(
                  row['id'] != null
                      ? 'charging-reviews/${row['id']}'
                      : 'charging-reviews/sessions/${row['session_id']}',
                  row['id'] != null ? 'PUT' : 'POST',
                  {
                    'request_key': operationKey(),
                    if (row['id'] != null) 'expected_version': row['version'],
                    'rating': rating,
                    'body': body.text.trim(),
                    'consent': true,
                    'terms_version': terms,
                  },
                );
              },
        child: Text(tr('Send for moderation', 'إرسال للمراجعة')),
      ),
    ];
  }

  Widget card(Map<String, dynamic> row) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (row['station_name'] != null) Text('${row['station_name']}'),
          if (mode == 'eligible') ...[
            if (row['evidence'] != 'INELIGIBLE') evidence('${row['evidence']}'),
            if (row['can_review'] == true)
              if (widget.role == 'customer')
                TextButton(
                  onPressed: busy || background || pending != null
                      ? null
                      : guarded(() => edit(row)),
                  child: Text(tr('Write feedback', 'كتابة ملاحظات')),
                )
              else
                Text(
                  tr(
                    'No new feedback available. Review existing feedback or source completion.',
                    'لا تتوفر ملاحظات جديدة. راجع الملاحظات الحالية أو اكتمال المصدر.',
                  ),
                ),
          ] else if (mode == 'history') ...[
            Text('${row['version']} · ${row['action']}'),
            if (row['body'] != null) SelectableText('${row['body']}'),
            if (row['reason'] != null) Text('${row['reason']}'),
          ] else ...[
            evidence('${row['evidence']}'),
            Text('${row['rating']} / 5'),
            SelectableText('${row['body']}'),
            Text(serverEventTime(context, row['created_at'])),
            if (row['state'] != null) Text('${row['state']}'),
            if (mode == 'mine') ...[
              TextButton(
                onPressed: busy || background
                    ? null
                    : guarded(() {
                        historyId = row['id'] as int;
                        load('history');
                      }),
                child: Text(tr('Feedback history', 'سجل الملاحظات')),
              ),
              if (widget.role == 'customer')
                TextButton(
                  onPressed: busy || background || pending != null
                      ? null
                      : guarded(() => edit(row)),
                  child: Text(tr('Revise feedback', 'تعديل الملاحظات')),
                ),
              TextButton(
                onPressed: busy || background || pending != null
                    ? null
                    : guarded(() {
                        mutate('charging-reviews/${row['id']}', 'DELETE', {
                          'request_key': operationKey(),
                          'expected_version': row['version'],
                        });
                      }),
                child: Text(tr('Withdraw publication', 'سحب النشر')),
              ),
            ],
          ],
        ],
      ),
    ),
  );
}
