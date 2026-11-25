import 'notification_email.dart';
import 'provider_disputes.dart';
import 'dart:math';
import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'reservation_times.dart';
import 'fleet.dart';
import 'charger_diagnostics.dart';
import 'charger_repairs.dart';
import 'charging_operations.dart';

/// Server UTC, independent of device and station timezone.
String supportMessageTime(BuildContext context, dynamic raw) {
  final formatted=reservationTime(context,{'created_at':raw},'created_at');
  if(formatted!='—')return formatted;
  return EvStrings.of(context).arabic?'وقت الرسالة غير متاح':'Message time unavailable';
}

/// A positive ID alone does not acknowledge the exact public reply.
bool supportReplyReceiptMatches(dynamic row, int ticketId, String body,
    {int? accountId}) {
  if (row is! Map || row['id'] is! int || row['id'] <= 0 ||
      row['support_ticket_id'] is! int || row['support_ticket_id'] != ticketId ||
      row['body'] != body || row['author_id'] is! int ||
      row['author_id'] <= 0 ||
      (accountId != null && row['author_id'] != accountId)) return false;
  final visibility = row['internal'];
  return visibility == false || visibility == 0 || visibility == '0';
}

String newSupportRequestKey() {
  final random = Random.secure();
  return List.generate(16,
    (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
  ).join();
}

/// Presentation-only: all values are text, never an authorization or charging
/// command. Unknown or malformed event metadata cannot crash the inbox.
int? providerDisputeNotificationTarget(Map<String, dynamic> row) {
  final metadata = row['metadata'];
  if (row['kind'] != 'payment.provider_dispute.test' || metadata is! Map ||
      metadata['evidence'] != 'STRIPE_TEST_CONTRACT' || metadata['review_only'] != true ||
      metadata['real_money_moved'] != false || metadata['physical_verification'] != false ||
      metadata['invoice_id'] is! int || metadata['invoice_id'] <= 0 ||
      metadata['external_dispute_id'] is! int || metadata['external_dispute_id'] <= 0) return null;
  return metadata['external_dispute_id'] as int;
}

/// A notification is not an authority to discover another user's vehicle.
/// Only simulator-sourced, finite percentage observations can open this route;
/// the destination rechecks the current signed-in garage owner via the API.
int? batteryDiscoveryNotificationTarget(Map<String, dynamic> row) {
  if (row['kind'] != 'vehicle.battery.simulated') return null;
  final metadata = row['metadata'];
  if (metadata is! Map || metadata['source'] != 'simulator') return null;
  final vehicle = metadata['vehicle_id'], percent = metadata['battery_percent'];
  return vehicle is int && vehicle > 0 &&
          percent is int && percent >= 0 && percent <= 100
      ? vehicle
      : null;
}

String inboxNotificationTitle(Map<String, dynamic> row, EvStrings strings) {
  final data = row['metadata'];
  final metadata = data is Map ? data : const <String, Object>{};
  Object id(String key) {
    final value = metadata[key];
    return value is int && value > 0 ? value : '?';
  }

  switch (row['kind']) {
    case 'account.security.enabled':
      return strings.t('accountSecurity_enabled');
    case 'account.security.disabled':
      return strings.t('accountSecurity_disabled');
    case 'account.security.recovery_rotated':
      return strings.t('accountSecurity_recovery_rotated');
    case 'account.security.recovery_used':
      return strings.t('accountSecurity_recovery_used');
    case 'payment.provider_dispute.test':
      return providerDisputeNotificationTarget(row) == null ? strings.t('notificationOtherTitle')
        : strings.t('providerDisputeNotice', {'invoice': id('invoice_id')});

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
    case 'charging.fault.escalated.simulated':
      return strings.t('simulatedFaultAdminEscalation', {
        'station': id('station_id'),
      });
    case 'charging.fault.escalation_closed.simulated':
      return strings.t(
        row['metadata'] is Map &&
          (row['metadata'] as Map)['resolution_source'] == 'source_recovered'
            ? 'simulatedFaultAdminSourceResolved'
            : 'simulatedFaultAdminAcknowledged',
        {'station': id('station_id')},
      );
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
      row['kind'] != 'charging.fault.reminder.simulated' &&
      row['kind'] != 'charging.fault.escalated.simulated' &&
      row['kind'] != 'charging.fault.escalation_closed.simulated') {
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

class _NewSupportTicketDialog extends StatefulWidget {
  final String? initialSubject;
  final String? initialBody;

  const _NewSupportTicketDialog({this.initialSubject, this.initialBody});

  @override
  State<_NewSupportTicketDialog> createState() => _NewSupportTicketDialogState();
}

class _NewSupportTicketDialogState extends State<_NewSupportTicketDialog> {
  final fields = GlobalKey<FormState>();
  late final TextEditingController subjectInput;
  late final TextEditingController bodyInput;

  @override
  void initState() {
    super.initState();
    subjectInput = TextEditingController(text: widget.initialSubject);
    bodyInput = TextEditingController(text: widget.initialBody);
  }

  @override
  void dispose() {
    subjectInput.dispose();
    bodyInput.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final strings = EvStrings.of(context);
    return AlertDialog(
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
          onPressed: () => Navigator.of(context).pop(),
          child: Text(strings.t('cancel')),
        ),
        FilledButton(
          key: const Key('support-create-confirm'),
          onPressed: () {
            if (!fields.currentState!.validate()) return;
            Navigator.of(context).pop({
              'subject': subjectInput.text.trim(),
              'body': bodyInput.text.trim(),
            });
          },
          child: Text(strings.t('openTicket')),
        ),
      ],
    );
  }
}

class SupportView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onSessionExpired;
  final Future<void> Function(int)? onBatteryDiscovery;
  final int? accountId;
  const SupportView({
    super.key,
    required this.api,
    required this.onSessionExpired,
    this.onBatteryDiscovery,
    this.accountId,
  });
  @override
  SupportViewState createState() => SupportViewState();
}

class SupportViewState extends State<SupportView>
    with WidgetsBindingObserver {
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
  late bool Function() authorized;
  bool foreground = true, freshRead = false;
  bool current(int request, bool Function() session) =>
      mounted && foreground && authorized() && session() &&
      generation == request;

  void clearPrivate() {
    generation++;
    data = null;
    error = null;
    loading = false;
    freshRead = false;
    preferencesLoaded = false;
    savedPreferences = null;
    category = 'all';
    unreadOnly = false;
    unreadCount = null;
    latestNotificationId = null;
    unreadByCategory = null;
    pendingTicketSubject = null;
    pendingTicketBody = null;
    pendingTicketKey = null;
  }

  bool ensureActor() {
    if (!mounted) return false;
    if (authorized()) return true;
    // A revoked session may be observed through a user action before a
    // parent rebuild. Never leave the prior actor's private rows visible.
    setState(clearPrivate);
    return false;
  }

  Future<void> refresh() async {
    if (!ensureActor()) return;
    await load(page: data?.current ?? 1);
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    // An observer receives future transitions, not the state at first mount.
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant SupportView oldWidget) {
    super.didUpdateWidget(oldWidget);
    final identityChanged =
        oldWidget.api != widget.api || oldWidget.accountId != widget.accountId;
    if (identityChanged || !authorized()) {
      // EvApi can retain object identity while its authenticated session
      // changes. The old closure has been revoked: discard every private
      // inbox row, notification preference and pending ticket key first.
      clearPrivate();
      authorized = widget.api.sessionFence();
      if (identityChanged) {
        final nextApi = widget.api;
        final nextId = widget.accountId;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && authorized() &&
              identical(widget.api, nextApi) && widget.accountId == nextId) {
            load();
          }
        });
      }
      // A same-object session rotation must NEVER auto-read private history:
      // the next account chooses explicit Refresh after the secure reset.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!mounted) return;
    if (!foreground) {
      setState(clearPrivate);
    } else {
      // Rebuild the explicit Refresh affordance, but do not silently reload
      // private history or replay an uncertain ticket/notice mutation.
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    if (!ensureActor() || !foreground ||
        page < 1 || page > 100000) return;
    final session = widget.api.sessionFence();
    final request = ++generation;
    final showingNotifications = notifications;
    setState(() {
      loading = true;
      error = null;
      data = null;
      freshRead = false;
      unreadCount = null;
      unreadByCategory = null;
      latestNotificationId = null;
    });
    try {
      if (showingNotifications && !preferencesLoaded) {
        final prefs = await widget.api.request('notifications/preferences');
        if (!current(request, session)) return;
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
      if (!current(request, session)) return;
      final result = ApiPage.fromJson(response, (row) => row);
      // The server paginates both private ticket and notification inboxes
      // in groups of 25. A wrong-page, duplicate or overlarge payload
      // must never authorize a stale ticket/navigation/mark-read action.
      final seenIds = <int>{};
      if (result.current != page || result.last < page ||
          result.last > 100000 || result.total < 0 ||
          result.items.length > 25 ||
          result.items.any((row) =>
              row['id'] is! int || !seenIds.add(row['id'] as int))) {
        throw const FormatException('Unverified private inbox page');
      }
      // Never render private action targets from an ambiguous or corrupted
      // inbox projection. A malformed row requires a fresh authoritative GET.
      if (result.items.any((row) {
        final id = row['id'];
        if (id is! int || id < 1) return true;
        if (showingNotifications) {
          final ticketId = row['support_ticket_id'];
          return row['kind'] is! String ||
              (ticketId != null && (ticketId is! int || ticketId < 1)) ||
              (row['read_at'] != null && row['read_at'] is! String);
        }
        return row['subject'] is! String ||
            !(const ['open', 'in_progress', 'resolved', 'closed'])
                .contains(row['status']);
      })) {
        throw const FormatException('Unverified private support rows');
      }
      final summary = showingNotifications
          ? await widget.api.request('notifications/summary')
          : null;
      if (current(request, session)) {
        setState(() {
          data = result;
          freshRead = true;
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
      if (!current(request, session)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(clearPrivate);
        widget.onSessionExpired();
        return;
      }
      setState(() => error = e);
    } finally {
      if (current(request, session)) setState(() => loading = false);
    }
  }

  Future<void> createTicket() async {
    if (!ensureActor() || loading || notifications || !freshRead ||
        !foreground) return;
    final dialogSession = widget.api.sessionFence();
    final dialogGeneration = generation;
    final dialogApi = widget.api;
    // A popped dialog remains in the widget tree for its reverse route
    // animation. Controllers are owned and disposed by that dialog's State,
    // not immediately when the showDialog future resolves.
    final desired = await showDialog<Map<String, String>>(
      context: context,
      builder: (_) => _NewSupportTicketDialog(
        initialSubject: pendingTicketSubject,
        initialBody: pendingTicketBody,
      ),
    );
    if (desired == null || !current(dialogGeneration, dialogSession) ||
        !identical(widget.api, dialogApi)) return;
    if (pendingTicketKey == null ||
        pendingTicketSubject != desired['subject'] ||
        pendingTicketBody != desired['body']) {
      pendingTicketKey = newSupportRequestKey();
      pendingTicketSubject = desired['subject'];
      pendingTicketBody = desired['body'];
    }
    final session = widget.api.sessionFence();
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
      if (!current(request, session)) return;
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
      if (!current(request, session)) return;
      if (errorValue is ApiException && [401, 419].contains(errorValue.status)) {
        setState(clearPrivate);
        widget.onSessionExpired();
        return;
      }
      setState(() {
        error = errorValue;
        loading = false;
      });
    }
  }

  Future<void> editNotificationPreferences() async {
    final prefs = savedPreferences;
    if (!ensureActor() || !notifications || loading || !freshRead ||
        prefs == null || !foreground) return;
    final dialogSession = widget.api.sessionFence();
    final dialogGeneration = generation;
    final dialogApi = widget.api;
    var selected = prefs['default_category'] as String;
    var unread = prefs['default_unread_only'] as bool;
    var repeat = prefs['remind_unresolved_simulator_faults'] as bool;
    var delay = prefs['fault_reminder_delay_minutes'] is int
        ? prefs['fault_reminder_delay_minutes'] as int : 15;
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
                  items: const ['all', 'support', 'vehicle', 'charging', 'fleet', 'other']
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
    if (desired == null || !current(dialogGeneration, dialogSession) ||
        !identical(widget.api, dialogApi)) return;
    final session = widget.api.sessionFence();
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
      if (!current(request, session)) return;
      if (accepted is! Map ||
          accepted['default_category'] != desired['default_category'] ||
          accepted['default_unread_only'] != desired['default_unread_only'] ||
          accepted['remind_unresolved_simulator_faults'] !=
              desired['remind_unresolved_simulator_faults'] ||
          accepted['fault_reminder_delay_minutes'] != desired['fault_reminder_delay_minutes'] ||
          accepted['in_app_only'] != true ||
          accepted['critical_events_always_visible'] != true ||
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
      if (!current(request, session)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(clearPrivate);
        widget.onSessionExpired();
        return;
      }
      setState(() {
        error = e;
        loading = false;
      });
    }
  }

  Future<void> markCurrentRead() async {
    final throughId = latestNotificationId;
    if (!ensureActor() || !notifications || loading || !freshRead ||
        !foreground || throughId == null || throughId <= 0) return;
    final session = widget.api.sessionFence();
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
      if (current(request, session)) await load(page: 1);
    } catch (e) {
      if (!current(request, session)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(clearPrivate);
        widget.onSessionExpired();
        return;
      }
      setState(() {
        error = e;
        loading = false;
      });
    }
  }

  Future<void> markNotification(int id, {required bool read}) async {
    if (!ensureActor() || !notifications || loading || !freshRead ||
        !foreground || id <= 0) return;
    final session = widget.api.sessionFence();
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
      if (current(request, session)) await load(page: data?.current ?? 1);
    } catch (e) {
      if (!current(request, session)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(clearPrivate);
        widget.onSessionExpired();
        return;
      }
      setState(() {
        error = e;
        loading = false;
      });
    }
  }

  Future<void> open(int id) async {
    if (!ensureActor() || !foreground || id <= 0) return;
    final session = widget.api.sessionFence();
    final request = generation;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => TicketPage(
          api: widget.api,
          id: id,
          accountId: widget.accountId,
          onSessionExpired: widget.onSessionExpired,
        ),
      ),
    );
    if (current(request, session)) await refresh();
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    // Bind every inbox deep link to the exact page/actor that rendered it.
    // A retained callback must never navigate for a revoked account or an
    // older filtered notification page after its private read was replaced.
    final navigationGeneration = generation;
    final navigationApi = widget.api;
    final navigationSession = navigationApi.sessionFence();
    bool canNavigate() =>
        notifications && freshRead && !loading && foreground &&
        identical(widget.api, navigationApi) &&
        current(navigationGeneration, navigationSession);
    bool canOpenTicket() =>
        !notifications && freshRead && !loading && foreground &&
        identical(widget.api, navigationApi) &&
        current(navigationGeneration, navigationSession);
    if (!authorized()) {
      return Center(child: Text(s.t('expired')));
    }
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
                  onPressed: loading || !freshRead ? null : createTicket,
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
            Wrap(
              children: [
                TextButton.icon(
                  key: const Key('notification-preferences-open'),
                  onPressed: loading || !freshRead || savedPreferences == null
                      ? null : editNotificationPreferences,
                  icon: const Icon(Icons.tune),
                  label: Text(s.t('notificationPreferencesTitle')),
                ),
                TextButton(
                  onPressed: loading ? null : () => Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => NotificationEmailView(api: widget.api, onExpired: widget.onSessionExpired))),
                  child: Text(Localizations.localeOf(context).languageCode == 'ar' ? 'إشعارات البريد' : 'Email activity notices'),
                ),
              ],
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
          if (!loading && !freshRead && foreground) ...[
            Semantics(
              liveRegion: true,
              child: Text(
                Localizations.localeOf(context).languageCode == 'ar'
                    ? 'حدّث سجل الحساب قبل اتخاذ إجراء جديد.'
                    : 'Refresh this account history before taking another action.',
              ),
            ),
            TextButton.icon(
              key: const Key('support-manual-refresh'),
              onPressed: refresh,
              icon: const Icon(Icons.refresh),
              label: Text(s.t('refresh')),
            ),
          ],
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
                      if (widget.onBatteryDiscovery != null &&
                          batteryDiscoveryNotificationTarget(row) != null)
                        TextButton(
                          onPressed: loading || !authorized()
                              ? null
                              : () {
                                  if (!canNavigate()) {
                                    ensureActor();
                                    return;
                                  }
                                  final target = batteryDiscoveryNotificationTarget(row);
                                  if (target != null) {
                                    widget.onBatteryDiscovery?.call(target);
                                  }
                                },
                          child: Text(
                            s.arabic
                                ? 'البحث عن شواحن متوافقة'
                                : 'Find compatible chargers',
                          ),
                        ),

                      Wrap(
                        spacing: 8,
                        children: [
                          if (row['kind'] == 'fleet.invitation' && widget.accountId != null)
                            TextButton(
                              key: const Key('review-fleet-invitation'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      if (!canNavigate()) {
                                        ensureActor();
                                        return;
                                      }
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
                                      if (!canNavigate()) {
                                        ensureActor();
                                        return;
                                      }
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
                          if (providerDisputeNotificationTarget(row) != null)
                            TextButton(onPressed: loading ? null : () async {
                              if (!canNavigate()) {
                                ensureActor();
                                return;
                              }
                              final id = providerDisputeNotificationTarget(row);
                              if (id == null) return;
                              await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) =>
                                ProviderDisputesView(api: widget.api, onExpired: widget.onSessionExpired, initialDisputeId: id)));
                              if (mounted) await refresh();
                            }, child: Text(s.t('reviewProviderDispute'))),
                          if (simulatedMonitorTarget(row) != null)
                            TextButton(
                              key: const Key('review-simulator-monitor'),
                              onPressed: loading
                                  ? null
                                  : () async {
                                      if (!canNavigate()) {
                                        ensureActor();
                                        return;
                                      }
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
                                      if (!canNavigate()) {
                                        ensureActor();
                                        return;
                                      }
                                      final target = simulatedRepairTarget(row);
                                      if (target == null) return;
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => ChargerRepairsView(
                                            api: widget.api,
                                            companyId: target['company_id']!,
                                            chargerId: target['charger_id']!,
                                            initialAlertId: target['alert_id'],
                                            initialOrderId: target['repair_order_id'],
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
                                      if (!canNavigate()) {
                                        ensureActor();
                                        return;
                                      }
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
                                  : () {
                                      if (!canNavigate()) return;
                                      open(row['support_ticket_id'] as int);
                                    },
                              child: Text(
                                s.t('ticket', {'id': row['support_ticket_id']}),
                              ),
                            ),
                          if (row['id'] is int)
                            TextButton(
                              onPressed: loading
                                  ? null
                                  : () {
                                      if (!canNavigate()) return;
                                      markNotification(
                                        row['id'] as int,
                                        read: row['read_at'] == null,
                                      );
                                    },
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
                            : () {
                                // Even if a callback was retained before a
                                // same-EvApi session rotation, the old ticket
                                // ID cannot navigate in the new account.
                                if (!canOpenTicket()) {
                                  ensureActor();
                                  return;
                                }
                                open(row['id'] as int);
                              },
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

}

class TicketPage extends StatefulWidget {
  final EvApi api;
  final int id;
  final int? accountId;
  final VoidCallback onSessionExpired;
  const TicketPage({
    super.key,
    required this.api,
    required this.id,
    this.accountId,
    required this.onSessionExpired,
  });
  @override
  State<TicketPage> createState() => _TicketPageState();
}

class _TicketPageState extends State<TicketPage>
    with WidgetsBindingObserver {
  Map<String, dynamic>? ticket;
  ApiPage<Map<String, dynamic>>? messages;
  bool loading = false, foreground = true, freshRead = false;
  Object? error;
  late bool Function() authorized;
  int generation = 0;
  bool current(int n, bool Function() session) =>
      mounted && foreground && authorized() && session() && n == generation;

  bool knownMessageVisibility(Object? flag) =>
      flag is bool || flag == 0 || flag == 1 ||
      flag == '0' || flag == '1';

  bool publiclyVisible(Map<String, dynamic> row) =>
      row['internal'] == false ||
      row['internal'] == 0 ||
      row['internal'] == '0';

  void clearPrivate() {
    generation++;
    ticket = null;
    messages = null;
    loading = false;
    freshRead = false;
    error = null;
    reply.clear();
    pendingReplyBody = null;
    pendingReplyKey = null;
  }
  final reply = TextEditingController();
  final form = GlobalKey<FormState>();
  String? pendingReplyBody;
  String? pendingReplyKey;

  // Reply and ticket-create retries share the same secure key generator.
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    // An observer receives future transitions, not the state at first mount.
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant TicketPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    final identityChanged =
        oldWidget.api != widget.api || oldWidget.id != widget.id ||
        oldWidget.accountId != widget.accountId;
    if (identityChanged || !authorized()) {
      // A retained client can silently rotate credentials while this widget
      // stays mounted. Clear old messages and unsent/retry reply material.
      clearPrivate();
      authorized = widget.api.sessionFence();
      if (identityChanged) {
        final nextApi = widget.api;
        final nextId = widget.id;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && authorized() &&
              identical(widget.api, nextApi) && widget.id == nextId) {
            load();
          }
        });
      }
      // Same-object session rotation only re-arms explicit foreground Refresh.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!mounted) return;
    if (!foreground) {
      setState(clearPrivate);
    } else {
      // Rebuild the explicit Refresh affordance, but do not silently reload
      // private history or replay an uncertain ticket/notice mutation.
      setState(() {});
    }
  }

  void failed(Object e, int n, bool Function() session,
      {bool preserveRetry = false}) {
    if (!current(n, session)) return;
    if (e is ApiException && [401, 419].contains(e.status)) {
      setState(clearPrivate);
      widget.onSessionExpired();
      return;
    }
    setState(() {
      if (!preserveRetry) {
        ticket = null;
        messages = null;
        freshRead = false;
      }
      error = e;
    });
  }

  Future<void> load({int page = 1}) async {
    if (!foreground || !authorized() || page < 1 || page > 100000) return;
    final session = widget.api.sessionFence();
    final n = ++generation;
    setState(() {
      loading = true;
      error = null;
      ticket = null;
      messages = null;
      freshRead = false;
    });
    try {
      final response = await widget.api.request(
        'support/${widget.id}',
        query: {'page': '$page'},
      );
      if (!current(n, session)) return;
      if (response is! Map || response['ticket'] is! Map ||
          response['messages'] is! Map) {
        throw const FormatException('Unverified ticket');
      }
      final verified = Map<String, dynamic>.from(response['ticket'] as Map);
      if (verified['id'] != widget.id ||
          verified['subject'] is! String ||
          (verified['subject'] as String).isEmpty ||
          !const {'open', 'in_progress', 'resolved', 'closed'}
              .contains(verified['status'])) {
        throw const FormatException('Unverified ticket');
      }
      final list = ApiPage.fromJson(response['messages'], (row) => row);
      // Reply actions must never use an unverified thread page. The
      // authenticated Laravel message index is bounded to 50 per page.
      if (list.current != page || list.last < page ||
          list.last > 100000 || list.total < 0 ||
          list.items.length > 50 ||
          list.items.any((row) =>
              row['body'] is! String ||
              (row['body'] as String).length > 5000 ||
              (row['support_ticket_id'] != null &&
                  (row['support_ticket_id'] is! int ||
                   row['support_ticket_id'] != widget.id)) ||
              !knownMessageVisibility(row['internal']))) {
        throw const FormatException('Unverified ticket messages');
      }
      if (current(n, session)) {
        setState(() {
          ticket = verified;
          messages = list;
          freshRead = true;
        });
      }
    } catch (e) {
      failed(e, n, session);
    } finally {
      if (current(n, session)) setState(() => loading = false);
    }
  }

  Future<void> send() async {
    if (loading || !freshRead || !foreground || !authorized() ||
        (pendingReplyKey == null && form.currentState?.validate() != true)) return;
    final session = widget.api.sessionFence();
    final n = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      // An uncertain write must keep its original content and key.
      // A changed draft cannot allocate another request until recovery.
      if (pendingReplyKey == null) {
        pendingReplyBody = reply.text.trim();
        pendingReplyKey = newSupportRequestKey();
      }
      final text = pendingReplyBody!;
      final accepted = await widget.api.request(
        'support/${widget.id}/messages',
        method: 'POST',
        body: {'body': text, 'idempotency_key': pendingReplyKey},
      );
      if (!current(n, session)) return;
      if (!supportReplyReceiptMatches(accepted, widget.id, text,
          accountId: widget.accountId)) {
        throw const FormatException('Support reply not confirmed');
      }
      pendingReplyBody = null;
      pendingReplyKey = null;
      reply.clear();
      await load(page: messages?.last ?? 1);
      final fetched = generation;
      if (current(fetched, session) &&
          messages != null && messages!.current < messages!.last) {
        await load(page: messages!.last);
      }
    } catch (e) {
      failed(e, n, session, preserveRetry: true);
    } finally {
      if (current(n, session)) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    final page = messages;
    if (!authorized()) {
      return Scaffold(
        appBar: AppBar(title: Text(s.t('ticket', {'id': widget.id}))),
        body: Center(child: Text(s.t('expired'))),
      );
    }
    return Scaffold(
      appBar: AppBar(title: Text(s.t('ticket', {'id': widget.id}))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (loading) const LinearProgressIndicator(),
            if (!loading && ticket == null && error == null)
              TextButton(
                onPressed: foreground ? () => load() : null,
                child: Text(s.t('refresh')),
              ),
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
                if (publiclyVisible(row))
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${row['body']}'),
                          Text(
                            supportMessageTime(context,row['created_at']),
                            key: const Key('support-message-time'),
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
                      onPressed: loading || pendingReplyKey != null || page.current <= 1
                          ? null
                          : () => load(page: page.current - 1),
                      child: Text(s.t('previous')),
                    ),
                    TextButton(
                      onPressed: loading || pendingReplyKey != null || page.current >= page.last
                          ? null
                          : () => load(page: page.current + 1),
                      child: Text(s.t('next')),
                    ),
                  ],
                ),
              if (ticket!['status'] == 'closed' && pendingReplyKey == null)
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
                        enabled: !loading && pendingReplyKey == null,
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
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    reply.dispose();
    super.dispose();
  }
}
