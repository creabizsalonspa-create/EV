class ShowNotificationModel {
  String? status;
  Data? data;

  ShowNotificationModel({this.status, this.data});

  ShowNotificationModel.fromJson(Map<String, dynamic> json) {
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
  List<Notifications>? notifications;
  int? unreadCount;
  int? total;
  int? page;
  int? totalPages;

  Data(
      {this.notifications,
        this.unreadCount,
        this.total,
        this.page,
        this.totalPages});

  Data.fromJson(Map<String, dynamic> json) {
    if (json['notifications'] != null) {
      notifications = <Notifications>[];
      json['notifications'].forEach((v) {
        notifications!.add(new Notifications.fromJson(v));
      });
    }
    unreadCount = json['unreadCount'];
    total = json['total'];
    page = json['page'];
    totalPages = json['totalPages'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    if (this.notifications != null) {
      data['notifications'] =
          this.notifications!.map((v) => v.toJson()).toList();
    }
    data['unreadCount'] = this.unreadCount;
    data['total'] = this.total;
    data['page'] = this.page;
    data['totalPages'] = this.totalPages;
    return data;
  }
}

class Notifications {
  String? sId;
  String? userId;
  String? title;
  String? message;
  String? type;
  bool? isRead;
  String? createdAt;

  Notifications(
      {this.sId,
        this.userId,
        this.title,
        this.message,
        this.type,
        this.isRead,
        this.createdAt});

  Notifications.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    userId = json['userId'];
    title = json['title'];
    message = json['message'];
    type = json['type'];
    isRead = json['isRead'];
    createdAt = json['createdAt'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['userId'] = this.userId;
    data['title'] = this.title;
    data['message'] = this.message;
    data['type'] = this.type;
    data['isRead'] = this.isRead;
    data['createdAt'] = this.createdAt;
    return data;
  }
}
