=== 内容推送 (Content Pusher) ===
Contributors: qingya
Tags: sync, rest-api, push, comments, https
Requires at least: 6.0
Tested up to: 7.0.3
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

站间内容推送：把本地发送站的文章、评论（含 AI 已生成的评论）、话题推送到目标生产站。目标站零插件，仅核心 REST API + 应用密码，全程 HTTPS。

== Description ==

把**本地发送站**的文章、评论（含 AI 已生成的评论）、话题推送到**目标生产站**。

* 目标站零插件：只用 WordPress 核心 REST API（wp/v2）+ 应用密码（WP 5.6+ 核心功能），目标站只负责接收内容。
* 本地是发送站，目标站是生产站：单向推送（本地 → 生产）。
* 推送全程 HTTPS：地址强制 https://，请求强制校验证书。
* 话题"有相关插件才显示"：目标站有 abp_topic 话题分类法时按话题推送，否则落为标签或不推送。
* 匹配防重复：按远端 ID / slug 匹配，同名文章更新不重复建。
* 评论保留作者、时间与回复层级；AI 已生成的评论同样推送。
* 特色图与正文图片上传目标站媒体库并重写 URL，已传图片缓存不重复上传。

== Installation ==

1. 目标站（生产站）：用户 → 个人资料 → 应用程序密码，生成一个（建议管理员账号）。
2. 本地发送站安装启用本插件。
3. 设置 → 内容推送：填目标站地址（https://…）、应用密码用户名与密码，点「测试连接」。
4. 从文章列表行操作/批量操作或编辑页推送；开启自动推送后本地发布即自动推送。

== Changelog ==

= 1.0.1 =
* 新增备用渠道：文件导出（WXR 标准格式，含文章/评论/话题）；设置页注明导入时需在目标站安装相应导入插件（如 wordpress-importer）。

= 1.0.0 =
* 首版。
