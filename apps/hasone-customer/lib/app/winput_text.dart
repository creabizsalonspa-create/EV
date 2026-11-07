
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'resource.dart';

class InputFieldText extends StatefulWidget {
  const InputFieldText({
    super.key,
    this.inputFormatters,
    this.onFieldSubmitted,
    this.textEditingController,
    this.focusNode,
    this.keyboardType = TextInputType.text,
    this.textInputAction = TextInputAction.next,
    this.cursorColor = Colors.black,
    this.inputTextStyle,
    this.textAlignVertical = TextAlignVertical.center,
    this.textAlign = TextAlign.start,
    this.onChanged,
    this.maxLines = 1,
    this.validator,
    this.labelText,
    this.labelStyle,
    this.hintText,
    this.hintStyle,
    this.fillColor,
    this.suffixIcon,
    this.suffixIconColor,
    this.fieldBorderRadius = 8,
    this.fieldBorderColor = const Color(0xFF8A8A8A),
    this.focusedBorderColor = const Color(0xFFE5E7EB),
    this.enabledBorderColor = const Color(0xFFE5E7EB),
    this.isPassword = false,
    this.isPrefixIcon = true,
    this.readOnly = false,
    this.maxLength,
    this.prefixIcon,
    this.onTap,
    this.isDens = false,
    this.height = 48,
  });

  final TextEditingController? textEditingController;
  final FocusNode? focusNode;
  final TextInputType keyboardType;
  final TextInputAction textInputAction;
  final Color cursorColor;
  final TextStyle? inputTextStyle;
  final TextAlignVertical? textAlignVertical;
  final TextAlign textAlign;
  final int? maxLines;
  final void Function(String)? onChanged;
  final void Function(String)? onFieldSubmitted;
  final FormFieldValidator<String>? validator;
  final String? labelText;
  final TextStyle? labelStyle;
  final String? hintText;
  final TextStyle? hintStyle;
  final Color? fillColor;
  final Color? suffixIconColor;
  final Widget? suffixIcon;
  final Widget? prefixIcon;
  final double fieldBorderRadius;
  final Color fieldBorderColor;
  final Color? focusedBorderColor;
  final Color? enabledBorderColor;
  final bool isPassword;
  final bool isPrefixIcon;
  final bool readOnly;
  final int? maxLength;
  final List<TextInputFormatter>? inputFormatters;
  final VoidCallback? onTap;
  final bool? isDens;
  final double height;

  @override
  State<InputFieldText> createState() => _InputFieldTextState();
}

class _InputFieldTextState extends State<InputFieldText> {
  bool obscureText = true;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
        onTap: widget.onTap,
        autovalidateMode: AutovalidateMode.onUserInteraction,
        inputFormatters: widget.inputFormatters,
        onFieldSubmitted: widget.onFieldSubmitted,
        readOnly: widget.readOnly,
        controller: widget.textEditingController,
        focusNode: widget.focusNode,
        maxLength: widget.maxLength,
        keyboardType: widget.keyboardType,
        textInputAction: widget.textInputAction,
        cursorColor: widget.cursorColor,
        style: widget.inputTextStyle ??
            const TextStyle(
                fontFamily: 'Nunito',
                fontSize: 16,
                fontWeight: FontWeight.w400,
                color: Color(0xFF757575)),
        onChanged: widget.onChanged,
        maxLines: widget.maxLines,
        obscureText: widget.isPassword ? obscureText : false,
        validator: widget.validator,
        textAlign: widget.textAlign,
        textAlignVertical: widget.textAlignVertical,
        decoration: InputDecoration(
        contentPadding: EdgeInsets.symmetric(horizontal: 14.0, vertical: 14.0),
        isDense: widget.isDens,
        errorMaxLines: 2,
        labelText: widget.labelText,
        labelStyle: widget.labelStyle ??
            const TextStyle(
                fontFamily: 'Nunito',
                fontSize: 14,
                fontWeight: FontWeight.w400,
                color: Color(0xFF49454F)),
        floatingLabelStyle: const TextStyle(
            fontFamily: 'Nunito',
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: Color(0xFF49454F)),
        hintText: widget.hintText,
        hintStyle: widget.hintStyle ??
            const TextStyle(
                fontFamily: 'Nunito',
                fontSize: 14,
                fontWeight: FontWeight.w400,
                color: Color(0xFF9BAAC0)),
        fillColor: widget.fillColor ?? R.color.white,
        filled: true,
        prefixIcon: widget.prefixIcon,
        prefixIconColor: const Color(0xFF8A8A8A),
        suffixIcon: widget.isPassword
            ? GestureDetector(
          onTap: toggle,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Icon(
              obscureText ? Icons.visibility_off : Icons.visibility,
              color: const Color(0xFF616A88),
              size: 20,
            ),
          ),
        )
            : widget.suffixIcon,
        suffixIconColor: widget.suffixIconColor,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(widget.fieldBorderRadius),
          borderSide: BorderSide(
            color: widget.fieldBorderColor,
            width: 1.0,
          ),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(widget.fieldBorderRadius),
          borderSide: BorderSide(
            color: widget.enabledBorderColor ?? const Color(0xFFE5E7EB),
            width: 1.0,
          ),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(widget.fieldBorderRadius),
          borderSide: BorderSide(
            color: widget.focusedBorderColor ?? const Color(0xFFE5E7EB),
            width: 1.0,
          ),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(widget.fieldBorderRadius),
          borderSide: BorderSide(
            color: Colors.red.shade400,
            width: 1.0,
          ),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(widget.fieldBorderRadius),
          borderSide: BorderSide(
            color: Colors.red.shade600,
            width: 1.5,
          ),
        ),
      ),
    );
  }

  void toggle() {
    setState(() {
      obscureText = !obscureText;
    });
  }
}