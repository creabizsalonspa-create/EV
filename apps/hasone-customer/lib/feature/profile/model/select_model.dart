class SelectMOdel {
  String? status;
  List<Data>? data;

  SelectMOdel({this.status, this.data});

  SelectMOdel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
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
    if (this.data != null) {
      data['data'] = this.data!.map((v) => v.toJson()).toList();
    }
    return data;
  }
}

class Data {
  String? sId;
  String? brandId;
  String? name;
  String? image;

  Data({this.sId, this.brandId, this.name, this.image});

  Data.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    brandId = json['brandId'];
    name = json['name'];
    image = json['image'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['brandId'] = this.brandId;
    data['name'] = this.name;
    data['image'] = this.image;
    return data;
  }
}
