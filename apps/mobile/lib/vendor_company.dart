import 'dart:async';
import 'dart:io';
import 'dart:typed_data';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:pdfrx/pdfrx.dart';
import 'api.dart';
import 'rider_access.dart';
import 'vendor_agreements.dart';
import 'strings.dart';
import 'vendor_forms.dart';
import 'vendor_operations_strings.dart';

bool _documentLicensesRegistered = false;
void registerVendorDocumentLicenses() {
  if (_documentLicensesRegistered) return;
  _documentLicensesRegistered = true;
  LicenseRegistry.addLicense(() async* {
    yield LicenseEntryWithLineBreaks(
      ['PDFium and bundled third-party components'],
      await rootBundle.loadString(
        'third_party/pdfium_flutter/PDFIUM_LICENSES.txt',
      ),
    );
  });
}

const vendorPermissions = [
  'company.read',
  'inventory.read',
  'inventory.write',
  'charging.read',
];

class VendorEvidence {
  final Uint8List bytes;
  final String mime;
  const VendorEvidence(this.bytes, this.mime);
}

String? vendorEvidenceMime(List<int> b) {
  if (b.length >= 5 && String.fromCharCodes(b.take(5)) == '%PDF-')
    return 'application/pdf';
  if (b.length >= 8 && b.take(8).join(',') == '137,80,78,71,13,10,26,10')
    return 'image/png';
  if (b.length >= 3 && b[0] == 255 && b[1] == 216 && b[2] == 255)
    return 'image/jpeg';
  return null;
}

Future<VendorEvidence?> pickVendorEvidence() async {
  try {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['pdf', 'png', 'jpg', 'jpeg'],
      allowMultiple: false,
      withData: false,
      withReadStream: true,
    );
    if (result == null) return null;
    final f = result.files.single;
    if (f.size <= 0 || f.size > 5 * 1024 * 1024)
      throw const ApiException(422, 'Unsupported document size.');
    final stream =
        f.readStream ?? (f.path == null ? null : File(f.path!).openRead());
    if (stream == null)
      throw const ApiException(422, 'Unable to read selected document.');
    final bytes = BytesBuilder(copy: false);
    await for (final chunk in stream) {
      if (bytes.length + chunk.length > 5 * 1024 * 1024)
        throw const ApiException(422, 'Unsupported document size.');
      bytes.add(chunk);
    }
    final data = bytes.takeBytes(), mime = vendorEvidenceMime(data);
    if (mime == null)
      throw const ApiException(422, 'Unsupported document type.');
    return VendorEvidence(data, mime);
  } finally {
    // Only the picker plugin's own app-sandbox cache, never original user files.
    try {
      await FilePicker.platform.clearTemporaryFiles();
    } catch (_) {}
  }
}

Future<bool> vendorConfirm(
  BuildContext context,
  String key, {
  List<String> details = const [],
}) => showDialog<bool>(
  context: context,
  builder: (c) => AlertDialog(
    title: Text(vendorOpsText(c, 'confirm')),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(vendorOpsText(c, key)),
          for (final detail in details) Text(detail),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(c, false),
        child: Text(vendorOpsText(c, 'cancel')),
      ),
      FilledButton(
        onPressed: () => Navigator.pop(c, true),
        child: Text(vendorOpsText(c, 'confirm')),
      ),
    ],
  ),
).then((v) => v ?? false);

class VendorCompanyView extends StatefulWidget {
  final EvApi api;
  final int company;
  final VoidCallback onExpired;
  const VendorCompanyView({
    super.key,
    required this.api,
    required this.company,
    required this.onExpired,
  });
  @override
  State<VendorCompanyView> createState() => _CompanyState();
}

class _CompanyState extends State<VendorCompanyView> {
  Map<String, dynamic>? company;
  Object? error;
  bool busy = false, uncertain = false;
  int generation = 0;
  String t(String k) => vendorOpsText(context, k);
  String get base => 'vendor-ops/companies/${widget.company}';
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  void fail(Object e) {
    setState(() {
      company = null;
      error = e;
    });
    if (e is ApiException && [401, 419].contains(e.status)) {
      widget.onExpired();
      Navigator.of(context).popUntil((r) => r.isFirst);
    }
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      company = null;
    });
    try {
      final r = await widget.api.request(base);
      if (mounted && epoch == generation)
        setState(() {
          company = Map<String, dynamic>.from(r as Map);
          uncertain = false;
        });
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> open(Widget page) async {
    await Navigator.of(
      context,
    ).push(MaterialPageRoute<void>(builder: (_) => page));
    if (mounted) load();
  }

  Future<void> submit() async {
    if (!await vendorConfirm(context, 'submitConsent') || !mounted) return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      await widget.api.request('$base/submit', method: 'POST', body: {});
      if (mounted && epoch == generation) await load();
    } catch (e) {
      if (mounted && epoch == generation) {
        fail(e);
        setState(() => uncertain = true);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = company,
        caps = c?['capabilities'] as Map? ?? {},
        editable =
            caps['owner'] == true &&
            ['draft', 'changes_requested', 'rejected'].contains(c?['status']);
    return Scaffold(
      appBar: AppBar(title: Text(t('company'))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(t('notice')),
            if (busy) const LinearProgressIndicator(),
            if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
            if (uncertain) Text(t('uncertain')),
            TextButton(
              onPressed: busy ? null : load,
              child: Text(t('refresh')),
            ),
            if (c != null) ...[
              Text(
                '${c['legal_name']}',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              Text(t('${c['status']}')),
              Text('${t('country')}: ${c['country']}'),
              Text(
                '${t('license_expires_on')}: ${(c['license_expires_on'] as String).substring(0, 10)}',
              ),
              if (caps['owner'] == true || caps['reviewer'] == true)
                TextButton(
                  onPressed: busy
                      ? null
                      : () => open(
                          RiderAccessView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            records: RiderRecords.operatorRequests,
                            companyId: c['id'] as int,
                          ),
                        ),
                  child: Text(riderTitle(context)),
                ),
              if (caps['owner'] == true) ...[
                TextButton(
                  onPressed: busy
                      ? null
                      : () => open(
                          VendorAgreementsView(
                            api: widget.api,
                            companyId: c['id'] as int,
                            onExpired: widget.onExpired,
                          ),
                        ),
                  child: Text(vendorAgreementTitle(context)),
                ),
                if (editable)
                  FilledButton(
                    onPressed: busy
                        ? null
                        : () => open(
                            VendorEditView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              kind: 'company',
                              company: c,
                              editing: true,
                            ),
                          ),
                    child: Text(t('editCompany')),
                  )
                else
                  Text(t('editLocked')),
                TextButton(
                  onPressed: busy
                      ? null
                      : () => open(
                          VendorRecordsView(
                            api: widget.api,
                            company: c,
                            kind: 'documents',
                            onExpired: widget.onExpired,
                          ),
                        ),
                  child: Text(t('documents')),
                ),
                TextButton(
                  onPressed: busy
                      ? null
                      : () => open(
                          VendorRecordsView(
                            api: widget.api,
                            company: c,
                            kind: 'reviews',
                            onExpired: widget.onExpired,
                          ),
                        ),
                  child: Text(t('reviews')),
                ),
                TextButton(
                  onPressed: busy
                      ? null
                      : () => open(
                          VendorRecordsView(
                            api: widget.api,
                            company: c,
                            kind: 'staff',
                            onExpired: widget.onExpired,
                          ),
                        ),
                  child: Text(t('staff')),
                ),
                if (editable &&
                    c['submission_readiness']?['trade_license_present'] != true)
                  Text(t('needLicenseEvidence')),
                if (editable &&
                    c['submission_readiness']?['license_valid'] != true)
                  Text(t('needValidLicense')),
                if (editable)
                  FilledButton(
                    onPressed:
                        busy ||
                            c['submission_readiness']?['trade_license_present'] !=
                                true ||
                            c['submission_readiness']?['license_valid'] != true
                        ? null
                        : submit,
                    child: Text(t('submit')),
                  ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

class VendorRecordsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final Map<String, dynamic>? company;
  final String kind;
  final Future<VendorEvidence?> Function()? pick;
  const VendorRecordsView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.kind,
    this.company,
    this.pick,
  });
  @override
  State<VendorRecordsView> createState() => _VendorRecordsState();
}

Set<String>? staffPermissionSet(dynamic raw) {
  if (raw is! List || raw.isEmpty || raw.any((p) => p is! String || !vendorPermissions.contains(p))) return null;
  final values = raw.cast<String>().toSet();
  return values.length == raw.length ? values : null;
}

bool staffPermissionChangeReceipt(Map<String,dynamic> original, Set<String> desired, dynamic receipt) {
  if (receipt is! Map || original['invitation_version'] is! int ||
      receipt['id'] is! int || receipt['id'] != original['id'] ||
      receipt['invitation_version'] is! int ||
      receipt['invitation_version'] != original['invitation_version'] + 1 ||
      receipt['status'] != 'invited' || receipt['requires_acceptance'] != true) return false;
  final scopes = staffPermissionSet(receipt['permissions']);
  return scopes != null && setEquals(scopes,desired);
}

bool staffAcceptanceReceipt(Map<String,dynamic> original,dynamic receipt) {
  if (receipt is! Map || receipt['id'] != original['id'] ||
      receipt['company_id'] != original['company_id'] ||
      receipt['user_id'] != original['user_id'] ||
      receipt['invitation_version'] != original['invitation_version'] ||
      receipt['status'] != 'active' || receipt['admin_revoked_at'] != null) return false;
  final expected = staffPermissionSet(original['permissions']);
  final actual = staffPermissionSet(receipt['permissions']);
  return expected != null && actual != null && setEquals(expected,actual);
}

class _VendorRecordsState extends State<VendorRecordsView> with WidgetsBindingObserver {
  late bool Function() authorized;
  StreamSubscription<int>? sessionSubscription;
  bool foreground = true, retired = false, confirming = false;
  bool get active => mounted && foreground && !retired && authorized();
  bool current(int epoch) => active && generation == epoch;
  bool get top => ModalRoute.of(context)?.isCurrent ?? true;
  void redact() {
    rows = null; currentCompany = null; error = null;
    notice = null; busy = false; uncertain = false; confirming = false;
  }
  ApiPage<Map<String, dynamic>>? rows;
  Map<String, dynamic>? currentCompany;
  Object? error;
  bool busy = false, uncertain = false;
  int page = 1, generation = 0;
  String? notice;
  String t(String k) => vendorOpsText(context, k);
  String get base => widget.kind == 'invitations'
      ? 'vendor-ops/staff-invitations'
      : 'vendor-ops/companies/${widget.company!['id']}/${widget.kind}';
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api,widget.api) && !authorized()) {
        setState(() { retired = true; redact(); });
      }
    });
    if (active) load();
  }

  @override
  void didUpdateWidget(covariant VendorRecordsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api,widget.api) || oldWidget.kind != widget.kind ||
        oldWidget.company?['id'] != widget.company?['id']) {
      generation++; retired = true; redact();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    final next = state == AppLifecycleState.resumed;
    if (next == foreground) return;
    setState(() { foreground = next; generation++; redact(); });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    super.dispose();
  }

  void handleFailure(Object e, int epoch) {
    if (current(epoch)) { fail(e); }
    else if (mounted && foreground && epoch == generation &&
        e is ApiException && e.endsCurrentSession) {
      widget.onExpired();
      if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
    }
  }

  void fail(Object e) {
    setState(() {
      rows = null;
      currentCompany = null;
      error = e;
    });
    if (e is ApiException && [401, 419].contains(e.status)) {
      widget.onExpired();
      if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
    }
  }

  Future<void> load() async {
    if (!active) return;
    final epoch = ++generation, api = widget.api;
    setState(() {
      busy = true;
      rows = null;
      currentCompany = null;
      error = null;
      confirming = false;
    });
    try {
      final company = widget.kind == 'invitations'
          ? null
          : Map<String, dynamic>.from(
              await api.request(
                    'vendor-ops/companies/${widget.company!['id']}',
                  )
                  as Map,
            );
      if (!current(epoch)) return;
      if (company != null && company['id'] != widget.company!['id'])
        throw const FormatException('Wrong company source.');
      final r = ApiPage.fromJson(
        await api.request(base, query: {'page': '$page'}),
        (r) => r,
      );
      if (!current(epoch)) return;
      final seen = <int>{};
      if (r.current != page || r.last < 1 || r.total < 0 || r.items.length > 25 ||
          r.items.any((row) => row['id'] is! int || row['id'] <= 0 ||
              !seen.add(row['id'] as int))) {
        throw const FormatException('Wrong private record page.');
      }
      if (current(epoch))
        setState(() {
          rows = r;
          currentCompany = company;
          uncertain = false;
        });
    } catch (e) {
      handleFailure(e,epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> action(Map<String, dynamic> row) async {
    if (!active || !top || busy || confirming || !(rows?.items.contains(row) ?? false) ||
        (widget.kind != 'invitations' && currentCompany?['capabilities']?['owner'] != true)) return;
    final reviewed = generation, api = widget.api;
    setState(() => confirming = true);
    bool accepted = false;
    try {
      accepted = await vendorConfirm(
          context,
          widget.kind == 'invitations' ? 'acceptConsent' : 'revokeConsent',
          details: widget.kind == 'invitations'
              ? [
                  '${row['company']?['legal_name'] ?? ''}',
                  for (final p in row['permissions'] as List) t('$p'),
                ]
              : const [],
        );
    } finally {
      if (current(reviewed)) setState(() => confirming = false);
    }
    if (!accepted || !current(reviewed) || !top || busy) return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      final receipt = await api.request(
        widget.kind == 'invitations'
            ? 'vendor-ops/staff-invitations/${row['id']}/accept'
            : '$base/${row['id']}',
        method: widget.kind == 'invitations' ? 'POST' : 'DELETE',
        body: widget.kind == 'invitations'
            ? {'expected_version': row['invitation_version']}
            : null,
      );
      if (!current(epoch)) return;
      if (widget.kind == 'invitations' && !staffAcceptanceReceipt(row,receipt))
        throw const FormatException('Unconfirmed invitation acceptance.');
      await load();
    } catch (e) {
      if (current(epoch)) uncertain = true;
      handleFailure(e,epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  bool canEdit(Map<String,dynamic> row) =>
      widget.kind == 'staff' && currentCompany?['capabilities']?['owner'] == true &&
      row['company_id'] == widget.company?['id'] &&
      ['invited','active'].contains(row['status']) &&
      row['admin_revoked_at'] == null && row['invitation_version'] is int &&
      row['invitation_version'] > 0 && staffPermissionSet(row['permissions']) != null;

  Future<void> editPermissions(Map<String,dynamic> row) async {
    if (!active || !top || busy || confirming || !canEdit(row) ||
        !(rows?.items.contains(row) ?? false)) return;
    final original = staffPermissionSet(row['permissions'])!;
    final desired = {...original};
    final reviewed = generation, api = widget.api, path = '$base/${row['id']}/permissions';
    setState(() => confirming = true);
    Set<String>? decision;
    try {
      decision = await showDialog<Set<String>>(
        context:context,
        builder:(dialog) => StatefulBuilder(builder:(dialog,update) => AlertDialog(
          title:Text(t('editPermissions')),
          content:SingleChildScrollView(child:Column(mainAxisSize:MainAxisSize.min,
            children:[
              Text(t('editPermissionsConsent')),
              for(final permission in vendorPermissions) CheckboxListTile(
                value:desired.contains(permission),
                title:Text(t(permission)),
                onChanged:(value) => update(() {
                  if(value == true) { desired.add(permission); }
                  else { desired.remove(permission); }
                }),
              ),
            ],
          )),
          actions:[
            TextButton(onPressed:()=>Navigator.pop(dialog),child:Text(t('cancel'))),
            FilledButton(
              onPressed:desired.isEmpty || setEquals(desired,original) ? null :
                ()=>Navigator.pop(dialog,Set<String>.of(desired)),
              child:Text(t('confirm')),
            ),
          ],
        )),
      );
    } finally {
      if (current(reviewed)) setState(() => confirming = false);
    }
    if (decision == null || !current(reviewed) || !top || busy || !canEdit(row)) return;
    final epoch = ++generation;
    setState(() { busy = true; error = null; uncertain = false; });
    try {
      final receipt = await api.request(path,method:'PATCH',body:{
        'expected_version':row['invitation_version'],
        'permissions':[for(final permission in vendorPermissions) if(decision.contains(permission)) permission],
      });
      if (!current(epoch)) return;
      if (!staffPermissionChangeReceipt(row,decision,receipt))
        throw const FormatException('Unconfirmed permission change.');
      await load();
    } catch(e) {
      if (current(epoch)) uncertain = true;
      handleFailure(e,epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> create() async {
    if (!active || !top || busy || confirming || currentCompany?['capabilities']?['owner'] != true) return;
    final epoch = generation;
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => !current(epoch) ? const Scaffold(body:SizedBox.shrink()) : widget.kind == 'staff'
            ? VendorInviteView(
                api: widget.api,
                company: widget.company!['id'] as int,
                onExpired: widget.onExpired,
              )
            : VendorEvidenceUpload(
                api: widget.api,
                company: widget.company!['id'] as int,
                onExpired: widget.onExpired,
                pick: widget.pick,
              ),
      ),
    );
    if (current(epoch) && top) {
      if (saved == true)
        setState(
          () =>
              notice = t(widget.kind == 'staff' ? 'inviteNotice' : 'uploaded'),
        );
      load();
    }
  }

  Future<void> preview(Map<String, dynamic> row) async {
    if (!active || !top || busy || confirming || !(rows?.items.contains(row) ?? false)) return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      final doc = await widget.api.downloadDocument('$base/${row['id']}');
      if (!current(epoch)) return;
      if (doc.bytes.length != row['size_bytes'])
        throw const ApiException(502, 'Document metadata changed.');
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => !current(epoch) ? const Scaffold(body:SizedBox.shrink()) : VendorDocumentPreview(document: doc,revokedSessionEvents:widget.api.sessionEvents),
        ),
      );
    } catch (e) {
      handleFailure(e,epoch);
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rendered = generation;
    VoidCallback guard(VoidCallback action) => () { if (current(rendered) && top && !busy && !confirming) action(); };
    if (!active) return Scaffold(appBar:AppBar(title:Text(t(widget.kind))),body:Center(child:Text(t('privateHidden'))));
    final editable = [
      'draft',
      'changes_requested',
      'rejected',
    ].contains(currentCompany?['status']);
    return Scaffold(
      appBar: AppBar(title: Text(t(widget.kind))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(t('notice')),
            if (busy) const LinearProgressIndicator(),
            if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
            if (uncertain) Text(t('uncertain')),
            if (notice != null) Text(notice!),
            if (currentCompany?['capabilities']?['owner'] == true &&
                (widget.kind == 'staff' ||
                    (widget.kind == 'documents' && editable)))
              FilledButton(
                onPressed: busy || confirming ? null : guard(create),
                child: Text(t(widget.kind == 'staff' ? 'invite' : 'upload')),
              ),
            if (rows?.items.isEmpty == true) Text(t('empty')),
            if (rows != null)
              for (final row in rows!.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (widget.kind == 'documents') ...[
                          Text(t('${row['kind']}')),
                          Text('${row['mime_type']} · ${row['size_bytes']} B'),
                          Text('${row['created_at']}'),
                          TextButton(
                            onPressed: busy || confirming ? null : guard(() => preview(row)),
                            child: Text(t('preview')),
                          ),
                        ] else if (widget.kind == 'reviews') ...[
                          Text(
                            '${t('${row['from_status']}')} → ${t('${row['to_status']}')}',
                          ),
                          Text('${row['reason']}'),
                          Text('${row['created_at']}'),
                        ] else ...[
                          Text(
                            widget.kind == 'invitations'
                                ? '${row['company']?['legal_name'] ?? ''}'
                                : '${t('member')} #${row['user_id']}',
                          ),
                          Text(t('${row['status']}')),
                          for (final p in row['permissions'] as List)
                            Text(t('$p')),
                          if (widget.kind == 'invitations')
                            FilledButton(
                              onPressed: busy || confirming ? null : guard(() => action(row)),
                              child: Text(t('accept')),
                            )
                          else if (row['status'] != 'revoked' && currentCompany?['capabilities']?['owner'] == true)
                            TextButton(
                              onPressed: busy || confirming ? null : guard(() => action(row)),
                              child: Text(t('revoke')),
                            ),
                          if (canEdit(row))
                            TextButton(
                              key:ValueKey('staff-permissions-${row['id']}'),
                              onPressed:busy || confirming ? null : guard(()=>editPermissions(row)),
                              child:Text(t('editPermissions')),
                            ),
                        ],
                      ],
                    ),
                  ),
                ),
            Wrap(
              children: [
                if (page > 1)
                  TextButton(
                    onPressed: busy
                        ? null
                        : guard(() { page--; load(); }),
                    child: Text(t('previous')),
                  ),
                if (rows != null && page < rows!.last)
                  TextButton(
                    onPressed: busy
                        ? null
                        : guard(() { page++; load(); }),
                    child: Text(t('next')),
                  ),
                TextButton(
                  onPressed: busy || confirming ? null : guard(load),
                  child: Text(t('refresh')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class VendorInviteView extends StatefulWidget {
  final EvApi api;
  final int company;
  final VoidCallback onExpired;
  const VendorInviteView({
    super.key,
    required this.api,
    required this.company,
    required this.onExpired,
  });
  @override
  State<VendorInviteView> createState() => _VendorInviteState();
}

class _VendorInviteState extends State<VendorInviteView> {
  final email = TextEditingController();
  final scopes = <String>{};
  Object? error;
  bool busy = false, uncertain = false;
  String t(String k) => vendorOpsText(context, k);
  @override
  void dispose() {
    email.dispose();
    super.dispose();
  }

  Future<void> send() async {
    if (!email.text.contains('@') || scopes.isEmpty) {
      setState(
        () =>
            error = const ApiException(422, 'Choose valid invitation details.'),
      );
      return;
    }
    setState(() => busy = true);
    try {
      await widget.api.request(
        'vendor-ops/companies/${widget.company}/staff',
        method: 'POST',
        body: {'email': email.text.trim(), 'permissions': scopes.toList()},
      );
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e;
          uncertain = e is! ApiException || e.status != 422;
        });
        if (e is ApiException && [401, 419].contains(e.status)) {
          email.clear();
          widget.onExpired();
          Navigator.of(context).popUntil((r) => r.isFirst);
        }
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('invite'))),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('inviteNotice')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          if (uncertain) ...[
            Text(t('uncertain')),
            TextButton(
              onPressed: busy ? null : () => Navigator.pop(context),
              child: Text(t('reviewExisting')),
            ),
          ],
          TextField(
            controller: email,
            enabled: !busy && !uncertain,
            keyboardType: TextInputType.emailAddress,
            maxLength: 254,
            decoration: InputDecoration(labelText: t('email')),
          ),
          Text(t('permissions')),
          for (final p in vendorPermissions)
            CheckboxListTile(
              value: scopes.contains(p),
              title: Text(t(p)),
              onChanged: busy || uncertain
                  ? null
                  : (v) => setState(
                      () => v == true ? scopes.add(p) : scopes.remove(p),
                    ),
            ),
          FilledButton(
            onPressed: busy || uncertain ? null : send,
            child: Text(t('invite')),
          ),
        ],
      ),
    ),
  );
}

class VendorEvidenceUpload extends StatefulWidget {
  final EvApi api;
  final int company;
  final VoidCallback onExpired;
  final Future<VendorEvidence?> Function()? pick;
  const VendorEvidenceUpload({
    super.key,
    required this.api,
    required this.company,
    required this.onExpired,
    this.pick,
  });
  @override
  State<VendorEvidenceUpload> createState() => _EvidenceUploadState();
}

class _EvidenceUploadState extends State<VendorEvidenceUpload> {
  final key = operationKey();
  VendorEvidence? evidence;
  String kind = 'trade_license';
  bool consent = false, busy = false, uncertain = false;
  Object? error;
  int generation = 0;
  String t(String k) => vendorOpsText(context, k);
  @override
  void dispose() {
    generation++;
    evidence = null;
    super.dispose();
  }

  Future<void> pick() async {
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      final e = await (widget.pick?.call() ?? pickVendorEvidence());
      if (mounted && epoch == generation) setState(() => evidence = e);
    } catch (e) {
      if (mounted && epoch == generation) setState(() => error = e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> upload() async {
    if (!consent || evidence == null) return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      await widget.api.uploadDocument(
        'vendor-ops/companies/${widget.company}/documents',
        kind: kind,
        bytes: evidence!.bytes,
        mime: evidence!.mime,
        key: key,
      );
      if (mounted && epoch == generation) Navigator.pop(context, true);
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() {
          error = e;
          uncertain = e is! ApiException || e.status != 422;
          evidence = null;
        });
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
          Navigator.of(context).popUntil((r) => r.isFirst);
        }
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('upload'))),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('fileLimit')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          if (uncertain) ...[
            Text(t('uncertain')),
            TextButton(
              onPressed: busy ? null : () => Navigator.pop(context),
              child: Text(t('reviewExisting')),
            ),
          ],
          DropdownButtonFormField<String>(
            initialValue: kind,
            decoration: InputDecoration(labelText: t('kind')),
            items: ['trade_license', 'identity', 'agreement']
                .map((k) => DropdownMenuItem(value: k, child: Text(t(k))))
                .toList(),
            onChanged: busy || uncertain
                ? null
                : (v) => setState(() => kind = v!),
          ),
          TextButton(
            onPressed: busy || uncertain ? null : pick,
            child: Text(t('pick')),
          ),
          if (evidence != null)
            Text(
              '${t('picked')}: ${evidence!.mime} · ${evidence!.bytes.length} B',
            ),
          CheckboxListTile(
            value: consent,
            title: Text(t('uploadConsent')),
            onChanged: busy || uncertain
                ? null
                : (v) => setState(() => consent = v == true),
          ),
          FilledButton(
            onPressed: busy || uncertain || !consent || evidence == null
                ? null
                : upload,
            child: Text(t('upload')),
          ),
        ],
      ),
    ),
  );
}

/// Probe a bounded native raster before declaring a private PDF preview ready.
/// The sample never enters an image cache and is always released.
Future<bool> privatePdfRasterReady(PdfDocument document) async {
  PdfImage? sample;
  try {
    if (document.pages.isEmpty) return false;
    sample = await document.pages.first.render(fullWidth: 256, fullHeight: 256);
    return sample != null && sample.pixels.isNotEmpty;
  } catch (_) {
    return false;
  } finally {
    sample?.dispose();
  }
}

class VendorDocumentPreview extends StatefulWidget {
  final PrivateDocument document;
  final String? title, notice;
  // Optional, non-secret native auth generation events. When the account
  // changes while a private PDF is already open, remove its renderer
  // immediately instead of leaving a once-valid document on screen.
  final Stream<int>? revokedSessionEvents;
  const VendorDocumentPreview({
    super.key,
    required this.document,
    this.title,
    this.notice,
    this.revokedSessionEvents,
  });
  @override
  State<VendorDocumentPreview> createState() => _DocumentPreviewState();
}

class _DocumentPreviewState extends State<VendorDocumentPreview>
    with WidgetsBindingObserver {
  bool visible = true, pdfReady = false, pdfFailed = false, verifying = false;
  StreamSubscription<int>? revocation;
  final name = operationKey();

  void observe() {
    revocation?.cancel();
    revocation = widget.revokedSessionEvents?.listen((_) {
      if (!mounted) return;
      image?.evict();
      image = null;
      setState(() {
        visible = false;
        pdfReady = false;
        pdfFailed = false;
      });
      // Do not retain a private viewer route after logout. Deferring
      // navigation until after the redraw prevents setState during build.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
        }
      });
    });
  }
  ImageProvider? image;
  // pdfrx 2.2.24's load-finished event is skipped when all pages were
  // available during the initial native open. Verify actual raster capability
  // after viewer initialization instead of depending on that event.
  Future<void> verifyPdf(PdfDocument document) async {
    if (!mounted || !visible || pdfReady || verifying) return;
    verifying = true;
    try {
      final success = await privatePdfRasterReady(document);
      if (mounted && visible) {
        setState(() {
          pdfReady = success;
          pdfFailed = !success;
        });
      }
    } finally {
      verifying = false;
    }
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe();
    if (widget.document.mime != 'application/pdf')
      image = ResizeImage(
        MemoryImage(widget.document.bytes),
        width: 2048,
        height: 2048,
        policy: ResizeImagePolicy.fit,
      );
  }

  @override
  void didUpdateWidget(covariant VendorDocumentPreview oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.revokedSessionEvents,
        widget.revokedSessionEvents)) observe();
  }

  @override
  void dispose() {
    revocation?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    image?.evict();
    image = null;
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      image?.evict();
      setState(() => visible = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(widget.title ?? vendorOpsText(context, 'preview')),
    ),
    body: SafeArea(
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              widget.notice ?? vendorOpsText(context, 'previewNotice'),
            ),
          ),
          if (visible && pdfFailed)
            Text(
              vendorOpsText(context, 'previewFailed'),
              key: const Key('private-document-failed'),
            ),
          if (visible && pdfReady)
            Text(
              vendorOpsText(context, 'previewReady'),
              key: const Key('private-document-ready'),
            ),
          Expanded(
            child: !visible
                ? Center(
                    child: TextButton(
                      onPressed: () => Navigator.pop(context),
                      child: Text(vendorOpsText(context, 'cancel')),
                    ),
                  )
                : widget.document.mime == 'application/pdf'
                ? PdfViewer.data(
                    widget.document.bytes,
                    sourceName: name,
                    params: PdfViewerParams(
                      maxImageBytesCachedOnMemory: 16 * 1024 * 1024,
                      onViewerReady: (document, controller) =>
                          verifyPdf(document),
                      onDocumentLoadFinished: (ref, success) {
                        if (!success && mounted && visible)
                          setState(() => pdfFailed = true);
                      },
                      linkHandlerParams: PdfLinkHandlerParams(
                        onLinkTap: (_) {},
                      ),
                      errorBannerBuilder: (c, e, s, r) => Center(
                        child: Text(
                          vendorOpsText(c, 'previewFailed'),
                          key: const Key('private-document-failed'),
                        ),
                      ),
                    ),
                  )
                : InteractiveViewer(
                    child: Image(
                      image: image!,
                      fit: BoxFit.contain,
                      errorBuilder: (c, e, s) =>
                          Text(vendorOpsText(c, 'previewFailed')),
                    ),
                  ),
          ),
        ],
      ),
    ),
  );
}
