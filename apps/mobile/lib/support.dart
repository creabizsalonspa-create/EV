import 'dart:math';
import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'fleet.dart';
import 'charger_diagnostics.dart';
import 'charger_repairs.dart';
import 'charging_operations.dart';

String newSupportRequestKey() {
  final random = Random.secure();
  return List.generate(16,
    (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
  ).join();
}

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
    case 'charging.fault.reminder.simulated':
      return strings.t('simulatedFaultReminder', {
        'station': id('station_id'),
      });
    case 'charging.repair.opened.simulated':
      return strings.t('simulatedRepairOpened', {
        'station': id('station_id'),
      });
    case 'charging.repair.work_started.simulated':
      return strings.t('simulatedRepairStarted', {
        'station': id('station_id'),
      });
    case 'charging.repair.reopened.simulated':
      return strings.t('simulatedRepairReopened', {
        'station': id('station_id'),
      });
    case 'charging.repair.review_requested.simulated':
      return strings.t('simulatedRepairReviewRequested', {
        'station': id('station_id'),
      });
    case 'charging.repair.review_closed.simulated':
      return strings.t('simulatedRepairReviewClosed', {
        'station': id('station_id'),
      });
    case 'charging.monitor.requires_review.simulated':
      return strings.t('simulatedMonitorReview', {'station': id('station_id')});
    case 'charging.monitor.resumed.simulated':
      return strings.t('simulatedMonitorCaughtUp', {'station': id('station_id')});
    case 'charging.operational_case.opened':
      return strings.t('chargingOperationalCaseOpened', {'station': id('station_id')});
    case 'charging.operational_case.source_observed':
      return strings.t('chargingOperationalSourceObserved', {'station': id('station_id')});
    case 'charging.operational_case.review_resolved':
      return strings.t('chargingOperationalReviewClosed', {'station': id('station_id')});
    case 'fleet.invitation':
      return strings.t('fleetInvitationAlert', {'fleet': id('fleet_id')});
    case 'fleet.invitation.accepted':
      return strings.t('fleetAcceptedAlert', {'fleet': id('fleet_id')});
    case 'fleet.membership.revoked':
      return strings.t('fleetRevokedAlert', {'fleet': id('fleet_id')});
    case 'fleet.invitation.withdrawn':
      return strings.t('fleetInvitationWithdrawnAlert', {'fleet': id('fleet_id')});
    case 'fleet.vehicle.assigned':
      return strings.t('fleetVehicleAssignedAlert', {'fleet': id('fleet_id')});
    case 'fleet.vehicle.cost_center_changed':
      return strings.t('fleetCostCenterChangedAlert', {'fleet': id('fleet_id')});
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
    case 'support.status.open':
    case 'support.status.in_progress':
    case 'support.status.resolved':
    case 'support.status.closed':
      return strings.t('supportStatus');
    default:
      // Unknown operational event kinds must not masquerade as support tickets.
      return strings.t('notificationOtherTitle');
  }
}


/// Navigate to a repair-order review without conflating source evidence with
/// fault acknowledgment or physical repair. The vendor API rechecks access.
Map<String, int>? simulatedRepairTarget(Map<String, dynamic> row) {
  if (!const {
    'charging.repair.opened.simulated',
    'charging.repair.work_started.simulated',
    'charging.repair.reopened.simulated',
    'charging.repair.review_requested.simulated',
    'charging.repair.review_closed.simulated',
  }.contains(row['kind'])) {
    return null;
  }
  final metadata = row['metadata'];
  if (metadata is! Map ||
      metadata['source'] != 'simulator' ||
      metadata['physical_verification'] != false ||
      metadata['review_only'] != true) {
    return null;
  }
  const fields = ['company_id', 'charger_id', 'alert_id', 'repair_order_id'];
  if (!fields.every((key) => metadata[key] is int && metadata[key] > 0)) {
    return null;
  }
  return {for (final key in fields) key: metadata[key] as int};
}

/// Navigate only unambiguous, simulator-scoped owner notifications. Backend
/// VendorAccess still re-authorizes the selected charger on every request.
Map<String, int>? simulatedFaultTarget(Map<String, dynamic> row) {
  if (row['kind'] != 'charging.fault.simulated' &&
      row['kind'] != 'charging.fault.recovered.simulated' &&
      row['kind'] != 'charging.fault.reminder.simulated') {
    return null;
  }
  final details = row['metadata'];
  if (details is! Map ||
      details['source'] != 'simulator' ||
      details['physical_verification'] != false) {
    return null;
  }
  final company = details['company_id'];
  final charger = details['charger_id'];
  final alert = details['alert_id'];
  if (company is! int || company <= 0 ||
      charger is! int || charger <= 0 ||
      alert is! int || alert <= 0) {
    return null;
  }
  return {'company_id': company, 'charger_id': charger, 'alert_id': alert};
}

/// Strictly separate monitor navigation from fault acknowledgment navigation.
Map<String, int>? simulatedMonitorTarget(Map<String, dynamic> row) {
  if (row['kind'] != 'charging.monitor.requires_review.simulated' &&
      row['kind'] != 'charging.monitor.resumed.simulated') {
    return null;
  }
  final meta = row['metadata'];
  if (meta is! Map ||
      meta['source'] != 'simulator' ||
      meta['physical_verification'] != false) {
    return null;
  }
  final company = meta['company_id'];
  final charger = meta['charger_id'];
  if (company is! int || company <= 0 ||
      charger is! int || charger <= 0) {
    return null;
  }
  return {'company_id': company, 'charger_id': charger};
}

/// Owner-authorized operational review target. Never treats source evidence as
/// physical hardware acceptance or a charging command.
int? operationalCaseTarget(Map<String, dynamic> notice) {
  if (notice['kind'] != 'charging.operational_case.opened' &&
      notice['kind'] != 'charging.operational_case.source_observed' &&
      notice['kind'] != 'charging.operational_case.review_resolved') {
    return null;
  }
  final metadata = notice['metadata'];
  if (metadata is! Map || metadata['physical_verification'] != false) {
    return null;
  }
  final id = metadata['command_id'];
  final caseId = metadata['case_id'];
  return id is int && id > 0 && caseId is int && caseId > 0 ? id : null;
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
  bool preferencesLoaded = false;
  String category = 'all';
  Map<String, dynamic>? savedPreferences;
  int? unreadCount, latestNotificationId;
  Map<String, int>? unreadByCategory;
  String? pendingTicketSubject, pendingTicketBody, pendingTicketKey;
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
      if (showingNotifications && !preferencesLoaded) {
        final prefs = await widget.api.request('notifications/preferences');
        if (!mounted || request != generation) return;
        if (prefs is! Map ||
            !['all', 'support', 'vehicle', 'charging', 'fleet', 'other']
                .contains(prefs['default_category']) ||
            prefs['default_unread_only'] is! bool ||
            prefs['remind_unresolved_simulator_faults'] is! bool ||
            prefs['in_app_only'] != true ||
            prefs['external_delivery_available'] != false ||
            prefs['critical_events_always_visible'] != true) {
          throw const FormatException('Invalid notification preferences');
        }
        category = prefs['default_category'] as String;
        unreadOnly = prefs['default_unread_only'] as bool;
        savedPreferences = Map<String, dynamic>.from(prefs);
        preferencesLoaded = true;
      }
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
          final counts = summary is Map ? summary['unread_by_category'] : null;
          unreadByCategory = counts is Map &&
                  ['support', 'vehicle', 'charging', 'fleet', 'other']
                      .every((name) => counts[name] is int && counts[name] >= 0)
              ? {
                  for (final name in ['support', 'vehicle', 'charging', 'fleet', 'other'])
                    name: counts[name] as int,
                }
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

  Future<void> createTicket() async {
    if (loading || notifications) return;
    final strings = EvStrings.of(context);
    final subjectInput = TextEditingController(text: pendingTicketSubject);
    final bodyInput = TextEditingController(text: pendingTicketBody);
    final fields = GlobalKey<FormState>();
    final desired = await showDialog<Map<String, String>>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(strings.t('openTicket')),
        content: SingleChildScrollView(
          child: Form(
            key: fields,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  key: const Key('support-new-subject'),
                  controller: subjectInput,
                  maxLength: 150,
                  decoration: InputDecoration(labelText: strings.t('subject')),
                  validator: (value) => value == null || value.trim().isEmpty
                      ? strings.t('required') : null,
                ),
                TextFormField(
                  key: const Key('support-new-body'),
                  controller: bodyInput,
                  maxLength: 5000,
                  minLines: 3,
                  maxLines: 5,
                  decoration: InputDecoration(labelText: strings.t('body')),
                  validator: (value) => value == null || value.trim().isEmpty
                      ? strings.t('required') : null,
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: Text(strings.t('cancel')),
          ),
          FilledButton(
            key: const Key('support-create-confirm'),
            onPressed: () {
              if (!fields.currentState!.validate()) return;
              Navigator.of(dialogContext).pop({
                'subject': subjectInput.text.trim(),
                'body': bodyInput.text.trim(),
              });
            },
            child: Text(strings.t('openTicket')),
          ),
        ],
      ),
    );
    subjectInput.dispose();
    bodyInput.dispose();
    if (!mounted || desired == null) return;
    if (pendingTicketKey == null ||
        pendingTicketSubject != desired['subject'] ||
        pendingTicketBody != desired['body']) {
      pendingTicketKey = newSupportRequestKey();
      pendingTicketSubject = desired['subject'];
      pendingTicketBody = desired['body'];
    }
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final accepted = await widget.api.request(
        'support',
        method: 'POST',
        body: {...desired, 'idempotency_key': pendingTicketKey},
      );
      if (!mounted || request != generation) return;
      if (accepted is! Map ||
          accepted['id'] is! int ||
          (accepted['id'] as int) <= 0) {
        throw const FormatException('Support ticket creation not confirmed');
      }
      pendingTicketSubject = null;
      pendingTicketBody = null;
      pendingTicketKey = null;
      await load(page: 1);
    } catch (errorValue) {
      if (!mounted || request != generation) return;
      if (errorValue is ApiException && [401, 419].contains(errorValue.status)) {
        widget.onSessionExpired();
      }
      setState(() {
        error = errorValue;
        loading = false;
      });
    }
  }

  Future<void> editNotificationPreferences() async {
    final current = savedPreferences;
    if (!notifications || loading || current == null) return;
    var selected = current['default_category'] as String;
    var unread = current['default_unread_only'] as bool;
    var repeat = current['remind_unresolved_simulator_faults'] as bool;
    var delay = current['fault_reminder_delay_minutes'] is int
        ? current['fault_reminder_delay_minutes'] as int : 15;
    if (![15, 30, 60].contains(delay)) {
      delay = 15;
    }
    final s = EvStrings.of(context);

    final desired = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, updateDialog) => AlertDialog(
          title: Text(s.t('notificationPreferencesTitle')),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(s.t('notificationDefaultCategory')),
                DropdownButton<String>(
                  key: const Key('notification-preference-category'),
                  value: selected,
                  isExpanded: true,
                  items: const ['all', 'support', 'vehicle', 'charging', 'fleet']
                      .map((choice) => DropdownMenuItem<String>(
                            value: choice,
                            child: Text(s.t(
                              choice == 'all'
                                  ? 'notificationCategoryAll'
                                  : choice == 'support'
                                      ? 'notificationCategorySupport'
                                      : choice == 'vehicle'
                                          ? 'notificationCategoryVehicle'
                                          : choice == 'charging'
                                              ? 'notificationCategoryCharging'
                                              : choice == 'fleet'
                                          ? 'notificationCategoryFleet'
                                          : 'notificationCategoryOther',
                            )),
                          ))
                      .toList(),
                  onChanged: (choice) {
                    if (choice != null) updateDialog(() => selected = choice);
                  },
                ),
                CheckboxListTile(
                  key: const Key('notification-preference-unread'),
                  value: unread,
                  onChanged: (value) => updateDialog(() => unread = value ?? false),
                  title: Text(s.t('notificationDefaultUnread')),
                ),
                CheckboxListTile(
                  key: const Key('notification-preference-repeat'),
                  value: repeat,
                  onChanged: (value) => updateDialog(() => repeat = value ?? false),
                  title: Text(s.t('notificationRepeatSimulatorReminders')),
                ),
                Text(s.t('notificationReminderDelayTitle')),
                DropdownButton<int>(
                  key: const Key('notification-preference-delay'),
                  value: delay,
                  isExpanded: true,
                  items: const [15, 30, 60].map((minutes) =>
                    DropdownMenuItem<int>(
                      value: minutes,
                      child: Text(s.t('notificationReminderDelayChoice', {
                        'minutes': minutes,
                      })),
                    ),
                  ).toList(),
                  onChanged: (choice) {
                    if (choice != null) updateDialog(() => delay = choice);
                  },
                ),
                Text(s.t('notificationDeliveryUnavailable')),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: Text(s.t('cancel')),
            ),
            FilledButton(
              key: const Key('notification-preferences-confirm'),
              onPressed: () => Navigator.of(dialogContext).pop({
                'default_category': selected,
                'default_unread_only': unread,
                'remind_unresolved_simulator_faults': repeat,
                'fault_reminder_delay_minutes': delay,
              }),
              child: Text(s.t('save')),
            ),
          ],
        ),
      ),
    );
    if (!mounted || desired == null) return;
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      // Absolute desired-state PUT is idempotent on a lost acknowledgement;
      // never assume persistence until the authoritative response arrives.
      final accepted = await widget.api.request(
        'notifications/preferences',
        method: 'PUT',
        body: desired,
      );
      if (!mounted || request != generation) return;
      if (accepted is! Map ||
          accepted['default_category'] != desired['default_category'] ||
          accepted['default_unread_only'] != desired['default_unread_only'] ||
          accepted['remind_unresolved_simulator_faults'] !=
              desired['remind_unresolved_simulator_faults'] ||
          accepted['fault_reminder_delay_minutes'] != desired['fault_reminder_delay_minutes'] ||
          accepted['external_delivery_available'] != false) {
        throw const FormatException('Preference update not confirmed');
      }
      setState(() {
        savedPreferences = Map<String, dynamic>.from(accepted);
        category = accepted['default_category'] as String;
        unreadOnly = accepted['default_unread_only'] as bool;
        preferencesLoaded = true;
      });
      await load(page: 1);
    } catch (e) {
      if (!mounted || request != generation) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        widget.onSessionExpired();
      }
      setState(() {
        error = e;
        loading = false;
      });
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

  Future<void> markNotification(int id, {required bool read}) async {
    if (!notifications || loading || id <= 0) return;
    final request = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      await widget.api.request(
        'notifications/$id/${read ? 'read' : 'unread'}',
        method: 'POST',
      );
      if (mounted && request == generation) await load(page: data?.current ?? 1);
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
              if (!notifications)
                OutlinedButton.icon(
                  key: const Key('support-new-ticket'),
                  onPressed: loading ? null : createTicket,
                  icon: const Icon(Icons.add_comment_outlined),
                  label: Text(s.t('openTicket')),
                ),
              if (notifications)
                DropdownButton<String>(
                  key: const Key('support-category'),
                  value: category,
                  items: const ['all', 'support', 'vehicle', 'charging', 'fleet', 'other']
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
                                  : value == 'fleet'
                                      ? 'notificationCategoryFleet'
                                      : 'notificationCategoryOther',
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
          if (notifications && preferencesLoaded)
            TextButton.icon(
              key: const Key('notification-preferences-open'),
              onPressed: loading ? null : editNotificationPreferences,
              icon: const Icon(Icons.tune),
              label: Text(s.t('notificationPreferencesTitle')),
            ),
          if (notifications && preferencesLoaded)
            Text(s.t('notificationDeliveryUnavailable')),
          if (notifications && unreadCount != null)
            Text(
              s.t('unreadNotificationCount', {'count': unreadCount!}),
              key: const Key('notification-unread-count'),
            ),
          if (notifications && unreadByCategory != null)
            Text(
              s.t('notificationCategoryCounts', unreadByCategory!),
              key: const Key('notification-category-counts'),
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
                          if (operationalCaseTarget(row) != null)
                            TextButton(
                              key: const Key('review-operational-case'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      final commandId = operationalCaseTarget(row);
                                      if (commandId == null) return;
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => ChargingOperationsView(
                                            api: widget.api,
                                            onExpired: widget.onSessionExpired,
                                            focusCommandId: commandId,
                                          ),
                                        ),
                                      );
                                      if (mounted) await refresh();
                                    },
                              child: Text(s.t('reviewOperationalCase')),
                            ),
                          if (simulatedMonitorTarget(row) != null)
                            TextButton(
                              key: const Key('review-simulator-monitor'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      final target = simulatedMonitorTarget(row);
                                      if (target == null) return;
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => ChargerDiagnosticsView(
                                            api: widget.api,
                                            companyId: target['company_id']!,
                                            chargerId: target['charger_id']!,
                                            onExpired: widget.onSessionExpired,
                                          ),
                                        ),
                                      );
                                      if (mounted) await refresh();
                                    },
                              child: Text(s.t('reviewSimulatorMonitor')),
                            ),
                          if (simulatedRepairTarget(row) != null)
                            TextButton(
                              key: const Key('review-simulated-repair'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      final target = simulatedRepairTarget(row);
                                      if (target == null) return;
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => ChargerRepairsView(
                                            api: widget.api,
                                            companyId: target['company_id']!,
                                            chargerId: target['charger_id']!,
                                            initialAlertId: target['alert_id'],
                                            onExpired: widget.onSessionExpired,
                                          ),
                                        ),
                                      );
                                      if (mounted) await refresh();
                                    },
                              child: Text(s.t('reviewSimulatedRepair')),
                            ),
                          if (simulatedFaultTarget(row) != null)
                            TextButton(
                              key: const Key('review-simulated-fault'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      final target = simulatedFaultTarget(row);
                                      if (target == null) return;
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => ChargerDiagnosticsView(
                                            api: widget.api,
                                            companyId: target['company_id']!,
                                            chargerId: target['charger_id']!,
                                            onExpired: widget.onSessionExpired,
                                            openFaultAlerts: true,
                                          ),
                                        ),
                                      );
                                      if (mounted) await refresh();
                                    },
                              child: Text(s.t('reviewSimulatedFault')),
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
                          if (row['id'] is int)
                            TextButton(
                              onPressed: loading
                                  ? null
                                  : () => markNotification(
                                      row['id'] as int,
                                      read: row['read_at'] == null,
                                    ),
                              child: Text(s.t(
                                row['read_at'] == null ? 'markRead' : 'markUnread',
                              )),
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
  String? pendingReplyBody;
  String? pendingReplyKey;

  // Reply and ticket-create retries share the same secure key generator.
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
      final text = reply.text.trim();
      if (pendingReplyKey == null || pendingReplyBody != text) {
        pendingReplyBody = text;
        pendingReplyKey = newSupportRequestKey();
      }
      await widget.api.request(
        'support/${widget.id}/messages',
        method: 'POST',
        body: {'body': text, 'idempotency_key': pendingReplyKey},
      );
      pendingReplyBody = null;
      pendingReplyKey = null;
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
