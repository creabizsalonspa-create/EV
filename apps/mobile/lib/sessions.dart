import 'package:flutter/material.dart';
import 'api.dart';
import 'invoice.dart';
import 'strings.dart';
import 'action_form.dart';

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

class _SessionsViewState extends State<SessionsView> {
  ApiPage<Map<String, dynamic>>? data;
  bool busy = false;
  Object? error;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    load();
  }

  void failed(Object e) {
    if (!mounted) return;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
    if (mounted) setState(() => error = e);
  }

  Future<void> load({int page = 1}) async {
    final request = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'simulations',
        query: {'page': '$page'},
      );
      if (mounted && request == generation)
        setState(() => data = ApiPage.fromJson(result, (v) => v));
    } catch (e) {
      if (mounted && request == generation) failed(e);
    } finally {
      if (mounted && request == generation) setState(() => busy = false);
    }
  }

  Future<void> meter(Map<String, dynamic> row) async {
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
      submit: (v) => widget.api.request(
        'simulations/${row['id']}/meter',
        method: 'PUT',
        body: {'energy_wh': int.parse(v['energy_wh'])},
      ),
      onExpired: widget.onExpired,
    );
    if (mounted) await load(page: data?.current ?? 1);
    if (result == null) return;
  }

  Future<void> stop(int id, {bool linked = false}) async {
    if (!await confirmAction(
          context,
          linked ? 'stopCsms' : 'stopSimulation',
          linked ? 'stopCsmsConsent' : 'stopConsent',
        ) ||
        !mounted)
      return;
    setState(() => busy = true);
    try {
      await widget.api.request(
        linked ? 'charging-sessions/$id/csms-stop' : 'simulations/$id/stop',
        method: 'POST',
      );
      if (mounted) await load(page: data?.current ?? 1);
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> cancelPending(Map<String, dynamic> row) async {
    if (!await confirmAction(
          context,
          'cancelUndispatched',
          'cancelUndispatchedConsent',
        ) ||
        !mounted)
      return;
    setState(() => busy = true);
    try {
      await widget.api.request(
        'reservations/${row['reservation_id']}',
        method: 'DELETE',
      );
      if (mounted) await load(page: data?.current ?? 1);
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = data;
    return RefreshIndicator(
      onRefresh: () => load(page: p?.current ?? 1),
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          Text(
            s.t('sessions'),
            style: Theme.of(context).textTheme.headlineLarge,
          ),
          Text(s.t('meterDisclaimer')),
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
                      s.t('energy', {
                        'energy': row['energy_wh'],
                        'total': row['total_minor'] ?? s.t('pending'),
                        'currency': row['currency'],
                      }),
                    ),
                    if (row['csms_linked'] == true) Text(s.t('sourceControls')),
                    if (row['start_admission_blocked'] == true)
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
  void dispose() {
    generation++;
    super.dispose();
  }
}
