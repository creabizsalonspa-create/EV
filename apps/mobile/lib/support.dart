import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

class SupportView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onSessionExpired;
  const SupportView({
    super.key,
    required this.api,
    required this.onSessionExpired,
  });
  @override
  SupportViewState createState() => SupportViewState();
}

class SupportViewState extends State<SupportView> {
  bool notifications = false, loading = false;
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
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final response = await widget.api.request(
        notifications ? 'notifications' : 'support',
        query: {'page': '$page'},
      );
      final result = ApiPage.fromJson(response, (row) => row);
      if (mounted && request == generation) setState(() => data = result);
    } catch (e) {
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onSessionExpired();
      if (mounted && request == generation) setState(() => error = e);
    } finally {
      if (mounted && request == generation) setState(() => loading = false);
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
                        s.t(
                          row['kind'] == 'support.reply'
                              ? 'supportReply'
                              : 'supportStatus',
                        ),
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(s.t(row['read_at'] == null ? 'unread' : 'read')),
                      Wrap(
                        spacing: 8,
                        children: [
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
    if (e is ApiException && [401, 419].contains(e.status))
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
