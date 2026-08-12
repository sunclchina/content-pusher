<?php
/**
 * 卸载：删除本插件全部选项与 meta。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cp_settings' );
delete_option( 'cp_log' );
delete_option( 'cp_media_map' );
delete_option( 'cp_term_map' );
delete_option( 'cp_target_taxonomies' );
delete_post_meta_by_key( '_cp_remote_id' );
delete_post_meta_by_key( '_cp_remote_url' );
delete_post_meta_by_key( '_cp_remote_comment_id' );
delete_post_meta_by_key( '_cp_last_push' );
delete_post_meta_by_key( '_cp_last_error' );
delete_comment_meta_by_key( '_cp_remote_comment_id' );
