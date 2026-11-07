import 'dart:typed_data';
import 'package:creabiz_ev/vendor_company.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pdfrx/pdfrx.dart';

class Document extends Fake implements PdfDocument {
  @override
  final List<PdfPage> pages;
  Document(this.pages);
}

class Raster extends Fake implements PdfImage {
  @override
  final Uint8List pixels;
  bool released = false;
  Raster(this.pixels);
  @override
  void dispose() => released = true;
}

class Page extends Fake implements PdfPage {
  final Future<PdfImage?> Function() result;
  Map<Symbol, dynamic>? renderOptions;
  Page(this.result);
  @override
  dynamic noSuchMethod(Invocation invocation) {
    if (invocation.memberName == #render) {
      renderOptions = invocation.namedArguments;
      return result();
    }
    return super.noSuchMethod(invocation);
  }
}

void main() {
  test(
    'readiness requires actual bounded raster and always releases it',
    () async {
      final raster = Raster(Uint8List.fromList([255, 255, 255, 255]));
      final page = Page(() async => raster);
      expect(await privatePdfRasterReady(Document([page])), isTrue);
      expect(page.renderOptions?[#fullWidth], 256);
      expect(page.renderOptions?[#fullHeight], 256);
      expect(raster.released, isTrue);
    },
  );
  test('empty raster is rejected and released', () async {
    final raster = Raster(Uint8List(0));
    expect(
      await privatePdfRasterReady(Document([Page(() async => raster)])),
      isFalse,
    );
    expect(raster.released, isTrue);
  });
  test(
    'null, failed and empty documents cannot declare preview ready',
    () async {
      expect(await privatePdfRasterReady(Document([])), isFalse);
      expect(
        await privatePdfRasterReady(Document([Page(() async => null)])),
        isFalse,
      );
      expect(
        await privatePdfRasterReady(
          Document([
            Page(() async => throw StateError('synthetic render failure')),
          ]),
        ),
        isFalse,
      );
    },
  );
}
