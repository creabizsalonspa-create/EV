package com.creabiz.creabiz_ev

import android.content.Intent
import android.net.Uri
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private var returnPending = false
    private var clearPrivacyFlagOnResume = false
    private var returnChannel: MethodChannel? = null
    private var navigationPending: String? = null
    private var navigationChannel: MethodChannel? = null

    private fun acceptReturn(intent: Intent?) {
        val uri: Uri = intent?.data ?: return
        if (intent.action != Intent.ACTION_VIEW || uri.scheme != "creabizev" ||
            uri.host != "payments" || uri.path != "/hosted-return" ||
            uri.port != -1 || uri.userInfo != null || uri.query != null || uri.fragment != null) return
        // Only a boolean wake-up signal is retained, never payment IDs/status.
        returnPending = true
        returnChannel?.invokeMethod("hostedReturn", null)
    }

    private fun acceptNavigation(intent: Intent?) {
        val uri: Uri = intent?.data ?: return
        if (intent.action != Intent.ACTION_VIEW || uri.scheme != "creabizev" ||
            uri.host != "navigate" || uri.port != -1 || uri.userInfo != null ||
            uri.query != null || uri.fragment != null) return
        val target = uri.path?.removePrefix("/tab/") ?: return
        if (target !in setOf("discover", "reservations", "sessions", "garage", "support", "fleet") ||
            uri.encodedPath != "/tab/$target") return
        // Only a screen name is retained, never an identity, URL or action.
        navigationPending = target
        navigationChannel?.invokeMethod("navigationAvailable", null)
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
        navigationChannel = MethodChannel(
            flutterEngine.dartExecutor.binaryMessenger, "com.creabiz.ev/navigation"
        )
        navigationChannel?.setMethodCallHandler { call, result ->
            if (call.method == "consumeNavigation") {
                val target = navigationPending
                navigationPending = null
                result.success(target)
            } else result.notImplemented()
        }
        acceptReturn(intent)
        acceptNavigation(intent)
    }

    // The OS may capture a task-switcher thumbnail while Flutter is paused.
    // Keep screenshots enabled in the foreground; protect only inactive UI.
    override fun onPause() {
        // A specialist screen may already require FLAG_SECURE while active.
        // Never remove that independent restriction when coming back.
        clearPrivacyFlagOnResume =
            (window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE) == 0
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        if (clearPrivacyFlagOnResume) {
            window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
        clearPrivacyFlagOnResume = false
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        acceptReturn(intent)
        acceptNavigation(intent)
    }
}
