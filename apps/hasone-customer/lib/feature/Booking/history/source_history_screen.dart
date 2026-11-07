import 'package:flutter/material.dart';

import '../../../core/service/api_service/api_service.dart';
import 'source_history.dart';

class SourceHistoryScreen extends StatefulWidget {
  final SourceHistoryController? controller;
  const SourceHistoryScreen({super.key, this.controller});
  @override
  State<SourceHistoryScreen> createState() => _SourceHistoryScreenState();
}

class _SourceHistoryScreenState extends State<SourceHistoryScreen>
    with WidgetsBindingObserver {
  late final SourceHistoryController _history;
  bool? _arabic;
  bool get _ar =>
      _arabic ?? Localizations.localeOf(context).languageCode == 'ar';
  String _t(String en, String ar) => _ar ? ar : en;

  @override
  void initState() {
    super.initState();
    _history =
        widget.controller ??
        SourceHistoryController(
          load: SourceHistoryApi(NetworkService().client).load,
        );
    WidgetsBinding.instance.addObserver(this);
    _history.refresh();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _history.resume();
    } else {
      _history.suspend();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _history.dispose();
    super.dispose();
  }

  String _money(int fils) =>
      '${fils ~/ 100}.${(fils % 100).toString().padLeft(2, '0')}';
  String _time(DateTime? value) => value == null
      ? _t('Unavailable', 'غير متاح')
      : '${value.toIso8601String().replaceFirst('T', ' ').replaceFirst('.000Z', '')} UTC';

  Widget _row(String title, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.labelMedium),
        Text(value, style: Theme.of(context).textTheme.bodyLarge),
      ],
    ),
  );

  Widget _entry(SourceHistoryEntry entry) {
    final state = switch (entry.state) {
      'ended' => _t('Ended at source', 'انتهت الجلسة في المصدر'),
      'active' => _t('Active at last read', 'نشطة عند آخر قراءة'),
      _ => _t('Source requires review', 'مصدر الجلسة يحتاج إلى مراجعة'),
    };
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              entry.stationName.isEmpty
                  ? _t('Charging station', 'محطة الشحن')
                  : entry.stationName,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            _row(_t('Source status', 'حالة المصدر'), state),
            _row(
              _t('Connector scope', 'نطاق الموصل'),
              entry.resource == null
                  ? _t('Connector scope unverified', 'نطاق الموصل غير متحقق')
                  : '${entry.resource!.evseId == null ? '' : '${_t('EVSE', 'وحدة الشحن')} ${entry.resource!.evseId} · '}'
                        '${_t('Connector', 'الموصل')} ${entry.resource!.connectorId}',
            ),
            if (!entry.chargerEnabled)
              Text(
                _t(
                  'Charger currently disabled or unavailable',
                  'الشاحن معطل أو غير متاح حالياً',
                ),
              ),
            _row(
              _t('Metered energy', 'الطاقة المقاسة'),
              entry.energyWh == null
                  ? _t('Unavailable', 'غير متاح')
                  : '${entry.energyWh! ~/ 1000}.${(entry.energyWh! % 1000).toString().padLeft(3, '0')} kWh',
            ),
            _row(
              _t(
                'Calculated amount • not collected',
                'المبلغ المحسوب • لم يتم تحصيله',
              ),
              entry.totalFils == null
                  ? _t('Unverified', 'غير متحقق')
                  : 'AED ${_money(entry.totalFils!)}',
            ),
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: Text(_t('Session details', 'تفاصيل الجلسة')),
              children: [
                _row(_t('Session reference', 'مرجع الجلسة'), entry.sessionId),
                _row(_t('Booking reference', 'مرجع الحجز'), entry.bookingId),
                _row(
                  _t('Charger', 'الشاحن'),
                  entry.chargerId ?? _t('Unavailable', 'غير متاح'),
                ),
                _row(
                  _t('Protocol', 'البروتوكول'),
                  entry.protocol ?? _t('Unverified', 'غير متحقق'),
                ),
                _row(_t('Started', 'بدأت'), _time(entry.startedAt)),
                _row(_t('Ended', 'انتهت'), _time(entry.endedAt)),
              ],
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: _ar ? TextDirection.rtl : TextDirection.ltr,
    child: Scaffold(
      appBar: AppBar(
        title: Text(_t('Charging history', 'سجل الشحن')),
        actions: [
          TextButton(
            onPressed: () => setState(() => _arabic = !_ar),
            child: Text(_ar ? 'English' : 'العربية'),
          ),
          IconButton(
            tooltip: _t('Refresh history', 'تحديث السجل'),
            onPressed: _history.refresh,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: SafeArea(
        child: AnimatedBuilder(
          animation: _history,
          builder: (context, _) => RefreshIndicator(
            onRefresh: _history.refresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  _t(
                    'Read-only source records. Calculated amounts are not payment receipts. Refresh for current source status.',
                    'سجلات المصدر للعرض فقط. المبالغ المحسوبة ليست إيصالات دفع. حدّث الصفحة لمعرفة حالة المصدر الحالية.',
                  ),
                ),
                const SizedBox(height: 16),
                ..._history.entries.map(_entry),
                if (_history.loading)
                  const Center(child: CircularProgressIndicator()),
                if (_history.unavailableCount > 0)
                  Text(
                    _t(
                      'Records requiring review: ${_history.unavailableCount}',
                      'سجلات تحتاج إلى مراجعة: ${_history.unavailableCount}',
                    ),
                  ),
                if (_history.failed) ...[
                  Text(
                    _t(
                      'History could not be loaded. Try again.',
                      'تعذر تحميل السجل. حاول مرة أخرى.',
                    ),
                  ),
                  TextButton(
                    onPressed: _history.hasMore
                        ? _history.more
                        : _history.refresh,
                    child: Text(_t('Retry', 'إعادة المحاولة')),
                  ),
                ] else if (!_history.loading && _history.entries.isEmpty)
                  Text(
                    _t(
                      'No verified session records on this page.',
                      'لا توجد سجلات جلسات متحققة في هذه الصفحة.',
                    ),
                  ),
                if (!_history.loading && !_history.failed && _history.hasMore)
                  OutlinedButton(
                    onPressed: _history.more,
                    child: Text(
                      _t('Load older sessions', 'تحميل الجلسات الأقدم'),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}
