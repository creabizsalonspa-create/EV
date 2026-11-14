import 'package:flutter/material.dart';
import 'api.dart';
import 'invoice.dart';
import 'strings.dart';
import 'action_form.dart';
import 'charging_operations.dart';
import 'charging_reviews.dart';
import 'session_cost.dart';

class SessionsView extends StatefulWidget {
  final EvApi api;
  final String role;
  final VoidCallback onExpired;
  const SessionsView({
    super.key,
    required this.api,
    required this.role,
    required this.onExpired,
  });
  @override
  State<SessionsView> createState() => _SessionsViewState();
}

class _SessionsViewState extends State<SessionsView> with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? data;
  bool busy = false;
  Object? error;
  int generation = 0;
  bool foreground = true;
  late bool Function() ownSession;
  @override
  void initState() {
    super.initState();
    ownSession = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  bool active(int epoch, bool Function() fence) => mounted && foreground && epoch == generation && fence();

  void failed(Object e) {
    if (!mounted || !foreground || !ownSession()) return;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(() => error = e);
  }

  Future<void> load({int page = 1}) async {
    if (!foreground) return;
    if (!ownSession()) { if (mounted) setState(() { data = null; error = const ApiException(409,'Session changed'); }); return; }
    final request = ++generation, current = ownSession;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'simulations',
        query: {'page': '$page'},
      );
      if (mounted && request == generation && current() && foreground)
        setState(() => data = ApiPage.fromJson(result, (v) => v));
    } catch (e) {
      if (mounted && request == generation && current() && foreground) failed(e);
    } finally {
      if (mounted && request == generation) setState(() { busy = false; if (!current()) data = null; });
    }
  }

  Future<void> reconcile(int id) async {
    final epoch = generation, fence = ownSession;
    if (!active(epoch,fence)) return;
    if (!await confirmAction(
          context,
          'reconcileEvidence',
          'reconcileConsent',
        ) ||
        !active(epoch,fence))
      return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        'charging-sessions/$id/reconcile',
        method: 'POST',
        body: {},
      );
      if (active(epoch,fence)) await load(page: data?.current ?? 1);
    } catch (e) {
      if (active(epoch,fence)) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> meter(Map<String, dynamic> row) async {
    final epoch = generation, fence = ownSession;
    if (!active(epoch,fence)) return;
    final initial = (row['energy_wh'] as num).toInt();
    final result = await apiForm(
      context,
      title: 'submitMeter',
      fields: [
        InputSpec(
          'energy_wh',
          'cumulativeWh',
          initial: '$initial',
          numeric: true,
          maxLength: 7,
          validate: (v, s) {
            final n = int.tryParse(v);
            return n == null || n < initial || n > 1000000
                ? s.t('meterRange')
                : null;
          },
        ),
      ],
      submit: (v) {
        if (!active(epoch,fence)) throw const ApiException(409,'Session changed');
        return widget.api.request(
        'simulations/${row['id']}/meter',
        method: 'PUT',
        body: {'energy_wh': int.parse(v['energy_wh'])},
      );
      },
      onExpired: () { if (active(epoch,fence)) widget.onExpired(); },
    );
    if (active(epoch,fence)) await load(page: data?.current ?? 1);
    if (result == null) return;
  }

  Future<void> stop(int id, {bool linked = false}) async {
    final epoch = generation, fence = ownSession;
    if (!active(epoch,fence)) return;
    if (!await confirmAction(
          context,
          linked ? 'stopCsms' : 'stopSimulation',
          linked ? 'stopCsmsConsent' : 'stopConsent',
        ) ||
        !active(epoch,fence))
      return;
    setState(() => busy = true);
    try {
      await widget.api.request(
        linked ? 'charging-sessions/$id/csms-stop' : 'simulations/$id/stop',
        method: 'POST',
      );
      if (active(epoch,fence)) await load(page: data?.current ?? 1);
    } catch (e) {
      if (active(epoch,fence)) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> cancelPending(Map<String, dynamic> row) async {
    final epoch = generation, fence = ownSession;
    if (!active(epoch,fence)) return;
    if (!await confirmAction(
          context,
          'cancelUndispatched',
          'cancelUndispatchedConsent',
        ) ||
        !active(epoch,fence))
      return;
    setState(() => busy = true);
    try {
      await widget.api.request(
        'reservations/${row['reservation_id']}',
        method: 'DELETE',
      );
      if (active(epoch,fence)) await load(page: data?.current ?? 1);
    } catch (e) {
      if (active(epoch,fence)) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = ownSession() && foreground ? data : null;
    return RefreshIndicator(
      onRefresh: () => load(page: p?.current ?? 1),
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (widget.role == 'vendor')
            TextButton(
              onPressed: busy
                  ? null
                  : () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => ChargingOperationsView(
                          api: widget.api,
                          onExpired: widget.onExpired,
                        ),
                      ),
                    ),
              child: Text(opsText(context, 'title')),
            ),

          Text(
            s.t('sessions'),
            style: Theme.of(context).textTheme.headlineLarge,
          ),
          Text(s.t('meterDisclaimer')),
          TextButton(onPressed:busy ? null : () => load(page:data?.current ?? 1),child:Text(s.t('refresh'))),
          if (['customer', 'vendor'].contains(widget.role))
            TextButton(
              onPressed: busy
                  ? null
                  : () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => ChargingReviewsView(
                          api: widget.api,
                          role: widget.role,
                          onExpired: widget.onExpired,
                        ),
                      ),
                    ),
              child: Text(
                Localizations.localeOf(context).languageCode == 'ar'
                    ? 'ملاحظات الشحن'
                    : 'Charging feedback',
              ),
            ),

          if (busy) const LinearProgressIndicator(),
          if (error != null) ...[
            Text(s.t(errorKey(error!))),
            TextButton(
              onPressed: busy ? null : () => load(page: p?.current ?? 1),
              child: Text(s.t('retry')),
            ),
          ],
          if (!busy && error == null && (p?.items.isEmpty ?? false))
            Padding(
              padding: const EdgeInsets.all(24),
              child: Text(s.t('empty')),
            ),
          for (final row in p?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Chip(
                      label: Text(
                        s.t(
                          row['csms_linked'] == true
                              ? 'csmsSimulation'
                              : 'simulated',
                        ),
                      ),
                    ),
                    Text(
                      s.t('session', {
                        'id': row['id'],
                        'status': s.t(row['status']),
                      }),
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    Text(
                      s.t('reservationNumber', {'id': row['reservation_id']}),
                    ),
                    Text(
                      s.t(row.containsKey('cost_projection') ? 'meterEnergyOnly' : 'energy', {
                        'energy': row['energy_wh'],
                        'total': row['total_minor'] ?? s.t('pending'),
                        'currency': row['currency'],
                      }),
                    ),
                    SessionCostPanel(value:row['cost_projection']),
                    if (row['csms_linked'] == true) Text(s.t('sourceControls')),
                    if (row['authorization_method'] == 'rider_token')
                      Text(
                        Localizations.localeOf(context).languageCode == 'ar'
                            ? 'التفويض ببطاقة أو رمز؛ دليل مصدر محاكاة فقط'
                            : 'Card/token authorization; simulated source evidence only',
                      ),
                    if (row['awaiting_rider_transaction'] == true)
                      Text(
                        Localizations.localeOf(context).languageCode == 'ar'
                            ? 'تم تسجيل التفويض؛ بانتظار معاملة المصدر. تبقى السعة محجوزة حتى تسوية الحالة.'
                            : 'Authorization recorded; awaiting a source transaction. Capacity stays reserved until reconciled.',
                      ),
                    if (row['csms_linked'] == true) ...[
                      if (row['start_command_status'] != null)
                        Text(
                          s.t('transportStart', {
                            'status': s.t(row['start_command_status']),
                          }),
                        ),
                      if (row['stop_command_status'] != null)
                        Text(
                          s.t('transportStop', {
                            'status': s.t(row['stop_command_status']),
                          }),
                        ),
                      if ([
                        row['start_command_status'],
                        row['stop_command_status'],
                      ].contains('source_observed'))
                        Text(s.t('sourceOutcome')),
                      if (![
                            "cancelled",
                            "expired_unstarted",
                          ].contains(row['status']) &&
                          [
                            row['start_command_status'],
                            row['stop_command_status'],
                          ].any(
                            (v) => [
                              "pending",
                              "dispatching",
                              "outcome_unknown",
                              "submitted",
                              "rejected",
                            ].contains(v),
                          ))
                        TextButton(
                          onPressed: busy
                              ? null
                              : () => reconcile(row['id'] as int),
                          child: Text(s.t('reconcileEvidence')),
                        ),
                    ],
                    if (row['start_authorization_revoked'] == true)
                      Text(
                        Localizations.localeOf(context).languageCode == 'ar'
                            ? 'تغير تفويض الحساب قبل إرسال طلب البدء. ألغِ الحجز غير المرسل وأكد طلباً جديداً؛ لا يوقف هذا جلسة مصدر قائمة.'
                            : 'Account authorization changed before this start was sent. Cancel the undispatched reservation and confirm a fresh request; this does not stop an existing source session.',
                      )
                    else if (row['start_admission_blocked'] == true)
                      Text(s.t('startAdmissionBlocked')),
                    if (widget.role == 'customer' &&
                        row['can_cancel_before_dispatch'] == true)
                      OutlinedButton(
                        onPressed: busy ? null : () => cancelPending(row),
                        child: Text(s.t('cancelUndispatched')),
                      ),
                    if (row['status'] == 'stopped')
                      TextButton(
                        onPressed: busy
                            ? null
                            : () => Navigator.push(
                                context,
                                MaterialPageRoute<void>(
                                  builder: (_) => InvoicePage(
                                    api: widget.api,
                                    sessionId: row['id'] as int,
                                    onExpired: widget.onExpired,
                                  ),
                                ),
                              ),
                        child: Text(s.t('invoice')),
                      ),
                    if (row['status'] == 'charging')
                      Wrap(
                        spacing: 8,
                        children: [
                          if (widget.role == 'vendor' &&
                              row['csms_linked'] != true)
                            FilledButton(
                              onPressed: busy ? null : () => meter(row),
                              child: Text(s.t('submitMeter')),
                            ),
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () => stop(
                                    row['id'] as int,
                                    linked: row['csms_linked'] == true,
                                  ),
                            child: Text(
                              s.t(
                                row['csms_linked'] == true
                                    ? 'stopCsms'
                                    : 'stopSimulation',
                              ),
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
          if (p != null) ...[
            Text(
              s.t('page', {
                'page': p.current,
                'last': p.last,
                'total': p.total,
              }),
            ),
            Wrap(
              spacing: 8,
              children: [
                TextButton(
                  onPressed: busy || p.current <= 1
                      ? null
                      : () => load(page: p.current - 1),
                  child: Text(s.t('previous')),
                ),
                TextButton(
                  onPressed: busy || p.current >= p.last
                      ? null
                      : () => load(page: p.current + 1),
                  child: Text(s.t('next')),
                ),
              ],
            ),
          ],
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  @override
  void didUpdateWidget(covariant SessionsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.role != widget.role) {
      generation++; data = null; error = null; busy = false;
      ownSession = widget.api.sessionFence();
      if (foreground) load();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground && mounted) setState(() { generation++; data = null; error = null; busy = false; });
  }
  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}
