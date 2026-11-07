import 'package:flutter/material.dart';


class LoadingIndicator extends StatelessWidget {
  final double size;
  final Color color;

  const LoadingIndicator({super.key, this.size = 24.0, this.color = Colors.white});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SizedBox(
        height: size,
        width: size,
        child: CircularProgressIndicator(
          backgroundColor: Colors.green,
          valueColor: AlwaysStoppedAnimation<Color>(color),
          value: null,
          strokeWidth: 3.0,
        ),
      ),
    );
  }
}