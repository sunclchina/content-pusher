<?php
/**
 * Plugin Name: 内容推送-目标站兼容（thread 话题）
 * Description: ① 恢复 thread 文章类型（第三方插件话题帖）的 REST 创建权限 create_posts；
 *              ② 注册第三方插件话题关联 meta（xhai_postparent / xhai_thread）的 REST 读写。
 *              供「内容推送」插件以第三方插件兼容模式推送话题帖并建立文章↔话题关联。不影响第三方插件原有流程。
 * Version:     1.1.0
 * Author:      青崖
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * 背景：第三方插件注册 thread 类型时把 create_posts 设为 false（话题帖只能由插件自身的 AI 流程创建），
 * 且其关联 meta（thread 帖 xhai_postparent=文章ID / 文章 xhai_thread=threadID 列表）未开放 REST，
 * 外部无法创建话题帖与建立关联。「内容推送」推送话题需要此兼容插件放开这两处。
 *
 * @package Content_Pusher_Compat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ① 恢复 thread 类型 REST 创建权限。
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

// ② 注册第三方插件话题关联 meta 的 REST 读写（数据结构与第三方插件一致：thread 帖 xhai_postparent=文章ID；
//    文章 xhai_thread=threadID 列表）。
add_action(
	'init',
	function () {
		register_post_meta(
			'thread',
			'xhai_postparent',
			array(
				'type'         => 'integer',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => 0,
			)
		);
		register_post_meta(
			'post',
			'xhai_thread',
			array(
				'type'         => 'integer',
				'single'       => false,
				'show_in_rest' => true,
			)
		);
	},
	11
);
