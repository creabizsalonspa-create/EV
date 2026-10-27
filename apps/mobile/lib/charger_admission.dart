import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

String admissionText(BuildContext context, String key) =>
    (Localizations.localeOf(context).languageCode == 'ar'
        ? admissionAr
        : admissionEn)[key] ??
    key;
const admissionEn = {
  'title': 'Secure charger admission',
  'notice':
      'Admission credentials only. CSMS registration, physical charging and hardware acceptance are not verified.',
  'identity': 'Charger identity',
  'pin': 'Certificate SHA-256 (optional)',
  'prepare': 'Prepare identity',
  'rotate': 'Issue / rotate credential',
  'rotation':
      'The previous credential becomes invalid immediately. The new secret is shown once for 30 seconds. If the response is lost, refresh and explicitly rotate again.',
  'revoke': 'Revoke credential',
  'revocation':
      'Deny new connections and close connections at the next ingress recheck. This does not stop physical charging.',
  'secret': 'One-time secret — save securely now',
  'hide': 'Hide secret',
  'version': 'Credential version',
  'expires': 'Expires (UTC)',
  'enabled': 'Admission enabled',
  'disabled': 'Admission disabled',
  'refresh': 'Refresh admission',
};
const admissionAr = {
  'title': 'قبول الشاحن الآمن',
  'notice':
      'بيانات قبول الاتصال فقط. لم يُتحقق من تسجيل CSMS أو الشحن الفعلي أو قبول الأجهزة.',
  'identity': 'هوية الشاحن',
  'pin': 'بصمة الشهادة SHA-256 (اختياري)',
  'prepare': 'إعداد الهوية',
  'rotate': 'إصدار / تدوير بيانات الدخول',
  'rotation':
      'تصبح بيانات الدخول السابقة غير صالحة فوراً. يظهر السر الجديد مرة واحدة لمدة 30 ثانية. عند فقد الرد حدّث البيانات ودوّرها مجدداً بشكل صريح.',
  'revoke': 'إبطال بيانات الدخول',
  'revocation':
      'يُمنع الاتصال الجديد ويُغلق الاتصال عند فحص البوابة التالي. هذا لا يوقف الشحن الفعلي.',
  'secret': 'سر لمرة واحدة — احفظه بأمان الآن',
  'hide': 'إخفاء السر',
  'version': 'إصدار بيانات الدخول',
  'expires': 'انتهاء الصلاحية (UTC)',
  'enabled': 'قبول الاتصال مفعّل',
  'disabled': 'قبول الاتصال معطّل',
  'refresh': 'تحديث القبول',
};

class ChargerAdmissionView extends StatefulWidget {
  final EvApi api;
  final Map<String, dynamic> company, charger;
  final VoidCallback onExpired;
  const ChargerAdmissionView({
    super.key,
    required this.api,
    required this.company,
    required this.charger,
    required this.onExpired,
  });
  @override
  State<ChargerAdmissionView> createState() => _ChargerAdmissionState();
}

class _ChargerAdmissionState extends State<ChargerAdmissionView> {
  Map<String, dynamic>? data;
  Object? error;
  bool busy = false;
  String? secret;
  Timer? timer;
  String get base =>
      'vendor-ops/companies/${widget.company['id']}/chargers/${widget.charger['id']}/admission';
  String t(String k) => admissionText(context, k);
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    timer?.cancel();
    secret = null;
    super.dispose();
  }

  void fail(Object e) {
    if (!mounted) return;
    setState(() {
      error = e;
      data = null;
      secret = null;
    });
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load() async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
      secret = null;
    });
    timer?.cancel();
    try {
      final value = await widget.api.request(base);
      if (mounted) setState(() => data = Map<String, dynamic>.from(value));
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> prepare() async {
    final ok = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _PrepareAdmission(
        api: widget.api,
        base: base,
        onExpired: widget.onExpired,
      ),
    );
    if (mounted && ok == true) await load();
  }

  Future<void> change(bool revoke) async {
    if (busy || data?['profile'] == null) return;
    final version = data!['profile']['credential_version'];
    if (!await showDialog<bool>(
          context: context,
          builder: (c) => AlertDialog(
            title: Text(t(revoke ? 'revoke' : 'rotate')),
            content: Text(t(revoke ? 'revocation' : 'rotation')),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(c, false),
                child: Text(EvStrings.of(c).t('cancel')),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(c, true),
                child: Text(EvStrings.of(c).t('confirm')),
              ),
            ],
          ),
        ).then((v) => v ?? false) ||
        !mounted)
      return;
    setState(() {
      busy = true;
      error = null;
      secret = null;
    });
    timer?.cancel();
    try {
      final result = await widget.api.request(
        '$base/credential',
        method: revoke ? 'DELETE' : 'POST',
        body: revoke ? null : {'expected_version': version, 'days': 30},
      );
      if (!mounted) return;
      setState(() {
        data = {
          'profile': revoke ? result : result['profile'],
          'can_manage': true,
        };
        secret = revoke ? null : result['secret'] as String;
      });
      if (secret != null)
        timer = Timer(const Duration(seconds: 30), () {
          if (mounted) setState(() => secret = null);
        });
    } catch (e) {
      fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = data?['profile'];
    final s = EvStrings.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(t('title'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(s.t(errorKey(error!))),
          TextButton(onPressed: busy ? null : load, child: Text(t('refresh'))),
          if (data != null && p == null && data!['can_manage'] == true)
            FilledButton(
              onPressed: busy ? null : prepare,
              child: Text(t('prepare')),
            ),
          if (p != null) ...[
            Text('${p['identity']} · OCPP ${p['protocol']}'),
            Text('${t('version')}: ${p['credential_version']}'),
            Text(t(p['enabled'] == true ? 'enabled' : 'disabled')),
            if (p['expires_at'] != null)
              Text('${t('expires')}: ${p['expires_at']}'),
            if (data!['can_manage'] == true) ...[
              FilledButton(
                onPressed: busy ? null : () => change(false),
                child: Text(t('rotate')),
              ),
              OutlinedButton(
                onPressed: busy ? null : () => change(true),
                child: Text(t('revoke')),
              ),
            ],
          ],
          if (secret != null) ...[
            Text(t('secret')),
            SelectableText(secret!, key: const Key('charger-one-time-secret')),
            TextButton(
              onPressed: () {
                timer?.cancel();
                setState(() => secret = null);
              },
              child: Text(t('hide')),
            ),
          ],
        ],
      ),
    );
  }
}

class _PrepareAdmission extends StatefulWidget {
  final EvApi api;
  final String base;
  final VoidCallback onExpired;
  const _PrepareAdmission({
    required this.api,
    required this.base,
    required this.onExpired,
  });
  @override
  State<_PrepareAdmission> createState() => _PrepareAdmissionState();
}

class _PrepareAdmissionState extends State<_PrepareAdmission> {
  final identity = TextEditingController(), pin = TextEditingController();
  final form = GlobalKey<FormState>();
  bool busy = false;
  Object? error;
  @override
  void dispose() {
    identity.dispose();
    pin.dispose();
    super.dispose();
  }

  Future<void> save() async {
    if (!form.currentState!.validate() || busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        widget.base,
        method: 'POST',
        body: {
          'identity': identity.text.trim(),
          'certificate_sha256': pin.text.trim().isEmpty
              ? null
              : pin.text.trim(),
        },
      );
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    return PopScope(
      canPop: !busy,
      child: AlertDialog(
        title: Text(admissionText(context, 'prepare')),
        content: SingleChildScrollView(
          child: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (busy) const LinearProgressIndicator(),
                if (error != null) Text(s.t(errorKey(error!))),
                TextFormField(
                  controller: identity,
                  enabled: !busy,
                  maxLength: 100,
                  decoration: InputDecoration(
                    labelText: admissionText(context, 'identity'),
                  ),
                  validator: (v) =>
                      RegExp(
                        r'^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$',
                      ).hasMatch(v!.trim())
                      ? null
                      : s.t('required'),
                ),
                TextFormField(
                  controller: pin,
                  enabled: !busy,
                  maxLength: 64,
                  decoration: InputDecoration(
                    labelText: admissionText(context, 'pin'),
                  ),
                  validator: (v) =>
                      v!.trim().isEmpty ||
                          RegExp(r'^[a-f0-9]{64}$').hasMatch(v.trim())
                      ? null
                      : s.t('required'),
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: busy ? null : () => Navigator.pop(context, false),
            child: Text(s.t('cancel')),
          ),
          FilledButton(onPressed: busy ? null : save, child: Text(s.t('save'))),
        ],
      ),
    );
  }
}
