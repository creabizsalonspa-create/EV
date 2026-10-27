import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'api.dart';
import 'catalog.dart';
import 'preferences.dart';
import 'strings.dart';
import 'support.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final preferences = AppPreferences(DevicePreferenceStore());
  await preferences.load();
  runApp(EvApp(preferences: preferences));
}

class EvApp extends StatefulWidget {
  final AppPreferences? preferences;
  final EvApi? api;
  const EvApp({super.key, this.preferences, this.api});
  @override
  State<EvApp> createState() => _EvAppState();
}

class _EvAppState extends State<EvApp> {
  late final AppPreferences preferences;
  @override
  void initState() {
    super.initState();
    preferences = widget.preferences ?? AppPreferences(DevicePreferenceStore());
    if (!preferences.ready) preferences.load();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: preferences,
    builder: (context, _) => MaterialApp(
      title: 'Crea-Biz EV',
      themeMode: preferences.theme,
      locale: preferences.locale,
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: theme(Brightness.light),
      darkTheme: theme(Brightness.dark),
      home: preferences.ready
          ? Workspace(preferences: preferences, initialApi: widget.api)
          : const Scaffold(body: Center(child: CircularProgressIndicator())),
    ),
  );
  ThemeData theme(Brightness brightness) => ThemeData(
    colorScheme: ColorScheme.fromSeed(
      seedColor: const Color(0xff126b63),
      brightness: brightness,
    ),
    useMaterial3: true,
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(
        borderRadius: BorderRadius.all(Radius.circular(12)),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
    ),
    cardTheme: const CardThemeData(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.all(Radius.circular(20)),
      ),
    ),
  );
  @override
  void dispose() {
    if (widget.preferences == null) preferences.dispose();
    super.dispose();
  }
}

class Workspace extends StatefulWidget {
  final AppPreferences preferences;
  final EvApi? initialApi;
  const Workspace({super.key, required this.preferences, this.initialApi});
  @override
  State<Workspace> createState() => _WorkspaceState();
}

class _WorkspaceState extends State<Workspace> {
  EvApi? api;
  Account? account;
  String? error;
  String? message;
  bool busy = false;
  int tab = 0;
  int recordPage = 1, recordLast = 1, recordTotal = 0;
  final email = TextEditingController(),
      password = TextEditingController(),
      name = TextEditingController();
  final loginForm = GlobalKey<FormState>();
  final catalogKey = GlobalKey<CatalogViewState>();
  final supportKey = GlobalKey<SupportViewState>();
  bool registering = false;
  List<Map<String, dynamic>> records = [];
  static const screens = [
    'discover',
    'reservations',
    'sessions',
    'garage',
    'support',
  ];
  static const endpoints = [
    'stations',
    'reservations',
    'simulations',
    'vehicles',
    'support',
  ];
  EvStrings get s => EvStrings.of(context);

  @override
  void initState() {
    super.initState();
    if (widget.initialApi != null) {
      api = widget.initialApi;
      return;
    }
    const origin = String.fromEnvironment('EV_API_BASE_URL');
    try {
      if (origin.isEmpty) throw ArgumentError('Missing service origin');
      api = EvApi(Uri.parse(origin));
    } on ArgumentError {
      error = 'serviceUnavailable';
    }
  }

  void resetSession() {
    if (!mounted) return;
    Navigator.of(context).popUntil((route) => route.isFirst);
    setState(() {
      account = null;
      records = [];
      tab = 0;
      name.clear();
      password.clear();
      email.clear();
      message = null;
    });
  }

  void expiredSession() {
    resetSession();
    if (mounted) setState(() => error = 'expired');
  }

  Future<void> perform(Future<void> Function() task) async {
    if (busy || api == null) return;
    setState(() {
      busy = true;
      error = null;
      message = null;
    });
    try {
      await task();
    } catch (e) {
      if (e is ApiException && (e.status == 401 || e.status == 419))
        resetSession();
      if (mounted) setState(() => error = errorKey(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> load({int page = 1}) async {
    if (tab == 0 || tab == 4) return;
    final selectedTab = tab;
    final data = await api!.request(endpoints[tab], query: {'page': '$page'});
    final parsed = ApiPage.fromJson(data, (row) => row);
    if (!mounted || tab != selectedTab) return;
    setState(() {
      records = parsed.items;
      recordPage = parsed.current;
      recordLast = parsed.last;
      recordTotal = parsed.total;
    });
  }

  Future<void> login() async {
    if (!loginForm.currentState!.validate()) return;
    await perform(() async {
      final user = await api!.signIn(
        email.text.trim(),
        password.text,
        name: registering ? name.text.trim() : null,
      );
      password.clear();
      if (!mounted) return;
      if (user.role == 'admin') {
        await api!.signOut();
        if (mounted) setState(() => error = 'webAdmin');
        return;
      }
      setState(() {
        account = user;
        tab = 0;
        records = [];
      });
    });
  }

  Future<Map<String, dynamic>?> entry(
    String title,
    Map<String, String> fields,
  ) => showDialog<Map<String, dynamic>>(
    context: context,
    builder: (_) => EntryDialog(title: title, fields: fields),
  );
  Future<void> create() async {
    Map<String, String> fields;
    String path, title;
    if (tab == 0 && account?.role == 'vendor') {
      fields = {
        'name': '',
        'country': 'AE',
        'currency': 'AED',
        'timezone': 'Asia/Dubai',
        'latitude': '',
        'longitude': '',
        'connector_type': 'CCS2',
        'power_kw': '',
        'source': 'simulator',
      };
      path = 'stations';
      title = 'createStation';
    } else if (tab == 3) {
      fields = {'name': '', 'connector_type': 'CCS2'};
      path = 'vehicles';
      title = 'addVehicle';
    } else if (tab == 4) {
      fields = {'subject': '', 'body': ''};
      path = 'support';
      title = 'openTicket';
    } else {
      return;
    }
    final values = await entry(s.t(title), fields);
    if (values != null && mounted)
      await perform(() async {
        await api!.request(path, method: 'POST', body: values);
        if (tab == 0) {
          await catalogKey.currentState?.refresh();
        } else if (tab == 4) {
          await supportKey.currentState?.refresh();
        } else {
          await load();
        }
        if (mounted) setState(() => message = 'saved');
      });
  }

  Future<void> reserve(Station station) async {
    final date = await showDatePicker(
      context: context,
      initialDate: DateTime.now().add(const Duration(days: 1)),
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 29)),
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: const TimeOfDay(hour: 12, minute: 0),
      helpText: s.t('utcTime'),
    );
    if (time == null || !mounted) return;
    if (time.minute != 0 && time.minute != 30) {
      setState(() => error = 'halfHour');
      return;
    }
    final slot = DateTime.utc(
      date.year,
      date.month,
      date.day,
      time.hour,
      time.minute,
    );
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(s.t('reserveConfirm')),
        content: Text(
          '${station.name}\n${slot.toIso8601String()}\n${s.t('reservationTerms')}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(s.t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(s.t('reserve')),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    await perform(() async {
      await api!.request(
        'reservations',
        method: 'POST',
        body: {
          'station_id': station.id,
          'starts_at': slot.toIso8601String().replaceFirst('.000Z', 'Z'),
          'idempotency_key':
              'mobile-${DateTime.now().microsecondsSinceEpoch}-${station.id}',
        },
      );
      if (mounted) setState(() => tab = 1);
      await load();
    });
  }

  Future<void> recovery() async {
    final values = await entry(s.t('forgot'), {'email': email.text.trim()});
    if (values == null || !mounted) return;
    await perform(() async {
      await api!.csrf();
      await api!.request('forgot-password', method: 'POST', body: values);
      if (mounted) setState(() => message = 'recoverySent');
    });
  }

  Future<void> profile() async {
    final values = await entry(s.t('profile'), {'name': account!.name});
    if (values == null || !mounted) return;
    await perform(() async {
      final updated = await api!.request(
        'profile',
        method: 'PATCH',
        body: {...values, 'locale': widget.preferences.locale.languageCode},
      );
      if (mounted)
        setState(
          () => account = Account.fromJson(
            Map<String, dynamic>.from(updated as Map),
          ),
        );
    });
  }

  Widget recordCard(Map<String, dynamic> row) {
    final id = row['id'];
    final title = tab == 1
        ? s.t('reservation', {'id': id, 'status': s.t(row['status'])})
        : tab == 2
        ? s.t('session', {'id': id, 'status': s.t(row['status'])})
        : tab == 3
        ? '${row['name']}'
        : '${row['subject']} · ${s.t(row['status'])}';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            if (tab == 1)
              Text(
                s.t('stationSlot', {
                  'id': row['station_id'],
                  'time': row['starts_at'],
                }),
              ),
            if (tab == 2)
              Text(
                s.t('energy', {
                  'energy': row['energy_wh'],
                  'total': row['total_minor'] ?? s.t('pending'),
                  'currency': row['currency'],
                }),
              ),
            if (tab == 3)
              Text(
                '${row['connector_type']}',
                textDirection: TextDirection.ltr,
              ),
            if (tab == 1 &&
                account?.role == 'customer' &&
                row['status'] == 'confirmed')
              Wrap(
                spacing: 8,
                children: [
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => perform(() async {
                            await api!.request(
                              'reservations/$id',
                              method: 'DELETE',
                            );
                            await load(page: recordPage);
                          }),
                    child: Text(s.t('cancelBooking')),
                  ),
                  FilledButton(
                    onPressed: busy
                        ? null
                        : () => perform(() async {
                            await api!.request(
                              'reservations/$id/simulate-start',
                              method: 'POST',
                            );
                            if (mounted) setState(() => tab = 2);
                            await load();
                          }),
                    child: Text(s.t('startSimulator')),
                  ),
                ],
              ),
            if (tab == 2 && row['status'] == 'charging')
              TextButton(
                onPressed: busy
                    ? null
                    : () => perform(() async {
                        await api!.request(
                          'simulations/$id/stop',
                          method: 'POST',
                        );
                        await load(page: recordPage);
                      }),
                child: Text(s.t('stopSimulation')),
              ),
          ],
        ),
      ),
    );
  }

  Widget recordsView() => RefreshIndicator(
    onRefresh: () => perform(() => load(page: recordPage)),
    child: ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          s.t(screens[tab]),
          style: Theme.of(context).textTheme.headlineLarge,
        ),
        Text(s.t('development')),
        if (error != null)
          TextButton(
            onPressed: busy
                ? null
                : () => perform(() => load(page: recordPage)),
            child: Text(s.t('retry')),
          ),
        ...records.map(recordCard),
        if (!busy && error == null && records.isEmpty)
          Padding(padding: const EdgeInsets.all(24), child: Text(s.t('empty'))),
        Text(
          s.t('page', {
            'page': recordPage,
            'last': recordLast,
            'total': recordTotal,
          }),
        ),
        Wrap(
          spacing: 12,
          children: [
            OutlinedButton(
              onPressed: busy || recordPage <= 1
                  ? null
                  : () => perform(() => load(page: recordPage - 1)),
              child: Text(s.t('previous')),
            ),
            OutlinedButton(
              onPressed: busy || recordPage >= recordLast
                  ? null
                  : () => perform(() => load(page: recordPage + 1)),
              child: Text(s.t('next')),
            ),
          ],
        ),
        const SizedBox(height: 96),
      ],
    ),
  );
  Widget loginView() => Center(
    child: SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: Form(
          key: loginForm,
          child: AutofillGroup(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  s.t('tagline'),
                  style: Theme.of(context).textTheme.headlineLarge,
                ),
                const SizedBox(height: 24),
                if (registering)
                  TextFormField(
                    key: const Key('auth-name'),
                    controller: name,
                    decoration: InputDecoration(labelText: s.t('name')),
                    enabled: !busy,
                    validator: (v) =>
                        v!.trim().isEmpty ? s.t('required') : null,
                  ),
                const SizedBox(height: 12),
                TextFormField(
                  key: const Key('auth-email'),
                  controller: email,
                  decoration: InputDecoration(labelText: s.t('email')),
                  enabled: !busy,
                  keyboardType: TextInputType.emailAddress,
                  autofillHints: const [AutofillHints.email],
                  validator: (v) =>
                      !RegExp(r'^\S+@\S+\.\S+$').hasMatch(v!.trim())
                      ? s.t('invalidEmail')
                      : null,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  key: const Key('auth-password'),
                  controller: password,
                  decoration: InputDecoration(labelText: s.t('password')),
                  enabled: !busy,
                  obscureText: true,
                  autofillHints: const [AutofillHints.password],
                  validator: (v) => v!.isEmpty
                      ? s.t('required')
                      : registering && v.length < 12
                      ? s.t('passwordLength')
                      : null,
                  onFieldSubmitted: (_) => login(),
                ),
                const SizedBox(height: 24),
                FilledButton(
                  key: const Key('auth-submit'),
                  onPressed: busy || api == null ? null : login,
                  child: Text(s.t(registering ? 'register' : 'signIn')),
                ),
                TextButton(
                  onPressed: busy
                      ? null
                      : () => setState(() => registering = !registering),
                  child: Text(
                    s.t(registering ? 'alreadyAccount' : 'createAccount'),
                  ),
                ),
                TextButton(
                  onPressed: busy || api == null ? null : recovery,
                  child: Text(s.t('forgot')),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
  void settings() => showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (context) => SafeArea(
      child: AnimatedBuilder(
        animation: widget.preferences,
        builder: (context, _) {
          final t = EvStrings.of(context);
          final p = widget.preferences;
          return SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  t.t('settings'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 20),
                DropdownButtonFormField<ThemeMode>(
                  key: ValueKey('theme-choice-${p.theme.name}-${p.saving}'),
                  initialValue: p.theme,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: t.t('appearance')),
                  items: ThemeMode.values
                      .map(
                        (m) => DropdownMenuItem(
                          value: m,
                          child: Text(t.t(m.name)),
                        ),
                      )
                      .toList(),
                  onChanged: p.saving ? null : (v) => p.update(theme: v!),
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<Locale>(
                  key: ValueKey(
                    'language-choice-${p.locale.languageCode}-${p.saving}',
                  ),
                  initialValue: p.locale,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: t.t('language')),
                  items: [
                    DropdownMenuItem(
                      value: const Locale('en'),
                      child: Text(t.t('english')),
                    ),
                    DropdownMenuItem(
                      value: const Locale('ar'),
                      child: Text(t.t('arabic')),
                    ),
                  ],
                  onChanged: p.saving ? null : (v) => p.update(locale: v!),
                ),
                if (p.storageError)
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: Text(t.t('storageError')),
                  ),
                const SizedBox(height: 16),
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: Text(t.t('close')),
                ),
              ],
            ),
          );
        },
      ),
    ),
  );
  @override
  Widget build(BuildContext context) {
    final signedIn = account != null;
    final destinations = [
      NavigationDestination(
        icon: const Icon(Icons.ev_station_outlined),
        label: s.t('discover'),
      ),
      NavigationDestination(
        icon: const Icon(Icons.event_outlined),
        label: s.t('bookings'),
      ),
      NavigationDestination(
        icon: const Icon(Icons.bolt),
        label: s.t('sessions'),
      ),
      NavigationDestination(
        icon: const Icon(Icons.directions_car_outlined),
        label: s.t('garage'),
      ),
      NavigationDestination(
        icon: const Icon(Icons.support_agent),
        label: s.t('support'),
      ),
    ];
    void select(int value) {
      setState(() {
        tab = value;
        records = [];
        recordPage = recordLast = 1;
        recordTotal = 0;
        error = null;
        message = null;
      });
      perform(load);
    }

    Widget content = signedIn
        ? IndexedStack(
            index: tab == 0 ? 0 : 1,
            children: [
              CatalogView(
                key: catalogKey,
                loader: (filter, page) =>
                    api!.catalog(filter: filter, page: page),
                customer: account!.role == 'customer',
                busy: busy,
                onReserve: reserve,
              ),
              tab == 4
                  ? SupportView(
                      key: supportKey,
                      api: api!,
                      onSessionExpired: expiredSession,
                    )
                  : recordsView(),
            ],
          )
        : loginView();
    final wide = MediaQuery.sizeOf(context).width >= 800;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          signedIn ? '${s.t('brand')} · ${s.t(account!.role)}' : s.t('brand'),
        ),
        actions: [
          IconButton(
            key: const Key('settings'),
            tooltip: s.t('settings'),
            onPressed: settings,
            icon: const Icon(Icons.tune),
          ),
          if (signedIn)
            PopupMenuButton<String>(
              tooltip: s.t('account'),
              onSelected: (value) {
                if (value == 'profile') {
                  profile();
                } else if (value == 'verify') {
                  perform(() async {
                    await api!.request(
                      'email/verification-notification',
                      method: 'POST',
                    );
                    final current = await api!.request('me');
                    if (mounted)
                      setState(() {
                        account = Account.fromJson(
                          Map<String, dynamic>.from(current as Map),
                        );
                        message = account!.emailVerified
                            ? 'emailVerified'
                            : 'verificationRequested';
                      });
                  });
                } else {
                  perform(() async {
                    try {
                      await api!.signOut();
                    } finally {
                      resetSession();
                    }
                  });
                }
              },
              itemBuilder: (_) => [
                PopupMenuItem(
                  value: 'profile',
                  enabled: !busy,
                  child: Text(s.t('profile')),
                ),
                PopupMenuItem(
                  value: 'verify',
                  enabled: !busy && !account!.emailVerified,
                  child: Text(
                    s.t(
                      account!.emailVerified ? 'emailVerified' : 'verifyEmail',
                    ),
                  ),
                ),
                PopupMenuItem(
                  value: 'logout',
                  enabled: !busy,
                  child: Text(s.t('signOut')),
                ),
              ],
            ),
        ],
      ),
      body: SafeArea(
        child: Column(
          children: [
            if (busy) const LinearProgressIndicator(),
            if (error != null ||
                message != null ||
                widget.preferences.storageError)
              Padding(
                padding: const EdgeInsets.all(12),
                child: Semantics(
                  liveRegion: true,
                  child: Text(
                    s.t(error ?? message ?? 'storageError'),
                    style: TextStyle(
                      color: error != null
                          ? Theme.of(context).colorScheme.error
                          : null,
                    ),
                  ),
                ),
              ),
            Expanded(
              child: wide && signedIn
                  ? Row(
                      children: [
                        NavigationRail(
                          selectedIndex: tab,
                          onDestinationSelected: busy ? null : select,
                          labelType: NavigationRailLabelType.all,
                          destinations: destinations
                              .map(
                                (d) => NavigationRailDestination(
                                  icon: d.icon,
                                  label: Text(d.label),
                                ),
                              )
                              .toList(),
                        ),
                        const VerticalDivider(width: 1),
                        Expanded(child: content),
                      ],
                    )
                  : content,
            ),
          ],
        ),
      ),
      floatingActionButton:
          signedIn &&
              ((tab == 0 && account?.role == 'vendor') || tab == 3 || tab == 4)
          ? FloatingActionButton(
              tooltip: s.t('create'),
              onPressed: busy ? null : create,
              child: const Icon(Icons.add),
            )
          : null,
      bottomNavigationBar: signedIn && !wide
          ? NavigationBar(
              selectedIndex: tab,
              labelBehavior:
                  NavigationDestinationLabelBehavior.onlyShowSelected,
              onDestinationSelected: busy ? null : select,
              destinations: destinations,
            )
          : null,
    );
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    name.dispose();
    if (widget.initialApi == null) api?.close();
    super.dispose();
  }
}

class EntryDialog extends StatefulWidget {
  final String title;
  final Map<String, String> fields;
  const EntryDialog({super.key, required this.title, required this.fields});
  @override
  State<EntryDialog> createState() => _EntryDialogState();
}

class _EntryDialogState extends State<EntryDialog> {
  late final Map<String, TextEditingController> controls;
  final form = GlobalKey<FormState>();
  @override
  void initState() {
    super.initState();
    controls = widget.fields.map(
      (k, v) => MapEntry(k, TextEditingController(text: v)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    return AlertDialog(
      title: Text(widget.title),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: controls.entries
                  .map(
                    (e) => Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: ['source', 'connector_type'].contains(e.key)
                          ? DropdownButtonFormField<String>(
                              initialValue: e.value.text,
                              isExpanded: true,
                              decoration: InputDecoration(
                                labelText: s.t(e.key),
                              ),
                              items:
                                  (e.key == 'source'
                                          ? ['simulator', 'discovery']
                                          : ['CCS2', 'Type2', 'CHAdeMO'])
                                      .map(
                                        (v) => DropdownMenuItem(
                                          value: v,
                                          child: Text(
                                            v == 'simulator'
                                                ? s.t('simulated')
                                                : v == 'discovery'
                                                ? s.t('discoveryOnly')
                                                : v,
                                          ),
                                        ),
                                      )
                                      .toList(),
                              onChanged: (v) => e.value.text = v!,
                            )
                          : TextFormField(
                              controller: e.value,
                              decoration: InputDecoration(
                                labelText: s.t(e.key),
                              ),
                              minLines: e.key == 'body' ? 3 : 1,
                              maxLines: e.key == 'body' ? 6 : 1,
                              validator: (v) =>
                                  v!.trim().isEmpty ? s.t('required') : null,
                            ),
                    ),
                  )
                  .toList(),
            ),
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(s.t('cancel')),
        ),
        FilledButton(
          onPressed: () {
            if (form.currentState!.validate())
              Navigator.pop(
                context,
                controls.map((k, v) => MapEntry(k, v.text.trim())),
              );
          },
          child: Text(s.t('save')),
        ),
      ],
    );
  }

  @override
  void dispose() {
    for (final c in controls.values) c.dispose();
    super.dispose();
  }
}
