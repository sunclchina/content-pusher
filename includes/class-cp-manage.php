<?php
/**
 * 推送管理页：选择文章 → 单篇一键 / 批量推送；每次可勾选内容（封面/摘要/评论/话题）；
 * 同名查重策略可选（跳过 / 覆盖更新）。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Manage {

	/**
	 * 注册钩子。
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cp_push_one', array( __CLASS__, 'handle_push_one' ) );
		add_action( 'admin_post_cp_bulk_push', array( __CLASS__, 'handle_bulk_push' ) );
		add_action( 'admin_post_cp_full_sync', array( __CLASS__, 'handle_full_sync' ) );
	}

	/**
	 * 子菜单：推送。
	 */
	public static function menu() {
		add_submenu_page(
			'content-pusher',
			'推送管理',
			'推送',
			'edit_posts',
			'cp-push-manage',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * 渲染推送管理页。
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$settings = get_option( CP_OPTION, array() );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), CP_Settings::defaults() );

		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$status = isset( $_GET['cp_status'] ) && in_array( $_GET['cp_status'], array( 'publish', 'draft', 'future', 'pending', 'all' ), true )
			? $_GET['cp_status'] : 'publish';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'all' === $status ? array( 'publish', 'draft', 'future', 'pending' ) : $status,
			'posts_per_page' => 20,
			'paged'          => $paged,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$q = new WP_Query( $args );

		// 当前配置（管理页每次可临时改，默认取设置）。
		$dedup = isset( $_GET['dedup'] ) && in_array( $_GET['dedup'], array( 'skip', 'overwrite' ), true )
			? $_GET['dedup'] : ( isset( $settings['dedup'] ) ? $settings['dedup'] : 'overwrite' );
		$include_default = isset( $settings['include_default'] ) && is_array( $settings['include_default'] )
			? $settings['include_default'] : array( 'cover', 'excerpt', 'comments', 'topics' );
		?>
		<div class="wrap">
			<h1>推送管理 <span class="cp-sub">选择文章 → 单篇一键或批量推送 → 可选封面/摘要/评论/话题 → 同名查重（跳过/覆盖）</span></h1>

			<?php
			$configured = ( new CP_Client( $settings ) )->is_configured();
			if ( ! $configured ) {
				echo '<div class="notice notice-error"><p>尚未配置目标站（设置 → 内容推送 → 目标站地址与应用密码）。</p></div>';
			}
			?>

			<form method="get">
				<input type="hidden" name="page" value="cp-push-manage" />
				<label>状态
					<select name="cp_status">
						<?php foreach ( array( 'publish' => '已发布', 'draft' => '草稿', 'future' => '定时', 'pending' => '待审', 'all' => '全部' ) as $k => $v ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $v ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索标题…" /></label>
				<button class="button" type="submit">筛选</button>
			</form>

			<?php if ( $q->have_posts() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cp-bulk-form">
				<input type="hidden" name="action" value="cp_bulk_push" />
				<?php wp_nonce_field( 'cp_bulk_push' ); ?>

				<div class="cp-config-bar">
					<span class="cp-config-title">推送配置：</span>
					<label>同名查重
						<select name="dedup" id="cp-dedup">
							<option value="overwrite" <?php selected( $dedup, 'overwrite' ); ?>>覆盖更新（同名则更新远端）</option>
							<option value="skip" <?php selected( $dedup, 'skip' ); ?>>跳过（同名则不推）</option>
						</select>
					</label>
					<span class="cp-config-title">包含：</span>
					<label><input type="checkbox" id="cp-inc-cover" <?php checked( in_array( 'cover', $include_default, true ) ); ?> /> 封面</label>
					<label><input type="checkbox" id="cp-inc-excerpt" <?php checked( in_array( 'excerpt', $include_default, true ) ); ?> /> 摘要</label>
					<label><input type="checkbox" id="cp-inc-comments" <?php checked( in_array( 'comments', $include_default, true ) ); ?> /> 评论</label>
					<label><input type="checkbox" id="cp-inc-topics" <?php checked( in_array( 'topics', $include_default, true ) ); ?> /> 话题</label>
					<button class="button button-primary" type="submit" <?php echo $configured ? '' : 'disabled'; ?>>推送勾选文章（后台排队）</button>
					<span class="cp-full-wrap">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cp-full-form" style="display:inline">
							<input type="hidden" name="action" value="cp_full_sync" />
							<?php wp_nonce_field( 'cp_full_sync' ); ?>
							<input type="hidden" name="dedup" data-cp-dedup="1" value="<?php echo esc_attr( $dedup ); ?>" />
							<input type="hidden" name="include[]" value="cover" data-cp-field="inc-cover" />
							<input type="hidden" name="include[]" value="excerpt" data-cp-field="inc-excerpt" />
							<input type="hidden" name="include[]" value="comments" data-cp-field="inc-comments" />
							<input type="hidden" name="include[]" value="topics" data-cp-field="inc-topics" />
							<button type="submit" class="button" <?php echo $configured ? '' : 'disabled'; ?>
								onclick="return confirm('全量同步：将按当前配置推送全部已发布文章（更新+评论+图片），后台排队逐篇执行。确认开始？');">全量同步</button>
						</form>
					</span>
					<span class="cp-config-hint">「全量同步」= 全部已发布文章，手动触发；默认不自动执行。</span>
				</div>

				<table class="widefat striped cp-manage-table">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="cp-check-all" /></td>
							<th>标题</th>
							<th>分类</th>
							<th>状态</th>
							<th>评论</th>
							<th>推送状态</th>
							<th>操作</th>
						</tr>
					</thead>
					<tbody>
						<?php
						while ( $q->have_posts() ) :
							$q->the_post();
							$pid   = (int) get_the_ID();
							$cats  = wp_get_post_categories( $pid, array( 'fields' => 'names' ) );
							$ncomm = (int) get_comments( array( 'post_id' => $pid, 'count' => true ) );
							?>
							<tr>
								<td class="check-column"><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $pid ); ?>" /></td>
								<td><strong><?php echo esc_html( get_the_title() ); ?></strong><br /><span class="cp-muted">ID <?php echo esc_html( $pid ); ?></span></td>
								<td><?php echo esc_html( implode( ', ', $cats ) ); ?></td>
								<td><?php echo esc_html( get_post_status_object( get_post_status() ) ? get_post_status_object( get_post_status() )->label : get_post_status() ); ?></td>
								<td><?php echo esc_html( $ncomm ); ?></td>
								<td><?php CP_Admin::render_column( 'cp_push', $pid ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cp-single-form">
										<input type="hidden" name="action" value="cp_push_one" />
										<?php wp_nonce_field( 'cp_push_one_' . $pid ); ?>
										<input type="hidden" name="post_id" value="<?php echo esc_attr( $pid ); ?>" />
										<input type="hidden" name="dedup" data-cp-dedup="1" value="<?php echo esc_attr( $dedup ); ?>" />
										<input type="hidden" name="include[]" value="cover" data-cp-field="inc-cover" />
										<input type="hidden" name="include[]" value="excerpt" data-cp-field="inc-excerpt" />
										<input type="hidden" name="include[]" value="comments" data-cp-field="inc-comments" />
										<input type="hidden" name="include[]" value="topics" data-cp-field="inc-topics" />
										<button type="submit" class="button button-small" <?php echo $configured ? '' : 'disabled'; ?>>一键推送</button>
									</form>
								</td>
							</tr>
						<?php endwhile; ?>
					</tbody>
				</table>

				<?php // 批量表单的配置隐藏字段（单篇表单每行自带一份）。 ?>
				<input type="hidden" name="dedup" data-cp-dedup="1" value="<?php echo esc_attr( $dedup ); ?>" />
				<input type="hidden" name="include[]" value="cover" data-cp-field="inc-cover" />
				<input type="hidden" name="include[]" value="excerpt" data-cp-field="inc-excerpt" />
				<input type="hidden" name="include[]" value="comments" data-cp-field="inc-comments" />
				<input type="hidden" name="include[]" value="topics" data-cp-field="inc-topics" />
			</form>

			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $q->max_num_pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					);
					?>
				</div>
			</div>
			<?php else : ?>
				<p>没有符合条件的文章。</p>
			<?php endif; ?>
			<?php wp_reset_postdata(); ?>
		</div>

		<script>
		(function () {
			var checkAll = document.getElementById('cp-check-all');
			if (checkAll) {
				checkAll.addEventListener('change', function () {
					document.querySelectorAll('#cp-bulk-form input[name="post_ids[]"]').forEach(function (cb) {
						cb.checked = checkAll.checked;
					});
				});
			}
			// 配置同步：把顶部配置写入所有表单（批量 + 每行单篇）的 hidden 字段。
			function cpSync() {
				var inc = { 'cover': 'cp-inc-cover', 'excerpt': 'cp-inc-excerpt', 'comments': 'cp-inc-comments', 'topics': 'cp-inc-topics' };
				Object.keys(inc).forEach(function (k) {
					var checked = document.getElementById(inc[k]) && document.getElementById(inc[k]).checked;
					document.querySelectorAll('input[data-cp-field="' + inc[k] + '"]').forEach(function (h) {
						h.disabled = !checked;
					});
				});
				var dedup = document.getElementById('cp-dedup');
				if (dedup) {
					document.querySelectorAll('input[data-cp-dedup]').forEach(function (h) { h.value = dedup.value; });
				}
			}
			['cp-inc-cover', 'cp-inc-excerpt', 'cp-inc-comments', 'cp-inc-topics', 'cp-dedup'].forEach(function (id) {
				var el = document.getElementById(id);
				if (el) { el.addEventListener('change', cpSync); }
			});
			cpSync();
		})();
		</script>
		<style>
			.cp-sub { font-size: 12px; font-weight: normal; color: #666; margin-left: 8px; }
			.cp-config-bar { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 10px 12px; margin: 12px 0; display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
			.cp-config-title { font-weight: 600; }
			.cp-manage-table .check-column, .cp-manage-table td.check-column { padding: 8px 0 8px 8px; }
			.cp-muted { color: #999; font-size: 11px; }
		</style>
		<?php
	}

	/**
	 * 全量同步：按当前配置（查重/包含内容）排队推送全部已发布文章，WP-Cron 后台逐篇执行。
	 * 默认不执行，仅手动点击触发。
	 */
	public static function handle_full_sync() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		check_admin_referer( 'cp_full_sync' );
		$opts = self::read_opts();
		$opts = array(
			'include' => $opts['include'],
			'dedup'   => $opts['dedup'],
		);
		$posts = get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$queued = 0;
		foreach ( $posts as $id ) {
			$id = (int) $id;
			if ( wp_next_scheduled( 'cp_push_post_event', array( $id, $opts ) ) ) {
				continue;
			}
			wp_schedule_single_event( time() + 5 + $queued * 5, 'cp_push_post_event', array( $id, $opts ) );
			$queued++;
		}
		$msg = sprintf(
			'全量同步已排队 %d 篇（查重=%s，包含=%s），WP-Cron 后台逐篇执行，进度见推送日志与列表状态',
			$queued,
			'overwrite' === $opts['dedup'] ? '覆盖' : '跳过',
			implode( ',', $opts['include'] )
		);
		wp_safe_redirect( add_query_arg( array( 'page' => 'cp-push-manage', 'cp_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 从请求读取推送配置（include/dedup）。
	 *
	 * @return array
	 */
	private static function read_opts() {
		$include = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['include'] ) ) : array();
		$dedup   = isset( $_POST['dedup'] ) && in_array( $_POST['dedup'], array( 'skip', 'overwrite' ), true ) ? $_POST['dedup'] : 'overwrite';
		return array( 'include' => $include, 'dedup' => $dedup );
	}

	/**
	 * 单篇推送（同步执行，立即回显结果）。
	 */
	public static function handle_push_one() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'cp_push_one_' . $post_id );
		if ( ! $post_id ) {
			wp_die( '缺少文章 ID' );
		}
		$opts = self::read_opts();
		$r    = CP_Push::push_post( $post_id, $opts );
		if ( $r['ok'] ) {
			$map = array( 'create' => '新建', 'update' => '更新', 'skipped' => '查重跳过' );
			$msg = sprintf(
				'文章 #%d 推送%s：远端 ID=%d，评论 +%d（跳过 %d），图片 %d%s',
				$post_id,
				isset( $map[ $r['action'] ] ) ? $map[ $r['action'] ] : $r['action'],
				$r['remote_id'],
				isset( $r['summary']['comments_pushed'] ) ? $r['summary']['comments_pushed'] : 0,
				isset( $r['summary']['comments_skipped'] ) ? $r['summary']['comments_skipped'] : 0,
				isset( $r['summary']['media_uploaded'] ) ? $r['summary']['media_uploaded'] : 0,
				$r['remote_url'] ? '，链接 ' . $r['remote_url'] : ''
			);
		} else {
			$msg = '推送失败：' . $r['error'];
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'cp-push-manage', 'cp_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 批量推送：逐篇排 WP-Cron 单次任务（带配置参数），不阻塞页面。
	 */
	public static function handle_bulk_push() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1 );
		}
		check_admin_referer( 'cp_bulk_push' );
		$ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['post_ids'] ) ) : array();
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'cp-push-manage', 'cp_msg' => rawurlencode( '未勾选任何文章' ) ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$opts  = self::read_opts();
		$opts  = array(
			'include' => $opts['include'],
			'dedup'   => $opts['dedup'],
		);
		$queued = 0;
		foreach ( $ids as $id ) {
			if ( wp_next_scheduled( 'cp_push_post_event', array( $id, $opts ) ) ) {
				continue;
			}
			wp_schedule_single_event( time() + 5 + $queued * 5, 'cp_push_post_event', array( $id, $opts ) );
			$queued++;
		}
		$msg = sprintf( '已排队 %d 篇文章推送到目标站（查重=%s，包含=%s），WP-Cron 逐篇执行，进度见推送日志', $queued, 'overwrite' === $opts['dedup'] ? '覆盖' : '跳过', implode( ',', $opts['include'] ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'cp-push-manage', 'cp_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
