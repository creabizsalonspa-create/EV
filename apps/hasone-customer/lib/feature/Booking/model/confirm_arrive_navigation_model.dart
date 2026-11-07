class ConfirmArriveStationModel {
  String? status;
  String? message;
  Data? data;

  ConfirmArriveStationModel({this.status, this.message, this.data});

  ConfirmArriveStationModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? new Data.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['status'] = this.status;
    data['message'] = this.message;
    if (this.data != null) {
      data['data'] = this.data!.toJson();
    }
    return data;
  }
}

class Data {
  String? bookingId;
  String? popupTitle;
  String? arrivalStatus;
  String? stationName;
  String? description;

  Data(
      {this.bookingId,
        this.popupTitle,
        this.arrivalStatus,
        this.stationName,
        this.description});

  Data.fromJson(Map<String, dynamic> json) {
    bookingId = json['bookingId'];
    popupTitle = json['popupTitle'];
    arrivalStatus = json['arrivalStatus'];
    stationName = json['stationName'];
    description = json['description'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['bookingId'] = this.bookingId;
    data['popupTitle'] = this.popupTitle;
    data['arrivalStatus'] = this.arrivalStatus;
    data['stationName'] = this.stationName;
    data['description'] = this.description;
    return data;
  }
}
