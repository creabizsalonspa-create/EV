import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'discovery_client.dart';
import 'strings.dart';

class AddressSearch extends StatefulWidget {
  final DiscoveryClient client;
  final void Function(GeoPlace) onSelected;
  final String? country;
  const AddressSearch({
    super.key,
    required this.client,
    required this.onSelected,
    this.country,
  });
  @override
  State<AddressSearch> createState() => _AddressSearchState();
}

class _AddressSearchState extends State<AddressSearch> {
  final query = TextEditingController();
  Timer? timer;
  int generation = 0;
  bool consent = false, loading = false;
  List<GeoPlace> places = [];
  Object? error;
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  void changed(String _) {
    timer?.cancel();
    generation++;
    setState(() {
      places = [];
      error = null;
      loading = false;
    });
    if (consent && query.text.trim().length >= 3 && query.text.length <= 180)
      timer = Timer(const Duration(milliseconds: 600), lookup);
  }

  Future<void> lookup() async {
    timer?.cancel();
    if (loading) return;
    final text = query.text.trim();
    if (!consent || text.length < 3 || text.length > 180) return;
    final current = ++generation;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await widget.client.addresses(
        text,
        EvStrings.of(context).arabic ? 'ar' : 'en',
        country: widget.country,
      );
      if (mounted && current == generation) setState(() => places = result);
    } catch (e) {
      if (mounted && current == generation) setState(() => error = e);
    } finally {
      if (mounted && current == generation) setState(() => loading = false);
    }
  }

  @override
  void dispose() {
    generation++;
    timer?.cancel();
    query.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        value: consent,
        onChanged: (value) {
          consent = value ?? false;
          changed(query.text);
        },
        title: Text(
          t(
            'Allow typed addresses to be sent to Geoapify. Queries are not saved as preferences.',
            'السماح بإرسال العناوين المكتوبة إلى Geoapify. لا تُحفظ عمليات البحث كتفضيلات.',
          ),
        ),
      ),
      TextField(
        key: const Key('address-query'),
        controller: query,
        enabled: consent,
        maxLength: 180,
        onChanged: changed,
        onSubmitted: (_) => lookup(),
        decoration: InputDecoration(
          labelText: t(
            'Search an address (at least 3 characters)',
            'البحث عن عنوان (٣ أحرف على الأقل)',
          ),
          prefixIcon: const Icon(Icons.search),
        ),
      ),
      if (loading) const LinearProgressIndicator(),
      if (error != null)
        Semantics(
          liveRegion: true,
          child: Text(
            error is ApiException && (error as ApiException).status == 429
                ? t(
                    'Search limit reached. Wait before another lookup.',
                    'تم بلوغ حد البحث. انتظر قبل محاولة أخرى.',
                  )
                : t(
                    'Address lookup unavailable. Use GPS or manual coordinates.',
                    'البحث عن العنوان غير متاح. استخدم الموقع أو الإحداثيات اليدوية.',
                  ),
          ),
        ),
      for (final place in places)
        ListTile(
          title: Text(place.label),
          subtitle: Text(
            t(
              'Search centre only; not a charging station.',
              'مركز بحث فقط؛ ليس محطة شحن.',
            ),
          ),
          trailing: const Icon(Icons.arrow_forward),
          onTap: () {
            generation++;
            timer?.cancel();
            setState(() => places = []);
            widget.onSelected(place);
          },
        ),
    ],
  );
}
