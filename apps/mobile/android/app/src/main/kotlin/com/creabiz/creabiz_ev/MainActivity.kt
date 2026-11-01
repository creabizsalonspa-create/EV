package com.creabiz.creabiz_ev

import android.content.Intent
import android.net.Uri
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private var returnPending = false
    private var returnChannel: MethodChannel? = null

    private fun acceptReturn(intent: Intent?) {
        val uri: Uri = intent?.data ?: return
        if (intent.action != Intent.ACTION_VIEW || uri.scheme != "creabizev" ||
            uri.host != "payments" || uri.path != "/hosted-return" ||
            uri.port != -1 || uri.userInfo != null || uri.query != null || uri.fragment != null) return
        // Only a boolean wake-up signal is retained, never payment IDs/status.
        returnPending = true
        returnChannel?.invokeMethod("hostedReturn", null)
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        returnChannel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "com.creabiz.ev/payment_return")
        returnChannel?.setMethodCallHandler { call, result ->
            if (call.method == "consumeReturn") {
                val pending = returnPending
                returnPending = false
                result.success(pending)
            } else result.notImplemented()
        }
        acceptReturn(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        acceptReturn(intent)
    }
}
