import 'package:evcharg/app/widget_button.dart';
import 'package:evcharg/app/winput_text.dart';
import 'package:evcharg/app/wtext.dart';
import 'package:evcharg/feature/main_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:get/get.dart';

import '../../../../app/resource.dart';

class VehicelModelScreen extends StatefulWidget {
  const VehicelModelScreen({super.key});

  @override
  State<VehicelModelScreen> createState() => _VehicelModelScreenState();
}

class _VehicelModelScreenState extends State<VehicelModelScreen> {
  int _step = 0; // 0: Brand, 1: Model, 2: Connector, 3: Battery
  String? _selectedBrand;
  String? _selectedModel;
  String? _selectedConnector;
  final TextEditingController _batteryController = TextEditingController();

  final List<String> _brands = [
    'Tesla', 'BMW', 'Nissan', 'Chevrolet',
    'Audi', 'Hyundai', 'Ford', 'Volkswagen',
  ];

  final Map<String, List<String>> _models = {
    'Tesla': ['Model 3', 'Model S', 'Model X', 'Model Y'],
    'BMW': ['i3', 'i4', 'iX', 'i7'],
    'Nissan': ['Leaf', 'Ariya'],
    'Chevrolet': ['Bolt EV', 'Bolt EUV'],
    'Audi': ['e-tron', 'e-tron GT', 'Q4 e-tron'],
    'Hyundai': ['Ioniq 5', 'Ioniq 6', 'Kona Electric'],
    'Ford': ['Mustang Mach-E', 'F-150 Lightning'],
    'Volkswagen': ['ID.3', 'ID.4', 'ID.5'],
  };

  final List<String> _connectors = [
    'CCS', 'CHAdeMO', 'Type 2', 'Tesla Supercharger',
  ];

  @override
  void dispose() {
    _batteryController.dispose();
    super.dispose();
  }

  void _onContinue() {
    if (_step < 3) {
      setState(() => _step++);
    } else {
      Get.off(() => const MainScreen());
    }
  }

  bool get _canContinue {
    switch (_step) {
      case 0: return _selectedBrand != null;
      case 1: return _selectedModel != null;
      case 2: return _selectedConnector != null;
      case 3: return _batteryController.text.trim().isNotEmpty;
      default: return false;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(  backgroundColor: R.color.offWhite,
      body: SizedBox(
        width: double.infinity,
        height: double.infinity,
        child: Stack(
          children: [
            // ── Top gradient area ──────────────────────────────
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: Container(
                width: 390.w,
                height: 316.h,
                decoration:  BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: R.color.blueAccent,
                    stops: [0.0, 1.0],
                  ),
                ),
                padding: EdgeInsets.only(
                  top: 100.h,
                  left: 24.w,
                  right: 24.w,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    WText(
                      text: 'Set-Up Your Vehicle',
                      fontSize: 24.sp,
                      color: R.color.darkNavy,
                    ),
                    SizedBox(height: 6.h),
                    WText(
                      text: 'Help us personalize your charging experience',
                      fontWeight: FontWeight.w400,
                      color: R.color.mediumGray,
                    ),
                    SizedBox(height: 24.h),
                    _buildStepIndicator(),
                  ],
                ),
              ),
            ),

            // ── White card ────────────────────────────────────
            Positioned(
              top: 250.h,
              left: 0,
              right: 0,
              bottom: 0,
              child: Container(
                decoration: BoxDecoration(
                  color: R.color.white,
                  borderRadius: BorderRadius.only(
                    topLeft: Radius.circular(28.r),
                    topRight: Radius.circular(28.r),
                  ),
                ),
                child: Column(
                  children: [
                    Expanded(
                      child: SingleChildScrollView(
                        padding: EdgeInsets.fromLTRB(24.w, 28.h, 24.w, 0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _buildStepTitle(),
                            SizedBox(height: 6.h),
                            _buildStepSubtitle(),
                            SizedBox(height: 18.h),
                            _buildStepContent(),
                          ],
                        ),
                      ),
                    ),
                    // Continue button
                    Padding(
                      padding: EdgeInsets.fromLTRB(24.w, 12.h, 24.w, 36.h),
                      child: WButton(
                        label: 'Continue',
                        onPressed: _canContinue ? _onContinue : null,
                        buttonColor: _canContinue
                            ? R.color.mintGreen
                            : R.color.neutralGray,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Step indicator ──────────────────────────────────────
  Widget _buildStepIndicator() {
    return Row(
      children: List.generate(4, (i) {
        final isActive = i == _step;
        final isDone = i < _step;
        return Expanded(
          child: Container(
            margin: EdgeInsets.only(right: i < 3 ? 6.w : 0),
            height: 8.h,
            decoration: BoxDecoration(
              color: (isActive || isDone)
                  ? R.color.forestGreen
                  : R.color.lightGrayShade,
              borderRadius: BorderRadius.circular(4.r),
            ),
          ),
        );
      }),
    );
  }

  // ── Step title ──────────────────────────────────────────
  Widget _buildStepTitle() {
    const titles = [
      'Select Vehicle Brand',
      'Select Model',
      'Connector Type',
      'Battery Capacity',
    ];
    return WText(
      text: titles[_step],
      fontSize: 20.sp,
      color: R.color.darkNavy,
    );
  }

  // ── Step subtitle ────────────────────────────────────────
  Widget _buildStepSubtitle() {
    final subtitles = [
      'Choose Your EV manufacturer',
      'Choose your ${_selectedBrand ?? 'EV'} Model',
      'Select Your Charging Connector',
      'Enter Your Battery Capacity in KWh',
    ];
    return WText(
      text: subtitles[_step],
      fontSize: 14.sp,
      fontWeight: FontWeight.w400,
      color: R.color.neutralGray
    );
  }

  // ── Step content ─────────────────────────────────────────
  Widget _buildStepContent() {
    switch (_step) {
      case 0: return _buildBrandGrid();
      case 1: return _buildModelList();
      case 2: return _buildConnectorList();
      case 3: return _buildBatteryInput();
      default: return const SizedBox.shrink();
    }
  }

  // ── Step 0: Brand grid ───────────────────────────────────
  Widget _buildBrandGrid() {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: _brands.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 12.w,
        mainAxisSpacing: 12.h,
        childAspectRatio: 3.2,
      ),
      itemBuilder: (_, i) {
        final brand = _brands[i];
        final selected = _selectedBrand == brand;
        return _selectionTile(
          label: brand,
          selected: selected,
          onTap: () => setState(() {
            _selectedBrand = brand;
            _selectedModel = null; // reset model when brand changes
          }),
        );
      },
    );
  }

  // ── Step 1: Model list ───────────────────────────────────
  Widget _buildModelList() {
    final models = _models[_selectedBrand] ?? [];
    return ListView.separated(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: models.length,
      separatorBuilder: (_, __) => SizedBox(height: 12.h),
      itemBuilder: (_, i) {
        final model = models[i];
        final selected = _selectedModel == model;
        return _selectionTile(
          label: model,
          selected: selected,
          onTap: () => setState(() => _selectedModel = model),
        );
      },
    );
  }

  // ── Step 2: Connector list ───────────────────────────────
  Widget _buildConnectorList() {
    return ListView.separated(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: _connectors.length,
      separatorBuilder: (_, __) => SizedBox(height: 12.h),
      itemBuilder: (_, i) {
        final connector = _connectors[i];
        final selected = _selectedConnector == connector;
        return _selectionTile(
          label: connector,
          selected: selected,
          onTap: () => setState(() => _selectedConnector = connector),
        );
      },
    );
  }

  // ── Step 3: Battery input ────────────────────────────────
  Widget _buildBatteryInput() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InputFieldText(
          textEditingController: _batteryController,
          hintText: 'e.g. 75',
          keyboardType: TextInputType.number,
          fieldBorderRadius: 10,
          onChanged: (_) => setState(() {}),
        ),
        SizedBox(height: 10.h),
        WText(
          text: 'This helps us calculate charging time and costs',
          fontSize: 14.sp,
          fontWeight: FontWeight.w500,
          color: R.color.neutralGray,
        ),
      ],
    );
  }

  // ── Reusable selection tile ──────────────────────────────
  Widget _selectionTile({
    required String label,
    required bool selected,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: EdgeInsets.symmetric(horizontal: 16.w, vertical: 14.h),
        decoration: BoxDecoration(
          color: selected
              ? R.color.softMint.withValues(alpha: 0.2)
              : R.color.white,
          borderRadius: BorderRadius.circular(10.r),
          border: Border.all(
            color: selected
                ? R.color.mintGreen
                : R.color.lightGray,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: WText(
          text: label,
          fontSize: 14.sp,
          fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
          color: selected ? R.color.mintGreen:R.color.darkNavy,
        ),
      ),
    );
  }
}
