import 'package:flutter/material.dart';
import 'api.dart';

void main() => runApp(const EvApp());

class EvApp extends StatefulWidget {
  const EvApp({super.key});
  @override
  State<EvApp> createState() => _EvAppState();
}

class _EvAppState extends State<EvApp> {
  ThemeMode mode = ThemeMode.system;
  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Crea-Biz EV',
    themeMode: mode,
    theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff126b63)), useMaterial3: true),
    darkTheme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff88ded0), brightness: Brightness.dark), useMaterial3: true),
    home: Workspace(onTheme: (value) => setState(() => mode = value), theme: mode),
  );
}

class Workspace extends StatefulWidget {
  final ValueChanged<ThemeMode> onTheme;
  final ThemeMode theme;
  const Workspace({super.key, required this.onTheme, required this.theme});
  @override
  State<Workspace> createState() => _WorkspaceState();
}

class _WorkspaceState extends State<Workspace> {
  EvApi? api;
  Account? account;
  String? error;
  bool busy = false;
  int tab = 0;
  final email = TextEditingController();
  final password = TextEditingController();
  final name = TextEditingController();
  bool registering = false;
  List<Map<String, dynamic>> records = [];
  List<Station> catalog = [];
  static const screens = ['Discover', 'Reservations', 'Sessions', 'Garage', 'Support'];
  static const endpoints = ['stations', 'reservations', 'simulations', 'vehicles', 'support'];

  @override
  void initState() {
    super.initState();
    const origin = String.fromEnvironment('EV_API_BASE_URL');
    try {
      if (origin.isEmpty) throw ArgumentError('Missing service origin');
      api = EvApi(Uri.parse(origin));
    } on ArgumentError {
      error = 'The service is unavailable. Please contact support.';
    }
  }

  Future<void> perform(Future<void> Function() task) async {
    if (busy || api == null) return;
    setState(() { busy = true; error = null; });
    try { await task(); }
    catch (e) {
      if (mounted) setState(() {
        error = e is ApiException ? e.message : 'Unable to complete this request. Refresh before trying again.';
        if (e is ApiException && e.status == 401) { account = null; catalog = []; records = []; }
      });
    } finally { if (mounted) setState(() => busy = false); }
  }

  Future<void> load() async {
    final data = await api!.request(endpoints[tab]);
    if (!mounted) return;
    setState(() {
      if (tab == 0) {
        catalog = (data['data'] as List).map((j) => Station.fromJson(Map<String, dynamic>.from(j as Map))).toList();
      } else {
        records = (data['data'] as List).map((j) => Map<String, dynamic>.from(j as Map)).toList();
      }
    });
  }

  Future<void> login() => perform(() async {
    final user = await api!.signIn(email.text.trim(), password.text, name: registering ? name.text.trim() : null);
    password.clear();
    if (!mounted) return;
    if (user.role == 'admin') {
      await api!.signOut();
      throw const ApiException(403, 'Use the web operations workspace for this account.');
    }
    setState(() { account = user; tab = 0; records = []; });
    await load();
  });

  Future<Map<String, dynamic>?> form(String title, Map<String, String> fields) =>
      showDialog<Map<String, dynamic>>(context: context, builder: (_) => _EntryDialog(title: title, fields: fields));

  Future<void> create() async {
    Map<String, String> fields;
    String path;
    if (tab == 0 && account?.role == 'vendor') {
      fields = {'name': '', 'country': 'AE', 'currency': 'AED', 'timezone': 'Asia/Dubai', 'latitude': '', 'longitude': '', 'connector_type': 'CCS2', 'power_kw': '', 'source': 'simulator'};
      path = 'stations';
    } else if (tab == 3) {
      fields = {'name': '', 'connector_type': 'CCS2'}; path = 'vehicles';
    } else if (tab == 4) {
      fields = {'subject': '', 'body': ''}; path = 'support';
    } else { return; }
    final values = await form('Create ${screens[tab]}', fields);
    if (values != null && mounted) await perform(() async { await api!.request(path, method: 'POST', body: values); await load(); });
  }

  Future<void> reserve(Station station) async {
    final date = await showDatePicker(context: context, initialDate: DateTime.now().add(const Duration(days: 1)), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 29)));
    if (date == null || !mounted) return;
    final time = await showTimePicker(context: context, initialTime: const TimeOfDay(hour: 12, minute: 0), helpText: 'UTC start — minute 00 or 30');
    if (time == null || !mounted) return;
    if (time.minute != 0 && time.minute != 30) { setState(() => error = 'Choose a UTC half-hour boundary.'); return; }
    final slot = DateTime.utc(date.year, date.month, date.day, time.hour, time.minute);
    final confirmed = await showDialog<bool>(context: context, builder: (context) => AlertDialog(
      title: const Text('Reserve simulator slot?'), content: Text('${station.name}\n${slot.toIso8601String()}\n30-minute UTC slot. No physical charging or payment.'),
      actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Reserve'))],
    ));
    if (confirmed != true || !mounted) return;
    await perform(() async {
      await api!.request('reservations', method: 'POST', body: {'station_id': station.id, 'starts_at': slot.toIso8601String().replaceFirst('.000Z', 'Z'), 'idempotency_key': 'mobile-${DateTime.now().microsecondsSinceEpoch}-${station.id}'});
      if (mounted) setState(() => tab = 1);
      await load();
    });
  }

  Widget stationCard(Station station) => Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
    Chip(label: Text(station.source)), Text(station.name, style: Theme.of(context).textTheme.titleLarge),
    Text('${station.connector} · ${station.powerKw} kW'), const Text('Availability unknown. No live network connected.'),
    if (station.reservable && account?.role == 'customer') FilledButton(onPressed: busy ? null : () => reserve(station), child: const Text('Reserve simulator slot')),
  ])));

  Widget recordCard(Map<String, dynamic> row) {
    final id = row['id'];
    final title = tab == 1 ? 'Reservation #$id · ${row['status']}' : tab == 2 ? 'SIMULATED #$id · ${row['status']}' : tab == 3 ? '${row['name']}' : '${row['subject']} · ${row['status']}';
    return Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(title, style: Theme.of(context).textTheme.titleMedium),
      if (tab == 1) Text('Station ${row['station_id']} · ${row['starts_at']} UTC'),
      if (tab == 2) Text('${row['energy_wh']} Wh · ${row['total_minor'] ?? 'pending'} ${row['currency']} minor units · No payment processed'),
      if (tab == 3) Text('${row['connector_type']}'),
      if (tab == 1 && account?.role == 'customer' && row['status'] == 'confirmed') Wrap(spacing: 8, children: [
        TextButton(onPressed: busy ? null : () => perform(() async { await api!.request('reservations/$id', method: 'DELETE'); await load(); }), child: const Text('Cancel booking')),
        FilledButton(onPressed: busy ? null : () => perform(() async { await api!.request('reservations/$id/simulate-start', method: 'POST'); if (mounted) setState(() => tab = 2); await load(); }), child: const Text('Start simulator')),
      ]),
      if (tab == 2 && row['status'] == 'charging') TextButton(onPressed: busy ? null : () => perform(() async { await api!.request('simulations/$id/stop', method: 'POST'); await load(); }), child: const Text('Stop simulation')),
    ])));
  }

  @override
  Widget build(BuildContext context) {
    final signedIn = account != null;
    return Scaffold(
      appBar: AppBar(title: Text(signedIn ? 'Crea-Biz · ${account!.role}' : 'Crea-Biz EV'), actions: [
        PopupMenuButton<ThemeMode>(tooltip: 'Appearance', initialValue: widget.theme, onSelected: widget.onTheme, itemBuilder: (_) => ThemeMode.values.map((mode) => PopupMenuItem(value: mode, child: Text(mode.name))).toList()),
        if (signedIn) IconButton(tooltip: 'Sign out', icon: const Icon(Icons.logout), onPressed: busy ? null : () => perform(() async { try { await api!.signOut(); } finally { if (mounted) setState(() { account = null; records = []; catalog = []; }); } })),
      ]),
      body: SafeArea(child: Column(children: [
        if (busy) const LinearProgressIndicator(),
        if (error != null) Padding(padding: const EdgeInsets.all(12), child: Semantics(liveRegion: true, child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)))),
        Expanded(child: signedIn ? RefreshIndicator(onRefresh: () => perform(load), child: ListView(padding: const EdgeInsets.all(16), children: [
          Text(screens[tab], style: Theme.of(context).textTheme.headlineLarge),
          const Padding(padding: EdgeInsets.symmetric(vertical: 12), child: Text('Development workspace · simulated charging only.')),
          if (tab == 0) ...catalog.map(stationCard) else ...records.map(recordCard),
          if (!busy && (tab == 0 ? catalog.isEmpty : records.isEmpty)) const Padding(padding: EdgeInsets.all(24), child: Text('No records yet. Pull down to refresh.')),
          const Padding(padding: EdgeInsets.all(12), child: Text('Showing the first page. Full catalog filtering and pagination are still under development.')),
        ])) : Center(child: SingleChildScrollView(padding: const EdgeInsets.all(24), child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 440), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('Your next charge, connected.', style: Theme.of(context).textTheme.headlineLarge), const SizedBox(height: 24),
          if (registering) TextField(controller: name, decoration: const InputDecoration(labelText: 'Name'), textInputAction: TextInputAction.next),
          TextField(controller: email, decoration: const InputDecoration(labelText: 'Email'), keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email]),
          TextField(controller: password, decoration: const InputDecoration(labelText: 'Password'), obscureText: true, autofillHints: const [AutofillHints.password], onSubmitted: (_) => login()),
          const SizedBox(height: 24), FilledButton(onPressed: busy || api == null ? null : login, child: Text(registering ? 'Register' : 'Sign in')),
          TextButton(onPressed: busy ? null : () => setState(() => registering = !registering), child: Text(registering ? 'Already have an account?' : 'Create an account')),
        ]))))),
      ])),
      floatingActionButton: signedIn && ((tab == 0 && account?.role == 'vendor') || tab == 3 || tab == 4) ? FloatingActionButton(tooltip: 'Create', onPressed: busy ? null : create, child: const Icon(Icons.add)) : null,
      bottomNavigationBar: signedIn ? NavigationBar(selectedIndex: tab, onDestinationSelected: busy ? null : (value) { setState(() { tab = value; records = []; catalog = []; }); perform(load); }, destinations: const [
        NavigationDestination(icon: Icon(Icons.ev_station_outlined), label: 'Discover'), NavigationDestination(icon: Icon(Icons.event_outlined), label: 'Bookings'), NavigationDestination(icon: Icon(Icons.bolt), label: 'Sessions'), NavigationDestination(icon: Icon(Icons.directions_car_outlined), label: 'Garage'), NavigationDestination(icon: Icon(Icons.support_agent), label: 'Support'),
      ]) : null,
    );
  }

  @override
  void dispose() { email.dispose(); password.dispose(); name.dispose(); api?.close(); super.dispose(); }
}

class _EntryDialog extends StatefulWidget {
  final String title;
  final Map<String, String> fields;
  const _EntryDialog({required this.title, required this.fields});
  @override
  State<_EntryDialog> createState() => _EntryDialogState();
}
class _EntryDialogState extends State<_EntryDialog> {
  late final Map<String, TextEditingController> controls;
  @override
  void initState() {
    super.initState();
    controls = widget.fields.map((key, value) => MapEntry(key, TextEditingController(text: value)));
  }
  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: SizedBox(width: 420, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: controls.entries.map((e) => Padding(
      padding: const EdgeInsets.only(bottom: 12), child: TextField(controller: e.value, decoration: InputDecoration(labelText: e.key.replaceAll('_', ' '), border: const OutlineInputBorder())),
    )).toList()))),
    actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(context, controls.map((key, value) => MapEntry(key, value.text.trim()))), child: const Text('Save'))],
  );
  @override
  void dispose() { for (final control in controls.values) { control.dispose(); } super.dispose(); }
}
