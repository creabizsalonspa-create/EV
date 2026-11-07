
import 'package:evcharg/app/resource.dart';
import 'package:flutter/material.dart';


class WText extends Text {
  WText({
    super.key,
    super.overflow,
    super.softWrap,
    super.maxLines,
    super.textAlign,
    required String text,
    final Color? color,
    final double? fontSize,
    final FontWeight? fontWeight,
    final bool? capitalize,
    final TextStyle? style,
    final double? letterSpacing,
    final double? height,
    final String? fontFamily,
    final TextDecoration? textDecoration}) :super(
    capitalize == true ? text.capitalize() : text,
    style: style ?? TextStyle(
        fontFamily: fontFamily ?? "Nunito",
        color: color?? R.color.white,
        fontSize: fontSize ??  16,
        fontWeight: fontWeight ?? FontWeight.w400,
        letterSpacing: letterSpacing,
        decoration: textDecoration,
        height: height ?? 1.2
    ),
  );
}


extension StringExtension on String {
  String capitalize(){
    if(isEmpty) return  "";
    return "${this[0].toUpperCase()}${substring(1)}";
  }

  String capitalizeAll(){
    if(isEmpty) return  "";
    return split(" ").map((e) => "${e[0].toUpperCase()}${e.length > 1 ? e.substring(1) : ""}").join(" ");
  }
}