import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

class RecoveryLink {
  final String email, token;
  const RecoveryLink(this.email, this.token);
  static RecoveryLink? parse(String text, Uri origin) {
    if (text.length > 4096 || text.trim() != text ||
        RegExp(r'^[A-Za-z][A-Za-z0-9+.-]*://[^/?#]*@').hasMatch(text)) return null;
    final uri = Uri.tryParse(text);
    if (uri == null ||
        !uri.hasScheme ||
        !uri.hasAuthority ||
        !['https', 'http'].contains(uri.scheme) ||
        uri.origin != origin.origin ||
        uri.authority.contains('@') ||
        uri.hasFragment ||
        (uri.scheme != 'https' &&
            !['localhost', '127.0.0.1', '::1'].contains(uri.host)))
      return null;
    final parts = uri.pathSegments;
    if (parts.length != 2 ||
        parts.first != 'reset-password' ||
        !RegExp(r'^[a-fA-F0-9]{64}$').hasMatch(parts.last) ||
        uri.queryParametersAll.length != 1 ||
        uri.queryParametersAll['email']?.length != 1)
      return null;
    final email = uri.queryParameters['email']!;
    if (email.length > 254 ||
        !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email))
      return null;
    return RecoveryLink(email, parts.last);
  }
}

/// Explicit pasted link only: no clipboard read, URL launch or persisted secret.
class AccountRecoveryView extends StatefulWidget {
  final EvApi api;
  final String initialEmail;
  const AccountRecoveryView({
    super.key,
    required this.api,
    this.initialEmail = '',
  });
  @override
  State<AccountRecoveryView> createState() => _AccountRecoveryViewState();
}

class _AccountRecoveryViewState extends State<AccountRecoveryView>
    with WidgetsBindingObserver {
  final email = TextEditingController(),
      link = TextEditingController(),
      password = TextEditingController(),
      confirmation = TextEditingController();
  late bool Function() owner;
  bool busy = false, sent = false, complete = false, hidden = false;
  int generation = 0;
  String? error;
  String tr(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  @override
  void initState() {
    super.initState();
    email.text = widget.initialEmail;
    owner = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    // This view handles pasted reset tokens; never expose its secret inputs
    // if first mounted while the operating system considers it inactive.
    hidden = lifecycle != null && lifecycle != AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didUpdateWidget(covariant AccountRecoveryView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.initialEmail != widget.initialEmail || !owner()) {
      // The same EvApi may be reused after reauthentication. A reset link,
      // password and previous acknowledgement belong to one actor only.
      generation++;
      clearSecrets();
      email
        ..clear()
        ..text = widget.initialEmail;
      owner = widget.api.sessionFence();
      sent = complete = busy = false;
      error = null;
    }
  }

  void clearSecrets() {
    link.clear();
    password.clear();
    confirmation.clear();
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    for (final c in [email, link, password, confirmation]) {
      c.clear();
      c.dispose();
    }
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    generation++;
    clearSecrets();
    setState(() {
      hidden = state != AppLifecycleState.resumed;
      busy = false;
      error = null;
    });
  }

  Future<void> submit(bool reset) async {
    if (busy || hidden || !owner()) return;
    final parsed = reset
        ? RecoveryLink.parse(link.text, widget.api.base)
        : null;
    final address = email.text.trim();
    if (reset
        ? parsed == null ||
              password.text.length < 12 ||
              password.text.length > 128 ||
              password.text != confirmation.text
        : address.length > 254 ||
              !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(address)) {
      setState(() => error = 'validation');
      return;
    }
    final body = reset
        ? <String, dynamic>{
            'email': parsed!.email,
            'token': parsed.token,
            'password': password.text,
            'password_confirmation': confirmation.text,
          }
        : <String, dynamic>{'email': address};
    final run = ++generation;
    bool current() => mounted && !hidden && run == generation && owner();
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.csrf();
      if (!current()) return;
      await widget.api.request(
        reset ? 'reset-password' : 'forgot-password',
        method: 'POST',
        body: body,
      );
      if (!current()) return;
      setState(() {
        if (reset) {
          complete = true;
          clearSecrets();
          email.clear();
        } else {
          sent = true;
        }
      });
    } catch (e) {
      if (current())
        setState(() {
          error = e is ApiException && e.status == 429
              ? 'limited'
              : reset
              ? 'reset'
              : 'network';
          if (reset) clearSecrets();
        });
    } finally {
      body.clear();
      if (current()) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final stale = !owner();
    if (stale) clearSecrets();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Account recovery', 'استعادة الحساب'))),
      body: hidden || stale
          ? Center(
              child: Text(
                tr(
                  'Return to sign in to continue.',
                  'ارجع إلى تسجيل الدخول للمتابعة.',
                ),
              ),
            )
          : ListView(
              padding: const EdgeInsets.all(24),
              children: [
                if (complete) ...[
                  Text(
                    tr(
                      'Password changed. Sign in with your new password. Existing sessions were revoked; enabled two-factor authentication remains required.',
                      'تم تغيير كلمة المرور. سجّل الدخول بكلمة المرور الجديدة. أُلغيت الجلسات السابقة؛ وتبقى المصادقة الثنائية مطلوبة عند تفعيلها.',
                    ),
                  ),
                  FilledButton(
                    key: const Key('recovery-done'),
                    onPressed: () => Navigator.of(context).pop(),
                    child: Text(
                      tr('Return to sign in', 'العودة لتسجيل الدخول'),
                    ),
                  ),
                ] else ...[
                  Text(
                    tr(
                      'Request an email, then paste its complete reset link here. Only links from this configured service are accepted. Links and passwords are never saved or opened externally.',
                      'اطلب رسالة بريد، ثم الصق رابط الاستعادة الكامل هنا. تُقبل روابط هذه الخدمة المهيأة فقط. لا تُحفظ الروابط وكلمات المرور ولا تُفتح خارجياً.',
                    ),
                  ),
                  TextField(
                    key: const Key('recovery-email'),
                    controller: email,
                    enabled: !busy,
                    keyboardType: TextInputType.emailAddress,
                    textDirection: TextDirection.ltr,
                    decoration: InputDecoration(
                      labelText: tr('Email', 'البريد الإلكتروني'),
                    ),
                  ),
                  OutlinedButton(
                    key: const Key('recovery-send'),
                    onPressed: busy ? null : () => submit(false),
                    child: Text(
                      tr('Send recovery email', 'إرسال رسالة الاستعادة'),
                    ),
                  ),
                  if (sent)
                    Text(
                      tr(
                        'If eligible, a reset link has been sent. Check your email. Delivery may take time.',
                        'إذا كان الحساب مؤهلاً، أُرسل رابط استعادة. تحقق من بريدك. قد يستغرق التسليم وقتاً.',
                      ),
                    ),
                  const Divider(),
                  TextField(
                    key: const Key('recovery-link'),
                    controller: link,
                    enabled: !busy,
                    autocorrect: false,
                    enableSuggestions: false,
                    enableIMEPersonalizedLearning: false,
                    obscureText: true,
                    textDirection: TextDirection.ltr,
                    maxLength: 4096,
                    decoration: InputDecoration(
                      labelText: tr(
                        'Complete reset link from email',
                        'رابط الاستعادة الكامل من البريد',
                      ),
                    ),
                  ),
                  for (final pair in [
                    (
                      password,
                      'recovery-password',
                      tr(
                        'New password (12–128 characters)',
                        'كلمة مرور جديدة (١٢–١٢٨ حرفاً)',
                      ),
                    ),
                    (
                      confirmation,
                      'recovery-confirmation',
                      tr('Confirm new password', 'تأكيد كلمة المرور الجديدة'),
                    ),
                  ])
                    TextField(
                      key: Key(pair.$2),
                      controller: pair.$1,
                      enabled: !busy,
                      obscureText: true,
                      autocorrect: false,
                      enableSuggestions: false,
                      enableIMEPersonalizedLearning: false,
                      maxLength: 128,
                      decoration: InputDecoration(labelText: pair.$3),
                    ),
                  FilledButton(
                    key: const Key('recovery-reset'),
                    onPressed: busy ? null : () => submit(true),
                    child: Text(tr('Change password', 'تغيير كلمة المرور')),
                  ),
                ],
                if (busy) const LinearProgressIndicator(),
                if (error != null)
                  Semantics(
                    liveRegion: true,
                    child: Text(
                      error == 'limited'
                          ? tr(
                              'Too many requests. Wait before trying again.',
                              'طلبات كثيرة. انتظر قبل المحاولة مجدداً.',
                            )
                          : error == 'validation'
                          ? tr(
                              'Enter a valid service reset link and matching 12–128 character passwords, or a valid email to request a link.',
                              'أدخل رابط استعادة صالحاً لهذه الخدمة وكلمتي مرور متطابقتين من ١٢–١٢٨ حرفاً، أو بريداً صالحاً لطلب رابط.',
                            )
                          : error == 'reset'
                          ? tr(
                              'Reset was not confirmed. The link may be expired or already used. Request a new email before trying again.',
                              'لم يتم تأكيد الاستعادة. قد يكون الرابط منتهياً أو مستخدماً. اطلب رسالة جديدة قبل المحاولة مجدداً.',
                            )
                          : tr(
                              'Request was not confirmed. Check your connection before trying again.',
                              'لم يتم تأكيد الطلب. تحقق من الاتصال قبل المحاولة مجدداً.',
                            ),
                    ),
                  ),
              ],
            ),
    );
  }
}
