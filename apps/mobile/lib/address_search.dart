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

class _AddressSearchState extends State<AddressSearch> with WidgetsBindingObserver {
  final query = TextEditingController();
  Timer? timer;
  StreamSubscription<int>? sessionSubscription;
  int generation = 0;
  int consentEpoch = 0;
  late bool foreground;
  bool consent = false, loading = false, empty = false;
  List<GeoPlace> places = [];
  Object? error;
  String? language;
  bool get active => mounted && foreground && widget.client.currentSession();
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
  }

  void subscribe() {
    final client = widget.client;
    sessionSubscription = client.sessionEvents?.listen((_) {
      if (mounted && identical(widget.client,client) && !client.currentSession()) {
        setState(revoke);
      }
    });
  }

  void resetResults() {
    timer?.cancel();
    generation++;
    places = [];
    error = null;
    empty = false;
    loading = false;
  }

  void revoke() {
    consentEpoch++;
    consent = false;
    resetResults();
    query.clear();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final next = EvStrings.of(context).arabic ? 'ar' : 'en';
    if (language != null && next != language) revoke();
    language = next;
  }

  @override
  void didUpdateWidget(covariant AddressSearch oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.client,widget.client)) {
      unawaited(sessionSubscription?.cancel());
      revoke();
      subscribe();
    } else if (oldWidget.country != widget.country || !widget.client.currentSession()) {
      revoke();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (!mounted || foreground == resumed) return;
    setState(() {
      foreground = resumed;
      if (!resumed) revoke();
    });
  }

  void changed(String _, int epoch) {
    if (!active || !consent || epoch != consentEpoch) return;
    setState(resetResults);
    final text = query.text.trim();
    if (text.length >= 3 && text.length <= 180) {
      final requestGeneration = generation;
      timer = Timer(const Duration(milliseconds: 600), () {
        if (active && consent && epoch == consentEpoch && requestGeneration == generation) {
          lookup(epoch);
        }
      });
    }
  }

  Future<void> lookup(int epoch) async {
    if (!active || !consent || epoch != consentEpoch || loading) return;
    timer?.cancel();
    final text = query.text.trim();
    if (text.length < 3 || text.length > 180) return;
    final client = widget.client;
    final country = widget.country;
    final locale = language ?? 'en';
    final current = ++generation;
    bool sameRequest() => mounted && current == generation &&
      identical(widget.client,client) && country == widget.country;
    bool accepted() => sameRequest() && active && consent && epoch == consentEpoch;
    setState(() {
      loading = true;
      error = null;
      empty = false;
      places = [];
    });
    try {
      final result = await client.addresses(text,locale,country:country);
      if (accepted()) setState(() {
        places = result;
        empty = result.isEmpty;
      });
    } catch (e) {
      if (accepted()) setState(() => error = e);
    } finally {
      if (accepted()) {
        setState(() => loading = false);
      } else if (sameRequest() && !client.currentSession()) {
        setState(revoke);
      }
    }
  }

  @override
  void dispose() {
    generation++;
    consentEpoch++;
    timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    query.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!active) {
      return Text(t('Address search hidden. Reopen discovery if the account changed.',
        'البحث عن العنوان مخفي. أعد فتح البحث إذا تغير الحساب.'));
    }
    final epoch = consentEpoch;
    final resultGeneration = generation;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CheckboxListTile(
          key: const Key('address-consent'),
          contentPadding: EdgeInsets.zero,
          value: consent,
          onChanged: (value) {
            if (!active || epoch != consentEpoch) return;
            setState(() {
              revoke();
              consent = value == true;
            });
          },
          title: Text(t(
            'Allow typed addresses to be sent to Geoapify. Queries are not saved as preferences.',
            'السماح بإرسال العناوين المكتوبة إلى Geoapify. لا تُحفظ عمليات البحث كتفضيلات.',
          )),
        ),
        TextField(
          key: const Key('address-query'),
          controller: query,
          enabled: consent,
          maxLength: 180,
          autocorrect: false,
          enableSuggestions: false,
          onChanged: (value) => changed(value,epoch),
          onSubmitted: (_) => lookup(epoch),
          decoration: InputDecoration(
            labelText: t(
              'Search an address (at least 3 characters)',
              'البحث عن عنوان (٣ أحرف على الأقل)',
            ),
            prefixIcon: const Icon(Icons.search),
          ),
        ),
        if (loading)
          Semantics(
            liveRegion: true,
            child: LinearProgressIndicator(
              semanticsLabel: t('Searching addresses', 'جارٍ البحث عن العناوين'),
            ),
          ),
        if (empty)
          Semantics(liveRegion:true,child:Text(t(
            'No address matches. Try manual coordinates.',
            'لا توجد عناوين مطابقة. جرب الإحداثيات اليدوية.',
          ))),
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
            subtitle: Text(t(
              'Search centre only; not a charging station.',
              'مركز بحث فقط؛ ليس محطة شحن.',
            )),
            trailing: const Icon(Icons.arrow_forward),
            onTap: () {
              if (!active || !consent || epoch != consentEpoch ||
                  generation != resultGeneration || !places.contains(place)) return;
              final callback = widget.onSelected;
              setState(resetResults);
              callback(place);
            },
          ),
      ],
    );
  }
}
