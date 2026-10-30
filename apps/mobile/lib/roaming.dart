import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

/// A partner location cannot grant charging, availability or payment authority.
class RoamingLocation {
  final Map<String, dynamic> location;
  final String received;
  RoamingLocation.fromJson(Map<String, dynamic> json)
    : location = Map<String, dynamic>.from(json['location'] as Map),
      received = json['received_at'] as String {
    if (json['evidence'] != 'PARTNER_REPORTED_UNVERIFIED' ||
        json['availability'] != 'unknown' ||
        json['remote_control'] != false ||
        json['reservable'] != false) {
      throw const FormatException('Unexpected roaming capability.');
    }
  }
}

typedef RoamingLoader = Future<ApiPage<RoamingLocation>> Function(int page);

class RoamingView extends StatefulWidget {
  final RoamingLoader loader;
  const RoamingView({super.key, required this.loader});
  @override
  State<RoamingView> createState() => _RoamingViewState();
}

class _RoamingViewState extends State<RoamingView> {
  ApiPage<RoamingLocation>? data;
  bool loading = true;
  Object? error;
  int generation = 0, target = 1;
  @override
  void initState() {
    super.initState();
    load(1);
  }

  Future<void> load(int page) async {
    final epoch = ++generation;
    setState(() {
      loading = true;
      error = null;
      target = page;
    });
    try {
      final result = await widget.loader(page);
      if (mounted && epoch == generation) setState(() => data = result);
    } catch (e) {
      if (mounted && epoch == generation) setState(() => error = e);
    } finally {
      if (mounted && epoch == generation) setState(() => loading = false);
    }
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    return Scaffold(
      appBar: AppBar(title: Text(s.t('roaming'))),
      body: RefreshIndicator(
        onRefresh: () => load(page?.current ?? target),
        child: ListView(
          padding: const EdgeInsets.all(16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            Text(s.t('roamingDisclaimer')),
            if (loading) const LinearProgressIndicator(),
            if (error != null) ...[
              Text(s.t('genericError'), semanticsLabel: s.t('genericError')),
              OutlinedButton(
                onPressed: loading ? null : () => load(target),
                child: Text(s.t('retry')),
              ),
            ],
            // Do not leave previous-page data visible after a failed refresh.
            if (!loading && error == null && page != null) ...[
              if (page.items.isEmpty) Text(s.t('roamingEmpty')),
              for (final row in page.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${row.location['name'] ?? row.location['id']}',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        Text(
                          '${row.location['address']} · ${row.location['city']} · ${row.location['country']}',
                        ),
                        SelectableText(
                          s.t('roamingCoordinates', {
                            'value':
                                '${row.location['latitude']}, ${row.location['longitude']}',
                          }),
                        ),
                        Text(
                          s.t('roamingUpdated', {
                            'value': '${row.location['last_updated']}',
                          }),
                        ),
                        Text(s.t('roamingReceived', {'value': row.received})),
                        for (final evse
                            in (row.location['evses'] as List? ?? [])) ...[
                          Text(s.t('roamingEvse', {'value': '${evse['uid']}'})),
                          Text(
                            s.t('roamingReported', {
                              'value': '${evse['reported_status']}',
                            }),
                          ),
                          for (final connector in evse['connectors'] as List)
                            Text(
                              '${connector['id']} · ${connector['standard']}',
                            ),
                        ],
                        Text(s.t('roamingUnknown')),
                      ],
                    ),
                  ),
                ),
              Text(
                s.t('page', {
                  'page': page.current,
                  'last': page.last,
                  'total': page.total,
                }),
              ),
              Wrap(
                spacing: 12,
                children: [
                  OutlinedButton(
                    onPressed: page.current > 1
                        ? () => load(page.current - 1)
                        : null,
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: page.current < page.last
                        ? () => load(page.current + 1)
                        : null,
                    child: Text(s.t('next')),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
