<?php
/**
 * 全链路功能测试（走本地 mock REST 模拟器，不碰生产站）。
 *
 * 前置：mock 服务器已启动（php -S 127.0.0.1:8799 -t tests/mock-server tests/mock-server/mock.php）
 * 用法：php tests/test-push-mock.php
 *
 * @package Content_Pusher
 */

require 'C:/inetpub/wwwroot/wordpress/wp-load.php';

// 重置 mock 存储，保证可重复运行。
@unlink( __DIR__ . '/mock-server/store.json' );

// 指向本地模拟器（http 仅测试用：直接写选项，绕过设置页 https 校验）。
update_option(
	'cp_settings',
	array(
		'target_url'               => 'http://127.0.0.1:8799',
		'app_user'                 => 'mockadmin',
		'app_password'             => 'mockpass',
		'auto_push'                => '',
		'push_comments'            => '1',
		'comments_include_pending' => '',
		'topic_mode'               => 'auto',
		'sync_images'              => '1',
		'push_status'              => 'follow',
		'timeout'                  => 30,
		'retries'                  => 1,
	)
);
update_option( 'cp_media_map', array() );
update_option( 'cp_term_map', array() );
update_option( 'cp_target_taxonomies', array() );

echo "== 1) 新建推送 7516（3 条评论，无图）==\n";
$r = CP_Push::push_post( 7516 );
echo 'ok=' . var_export( $r['ok'], true ) . " action={$r['action']} remote_id={$r['remote_id']}\n";
echo '   comments_pushed=' . $r['summary']['comments_pushed'] . ' skipped=' . $r['summary']['comments_skipped'] . ' media=' . $r['summary']['media_uploaded'] . ' terms_created=' . $r['summary']['terms_created'] . "\n";
echo '   meta_remote_id=' . get_post_meta( 7516, '_cp_remote_id', true ) . "\n";
echo '   meta_remote_url=' . get_post_meta( 7516, '_cp_remote_url', true ) . "\n";

echo "== 2) 重复推送 → 更新 + 评论全跳过 ==\n";
$r2 = CP_Push::push_post( 7516 );
echo 'ok=' . var_export( $r2['ok'], true ) . " action={$r2['action']} remote_id={$r2['remote_id']}\n";
echo '   comments_pushed=' . $r2['summary']['comments_pushed'] . ' skipped=' . $r2['summary']['comments_skipped'] . "\n";

echo "== 3) 带特色图的文章 → 媒体上传 ==\n";
global $wpdb;
$pid = (int) $wpdb->get_var( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_thumbnail_id' AND p.post_type='post' AND p.post_status='publish' ORDER BY p.ID LIMIT 1" );
echo "   文章 $pid：{$wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID=%d", $pid))}\n";
$r3 = CP_Push::push_post( $pid );
echo 'ok=' . var_export( $r3['ok'], true ) . " action={$r3['action']} remote_id={$r3['remote_id']}\n";
echo '   media_uploaded=' . $r3['summary']['media_uploaded'] . ' comments=' . $r3['summary']['comments_pushed'] . '/' . $r3['summary']['comments_skipped'] . "\n";

echo "== 4) 仅补推评论（重复安全）==\n";
$r4 = CP_Push::push_comments_only( 7516 );
echo 'ok=' . var_export( $r4['ok'], true ) . ' pushed=' . ( isset( $r4['pushed'] ) ? $r4['pushed'] : '-' ) . ' skipped=' . ( isset( $r4['skipped'] ) ? $r4['skipped'] : '-' ) . "\n";

echo "== 5) 测试连接（模拟器：无 abp_topic → 话题应落为标签）==\n";
$c  = new CP_Client( get_option( 'cp_settings' ) );
$t  = $c->test_connection();
echo 'ok=' . var_export( $t['ok'], true ) . ' user=' . ( isset( $t['user'] ) ? $t['user'] : '?' ) . ' tax=' . implode( ',', $t['taxonomies'] ) . "\n";
echo '   topic_mode 现为：' . ( in_array( 'abp_topic', get_option( 'cp_target_taxonomies', array() ), true ) ? 'abp_topic' : 'post_tag' ) . "\n";

echo "== 6) 推送日志（最近 8 条）==\n";
foreach ( CP_Log::get( 8 ) as $l ) {
	echo '   [' . $l['time'] . '] ' . $l['channel'] . ' ' . $l['level'] . ': ' . $l['message'] . "\n";
}
