import 'package:image/image.dart' as img;

/// صفحات PDF تُرَستَر بخلفية شفافة، وتحويلها في مولّد ESC/POS يتم بقراءة قنوات
/// RGB فقط (تدرّج رمادي ثم عكس ثم عتبة)، فتُقرأ البكسلات الشفافة كأسود وتخرج
/// الفاتورة بخلفية سوداء. الدمج فوق خلفية بيضاء معتمة قبل الإرسال يمنع ذلك.
img.Image flattenOnWhite(img.Image src) {
  final canvas = img.Image(
    width: src.width,
    height: src.height,
    numChannels: 3,
  );
  img.fill(canvas, color: img.ColorRgb8(255, 255, 255));
  img.compositeImage(canvas, src);
  return canvas;
}
