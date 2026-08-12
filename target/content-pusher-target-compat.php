<?php
/**
 * Plugin Name: 内容推送-目标站兼容（星河话题）
 * Description: 恢复 thread 文章类型（星河AI工具箱话题帖）的 REST 创建权限 create_posts。
 *              供「内容推送」插件以星河兼容模式推送话题帖使用。仅恢复 REST 创建能力，不影响星河原有流程。
 * Version:     1.0.0
 * Author:      青崖
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * 背景：星河AI工具箱注册 thread 类型时把 create_posts 显式设为 false（话题帖只能由星河 AI 流程创建），
 * 导致外部 REST 无法创建话题帖。「内容推送」推送星河话题需要此兼容插件放开创建权限。
 *
 * @package Content_Pusher_Compat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'register_post_type_args',
	function ( $args, $post_type ) {
		if ( 'thread' === $post_type && is_array( $args ) ) {
			if ( ! isset( $args['capabilities'] ) || ! is_array( $args['capabilities'] ) ) {
				$args['capabilities'] = array();
			}
			$args['capabilities']['create_posts'] = 'edit_posts';
		}
		return $args;
	},
	20,
	2
);
