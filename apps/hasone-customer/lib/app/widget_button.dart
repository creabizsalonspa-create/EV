import 'package:evcharg/app/resource.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:flutter/material.dart';
import 'loading.dart';



enum DecorationType {
  primary,
  primaryStroke,
  textOnly,
  solid,
  stroke,
}

enum IconPosition{
  left, right
}

class WButton extends StatelessWidget {
  final String label;
  final GestureTapCallback? onPressed;
  final bool isLoading;
  final double width;
  final double height;
  final bool isEnabled;
  final Color? textColor;
  final Color? buttonColor;
  final Widget? iconWidget;
  final double radius;
  final double verticalPadding;
  final double horizontalPadding;
  final bool hideTextOnLoading;
  final DecorationType decorationType;
  final double? fontSize;
  final FontWeight? fontWeight;
  final IconPosition? iconPosition;

  const WButton({
    super.key,
    this.onPressed,
    required this.label,
    this.iconWidget,
    this.radius = 12,
    this.isLoading = false,
    this.width = double.infinity,
    this.verticalPadding = 0,
    this.horizontalPadding = 12,
    this.hideTextOnLoading = true,
    this.fontSize = 16,
    this.fontWeight = FontWeight.w600,
    this.height = 52,
    this.textColor,
    this.buttonColor,
    this.isEnabled = true,
    this.decorationType = DecorationType.solid,
    this.iconPosition = IconPosition.left,
  });


  getDecoration(bool isEnabled) {
    switch (decorationType) {
      case DecorationType.primary:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(radius),
          color: R.color.deepNavyBlue2,
        );
      case DecorationType.primaryStroke:
        return BoxDecoration(
            borderRadius: BorderRadius.circular(radius),
            border: Border.all(width: 1.5, color: R.color.deepNavyBlue)
        );
      case DecorationType.textOnly:
        return null;
      case DecorationType.solid:
        return BoxDecoration(
          borderRadius: BorderRadius.circular(radius),
          color: buttonColor ?? R.color.mintGreen,
        );
      case DecorationType.stroke:
        return BoxDecoration(
            borderRadius: BorderRadius.circular(radius),
            border: Border.all(width: 1.5, color: buttonColor ?? R.color.mintGreen)
        );
    }
  }

  getTextColor(bool isEnabled) {
    DecorationType type = isEnabled ? decorationType : DecorationType.primary;
    switch (type) {
      case DecorationType.primary:
        return  R.color.white;
      case DecorationType.primaryStroke:
      case DecorationType.textOnly:
        return  Colors.blue;
      case DecorationType.solid:
        return textColor ?? R.color.white;
      case DecorationType.stroke:
        return textColor ?? buttonColor ?? R.color.mintGreen;
    }
  }

  // getColor(bool isEnabled) {
  //   if (isEnabled == false) {
  //     // return R.color.color_EAF0FB;
  //   }
  //
  //   switch (decorationType) {
  //     case DecorationType.primary:
  //       return R.color.royalBlue;
  //     case DecorationType.solid:
  //       return buttonColor ?? R.color.primary;
  //     case DecorationType.stroke:
  //       return Colors.transparent;
  //     default:
  //       return Colors.transparent;
  //   }
  // }

  getColor(bool isEnabled) {
    if (!isEnabled) {
      // return R.color.color_EAF0FB; // Use your disabled color here.
    }

    // Return transparent for all cases because color is handled in decoration
    return Colors.transparent;
  }


  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: Material(
          color: getColor(isEnabled),
          child: InkWell(
            onTap: isLoading ? (){ } : isEnabled ? onPressed : (){ },
            child: Container(
                decoration: getDecoration(isEnabled),
                padding: EdgeInsets.symmetric(horizontal: horizontalPadding, vertical: verticalPadding),
                width: width,
                height: height,
                child: isLoading ? Center(child: LoadingIndicator(size: height <= 52 ? 18 : 12,)) :
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if(iconPosition == IconPosition.left) getIcon(),
                    getLabel(),
                    if(iconPosition == IconPosition.right) getIcon(),
                  ],
                )
            ),
          )
      ),
    );
  }

  getIcon(){
    return iconWidget != null ?
    Padding(
        padding: EdgeInsets.only(
            right: iconPosition == IconPosition.left ?  8 : 0 ,
            left: iconPosition == IconPosition.right ?  8 : 0),
        child: iconWidget) :
    const SizedBox.shrink();
  }

  getLabel() {
    return FittedBox(
      fit: BoxFit.scaleDown,
      child: WText(
        text: label,
        color: textColor ?? getTextColor(isEnabled) ?? R.color.white,
        fontSize: fontSize ?? 16,
        fontWeight: fontWeight ?? FontWeight.w600,
        textAlign: TextAlign.center,
      ),
    );
  }
}