/**
 * Payment-integrity checks for the owner's separate Express/Mongo V-CHARGE app.
 * These are guards, not a payment processor or authorization to deploy.
 */
const validId = value => typeof value === 'string' && /^[a-fA-F0-9]{24}$/.test(value);
const cents = value => {
  const amount = Number(value);
  return Number.isFinite(amount) && amount > 0 ? Math.round(amount * 100) : null;
};
const currencyMatches = (a,b) => typeof a === 'string' && typeof b === 'string'
  && a.length === 3 && b.length === 3 && a.toUpperCase() === b.toUpperCase();
const recordId = record => String(record?._id ?? '');
const amountFor = (record,kind) => kind === 'booking' ? record.totalAmount : record.extendSessionCharge;
const transactionFor = (record,kind) => kind === 'booking' ? record.transactionId : record.extensionTransactionId;

function sslProofMatches({notice,gateway,record,kind,currency}) {
  if ((kind !== 'booking' && kind !== 'session') || !notice || !gateway
      || typeof gateway !== 'object' || !record) return false;
  const id=recordId(record);
  if (!validId(id) || notice.value_a !== kind || notice.value_b !== id) return false;
  if (!['VALID','VALIDATED'].includes(notice.status)
      || !['VALID','VALIDATED'].includes(gateway.status)) return false;
  const transaction=notice.tran_id;
  if (typeof transaction !== 'string' || !transaction.startsWith(kind+'_'+id+'_')
      || !/^[0-9]{10,}$/.test(transaction.slice((kind+'_'+id+'_').length))
      || transaction !== gateway.tran_id || transactionFor(record,kind) !== transaction) return false;
  const expected=cents(amountFor(record,kind));
  if (expected === null || expected !== cents(gateway.amount)
      || !currencyMatches(gateway.currency,currency)) return false;
  return record.paymentGateway === 'sslcommerz';
}

function stripeProofMatches({intent,record,kind,currency}) {
  if ((kind !== 'booking' && kind !== 'session') || !intent || !record) return false;
  const id=recordId(record);
  if (!validId(id) || typeof intent.id !== 'string' || !/^pi_[a-zA-Z0-9_]+$/.test(intent.id)) return false;
  if (intent.status !== 'succeeded' || intent.metadata?.paymentType !== kind
      || intent.metadata?.referenceId !== id) return false;
  if (!record.stripePaymentIntentId || record.stripePaymentIntentId !== intent.id
      || record.paymentGateway !== 'stripe') return false;
  const expected=cents(amountFor(record,kind));
  if (expected === null || expected !== Number(intent.amount_received)
      || !currencyMatches(intent.currency,currency)) return false;
  return true;
}

export function verifiedSslSettlement(proof) {
  if (!sslProofMatches(proof)) return false;
  const {record,kind}=proof;
  return kind === 'booking'
    ? !record.isPaid && record.paymentStatus === 'pending' && record.status === 'Upcoming'
    : record.status === 'Stopped' && record.extensionPaymentStatus === 'pending';
}

export function verifiedSslReplay(proof) {
  if (!sslProofMatches(proof)) return false;
  const {record,kind}=proof;
  return kind === 'booking'
    ? record.isPaid === true && record.paymentStatus === 'paid' && record.status === 'Upcoming'
    : record.status === 'Completed' && record.extensionPaymentStatus === 'paid';
}

export function verifiedStripeSettlement(proof) {
  if (!stripeProofMatches(proof)) return false;
  const {record,kind}=proof;
  return kind === 'booking'
    ? !record.isPaid && record.paymentStatus === 'pending' && record.status === 'Upcoming'
    : record.status === 'Stopped' && record.extensionPaymentStatus === 'pending';
}

export function verifiedStripeReplay(proof) {
  if (!stripeProofMatches(proof)) return false;
  const {record,kind,intent}=proof;
  return kind === 'booking'
    ? record.isPaid === true && record.paymentStatus === 'paid' &&
      record.transactionId === intent.id && record.status === 'Upcoming'
    : record.status === 'Completed' && record.extensionPaymentStatus === 'paid'
      && record.extensionTransactionId === intent.id;
}

export function belongsToAuthenticatedUser(record,userId) {
  return !!record && validId(recordId({ _id: record.userId }))
    && validId(String(userId ?? ''))
    && String(record.userId).toLowerCase() === String(userId).toLowerCase();
}
