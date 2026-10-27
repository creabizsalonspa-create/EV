import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'action_form.dart';

class FleetView extends StatefulWidget {
  final EvApi api;
  final int accountId;
  final VoidCallback onExpired;
  const FleetView({
    super.key,
    required this.api,
    required this.accountId,
    required this.onExpired,
  });
  @override
  State<FleetView> createState() => _FleetViewState();
}

class _FleetViewState extends State<FleetView> {
  bool invitations = false, busy = false;
  Object? error;
  ApiPage<Map<String, dynamic>>? data;
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
        invitations ? 'fleet-invitations' : 'fleets',
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

  Future<void> create() async {
    setState(() => busy = true);
    try {
      final regions = await widget.api.request('regions') as List;
      if (!mounted) return;
      final result = await apiForm(
        context,
        title: 'createFleet',
        fields: [
          const InputSpec('name', 'fleetName'),
          InputSpec(
            'country',
            'country',
            choices: {
              for (final r in regions) '${r['country']}': '${r['country']}',
            },
          ),
        ],
        submit: (v) => widget.api.request('fleets', method: 'POST', body: v),
        onExpired: widget.onExpired,
      );
      if (result is Map && mounted) {
        setState(() {
          invitations = false;
          data = null;
        });
        await load();
        if (mounted) await open(result['id'] as int);
      }
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> open(int id) async {
    await Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => FleetPage(
          api: widget.api,
          accountId: widget.accountId,
          id: id,
          onExpired: widget.onExpired,
        ),
      ),
    );
    if (mounted) await load(page: data?.current ?? 1);
  }

  Future<void> accept(int id) async {
    if (!await confirmAction(context, 'acceptInvitation', 'fleetConsent') ||
        !mounted)
      return;
    setState(() => busy = true);
    try {
      await widget.api.request('fleet-invitations/$id/accept', method: 'POST');
      if (mounted) {
        setState(() {
          invitations = false;
          data = null;
        });
        await load();
      }
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    return RefreshIndicator(
      onRefresh: () => load(page: page?.current ?? 1),
      child: ListView(
        padding: const EdgeInsets.all(16),
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          Text(s.t('fleet'), style: Theme.of(context).textTheme.headlineLarge),
          Wrap(
            spacing: 8,
            children: [
              ChoiceChip(
                key: const Key('my-fleets'),
                label: Text(s.t('myFleets')),
                selected: !invitations,
                onSelected: busy
                    ? null
                    : (_) {
                        setState(() {
                          invitations = false;
                          data = null;
                        });
                        load();
                      },
              ),
              ChoiceChip(
                key: const Key('fleet-invitations'),
                label: Text(s.t('invitations')),
                selected: invitations,
                onSelected: busy
                    ? null
                    : (_) {
                        setState(() {
                          invitations = true;
                          data = null;
                        });
                        load();
                      },
              ),
              FilledButton.icon(
                onPressed: busy ? null : create,
                icon: const Icon(Icons.add),
                label: Text(s.t('createFleet')),
              ),
            ],
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) ...[
            Text(s.t(errorKey(error!))),
            TextButton(
              onPressed: busy ? null : () => load(page: page?.current ?? 1),
              child: Text(s.t('retry')),
            ),
          ],
          if (!busy && error == null && (page?.items.isEmpty ?? false))
            Padding(
              padding: const EdgeInsets.all(24),
              child: Text(s.t('empty')),
            ),
          for (final row in page?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      invitations
                          ? s.t('fleetNumber', {'id': row['fleet_id']})
                          : '${row['name']}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    if (invitations) ...[
                      Text(s.t('invited')),
                      FilledButton(
                        onPressed: busy ? null : () => accept(row['id'] as int),
                        child: Text(s.t('acceptInvitation')),
                      ),
                    ] else ...[
                      Text(
                        s.t(
                          row['owner_id'] == widget.accountId
                              ? 'fleetOwner'
                              : 'fleetMember',
                        ),
                      ),
                      TextButton(
                        onPressed: busy ? null : () => open(row['id'] as int),
                        child: Text(s.t('manageFleet')),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          if (page != null) ...[
            Text(
              s.t('page', {
                'page': page.current,
                'last': page.last,
                'total': page.total,
              }),
            ),
            Wrap(
              spacing: 8,
              children: [
                TextButton(
                  onPressed: busy || page.current <= 1
                      ? null
                      : () => load(page: page.current - 1),
                  child: Text(s.t('previous')),
                ),
                TextButton(
                  onPressed: busy || page.current >= page.last
                      ? null
                      : () => load(page: page.current + 1),
                  child: Text(s.t('next')),
                ),
              ],
            ),
          ],
          const SizedBox(height: 32),
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

class FleetPage extends StatefulWidget {
  final EvApi api;
  final int accountId, id;
  final VoidCallback onExpired;
  const FleetPage({
    super.key,
    required this.api,
    required this.accountId,
    required this.id,
    required this.onExpired,
  });
  @override
  State<FleetPage> createState() => _FleetPageState();
}

class _FleetPageState extends State<FleetPage> {
  Map<String, dynamic>? data;
  bool busy = false;
  Object? error;
  String? notice;
  bool get owner => data?['fleet']['owner_id'] == widget.accountId;
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

  Future<void> load() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request('fleets/${widget.id}');
      if (mounted)
        setState(() => data = Map<String, dynamic>.from(result as Map));
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> invite() async {
    final result = await apiForm(
      context,
      title: 'inviteMember',
      fields: [
        InputSpec(
          'email',
          'email',
          maxLength: 254,
          validate: (v, s) => RegExp(r'^\S+@\S+\.\S+$').hasMatch(v)
              ? null
              : s.t('invalidEmail'),
        ),
      ],
      submit: (v) => widget.api.request(
        'fleets/${widget.id}/invitations',
        method: 'POST',
        body: v,
      ),
      onExpired: widget.onExpired,
    );
    if (result != null && mounted) {
      setState(() => notice = 'invitationRecorded');
      await load();
    }
  }

  Future<void> center() async {
    final result = await apiForm(
      context,
      title: 'addCostCenter',
      fields: [
        InputSpec(
          'code',
          'costCenterCode',
          maxLength: 30,
          validate: (v, s) => RegExp(r'^[a-zA-Z0-9_-]+$').hasMatch(v)
              ? null
              : s.t('invalidCode'),
        ),
        const InputSpec('name', 'name', maxLength: 100),
      ],
      submit: (v) => widget.api.request(
        'fleets/${widget.id}/cost-centers',
        method: 'POST',
        body: v,
      ),
      onExpired: widget.onExpired,
    );
    if (result != null && mounted) await load();
  }

  Future<void> revoke(int id) async {
    if (!await confirmAction(context, 'revokeMember', 'revokeConsent') ||
        !mounted)
      return;
    setState(() => busy = true);
    try {
      await widget.api.request(
        'fleets/${widget.id}/members/$id',
        method: 'DELETE',
      );
      if (mounted) await load();
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> assign() async {
    final vehicle = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) =>
          _VehiclePicker(api: widget.api, onExpired: widget.onExpired),
    );
    if (vehicle == null || !mounted) return;
    final centers = data!['cost_centers'] as List;
    final result = await apiForm(
      context,
      title: 'assignVehicle',
      fields: [
        InputSpec(
          'cost_center_id',
          'costCenter',
          choices: {
            for (final c in centers)
              '${c['id']}': '${c['code']} · ${c['name']}',
          },
        ),
      ],
      submit: (v) => widget.api.request(
        'fleets/${widget.id}/vehicles',
        method: 'POST',
        body: {
          'vehicle_id': vehicle['id'],
          'cost_center_id': int.parse(v['cost_center_id']),
        },
      ),
      onExpired: widget.onExpired,
    );
    if (result != null && mounted) await load();
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.t('fleetNumber', {'id': widget.id}))),
      body: RefreshIndicator(
        onRefresh: load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            if (busy) const LinearProgressIndicator(),
            if (error != null) ...[
              Text(s.t(errorKey(error!))),
              TextButton(
                onPressed: busy ? null : load,
                child: Text(s.t('retry')),
              ),
            ],
            if (notice != null)
              Text(s.t(notice!), key: const Key('fleet-notice')),
            if (data != null) ...[
              Text(
                '${data!['fleet']['name']}',
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              Text(s.t(owner ? 'fleetOwner' : 'fleetMember')),
              if (owner)
                Wrap(
                  spacing: 8,
                  children: [
                    FilledButton(
                      onPressed: busy ? null : invite,
                      child: Text(s.t('inviteMember')),
                    ),
                    OutlinedButton(
                      onPressed: busy ? null : center,
                      child: Text(s.t('addCostCenter')),
                    ),
                  ],
                ),
              if (owner) ...[
                Text(
                  s.t('members'),
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                for (final m in data!['members'] as List)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s.t('memberNumber', {'id': m['user_id']})),
                          Text('${s.t(m['role'])} · ${s.t(m['status'])}'),
                          if (m['status'] != 'revoked')
                            TextButton(
                              onPressed: busy
                                  ? null
                                  : () => revoke(m['id'] as int),
                              child: Text(s.t('revokeMember')),
                            ),
                        ],
                      ),
                    ),
                  ),
              ],
              Text(
                s.t('costCenters'),
                style: Theme.of(context).textTheme.titleLarge,
              ),
              if ((data!['cost_centers'] as List).isEmpty)
                Text(s.t('noCostCenters')),
              for (final c in data!['cost_centers'] as List)
                ListTile(
                  title: Text('${c['name']}'),
                  subtitle: Text('${c['code']}'),
                ),
              Text(
                s.t('fleetVehicles'),
                style: Theme.of(context).textTheme.titleLarge,
              ),
              Text(s.t('ownVehicleOnly')),
              if ((data!['cost_centers'] as List).isNotEmpty)
                FilledButton.icon(
                  onPressed: busy ? null : assign,
                  icon: const Icon(Icons.directions_car),
                  label: Text(s.t('assignVehicle')),
                ),
              for (final v in data!['vehicles'] as List)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(
                      s.t('vehicleCenter', {
                        'vehicle': v['vehicle_id'],
                        'center': v['cost_center_id'],
                      }),
                    ),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _VehiclePicker extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const _VehiclePicker({required this.api, required this.onExpired});
  @override
  State<_VehiclePicker> createState() => _VehiclePickerState();
}

class _VehiclePickerState extends State<_VehiclePicker> {
  ApiPage<Map<String, dynamic>>? data;
  bool busy = false;
  Object? error;
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
      final r = await widget.api.request('vehicles', query: {'page': '$page'});
      if (mounted) setState(() => data = ApiPage.fromJson(r, (v) => v));
    } catch (e) {
      if (mounted && e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
      if (mounted) setState(() => error = e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), p = data;
    return AlertDialog(
      title: Text(s.t('chooseVehicle')),
      content: SizedBox(
        width: 420,
        height: 360,
        child: Column(
          children: [
            if (busy) const LinearProgressIndicator(),
            if (error != null) ...[
              Text(s.t(errorKey(error!))),
              TextButton(
                onPressed: busy ? null : () => load(page: p?.current ?? 1),
                child: Text(s.t('retry')),
              ),
            ],
            Expanded(
              child: ListView(
                children: [
                  for (final v in p?.items ?? <Map<String, dynamic>>[])
                    ListTile(
                      title: Text('${v['name']}'),
                      subtitle: Text('${v['connector_type']}'),
                      onTap: busy ? null : () => Navigator.pop(context, v),
                    ),
                  if (!busy && p?.items.isEmpty == true) Text(s.t('empty')),
                ],
              ),
            ),
            if (p != null)
              Wrap(
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
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(s.t('cancel')),
        ),
      ],
    );
  }
}
