<?php
/**
 * Plugin Name:       内容推送 (Content Pusher)
 * Plugin URI:        https://github.com/
 * Description:       站间内容推送：把本地发送站的文章、评论（含 AI 已生成的评论）、话题推送到目标生产站。
 *                    目标站零插件 —— 仅使用 WordPress 核心 REST API（wp/v2）+ 应用密码，推送全程 HTTPS。
 *                    话题按设置映射为目标站分类法或标签：目标站有相关插件（如 abp_topic 话题分类法）即显示，没有则落为标签或忽略。
 * Version:           1.1.0
 * Author:            青崖
 * Text Domain:       content-pusher
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CP_VERSION', '1.1.0' );
define( 'CP_PLUGIN_FILE', __FILE__ );
define( 'CP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// 选项
define( 'CP_OPTION', 'cp_settings' );          // 插件设置
define( 'CP_LOG_OPTION', 'cp_log' );           // 推送日志（环形缓冲）
define( 'CP_MEDIA_MAP', 'cp_media_map' );      // 媒体映射缓存（attachment id / 源URL → 远端URL）
define( 'CP_TERM_MAP', 'cp_term_map' );        // 术语映射缓存（分类/标签名 → 远端 term id）
define( 'CP_TAX_CACHE', 'cp_target_taxonomies' ); // 目标站分类法列表缓存

// post / comment meta
define( 'CP_META_REMOTE_ID', '_cp_remote_id' );         // 本地文章 → 远端文章 ID
define( 'CP_META_REMOTE_URL', '_cp_remote_url' );       // 本地文章 → 远端文章链接
define( 'CP_META_REMOTE_COMMENT', '_cp_remote_comment_id' ); // 本地评论 → 远端评论 ID
define( 'CP_META_LAST_PUSH', '_cp_last_push' );         // 最近一次推送时间
define( 'CP_META_LAST_ERROR', '_cp_last_error' );       // 最近一次推送错误

require_once CP_PLUGIN_DIR . 'includes/class-cp-log.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-client.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-push.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-settings.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-admin.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-manage.php';

add_action( 'plugins_loaded', 'cp_boot' );
/**
 * 初始化后台（设置页 + 管理列/行操作/批量）。
 */
function cp_boot() {
	CP_Settings::init();
	CP_Admin::init();
	CP_Manage::init();
}

register_activation_hook( __FILE__, 'cp_activate' );
/**
 * 激活：写入默认设置（不覆盖已有）。
 */
function cp_activate() {
	if ( false === get_option( CP_OPTION ) ) {
		add_option( CP_OPTION, CP_Settings::defaults() );
	}
}

/**
 * 自动推送：文章转为已发布时排一个 WP-Cron 单次任务（不阻塞发布流程）。
 *
 * @param string  $new_status 新状态。
 * @param string  $old_status 旧状态。
 * @param WP_Post $post       文章对象。
 */
function cp_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( 'post' !== $post->post_type ) {
		return;
	}
	$settings = get_option( CP_OPTION, array() );
	if ( empty( $settings['auto_push'] ) ) {
		return;
	}
	$post_id = (int) $post->ID;
	if ( wp_next_scheduled( 'cp_push_post_event', array( $post_id ) ) ) {
		return;
	}
	wp_schedule_single_event( time() + 10, 'cp_push_post_event', array( $post_id ) );
}
add_action( 'transition_post_status', 'cp_on_publish', 10, 3 );

/**
 * WP-Cron 推送任务（自动推送 / 批量推送共用）。
 *
 * @param int   $post_id 本地文章 ID。
 * @param array $opts    推送选项（include/dedup），批量推送传入。
 */
function cp_push_post_cron( $post_id, $opts = array() ) {
	CP_Push::push_post( (int) $post_id, is_array( $opts ) ? $opts : array() );
}
add_action( 'cp_push_post_event', 'cp_push_post_cron', 10, 2 );

/**
 * 卸载：清理本插件全部数据。
 */
function cp_uninstall_cleanup() {
	delete_option( CP_OPTION );
	delete_option( CP_LOG_OPTION );
	delete_option( CP_MEDIA_MAP );
	delete_option( CP_TERM_MAP );
	delete_option( CP_TAX_CACHE );
	delete_post_meta_by_key( CP_META_REMOTE_ID );
	delete_post_meta_by_key( CP_META_REMOTE_URL );
	delete_post_meta_by_key( CP_META_REMOTE_COMMENT );
	delete_post_meta_by_key( CP_META_LAST_PUSH );
	delete_post_meta_by_key( CP_META_LAST_ERROR );
	delete_comment_meta_by_key( CP_META_REMOTE_COMMENT );
}
