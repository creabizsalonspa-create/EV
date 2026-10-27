import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

class InputSpec {
  final String key;
  final String label;
  final String initial;
  final int maxLength;
  final bool numeric;
  final Map<String, String>? choices;
  final String? Function(String, EvStrings)? validate;
  const InputSpec(
    this.key,
    this.label, {
    this.initial = '',
    this.maxLength = 150,
    this.numeric = false,
    this.choices,
    this.validate,
  });
}

Future<dynamic> apiForm(
  BuildContext context, {
  required String title,
  required List<InputSpec> fields,
  required Future<dynamic> Function(Map<String, dynamic>) submit,
  required VoidCallback onExpired,
}) => showDialog<dynamic>(
  context: context,
  barrierDismissible: false,
  builder: (_) => _ApiForm(
    title: title,
    fields: fields,
    submit: submit,
    onExpired: onExpired,
  ),
);

class _ApiForm extends StatefulWidget {
  final String title;
  final List<InputSpec> fields;
  final Future<dynamic> Function(Map<String, dynamic>) submit;
  final VoidCallback onExpired;
  const _ApiForm({
    required this.title,
    required this.fields,
    required this.submit,
    required this.onExpired,
  });
  @override
  State<_ApiForm> createState() => _ApiFormState();
}

class _ApiFormState extends State<_ApiForm> {
  final form = GlobalKey<FormState>();
  late final Map<String, TextEditingController> controls;
  Object? error;
  bool busy = false;
  @override
  void initState() {
    super.initState();
    controls = {
      for (final f in widget.fields)
        f.key: TextEditingController(text: f.initial),
    };
  }

  Future<void> save() async {
    if (busy || !form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.submit({
        for (final e in controls.entries) e.key: e.value.text.trim(),
      });
      if (mounted) Navigator.pop(context, result ?? true);
    } catch (e) {
      if (mounted && e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
      if (mounted) setState(() => error = e);
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
        title: Text(s.t(widget.title)),
        content: SizedBox(
          width: 420,
          child: SingleChildScrollView(
            child: Form(
              key: form,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (busy) const LinearProgressIndicator(),
                  if (error != null)
                    Text(s.t(errorKey(error!)), key: const Key('action-error')),
                  for (final field in widget.fields)
                    Padding(
                      padding: const EdgeInsets.only(top: 14),
                      child: field.choices != null
                          ? DropdownButtonFormField<String>(
                              key: ValueKey(field.key),
                              initialValue: field.initial.isEmpty
                                  ? null
                                  : field.initial,
                              isExpanded: true,
                              decoration: InputDecoration(
                                labelText: s.t(field.label),
                              ),
                              items: field.choices!.entries
                                  .map(
                                    (e) => DropdownMenuItem(
                                      value: e.key,
                                      child: Text(e.value),
                                    ),
                                  )
                                  .toList(),
                              onChanged: busy
                                  ? null
                                  : (v) => controls[field.key]!.text = v!,
                              validator: (v) => v == null || v.isEmpty
                                  ? s.t('required')
                                  : null,
                            )
                          : TextFormField(
                              key: ValueKey(field.key),
                              controller: controls[field.key],
                              enabled: !busy,
                              keyboardType: field.numeric
                                  ? TextInputType.number
                                  : field.key == 'email'
                                  ? TextInputType.emailAddress
                                  : TextInputType.text,
                              maxLength: field.maxLength,
                              decoration: InputDecoration(
                                labelText: s.t(field.label),
                              ),
                              validator: (v) => v!.trim().isEmpty
                                  ? s.t('required')
                                  : field.validate?.call(v.trim(), s),
                            ),
                    ),
                ],
              ),
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: busy ? null : () => Navigator.pop(context),
            child: Text(s.t('cancel')),
          ),
          FilledButton(onPressed: busy ? null : save, child: Text(s.t('save'))),
        ],
      ),
    );
  }

  @override
  void dispose() {
    for (final c in controls.values) c.dispose();
    super.dispose();
  }
}

Future<bool> confirmAction(
  BuildContext context,
  String title,
  String body,
) async =>
    await showDialog<bool>(
      context: context,
      builder: (context) {
        final s = EvStrings.of(context);
        return AlertDialog(
          title: Text(s.t(title)),
          content: Text(s.t(body)),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: Text(s.t('cancel')),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: Text(s.t('confirm')),
            ),
          ],
        );
      },
    ) ??
    false;
