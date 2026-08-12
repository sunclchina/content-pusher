<?php
/**
 * 后台管理：文章列表推送状态列、行操作（推送/补推评论）、批量推送、编辑页推送框、通知。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Admin {

	/**
	 * 注册钩子。
	 */
	public static function init() {
		add_filter( 'manage_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-post', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_cp_push_post', array( __CLASS__, 'handle_push_post' ) );
		add_action( 'admin_post_cp_push_comments', array( __CLASS__, 'handle_push_comments' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
	}

	/**
	 * 管理页样式（文章列表推送列/徽章）。
	 */
	public static function admin_css() {
		echo '<style>
			.cp-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; line-height:18px; background:#f0f0f1; color:#50575e; border:1px solid #dcdcde; margin:1px 2px 1px 0; }
			.cp-badge-ok { background:#edfaef; color:#007017; border-color:#b8e6bf; }
			.cp-badge-fail { background:#fcf0f1; color:#b32d2e; border-color:#f1adad; }
			.cp-muted { color:#999; font-size:11px; margin-right:4px; }
		</style>';
	}

	/**
	 * 列表列：推送状态。
	 *
	 * @param array $columns 列。
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['cp_push'] = '推送';
			}
		}
		return $out;
	}

	/**
	 * 渲染推送状态列。
	 *
	 * @param string $column  列名。
	 * @param int    $post_id 文章 ID。
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'cp_push' !== $column ) {
			return;
		}
		$rid   = (int) get_post_meta( $post_id, CP_META_REMOTE_ID, true );
		$err   = (string) get_post_meta( $post_id, CP_META_LAST_ERROR, true );
		$last  = (string) get_post_meta( $post_id, CP_META_LAST_PUSH, true );
		$n     = (int) get_comments( array( 'post_id' => $post_id, 'count' => true ) );
		$pushed = 0;
		if ( $n ) {
			global $wpdb;
			$pushed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
					 WHERE cm.meta_key = %s AND c.comment_post_ID = %d",
					CP_META_REMOTE_COMMENT,
					$post_id
				)
			);
		}
		$lines = array();
		if ( $rid ) {
			$lines[] = '<span class="cp-badge cp-badge-ok">已推送 #' . $rid . '</span>';
		} else {
			$lines[] = '<span class="cp-badge">未推送</span>';
		}
		if ( $err ) {
			$lines[] = '<span class="cp-badge cp-badge-fail" title="' . esc_attr( $err ) . '">失败</span>';
		}
		if ( $n ) {
			$lines[] = sprintf( '<span class="cp-muted">评论 %d/%d</span>', $pushed, $n );
		}
		if ( $last ) {
			$lines[] = '<span class="cp-muted">' . esc_html( substr( $last, 0, 16 ) ) . '</span>';
		}
		echo implode( ' ', $lines );
	}

	/**
	 * 行操作。
	 *
	 * @param array   $actions 操作。
	 * @param WP_Post $post    文章。
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( 'post' !== $post->post_type ) {
			return $actions;
		}
		$push_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cp_push_post&post_id=' . (int) $post->ID ),
			'cp_push_' . (int) $post->ID
		);
		$comments_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cp_push_comments&post_id=' . (int) $post->ID ),
			'cp_push_comments_' . (int) $post->ID
		);
		$actions['cp_push']          = '<a href="' . esc_url( $push_url ) . '">推送到生产站</a>';
		$actions['cp_push_comments'] = '<a href="' . esc_url( $comments_url ) . '">推送评论</a>';
		return $actions;
	}

	/**
	 * 批量操作。
	 *
	 * @param array $actions 操作。
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['cp_bulk_push'] = '推送到生产站（后台排队）';
		return $actions;
	}

	/**
	 * 批量推送：逐篇排 WP-Cron 单次任务（不阻塞页面，后台逐篇执行）。
	 *
	 * @param string $redirect 重定向地址。
	 * @param string $doaction 操作名。
	 * @param array  $post_ids 文章 ID。
	 * @return string
	 */
	public static function handle_bulk( $redirect, $doaction, $post_ids ) {
		if ( 'cp_bulk_push' !== $doaction ) {
			return $redirect;
		}
		$queued = 0;
		foreach ( (array) $post_ids as $id ) {
			$id = (int) $id;
			if ( ! $id ) {
				continue;
			}
			if ( wp_next_scheduled( 'cp_push_post_event', array( $id ) ) ) {
				continue;
			}
			wp_schedule_single_event( time() + 5 + $queued * 5, 'cp_push_post_event', array( $id ) );
			$queued++;
		}
		return add_query_arg( 'cp_msg', rawurlencode( sprintf( '已排队 %d 篇文章，WP-Cron 将逐篇推送到生产站（进度见推送日志）', $queued ) ), $redirect );
	}

	/**
	 * 行操作：推送文章。
	 */
	public static function handle_push_post() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		check_admin_referer( 'cp_push_' . $post_id );
		$r = CP_Push::push_post( $post_id );
		if ( $r['ok'] ) {
			$msg = sprintf(
				'文章 #%d 推送%s成功，远端 ID %d，评论 +%d（跳过 %d）%s',
				$post_id,
				'create' === $r['action'] ? '新建' : '更新',
				$r['remote_id'],
				isset( $r['summary']['comments_pushed'] ) ? $r['summary']['comments_pushed'] : 0,
				isset( $r['summary']['comments_skipped'] ) ? $r['summary']['comments_skipped'] : 0,
				$r['remote_url'] ? '，链接 ' . $r['remote_url'] : ''
			);
		} else {
			$msg = '推送失败：' . $r['error'];
		}
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'post', 'cp_msg' => rawurlencode( $msg ) ), admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * 行操作：仅补推评论。
	 */
	public static function handle_push_comments() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		check_admin_referer( 'cp_push_comments_' . $post_id );
		$r = CP_Push::push_comments_only( $post_id );
		$msg = $r['ok']
			? sprintf( '评论补推完成：新推 %d 条，跳过 %d 条', $r['pushed'], $r['skipped'] )
			: '评论补推失败：' . $r['error'];
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'post', 'cp_msg' => rawurlencode( $msg ) ), admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * 编辑页推送框。
	 *
	 * @param string $post_type 文章类型。
	 * @param WP_Post $post     文章。
	 */
	public static function meta_box( $post_type, $post ) {
		if ( 'post' !== $post_type ) {
			return;
		}
		add_meta_box(
			'cp_push_box',
			'内容推送',
			array( __CLASS__, 'render_meta_box' ),
			'post',
			'side',
			'high'
		);
	}

	/**
	 * 编辑页推送框内容。
	 *
	 * @param WP_Post $post 文章。
	 */
	public static function render_meta_box( $post ) {
		$rid  = (int) get_post_meta( $post->ID, CP_META_REMOTE_ID, true );
		$err  = (string) get_post_meta( $post->ID, CP_META_LAST_ERROR, true );
		$last = (string) get_post_meta( $post->ID, CP_META_LAST_PUSH, true );
		$url  = (string) get_post_meta( $post->ID, CP_META_REMOTE_URL, true );

		echo '<p>';
		if ( $rid ) {
			echo '<span class="cp-badge cp-badge-ok">已推送 #' . $rid . '</span>';
			if ( $url ) {
				echo '<br /><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>';
			}
		} else {
			echo '<span class="cp-badge">未推送</span>';
		}
		if ( $err ) {
			echo '<br /><span class="cp-badge cp-badge-fail" title="' . esc_attr( $err ) . '">上次失败：' . esc_html( $err ) . '</span>';
		}
		if ( $last ) {
			echo '<br /><span class="cp-muted">最近推送：' . esc_html( $last ) . '</span>';
		}
		echo '</p>';

		$push_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cp_push_post&post_id=' . (int) $post->ID ),
			'cp_push_' . (int) $post->ID
		);
		$comments_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cp_push_comments&post_id=' . (int) $post->ID ),
			'cp_push_comments_' . (int) $post->ID
		);
		echo '<p>'
			. '<a class="button button-primary" href="' . esc_url( $push_url ) . '">推送到生产站</a> '
			. '<a class="button" href="' . esc_url( $comments_url ) . '">推送评论</a>'
			. '</p>';
	}

	/**
	 * 后台通知（推送结果）。
	 */
	public static function notices() {
		if ( ! isset( $_GET['cp_msg'] ) ) {
			return;
		}
		$msg = wp_unslash( $_GET['cp_msg'] );
		$cls = 0 === strpos( $msg, '推送失败' ) || 0 === strpos( $msg, '评论补推失败' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}
