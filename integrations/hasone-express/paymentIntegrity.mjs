/**
 * Fail-closed payment verification for the owner's separately deployed
 * Express/Mongo adapter. Never treat an unverified callback as settlement.
 * Pure helpers: no payments, financial writes, or production credentials.
 */
const validId = (id) => typeof id === 'string' && /^[0-9a-fA-F]{24}$/.test(id);
const moneyCents = (value) => Number.isFinite(Number(value)) && Number(value) > 0
  ? Math.round(Number(value) * 100) : null;
const sameCurrency = (one, two) => typeof one === 'string' && typeof two === 'string'
  && one.toUpperCase() === two.toUpperCase();

export function verifiedSslSettlement({ notice, gateway, record, kind, currency }) {
  if (kind !== 'booking' && kind !== 'session') return false;
  if (!notice || !gateway || typeof gateway !== 'object' || !record) return false;
  const id = String(record._id ?? '');
  if (!validId(id) || notice.value_a !== kind || notice.value_b !== id) return false;
  if (!['VALID', 'VALIDATED'].includes(notice.status)
      || !['VALID', 'VALIDATED'].includes(gateway.status)) return false;
  const transaction = notice.tran_id;
  if (typeof transaction !== 'string'
      || !new RegExp(`^${kind}_${id}_[0-9]{10,}$`).test(transaction)
      || transaction !== gateway.tran_id) return false;
  const storedTransaction = kind === 'booking' ? record.transactionId : record.extensionTransactionId;
  if (storedTransaction !== transaction) return false;
  const expectedAmount = kind === 'booking' ? record.totalAmount : record.extendSessionCharge;
  if (moneyCents(expectedAmount) === null
      || moneyCents(expectedAmount) !== moneyCents(gateway.amount)) return false;
  if (!sameCurrency(gateway.currency, currency)) return false;
  if (kind === 'booking') {
    return record.paymentGateway === 'sslcommerz' && !record.isPaid
      && record.paymentStatus === 'pending' && record.status === 'Upcoming';
  }
  return record.paymentGateway === 'sslcommerz' && record.status === 'Stopped'
    && record.extensionPaymentStatus === 'pending';
}

export function verifiedStripeSettlement({ intent, record, kind, currency }) {
  if ((kind !== 'booking' && kind !== 'session') || !intent || !record) return false;
  const id = String(record._id ?? '');
  if (!validId(id) || typeof intent.id !== 'string' || !/^pi_[a-zA-Z0-9_]+$/.test(intent.id)) return false;
  if (intent.status !== 'succeeded' || intent.metadata?.paymentType !== kind
      || intent.metadata?.referenceId !== id) return false;
  if (!record.stripePaymentIntentId || record.stripePaymentIntentId !== intent.id) return false;
  const expectedAmount = kind === 'booking' ? record.totalAmount : record.extendSessionCharge;
  if (moneyCents(expectedAmount) === null
      || moneyCents(expectedAmount) !== Number(intent.amount_received)) return false;
  if (!sameCurrency(intent.currency, currency)) return false;
  if (kind === 'booking') {
    return !record.isPaid && record.paymentGateway === 'stripe'
      && record.paymentStatus === 'pending' && record.status === 'Upcoming';
  }
  return record.status === 'Stopped' && record.paymentGateway === 'stripe'
    && record.extensionPaymentStatus === 'pending';
}

export function belongsToAuthenticatedUser(document, userId) {
  return !!document && validId(String(document.userId ?? ''))
    && validId(String(userId ?? ''))
    && String(document.userId).toLowerCase() === String(userId).toLowerCase();
}
