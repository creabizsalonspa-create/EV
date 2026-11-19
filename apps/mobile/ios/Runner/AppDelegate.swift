import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  private var returnPending = false
  private var inactivePrivacyCover: UIView?
  private var returnChannel: FlutterMethodChannel?
  private var navigationPending: String?
  private var navigationChannel: FlutterMethodChannel?

  private func acceptReturn(_ url: URL) -> Bool {
    guard let parts = URLComponents(url: url, resolvingAgainstBaseURL: false),
      parts.scheme == "creabizev", parts.host == "payments", parts.path == "/hosted-return",
      parts.user == nil, parts.password == nil, parts.port == nil,
      parts.query == nil, parts.fragment == nil else { return false }
    returnPending = true
    returnChannel?.invokeMethod("hostedReturn", arguments: nil)
    return true
  }

  private func acceptNavigation(_ url: URL) -> Bool {
    guard let parts = URLComponents(url: url, resolvingAgainstBaseURL: false),
      parts.scheme == "creabizev", parts.host == "navigate",
      parts.user == nil, parts.password == nil, parts.port == nil,
      parts.query == nil, parts.fragment == nil,
      parts.path.hasPrefix("/tab/") else { return false }
    let target = String(parts.path.dropFirst("/tab/".count))
    guard ["discover", "reservations", "sessions", "garage", "support", "fleet"].contains(target),
      parts.percentEncodedPath == "/tab/\(target)" else { return false }
    navigationPending = target
    navigationChannel?.invokeMethod("navigationAvailable", arguments: nil)
    return true
  }

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    GeneratedPluginRegistrant.register(with: self)
    if let controller = window?.rootViewController as? FlutterViewController {
      returnChannel = FlutterMethodChannel(name: "com.creabiz.ev/payment_return", binaryMessenger: controller.binaryMessenger)
      returnChannel?.setMethodCallHandler { [weak self] call, result in
        guard call.method == "consumeReturn" else { result(FlutterMethodNotImplemented); return }
        let pending = self?.returnPending ?? false
        self?.returnPending = false
        result(pending)
      }
      navigationChannel = FlutterMethodChannel(
        name: "com.creabiz.ev/navigation", binaryMessenger: controller.binaryMessenger
      )
      navigationChannel?.setMethodCallHandler { [weak self] call, result in
        guard call.method == "consumeNavigation" else { result(FlutterMethodNotImplemented); return }
        let target = self?.navigationPending
        self?.navigationPending = nil
        result(target)
      }
    }
    if let url = launchOptions?[.url] as? URL {
      _ = acceptReturn(url)
      _ = acceptNavigation(url)
    }
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  // UIApplication can snapshot the window before Flutter renders an inactive
  // lifecycle frame. Cover the full native window without disposing any route.
  override func applicationWillResignActive(_ application: UIApplication) {
    super.applicationWillResignActive(application)
    guard inactivePrivacyCover == nil, let activeWindow = window else { return }
    let cover = UIView(frame: activeWindow.bounds)
    cover.backgroundColor = .black
    cover.autoresizingMask = [.flexibleWidth, .flexibleHeight]
    cover.isUserInteractionEnabled = true
    activeWindow.addSubview(cover)
    inactivePrivacyCover = cover
  }

  override func applicationDidBecomeActive(_ application: UIApplication) {
    inactivePrivacyCover?.removeFromSuperview()
    inactivePrivacyCover = nil
    super.applicationDidBecomeActive(application)
  }

  override func application(_ app: UIApplication, open url: URL, options: [UIApplication.OpenURLOptionsKey: Any] = [:]) -> Bool {
    if acceptReturn(url) || acceptNavigation(url) { return true }
    return super.application(app, open: url, options: options)
  }
}
