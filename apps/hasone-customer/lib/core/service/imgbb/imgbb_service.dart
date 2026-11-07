import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';

/// ImgBB image upload service
/// Uploads images to ImgBB and returns the public URL
class ImgBBService {
  static const String _apiKey = '062499640037b87a330cb09793b95435';
  static const String _uploadUrl = 'https://api.imgbb.com/1/upload';

  static final Dio _dio = Dio();

  /// Upload a [File] to ImgBB and return the display URL
  static Future<String?> uploadImage(File file) async {
    try {
      // Read file bytes and encode to base64
      final bytes = await file.readAsBytes();
      final base64Image = base64Encode(bytes);

      final formData = FormData.fromMap({
        'key': _apiKey,
        'image': base64Image,
      });

      final response = await _dio.post(
        _uploadUrl,
        data: formData,
        options: Options(
          headers: {'Accept': 'application/json'},
        ),
      );

      if (response.statusCode == 200 && response.data['success'] == true) {
        return response.data['data']['display_url'] as String;
      }
      return null;
    } catch (e) {
      print('ImgBB upload error: $e');
      return null;
    }
  }

  /// Upload from base64 string directly
  static Future<String?> uploadBase64(String base64Image) async {
    try {
      // Strip data URL prefix if present
      final cleaned = base64Image.contains('base64,')
          ? base64Image.split('base64,').last
          : base64Image;

      final formData = FormData.fromMap({
        'key': _apiKey,
        'image': cleaned,
      });

      final response = await _dio.post(
        _uploadUrl,
        data: formData,
        options: Options(
          headers: {'Accept': 'application/json'},
        ),
      );

      if (response.statusCode == 200 && response.data['success'] == true) {
        return response.data['data']['display_url'] as String;
      }
      return null;
    } catch (e) {
      print('ImgBB upload error: $e');
      return null;
    }
  }
}

