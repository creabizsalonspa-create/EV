import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/utils.dart';
import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

class FieldTitle extends StatelessWidget {
  const FieldTitle({super.key, required this.label, this.optional, this.optionalText,
    this.fontWeight = FontWeight.w500, this.textColor});

  final String label;
  final bool? optional;
  final String? optionalText;
  final FontWeight fontWeight;
  final Color? textColor;

  @override
  Widget build(BuildContext context) {
    return RichText(
      text: TextSpan(
          text: label,
          style: defaultTextStyle(
              fontWeight: fontWeight,
              color: textColor ?? R.color.coral
          ),
          children: optional == true ? [
            TextSpan(
              text: " ${optionalText ?? "Text"}",
              style: defaultTextStyle(
                  fontWeight: fontWeight,
                  color: R.color.darkGray
              ),
            )
          ]: []
      ),
    );
  }
}