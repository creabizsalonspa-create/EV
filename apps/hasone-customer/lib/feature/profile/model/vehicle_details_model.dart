class VehicledetailsModel {
  String? status;
  Data? data;

  VehicledetailsModel({this.status, this.data});

  VehicledetailsModel.fromJson(Map<String, dynamic> json) {
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
  String? sId;
  String? modelName;
  String? image;
  String? brandName;
  String? brandImage;

  Data({this.sId, this.modelName, this.image, this.brandName, this.brandImage});

  Data.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    modelName = json['modelName'];
    image = json['image'];
    brandName = json['brandName'];
    brandImage = json['brandImage'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['modelName'] = this.modelName;
    data['image'] = this.image;
    data['brandName'] = this.brandName;
    data['brandImage'] = this.brandImage;
    return data;
  }
}
