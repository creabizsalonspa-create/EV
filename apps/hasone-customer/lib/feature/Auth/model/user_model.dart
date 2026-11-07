// filepath: lib/feature/Auth/model/user_model.dart

class UserModel {
  final String? fullName;
  final String? email;
  final String? phone;
  final String? profilePicture;
  final String? homeAddress;
  final String? workAddress;

  UserModel({
    this.fullName,
    this.email,
    this.phone,
    this.profilePicture,
    this.homeAddress,
    this.workAddress,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) => UserModel(
        fullName: json['fullName']?.toString() ?? json['full_name']?.toString(),
        email: json['email']?.toString(),
        phone: json['phone']?.toString() ?? json['phone_number']?.toString(),
        profilePicture: json['profilePicture']?.toString() ??
            json['profile_picture']?.toString(),
        homeAddress:
            json['homeAddress']?.toString() ?? json['home_address']?.toString(),
        workAddress:
            json['workAddress']?.toString() ?? json['work_address']?.toString(),
      );

  Map<String, dynamic> toJson() => {
        'fullName': fullName,
        'email': email,
        'phone': phone,
        'profilePicture': profilePicture,
        'homeAddress': homeAddress,
        'workAddress': workAddress,
      };
}

