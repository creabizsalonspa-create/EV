import 'dart:async';

import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/core/service/payment/payment_service.dart';
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

/// Opens the SSLCommerz gateway URL in an in-app WebView.
/// Detects success/fail/cancel redirects and returns the result.
class SSLCommerzWebViewScreen extends StatefulWidget {
  final String gatewayUrl;

  /// Pass one of these — used for payment-status polling after redirect.
  final String? bookingId;
  final String? sessionId;

  /// Called when SSLCommerz confirms success.
  final VoidCallback? onSuccess;

  const SSLCommerzWebViewScreen({
    super.key,
    required this.gatewayUrl,
    this.bookingId,
    this.sessionId,
    this.onSuccess,
  });

  @override
  State<SSLCommerzWebViewScreen> createState() =>
      _SSLCommerzWebViewScreenState();
}

class _SSLCommerzWebViewScreenState extends State<SSLCommerzWebViewScreen> {
  bool _loading = true;
  bool _paymentProcessed = false;

  bool _isSuccessUrl(String url) {
    final lower = url.toLowerCase();
    // Only detect success from the actual backend callback URL,
    // NOT from intermediate SSLCommerz gateway URLs with tran_type=success
    if (lower.contains('sandbox.sslcommerz.com') ||
        lower.contains('securepay.sslcommerz.com')) {
      return false; // Intermediate SSLCommerz pages — let them load
    }
    return lower.contains('/payment/success') ||
        lower.contains('/payment/sslcommerz/success') ||
        lower.contains('status=success') ||
        lower.contains('status=valid') ||
        lower.contains('status=validated');
  }

  bool _isFailUrl(String url) {
    final lower = url.toLowerCase();
    if (lower.contains('sandbox.sslcommerz.com') ||
        lower.contains('securepay.sslcommerz.com')) {
      return false;
    }
    return lower.contains('/payment/fail') ||
        lower.contains('/payment/sslcommerz/fail') ||
        lower.contains('status=failed') ||
        lower.contains('status=fail');
  }

  bool _isCancelUrl(String url) {
    final lower = url.toLowerCase();
    if (lower.contains('sandbox.sslcommerz.com') ||
        lower.contains('securepay.sslcommerz.com')) {
      return false;
    }
    return lower.contains('/payment/cancel') ||
        lower.contains('/payment/sslcommerz/cancel') ||
        lower.contains('status=cancelled') ||
        lower.contains('status=cancel');
  }

  void _checkUrlForResult(String? urlStr) {
    if (urlStr == null || urlStr.isEmpty || _paymentProcessed) return;
    final url = urlStr.toLowerCase();
    if (_isSuccessUrl(url)) {
      _handleSuccess();
    } else if (_isFailUrl(url) || _isCancelUrl(url)) {
      _handleFailure();
    }
  }

  Future<void> _handleSuccess() async {
    if (_paymentProcessed) return;
    _paymentProcessed = true;

    if (mounted) setState(() => _loading = true);

    // Give the backend a moment to process the IPN callback
    await Future.delayed(const Duration(seconds: 2));

    // Poll payment status to confirm (quick poll)
    bool confirmed = false;
    if (widget.bookingId != null && widget.bookingId!.isNotEmpty) {
      confirmed = await PaymentService.pollPaymentStatus(
        type: 'booking',
        id: widget.bookingId!,
        maxAttempts: 5,
        interval: const Duration(seconds: 2),
      );
    } else if (widget.sessionId != null && widget.sessionId!.isNotEmpty) {
      confirmed = await PaymentService.pollPaymentStatus(
        type: 'session',
        id: widget.sessionId!,
        maxAttempts: 5,
        interval: const Duration(seconds: 2),
      );
    } else {
      confirmed = true;
    }

    if (!mounted) return;

    // Even if polling didn't confirm, SSLCommerz redirected to success URL
    // so the payment was successful server-side
    widget.onSuccess?.call();
    Get.back(result: true);
    Get.snackbar('Success', 'Payment completed successfully!',
        snackPosition: SnackPosition.BOTTOM,
        backgroundColor: Colors.green.shade100,
        colorText: Colors.green.shade900,
        margin: EdgeInsets.all(16.w));
  }

  void _handleFailure() {
    if (_paymentProcessed) return;
    _paymentProcessed = true;

    Get.back(result: false);
    Get.snackbar('Payment Failed',
        'Payment was not completed. Please try again.',
        snackPosition: SnackPosition.BOTTOM,
        backgroundColor: Colors.red.shade100,
        colorText: Colors.red.shade900,
        margin: EdgeInsets.all(16.w));
  }

  InAppWebViewController? _webViewController;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: R.color.offWhite,
      appBar: AppBar(
        backgroundColor: R.color.mintGreen,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.close, color: R.color.darkNavy),
          onPressed: () async {
            // If payment is already being processed, ignore close
            if (_paymentProcessed) return;

            // Check if we're on the success page before closing
            if (_webViewController != null) {
              final title = await _webViewController!.getTitle() ?? '';
              final url = (await _webViewController!.getUrl())?.toString() ?? '';
              if (title.toLowerCase().contains('payment successful') ||
                  _isSuccessUrl(url)) {
                // User is on the success page — treat as success
                _handleSuccess();
                return;
              }
            }

            Get.back(result: false);
          },
        ),
        title: WText(
          text: 'Complete Payment',
          color: R.color.darkNavy,
          fontSize: 16.sp,
          fontWeight: FontWeight.w600,
        ),
        centerTitle: true,
      ),
      body: Stack(
        children: [
          InAppWebView(
            initialUrlRequest:
                URLRequest(url: WebUri(widget.gatewayUrl)),
            initialSettings: InAppWebViewSettings(
              javaScriptEnabled: true,
              domStorageEnabled: true,
              useHybridComposition: true,
              useShouldOverrideUrlLoading: true,
              allowsInlineMediaPlayback: true,
            ),
            onWebViewCreated: (controller) {
              _webViewController = controller;
            },
            onLoadStart: (controller, url) {
              if (mounted) setState(() => _loading = true);
              _checkUrlForResult(url?.toString());
            },
            onLoadStop: (controller, url) {
              if (mounted) setState(() => _loading = false);
              _checkUrlForResult(url?.toString());
            },
            onTitleChanged: (controller, title) {
              if (_paymentProcessed) return;
              final t = (title ?? '').toLowerCase();
              if (t.contains('payment successful') || t.contains('payment success')) {
                _handleSuccess();
              }
            },
            shouldOverrideUrlLoading: (controller, navigationAction) async {
              final url =
                  navigationAction.request.url?.toString() ?? '';

              if (_isSuccessUrl(url)) {
                _handleSuccess();
                return NavigationActionPolicy.CANCEL;
              }
              if (_isFailUrl(url) || _isCancelUrl(url)) {
                _handleFailure();
                return NavigationActionPolicy.CANCEL;
              }

              // Allow all SSLCommerz gateway internal navigations
              return NavigationActionPolicy.ALLOW;
            },
            onReceivedError: (controller, request, error) {
              debugPrint('WebView error: ${error.description}');
            },
          ),
          if (_loading)
            Center(
              child: CircularProgressIndicator(color: R.color.mintGreen),
            ),
        ],
      ),
    );
  }
}

