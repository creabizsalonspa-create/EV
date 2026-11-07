#!/usr/bin/env python3
"""Fail-closed source patch for a STAGED copy of the owner's Express/Mongo API.

Run against a disposable source copy, never against a deployed application.
The app.js, paymentRoutes.js, and userService.js patches are preflighted before
any write; changed upstream code causes a failure rather than a partial patch.
"""
from pathlib import Path
import sys

def one(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected exactly one source match, found {count}')
    return text.replace(old, new)

def stage(root):
    root = root.resolve()
    forbidden = ('/home/hasio/ev-deployment', '/var/www', '/srv/www', '/opt/production')
    if any(str(root) == x or str(root).startswith(x + '/') for x in forbidden):
        raise RuntimeError('Cannot patch live deployment; copy source into staging')
    app, payments, service = (root / x for x in (
        'src/app.js', 'src/routes/paymentRoutes.js', 'src/services/userService.js'))
    helper_output = root / 'src/utils/paymentIntegrity.mjs'
    if not all(x.is_file() for x in (app, payments, service)):
        raise RuntimeError('Missing app.js, paymentRoutes.js, or userService.js')
    if helper_output.exists():
        raise RuntimeError('Safety helper exists; review patch state before retrying')
    a, p, s = (x.read_text(encoding='utf-8') for x in (app, payments, service))
    a = one(a, "app.use(express.json({ limit: '5mb' }));",
        """// Capture exact Stripe webhook bytes before JSON parsing.
app.use(express.json({ limit: '5mb', verify: (req, _res, buf) => {
  if (req.originalUrl?.split('?')[0] === '/api/v1/payment/stripe/webhook') {
    req.stripeRawBody = Buffer.from(buf);
  }
} }));""", 'Stripe raw body')
    p = one(p,
        'import { validateSSLCommerzIPN, verifyStripeWebhook, retrieveStripePaymentIntent } from "../services/paymentService.js";',
        """import { validateSSLCommerzIPN, verifyStripeWebhook, getPaymentSettings } from "../services/paymentService.js";
import { AuthVerification } from "../middlewares/authverification.js";
import { verifiedSslSettlement, verifiedStripeSettlement, belongsToAuthenticatedUser } from "../utils/paymentIntegrity.mjs";""",
        'payment validation imports')
    p = one(p, '    console.log("SSLCommerz SUCCESS:", req.body?.tran_id);\n    await handleSSLCommerzSuccess(req.body);',
        '    // Browser redirect is unverified: only a validated IPN settles payment.',
        'unverified browser success')
    p = one(p, '<h1 style="color:#16a34a;">✅ Payment Successful!</h1>',
        '<h1 style="color:#555;">Payment verification pending</h1>', 'browser response wording')
    p = one(p, '    console.log("SSLCommerz FAIL:", req.body?.tran_id);\n    await handleSSLCommerzFail(req.body);',
        '    // Browser redirect cannot mutate the financial ledger.', 'unverified browser fail')
    p = one(p, '    console.log("SSLCommerz CANCEL:", req.body?.tran_id);\n    await handleSSLCommerzCancel(req.body);',
        '    // Browser redirect cannot mutate the financial ledger.', 'unverified browser cancel')
    p = one(p, """    // Validate the IPN
    const validation = await validateSSLCommerzIPN(req.body);

    if (req.body.status === "VALID" || req.body.status === "VALIDATED") {
      await handleSSLCommerzSuccess(req.body);
    } else if (req.body.status === "FAILED") {
      await handleSSLCommerzFail(req.body);
    } else if (req.body.status === "CANCELLED") {
      await handleSSLCommerzCancel(req.body);
    }

    return res.status(200).json({ status: "ok" });""",
        """    const validation = await validateSSLCommerzIPN(req.body);
    const kind = req.body?.value_a;
    const id = req.body?.value_b;
    if (typeof id !== 'string' || !/^[0-9a-f]{24}$/i.test(id)) {
      return res.status(400).json({status:'rejected'});
    }
    const record = kind === 'booking' ? await BookingModel.findById(id)
      : kind === 'session' ? await ChargingSessionModel.findById(id) : null;
    const settings = await getPaymentSettings();
    if (!verifiedSslSettlement({notice:req.body,gateway:validation,record,
                               kind,currency:settings.currencyCode})) {
      return res.status(400).json({status:'rejected'});
    }
    await handleSSLCommerzSuccess(req.body);
    return res.status(200).json({status:'verified'});""", 'verified SSL IPN')
    p = one(p, """paymentRouter.post("/payment/stripe/webhook", express.raw({ type: "application/json" }), async (req, res) => {
  try {
    const sig = req.headers["stripe-signature"];
    const event = await verifyStripeWebhook(req.body, sig);

    // If webhook secret is not configured, parse body manually
    let eventData;
    if (event) {
      eventData = event;
    } else {
      // Fallback: parse raw body (for dev without webhook secret)
      eventData = JSON.parse(req.body.toString());
    }
""",
        """paymentRouter.post("/payment/stripe/webhook", async (req, res) => {
  try {
    const sig = req.headers["stripe-signature"];
    if (!Buffer.isBuffer(req.stripeRawBody) || !sig) {
      return res.status(400).json({received:false});
    }
    const eventData = await verifyStripeWebhook(req.stripeRawBody, sig);
    if (!eventData) return res.status(400).json({received:false});
""", 'signed Stripe webhook')
    p = one(p, """        if (booking && !booking.isPaid) {
          booking.isPaid""",
        """        const settings = await getPaymentSettings();
        if (!verifiedStripeSettlement({intent:pi,record:booking,
                                      kind:'booking',currency:settings.currencyCode})) {
          return res.status(400).json({received:false});
        }
        if (booking && !booking.isPaid) {
          booking.isPaid""", 'Stripe booking integrity')
    p = one(p, """        if (session && session.status === "Stopped") {
          session.status""",
        """        const settings = await getPaymentSettings();
        if (!verifiedStripeSettlement({intent:pi,record:session,
                                      kind:'session',currency:settings.currencyCode})) {
          return res.status(400).json({received:false});
        }
        if (session && session.status === "Stopped") {
          session.status""", 'Stripe session integrity')
    p = one(p, 'paymentRouter.get("/payment/status/booking/:id", async (req, res) => {',
        'paymentRouter.get("/payment/status/booking/:id", AuthVerification, async (req, res) => {',
        'booking status authentication')
    p = one(p, 'paymentRouter.get("/payment/status/session/:id", async (req, res) => {',
        'paymentRouter.get("/payment/status/session/:id", AuthVerification, async (req, res) => {',
        'session status authentication')
    p = one(p, '    if (!booking) return res.status(404).json({ status: "fail", message: "Booking not found." });',
        """    if (!belongsToAuthenticatedUser(booking, req.headers.user_id)) {
      return res.status(404).json({status:'fail',message:'Booking not found.'});
    }""", 'booking ownership')
    p = one(p, '    if (!session) return res.status(404).json({ status: "fail", message: "Session not found." });',
        """    if (!belongsToAuthenticatedUser(session, req.headers.user_id)) {
      return res.status(404).json({status:'fail',message:'Session not found.'});
    }""", 'session ownership')
    s = one(s, 'import crypto from "crypto";',
        'import crypto from "crypto";\nimport { verifiedStripeSettlement } from "../utils/paymentIntegrity.mjs";',
        'user service validation import')
    s = one(s, """        if (pi.status === "succeeded") {
          booking.isPaid""",
        """        if (verifiedStripeSettlement({intent:pi,record:booking,
                                      kind:'booking',currency:settings.currencyCode})) {
          booking.isPaid""", 'direct booking intent verification')
    s = one(s, """        if (pi.status === "succeeded") {
          session.status""",
        """        if (verifiedStripeSettlement({intent:pi,record:session,
                                      kind:'session',currency:settings.currencyCode})) {
          session.status""", 'direct session intent verification')
    def replace_block(source, start_marker, end_marker, replacement, label):
        start = source.find(start_marker)
        if start < 0 or source.find(start_marker, start+1) >= 0:
            raise RuntimeError(label + ': start marker must match once')
        end = source.find(end_marker, start)
        if end < 0:
            raise RuntimeError(label + ': end marker not found')
        return source[:start] + replacement + source[end:]
    # Legacy 'card' methods changed database payment status without PSP confirmation.
    s = replace_block(s, '    // ──────────────────────── Card (legacy / saved card) ────────────────────────',
        '  } catch (e) {',
        "    return {status:'fail',message:'Card payments require a verified payment processor.'};\n",
        'unsafe booking card settlement')
    s = replace_block(s, '// ─── Add Payment Card ───',
        '// NAVIGATION & ARRIVAL SERVICES',
        """// ─── Add Payment Card: raw PAN storage disabled ───
export const AddPaymentCardService = async () => ({
  status:'fail', message:'Card vault is unavailable; use provider tokenization.'
});

// ════════════════════════════════════════════════════
""", 'unsafe saved PAN collection')
    s = replace_block(s, '    // ──────────────────────── Card (legacy / default) ────────────────────────',
        '  } catch (e) {',
        "    return {status:'fail',message:'Card payments require a verified payment processor.'};\n",
        'unsafe session card settlement')
    helper = Path(__file__).resolve().parents[2] / 'integrations/hasone-express/paymentIntegrity.mjs'
    if not helper.is_file():
        raise RuntimeError('Missing payment guard module alongside this script')
    # Write only when *every* preflight match succeeds.
    for path, content in ((app,a),(payments,p),(service,s),
                          (helper_output,helper.read_text(encoding='utf-8'))):
        path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text(content,encoding='utf-8')
    print('Staged payment hardening; live production has not been changed.')

if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Usage: stage_payment_safety.py /path/to/disposable/source-copy')
    stage(Path(sys.argv[1]))
