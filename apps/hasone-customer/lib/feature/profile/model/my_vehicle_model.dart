class MyVehicleModel {
  String? status;
  String? message;
  List<Data>? data;

  MyVehicleModel({this.status, this.message, this.data});

  MyVehicleModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    if (json['data'] != null) {
      data = <Data>[];
      json['data'].forEach((v) {
        data!.add(new Data.fromJson(v));
      });
    }
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['status'] = this.status;
    data['message'] = this.message;
    if (this.data != null) {
      data['data'] = this.data!.map((v) => v.toJson()).toList();
    }
    return data;
  }
}

class Data {
  String? sId;
  String? userId;
  String? name;
  String? model;
  String? image;
  String? connectorType;
  int? batteryCapacityKwh;
  bool? isActive;

  Data(
      {this.sId,
        this.userId,
        this.name,
        this.model,
        this.image,
        this.connectorType,
        this.batteryCapacityKwh,
        this.isActive});

  Data.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    userId = json['userId'];
    name = json['name'];
    model = json['model'];
    image = json['image'];
    connectorType = json['connectorType'];
    batteryCapacityKwh = json['batteryCapacityKwh'];
    isActive = json['isActive'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['userId'] = this.userId;
    data['name'] = this.name;
    data['model'] = this.model;
    data['image'] = this.image;
    data['connectorType'] = this.connectorType;
    data['batteryCapacityKwh'] = this.batteryCapacityKwh;
    data['isActive'] = this.isActive;
    return data;
  }
}
