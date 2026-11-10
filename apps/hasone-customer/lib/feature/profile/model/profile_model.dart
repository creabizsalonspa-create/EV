class ProfileModel {
  String? status;
  Data? data;

  ProfileModel({this.status, this.data});

  ProfileModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    data = json['data'] != null ? new Data.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['status'] = this.status;
    if (this.data != null) {
      data['data'] = this.data!.toJson();
    }
    return data;
  }
}

class Data {
  String? fullName;
  String? email;
  String? phone;
  String? avatar;
  String? dateOfBirth;
  int? sessions;
  double? kwhUsed;
  int? favourites;

  Data(
      {this.fullName,
        this.email,
        this.phone,
        this.avatar,
        this.dateOfBirth,
        this.sessions,
        this.kwhUsed,
        this.favourites});

  Data.fromJson(Map<String, dynamic> json) {
    fullName = json['fullName'];
    email = json['email'];
    phone = json['phone'];
    avatar = json['avatar'];
    dateOfBirth = json['dateOfBirth'];
    sessions = (json['sessions'] is num) ? (json['sessions'] as num).toInt() : null;
    kwhUsed = (json['kwhUsed'] is num) ? (json['kwhUsed'] as num).toDouble() : null;
    favourites = (json['favourites'] is num) ? (json['favourites'] as num).toInt() : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['fullName'] = this.fullName;
    data['email'] = this.email;
    data['phone'] = this.phone;
    data['avatar'] = this.avatar;
    data['dateOfBirth'] = this.dateOfBirth;
    data['sessions'] = this.sessions;
    data['kwhUsed'] = this.kwhUsed;
    data['favourites'] = this.favourites;
    return data;
  }
}
