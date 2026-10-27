import 'package:flutter/widgets.dart';

String topologyText(BuildContext context, String key) =>
    (Localizations.localeOf(context).languageCode == 'ar'
        ? topologyAr
        : topologyEn)[key] ??
    key;
const topologyEn = {
  'capabilities':'Mapped connectors · choose a connector for capabilities',
  'choose': 'Choose connector',
  'title': 'Canonical EVSE mapping',
  'disclaimer':
      'Simulated inventory only. Connectors on the same EVSE share capacity. Live availability is unknown.',
  'unavailable': 'This simulator connector cannot accept new reservations.',
  'evse': 'EVSE',
  'connector': 'Connector',
  'create': 'Create EVSE',
  'map': 'Map connector',
  'number': 'EVSE number',
  'mode': 'Simulator mode',
  'application': 'Application simulator',
  'csms': 'CSMS simulator',
  'binding': 'Provisioned source binding ID (CSMS only)',
  'sourceEvse': 'Source EVSE number (0 for OCPP 1.6)',
  'sourceConnector': 'Source connector number (CSMS only)',
  'save': 'Confirm mapping',
  'immutable':
      'Mapping identity is immutable. Current legacy bookings and unresolved sessions must finish before activation. No hardware command is sent.',
  'empty': 'No mappings in this scope.',
  'invalid': 'Use whole numbers within the displayed limits.',
  'uncertain':
      'The mapping may have been saved. Refresh authoritative records before submitting again.',
};
const topologyAr = {
  'capabilities':'موصلات معتمدة · اختر موصلاً لعرض مواصفاته',
  'choose': 'اختيار الموصل',
  'title': 'ربط وحدات EVSE المعتمد',
  'disclaimer':
      'مخزون تجريبي فقط. تتشارك موصلات وحدة EVSE الواحدة السعة. التوافر المباشر غير معروف.',
  'unavailable': 'هذا الموصل التجريبي لا يقبل حجوزات جديدة.',
  'evse': 'وحدة EVSE',
  'connector': 'الموصل',
  'create': 'إنشاء وحدة EVSE',
  'map': 'ربط الموصل',
  'number': 'رقم وحدة EVSE',
  'mode': 'نمط المحاكاة',
  'application': 'محاكاة التطبيق',
  'csms': 'محاكاة CSMS',
  'binding': 'معرّف اتصال المصدر المُجهّز (CSMS فقط)',
  'sourceEvse': 'رقم EVSE في المصدر (0 لبروتوكول OCPP 1.6)',
  'sourceConnector': 'رقم الموصل في المصدر (CSMS فقط)',
  'save': 'تأكيد الربط',
  'immutable':
      'هوية الربط غير قابلة للتعديل. يجب انتهاء الحجوزات القديمة والجلسات غير المحسومة قبل التفعيل. لا يُرسل أمر لجهاز فعلي.',
  'empty': 'لا توجد روابط ضمن هذا النطاق.',
  'invalid': 'استخدم أرقاماً صحيحة ضمن الحدود الموضحة.',
  'uncertain': 'ربما حُفظ الربط. حدّث السجلات المعتمدة قبل إعادة الإرسال.',
};
