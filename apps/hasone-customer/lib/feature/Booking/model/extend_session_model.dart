class ExtendSessionModel {
  String? status;
  String? message;
  Data? data;

  ExtendSessionModel({this.status, this.message, this.data});

  ExtendSessionModel.fromJson(Map<String, dynamic> json) {
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
  String? sessionId;
  int? extendMins;
  int? estimatedCost;
  int? newTimeRemainingMs;
  List<Options>? options;

  Data(
      {this.sessionId,
        this.extendMins,
        this.estimatedCost,
        this.newTimeRemainingMs,
        this.options});

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    extendMins = (json['extendMins'] is num) ? (json['extendMins'] as num).toInt() : null;
    estimatedCost = (json['estimatedCost'] is num) ? (json['estimatedCost'] as num).toInt() : null;
    newTimeRemainingMs = (json['newTimeRemainingMs'] is num) ? (json['newTimeRemainingMs'] as num).toInt() : null;
    if (json['options'] != null) {
      options = <Options>[];
      json['options'].forEach((v) {
        options!.add(Options.fromJson(v));
      });
    }
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['sessionId'] = this.sessionId;
    data['extendMins'] = this.extendMins;
    data['estimatedCost'] = this.estimatedCost;
    data['newTimeRemainingMs'] = this.newTimeRemainingMs;
    if (this.options != null) {
      data['options'] = this.options!.map((v) => v.toJson()).toList();
    }
    return data;
  }
}

class Options {
  String? label;
  int? mins;
  int? cost;

  Options({this.label, this.mins, this.cost});

  Options.fromJson(Map<String, dynamic> json) {
    label = json['label'];
    mins = (json['mins'] is num) ? (json['mins'] as num).toInt() : null;
    cost = (json['cost'] is num) ? (json['cost'] as num).toInt() : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['label'] = this.label;
    data['mins'] = this.mins;
    data['cost'] = this.cost;
    return data;
  }
}
