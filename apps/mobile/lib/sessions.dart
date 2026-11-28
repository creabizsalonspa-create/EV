import 'dart:async';
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
  StreamSubscription<int>? sessionSubscription;
  void subscribeSession() {
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(widget.api,api) && !ownSession()) {
        // Redact immediately, even if the owning route has not rebuilt.
        // Do not bind the successor session or dispatch a new history read.
        setState(() { data = null; error = null; busy = false; });
      }
    });
  }
  @override
  void initState() {
    super.initState();
    ownSession = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribeSession();
    if (foreground) load();
  }

  bool active(int epoch, bool Function() fence) =>
      mounted && foreground && epoch == generation &&
      identical(fence, ownSession) && fence();

  void failed(Object e) {
    if (!mounted || !foreground || !ownSession()) return;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(() => error = e);
  }

  Future<void> load({int page = 1}) async {
    final api = widget.api, fence = ownSession;
    if (!foreground || !fence()) return;
    final request = ++generation;
    bool current() => active(request, fence) && identical(widget.api, api);
    setState(() {
      busy = true;
      error = null;
      // Do not retain another page or a prior account's balances across
      // an unverified refresh, denied read or failed transport.
      data = null;
    });
    try {
      final result = await api.request(
        'simulations',
        query: {'page': '$page'},
      );
      if (!current()) return;
      final parsed = ApiPage.fromJson(result, (v) => v);
      if (current()) setState(() => data = parsed);
    } catch (e) {
      if (current()) {
        failed(e);
      } else if (mounted && foreground && request == generation &&
          identical(widget.api,api) && identical(ownSession,fence) &&
          e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (current()) {
        setState(() => busy = false);
      } else if (mounted && request == generation &&
          identical(widget.api, api) && identical(ownSession, fence) &&
          !fence()) {
        // A retired account may leave an animated loading indicator behind
        // even when its response is correctly ignored. Clear only this
        // original view; never touch a successor request or its data.
        setState(() {
          generation++;
          busy = false;
          data = null;
          error = null;
        });
      }
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
    if (!foreground || !ownSession()) return const SizedBox.shrink();
    final s = EvStrings.of(context), p = data;
    final originalApi = widget.api;
    final originalSession = ownSession;
    final originalEpoch = generation;
    final originalRole = widget.role;
    bool stillCurrent() => mounted && foreground &&
        originalEpoch == generation &&
        identical(widget.api, originalApi) &&
        identical(ownSession, originalSession) &&
        widget.role == originalRole && originalSession();
    return RefreshIndicator(
      onRefresh: () => stillCurrent()
          ? load(page: p?.current ?? 1) : Future<void>.value(),
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          if (widget.role == 'vendor')
            TextButton(
              onPressed: busy
                  ? null
                  : () {
                      if (!stillCurrent()) return;
                      Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => ChargingOperationsView(
                            api: originalApi,
                            onExpired: widget.onExpired,
                          ),
                        ),
                      );
                    },
              child: Text(opsText(context, 'title')),
            ),

          Text(
            s.t('sessions'),
            style: Theme.of(context).textTheme.headlineLarge,
          ),
          Text(s.t('meterDisclaimer')),
          TextButton(onPressed:busy ? null : () {
            if (stillCurrent()) load(page:p?.current ?? 1);
          },child:Text(s.t('refresh'))),
          if (['customer', 'vendor'].contains(widget.role))
            TextButton(
              onPressed: busy
                  ? null
                  : () {
                      if (!stillCurrent()) return;
                      Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => ChargingReviewsView(
                            api: originalApi,
                            role: originalRole,
                            onExpired: widget.onExpired,
                          ),
                        ),
                      );
                    },
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
              onPressed: busy ? null : () {
                if (stillCurrent()) load(page: p?.current ?? 1);
              },
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
                              : () {
                                if (stillCurrent()) reconcile(row['id'] as int);
                              },
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
                        onPressed: busy ? null : () {
                          if (stillCurrent()) cancelPending(row);
                        },
                        child: Text(s.t('cancelUndispatched')),
                      ),
                    if (row['status'] == 'stopped')
                      TextButton(
                        onPressed: busy
                            ? null
                            : () {
                                if (!stillCurrent()) return;
                                Navigator.push(
                                  context,
                                  MaterialPageRoute<void>(
                                    builder: (_) => InvoicePage(
                                      api: originalApi,
                                      sessionId: row['id'] as int,
                                      onExpired: widget.onExpired,
                                    ),
                                  ),
                                );
                              },
                        child: Text(s.t('invoice')),
                      ),
                    if (row['status'] == 'charging')
                      Wrap(
                        spacing: 8,
                        children: [
                          if (widget.role == 'vendor' &&
                              row['csms_linked'] != true)
                            FilledButton(
                              onPressed: busy ? null : () {
                                if (stillCurrent()) meter(row);
                              },
                              child: Text(s.t('submitMeter')),
                            ),
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () {
                                    if (stillCurrent()) stop(
                                      row['id'] as int,
                                      linked: row['csms_linked'] == true,
                                    );
                                  },
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
                      : () {
                          if (stillCurrent()) load(page: p.current - 1);
                        },
                  child: Text(s.t('previous')),
                ),
                TextButton(
                  onPressed: busy || p.current >= p.last
                      ? null
                      : () {
                          if (stillCurrent()) load(page: p.current + 1);
                        },
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
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.role != widget.role || !ownSession()) {
      generation++;
      data = null;
      error = null;
      busy = false;
      if (!identical(oldWidget.api,widget.api)) {
        unawaited(sessionSubscription?.cancel());
        ownSession = widget.api.sessionFence();
        subscribeSession();
      } else {
        // Preserve the existing explicit owner rebind on parent updates.
        // No rebind occurs in the revocation listener itself.
        ownSession = widget.api.sessionFence();
      }
      final api = widget.api;
      final fence = ownSession;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && foreground && identical(api, widget.api) &&
            identical(fence, ownSession) && fence()) {
          load();
        }
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    foreground = resumed;
    if (!resumed && mounted) {
      generation++;
      setState(() {
        data = null;
        error = null;
        busy = false;
      });
    } else if (mounted) {
      // A private charging history is never implicitly fetched on resume.
      setState(() {});
    }
  }
  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    super.dispose();
  }
}
