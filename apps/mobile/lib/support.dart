import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'fleet.dart';

/// Presentation-only: all values are text, never an authorization or charging
/// command. Unknown or malformed event metadata cannot crash the inbox.
String inboxNotificationTitle(Map<String, dynamic> row, EvStrings strings) {
  final data = row['metadata'];
  final metadata = data is Map ? data : const <String, Object>{};
  Object id(String key) {
    final value = metadata[key];
    return value is int && value > 0 ? value : '?';
  }

  switch (row['kind']) {
    case 'charging.fault.simulated':
      return strings.t('simulatedFaultAlert', {
        'station': id('station_id'),
      });
    case 'charging.fault.recovered.simulated':
      return strings.t('simulatedRecoveryAlert', {
        'station': id('station_id'),
      });
    case 'fleet.invitation':
      return strings.t('fleetInvitationAlert', {'fleet': id('fleet_id')});
    case 'fleet.invitation.accepted':
      return strings.t('fleetAcceptedAlert', {'fleet': id('fleet_id')});
    case 'fleet.membership.revoked':
      return strings.t('fleetRevokedAlert', {'fleet': id('fleet_id')});
    case 'vehicle.battery.simulated':
      final percent = metadata['battery_percent'];
      return strings.t('simulatedBatteryAlert', {
        'vehicle': id('vehicle_id'),
        'percent': percent is int && percent >= 0 && percent <= 100
            ? percent
            : '?',
      });
    case 'support.reply':
      return strings.t('supportReply');
    default:
      return strings.t('supportStatus');
  }
}


class SupportView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onSessionExpired;
  final int? accountId;
  const SupportView({
    super.key,
    required this.api,
    required this.onSessionExpired,
    this.accountId,
  });
  @override
  SupportViewState createState() => SupportViewState();
}

class SupportViewState extends State<SupportView> {
  bool notifications = false, unreadOnly = false, loading = false;
  String category = 'all';
  int? unreadCount, latestNotificationId;
  Object? error;
  ApiPage<Map<String, dynamic>>? data;
  int generation = 0;
  Future<void> refresh() => load(page: data?.current ?? 1);
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load({int page = 1}) async {
    final request = ++generation;
    final showingNotifications = notifications;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final response = await widget.api.request(
        notifications ? 'notifications' : 'support',
        query: {
          'page': '$page',
          if (showingNotifications && unreadOnly) 'unread_only': '1',
          if (showingNotifications && category != 'all') 'category': category,
        },
      );
      final result = ApiPage.fromJson(response, (row) => row);
      final summary = showingNotifications
          ? await widget.api.request('notifications/summary')
          : null;
      if (mounted && request == generation) {
        setState(() {
          data = result;
          unreadCount = summary is Map && summary['unread_count'] is int
              ? summary['unread_count'] as int
              : null;
          latestNotificationId = summary is Map && summary['latest_id'] is int
              ? summary['latest_id'] as int
              : null;
        });
      }
    } catch (e) {
      if (mounted &&
          request == generation &&
          e is ApiException &&
          [401, 419].contains(e.status))
        widget.onSessionExpired();
      if (mounted && request == generation) setState(() => error = e);
    } finally {
      if (mounted && request == generation) setState(() => loading = false);
    }
  }

  Future<void> markCurrentRead() async {
    final throughId = latestNotificationId;
    if (!notifications || loading || throughId == null || throughId <= 0) return;
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      await widget.api.request(
        'notifications/read-through',
        method: 'POST',
        body: {'through_id': throughId},
      );
      if (mounted && request == generation) await load(page: 1);
    } catch (e) {
      if (mounted && request == generation) {
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onSessionExpired();
        }
        setState(() {
          error = e;
          loading = false;
        });
      }
    }
  }

  Future<void> open(int id) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => TicketPage(
          api: widget.api,
          id: id,
          onSessionExpired: widget.onSessionExpired,
        ),
      ),
    );
    if (mounted) await refresh();
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    return RefreshIndicator(
      onRefresh: refresh,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            s.t('support'),
            style: Theme.of(context).textTheme.headlineLarge,
          ),
          Wrap(
            spacing: 8,
            children: [
              ChoiceChip(
                key: const Key('support-tickets'),
                label: Text(s.t('messages')),
                selected: !notifications,
                onSelected: loading
                    ? null
                    : (_) {
                        setState(() {
                          notifications = false;
                          data = null;
                        });
                        load();
                      },
              ),
              if (notifications)
                DropdownButton<String>(
                  key: const Key('support-category'),
                  value: category,
                  items: const ['all', 'support', 'vehicle', 'charging', 'fleet']
                      .map(
                        (value) => DropdownMenuItem<String>(
                          value: value,
                          child: Text(
                            s.t(
                              value == 'all'
                                  ? 'notificationCategoryAll'
                                  : value == 'support'
                                  ? 'notificationCategorySupport'
                                  : value == 'vehicle'
                                  ? 'notificationCategoryVehicle'
                                  : value == 'charging'
                                  ? 'notificationCategoryCharging'
                                  : 'notificationCategoryFleet',
                            ),
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: loading
                      ? null
                      : (value) {
                          if (value == null) return;
                          setState(() {
                            category = value;
                            data = null;
                          });
                          load();
                        },
                ),
              if (notifications)
                FilterChip(
                  key: const Key('support-unread-only'),
                  label: Text(s.t('unreadOnly')),
                  selected: unreadOnly,
                  onSelected: loading
                      ? null
                      : (value) {
                          setState(() {
                            unreadOnly = value;
                            data = null;
                          });
                          load();
                        },
                ),
              ChoiceChip(
                key: const Key('support-notifications'),
                label: Text(s.t('notifications')),
                selected: notifications,
                onSelected: loading
                    ? null
                    : (_) {
                        setState(() {
                          notifications = true;
                          data = null;
                        });
                        load();
                      },
              ),
            ],
          ),
          if (notifications && unreadCount != null)
            Text(
              s.t('unreadNotificationCount', {'count': unreadCount!}),
              key: const Key('notification-unread-count'),
            ),
          if (notifications &&
              (unreadCount ?? 0) > 0 &&
              (latestNotificationId ?? 0) > 0)
            TextButton(
              key: const Key('mark-current-notifications-read'),
              onPressed: loading ? null : markCurrentRead,
              child: Text(s.t('markCurrentNotificationsRead')),
            ),
          if (loading) const LinearProgressIndicator(),
          if (error != null)
            Column(
              children: [
                Text(s.t(errorKey(error!))),
                TextButton(
                  onPressed: loading ? null : refresh,
                  child: Text(s.t('retry')),
                ),
              ],
            ),
          if (!loading && error == null && (page?.items.isEmpty ?? false))
            Padding(
              padding: const EdgeInsets.all(24),
              child: Text(s.t(notifications ? 'noNotifications' : 'empty')),
            ),
          for (final row in page?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (notifications) ...[
                      Text(
                        inboxNotificationTitle(row, s),
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(s.t(row['read_at'] == null ? 'unread' : 'read')),
                      Wrap(
                        spacing: 8,
                        children: [
                          if (row['kind'] == 'fleet.invitation' && widget.accountId != null)
                            TextButton(
                              key: const Key('review-fleet-invitation'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => Scaffold(
                                            appBar: AppBar(
                                              title: Text(s.t('fleet')),
                                            ),
                                            body: FleetView(
                                              api: widget.api,
                                              accountId: widget.accountId!,
                                              onExpired: widget.onSessionExpired,
                                              initialInvitations: true,
                                            ),
                                          ),
                                        ),
                                      );
                                      if (mounted) await refresh();
                                    },
                              child: Text(s.t('reviewFleetInvitation')),
                            ),
                          if (row['support_ticket_id'] != null)
                            TextButton(
                              onPressed: loading
                                  ? null
                                  : () => open(row['support_ticket_id'] as int),
                              child: Text(
                                s.t('ticket', {'id': row['support_ticket_id']}),
                              ),
                            ),
                          if (row['read_at'] == null)
                            TextButton(
                              onPressed: loading
                                  ? null
                                  : () async {
                                      setState(() => loading = true);
                                      try {
                                        await widget.api.request(
                                          'notifications/${row['id']}/read',
                                          method: 'POST',
                                        );
                                        await refresh();
                                      } catch (e) {
                                        if (e is ApiException &&
                                            [401, 419].contains(e.status))
                                          widget.onSessionExpired();
                                        if (mounted)
                                          setState(() {
                                            error = e;
                                            loading = false;
                                          });
                                      }
                                    },
                              child: Text(s.t('markRead')),
                            ),
                        ],
                      ),
                    ] else ...[
                      Text(
                        '${row['subject']}',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(s.t(row['status'] as String)),
                      TextButton(
                        onPressed: loading
                            ? null
                            : () => open(row['id'] as int),
                        child: Text(s.t('ticket', {'id': row['id']})),
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
                OutlinedButton(
                  onPressed: loading || page.current <= 1
                      ? null
                      : () => load(page: page.current - 1),
                  child: Text(s.t('previous')),
                ),
                OutlinedButton(
                  onPressed: loading || page.current >= page.last
                      ? null
                      : () => load(page: page.current + 1),
                  child: Text(s.t('next')),
                ),
              ],
            ),
          ],
          const SizedBox(height: 96),
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

class TicketPage extends StatefulWidget {
  final EvApi api;
  final int id;
  final VoidCallback onSessionExpired;
  const TicketPage({
    super.key,
    required this.api,
    required this.id,
    required this.onSessionExpired,
  });
  @override
  State<TicketPage> createState() => _TicketPageState();
}

class _TicketPageState extends State<TicketPage> {
  Map<String, dynamic>? ticket;
  ApiPage<Map<String, dynamic>>? messages;
  bool loading = false;
  Object? error;
  final reply = TextEditingController();
  final form = GlobalKey<FormState>();
  @override
  void initState() {
    super.initState();
    load();
  }

  void failed(Object e) {
    if (mounted && e is ApiException && [401, 419].contains(e.status))
      widget.onSessionExpired();
    if (mounted) setState(() => error = e);
  }

  Future<void> load({int page = 1}) async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final response = await widget.api.request(
        'support/${widget.id}',
        query: {'page': '$page'},
      );
      final list = ApiPage.fromJson(response['messages'], (row) => row);
      if (mounted)
        setState(() {
          ticket = Map<String, dynamic>.from(response['ticket'] as Map);
          messages = list;
        });
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> send() async {
    if (!form.currentState!.validate() || loading) return;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      await widget.api.request(
        'support/${widget.id}/messages',
        method: 'POST',
        body: {'body': reply.text.trim()},
      );
      if (!mounted) return;
      reply.clear();
      await load(page: messages?.last ?? 1);
      if (mounted && messages != null && messages!.current < messages!.last)
        await load(page: messages!.last);
    } catch (e) {
      failed(e);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    final page = messages;
    return Scaffold(
      appBar: AppBar(title: Text(s.t('ticket', {'id': widget.id}))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (loading) const LinearProgressIndicator(),
            if (error != null)
              Column(
                children: [
                  Text(s.t(errorKey(error!))),
                  TextButton(
                    onPressed: loading
                        ? null
                        : () => load(page: page?.current ?? 1),
                    child: Text(s.t('retry')),
                  ),
                ],
              ),
            if (ticket != null) ...[
              Text(
                '${ticket!['subject']}',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              Text(s.t(ticket!['status'])),
              for (final row in page?.items ?? <Map<String, dynamic>>[])
                if (row['internal'] != true)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${row['body']}'),
                          Text(
                            '${row['created_at'] ?? ''}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ),
              if (page != null)
                Wrap(
                  spacing: 8,
                  children: [
                    Text(
                      s.t('page', {
                        'page': page.current,
                        'last': page.last,
                        'total': page.total,
                      }),
                    ),
                    TextButton(
                      onPressed: loading || page.current <= 1
                          ? null
                          : () => load(page: page.current - 1),
                      child: Text(s.t('previous')),
                    ),
                    TextButton(
                      onPressed: loading || page.current >= page.last
                          ? null
                          : () => load(page: page.current + 1),
                      child: Text(s.t('next')),
                    ),
                  ],
                ),
              if (ticket!['status'] == 'closed')
                Text(s.t('closedTicket'))
              else
                Form(
                  key: form,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      TextFormField(
                        key: const Key('ticket-reply'),
                        controller: reply,
                        enabled: !loading,
                        maxLength: 5000,
                        minLines: 3,
                        maxLines: 6,
                        decoration: InputDecoration(labelText: s.t('reply')),
                        validator: (v) =>
                            v!.trim().isEmpty ? s.t('required') : null,
                      ),
                      FilledButton(
                        onPressed: loading ? null : send,
                        child: Text(s.t('sendReply')),
                      ),
                    ],
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }

  @override
  void dispose() {
    reply.dispose();
    super.dispose();
  }
}
