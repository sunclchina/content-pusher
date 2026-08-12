<?php
/**
 * 设置页：目标站（生产站）配置 + 推送选项 + 连接测试 + 日志查看。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Settings {

	/**
	 * 默认设置。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'target_url'              => '',   // 目标站地址（必须 https://）
			'app_user'                => '',   // 应用密码用户名
			'app_password'            => '',   // 应用密码
			'auto_push'               => '1',  // 文章发布时自动推送
			'push_comments'           => '1',  // 推送评论（真实评论 + AI 已生成评论）
			'comments_include_pending'=> '',   // 评论含待审
			'topic_mode'              => 'auto', // 话题：auto=目标站有 abp_topic 用它否则标签；post_tag/abp_topic/off
			'sync_images'             => '1',  // 同步特色图与正文图片
			'push_status'             => 'follow', // 远端状态：follow/publish/draft
			'dedup'                   => 'overwrite', // 同名查重：overwrite 覆盖更新 / skip 跳过
			'include_default'         => array( 'cover', 'excerpt', 'comments', 'topics' ), // 默认推送内容
			'timeout'                 => 60,   // 单请求超时（秒）
			'retries'                 => 2,    // 重试次数
		);
	}

	/**
	 * 注册菜单与设置。
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_cp_test_connection', array( __CLASS__, 'handle_test' ) );
		add_action( 'wp_ajax_cp_test_connection', array( __CLASS__, 'handle_test' ) ); // JS 走 admin-ajax.php（ajaxurl）
		add_action( 'admin_post_cp_clear_log', array( __CLASS__, 'handle_clear_log' ) );
	}

	/**
	 * 菜单：主菜单「内容推送」+ 子菜单「设置」「推送」两项。
	 */
	public static function menu() {
		add_menu_page(
			'内容推送',
			'内容推送',
			'manage_options',
			'content-pusher',
			array( __CLASS__, 'render' ),
			'dashicons-migrate',
			81
		);
		// 与主菜单同 slug：点主菜单即进设置页，左侧显示「设置」「推送」两项。
		add_submenu_page(
			'content-pusher',
			'内容推送设置',
			'设置',
			'manage_options',
			'content-pusher',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * 注册设置项。
	 */
	public static function register() {
		register_setting( 'cp_settings_group', CP_OPTION, array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * 设置清洗：URL 强制 https；密码留空则保留旧值；开关归一化。
	 *
	 * @param array $in 提交值。
	 * @return array
	 */
	public static function sanitize( $in ) {
		$d   = self::defaults();
		$out = $d;
		if ( ! is_array( $in ) ) {
			return $out;
		}
		$old = get_option( CP_OPTION, array() );
		$old = is_array( $old ) ? $old : array();

		$url = trim( (string) ( isset( $in['target_url'] ) ? $in['target_url'] : '' ) );
		$url = preg_replace( '#/wp-json/?.*$#i', '', $url );
		$err = CP_Client::validate_url( $url );
		if ( '' !== $err ) {
			add_settings_error( 'cp_settings', 'cp_bad_url', $err, 'error' );
			$out['target_url'] = isset( $old['target_url'] ) ? $old['target_url'] : '';
		} else {
			$out['target_url'] = rtrim( $url, '/' );
		}

		$out['app_user']     = sanitize_text_field( isset( $in['app_user'] ) ? (string) $in['app_user'] : '' );
		$out['app_password'] = isset( $in['app_password'] ) ? trim( (string) $in['app_password'] ) : '';
		if ( '' === $out['app_password'] && isset( $old['app_password'] ) ) {
			$out['app_password'] = $old['app_password']; // 留空 = 保持原密码
		}

		foreach ( array( 'auto_push', 'push_comments', 'comments_include_pending', 'sync_images' ) as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] ) ? '1' : '';
		}
		$out['topic_mode']  = in_array( isset( $in['topic_mode'] ) ? $in['topic_mode'] : '', array( 'auto', 'post_tag', 'abp_topic', 'off' ), true ) ? $in['topic_mode'] : 'auto';
		$out['push_status'] = in_array( isset( $in['push_status'] ) ? $in['push_status'] : '', array( 'follow', 'publish', 'draft' ), true ) ? $in['push_status'] : 'follow';
		$out['dedup']       = in_array( isset( $in['dedup'] ) ? $in['dedup'] : '', array( 'skip', 'overwrite' ), true ) ? $in['dedup'] : 'overwrite';
		$inc = ( isset( $in['include_default'] ) && is_array( $in['include_default'] ) ) ? array_map( 'sanitize_key', wp_unslash( $in['include_default'] ) ) : array();
		$out['include_default'] = array_values( array_intersect( $inc, array( 'cover', 'excerpt', 'comments', 'topics' ) ) );
		if ( ! $out['include_default'] ) {
			$out['include_default'] = array( 'cover', 'excerpt', 'comments', 'topics' );
		}
		$out['timeout']     = min( 120, max( 10, (int) ( isset( $in['timeout'] ) ? $in['timeout'] : 60 ) ) );
		$out['retries']     = min( 5, max( 0, (int) ( isset( $in['retries'] ) ? $in['retries'] : 2 ) ) );
		return $out;
	}

	/**
	 * 设置页渲染。
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = get_option( CP_OPTION, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		?>
		<div class="wrap">
			<h1>内容推送 <span class="cp-sub">本地发送站 → 目标生产站（目标站零插件，仅核心 REST API + 应用密码，全程 HTTPS）</span></h1>

			<?php settings_errors( 'cp_settings' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'cp_settings_group' ); ?>

				<h2 class="title">① 目标站（生产站）</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cp_target_url">目标站地址</label></th>
						<td>
							<input type="url" id="cp_target_url" name="<?php echo esc_attr( CP_OPTION ); ?>[target_url]"
								value="<?php echo esc_attr( $s['target_url'] ); ?>" class="regular-text" placeholder="https://sunclnas.cn" />
							<p class="description">必须 https:// 开头（推送强制 HTTPS 并校验证书）。可粘贴完整 API 地址，如 https://sunclnas.cn/wp-json/。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cp_app_user">应用密码用户名</label></th>
						<td>
							<input type="text" id="cp_app_user" name="<?php echo esc_attr( CP_OPTION ); ?>[app_user]"
								value="<?php echo esc_attr( $s['app_user'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description">目标站后台：用户 → 个人资料 → 应用程序密码（WordPress 核心功能，目标站无需装任何插件）。建议用管理员账号生成，名称如「content-pusher」。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cp_app_password">应用密码</label></th>
						<td>
							<input type="password" id="cp_app_password" name="<?php echo esc_attr( CP_OPTION ); ?>[app_password]"
								value="" class="regular-text" autocomplete="new-password" placeholder="留空表示保留原密码" />
							<p class="description">格式如：xxxx xxxx xxxx xxxx xxxx xxxx（原样填入）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">连接测试</th>
						<td>
							<button type="button" class="button" id="cp-test-btn">测试连接</button>
							<span id="cp-test-result" class="cp-test-result"></span>
							<p class="description">按当前表单填写值测试（无需先保存；密码框留空则用已保存密码）。验证应用密码、读取目标站分类法（决定话题映射：目标站有 abp_topic 话题插件则话题按话题推送，否则落为标签）。</p>
						</td>
					</tr>
				</table>

				<h2 class="title">② 推送选项</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">自动推送</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[auto_push]" value="1" <?php checked( $s['auto_push'], '1' ); ?> />
							文章在本地发布后自动推送到目标站（WP-Cron 后台排队，不阻塞发布）</label>
						</td>
					</tr>
					<tr>
						<th scope="row">远端状态</th>
						<td>
							<select name="<?php echo esc_attr( CP_OPTION ); ?>[push_status]">
								<option value="follow" <?php selected( $s['push_status'], 'follow' ); ?>>跟随本地状态（发布→发布，草稿→草稿）</option>
								<option value="publish" <?php selected( $s['push_status'], 'publish' ); ?>>一律发布</option>
								<option value="draft" <?php selected( $s['push_status'], 'draft' ); ?>>一律草稿</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">评论推送</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[push_comments]" value="1" <?php checked( $s['push_comments'], '1' ); ?> />
							推送文章评论：指文章现有的真实评论，也包括 AI 已生成的评论（按本地时间顺序、保留作者与回复层级）</label>
							<br />
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[comments_include_pending]" value="1" <?php checked( $s['comments_include_pending'], '1' ); ?> />
							连同待审核评论一起推送</label>
						</td>
					</tr>
					<tr>
						<th scope="row">话题推送</th>
						<td>
							<select name="<?php echo esc_attr( CP_OPTION ); ?>[topic_mode]">
								<option value="auto" <?php selected( $s['topic_mode'], 'auto' ); ?>>自动：目标站有 abp_topic 话题插件用它，否则落为标签</option>
								<option value="thread" <?php selected( $s['topic_mode'], 'thread' ); ?>>星河兼容：话题建为 thread 话题帖（目标站装有星河AI工具箱时显示话题）</option>
								<option value="post_tag" <?php selected( $s['topic_mode'], 'post_tag' ); ?>>一律作为标签</option>
								<option value="abp_topic" <?php selected( $s['topic_mode'], 'abp_topic' ); ?>>一律作为 abp_topic 话题分类法（目标站需有该插件）</option>
								<option value="off" <?php selected( $s['topic_mode'], 'off' ); ?>>不推送话题</option>
							</select>
							<p class="description">目标站有相关插件（话题分类法/主题支持）即可显示话题；没有则按标签归档或不显示。
							星河兼容：生产站装有星河AI工具箱时选此项，本地话题以 thread 话题帖形式推送到生产站（/thread/ 话题页），并尝试与文章建立关联。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">图片同步</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[sync_images]" value="1" <?php checked( $s['sync_images'], '1' ); ?> />
							同步特色图与正文图片（本地上传目标站媒体库并重写正文 URL；外部图片转存）</label>
						</td>
					</tr>
					<tr>
						<th scope="row">同名查重</th>
						<td>
							<select name="<?php echo esc_attr( CP_OPTION ); ?>[dedup]">
								<option value="overwrite" <?php selected( $s['dedup'], 'overwrite' ); ?>>覆盖更新（目标站已有同名文章则更新它）</option>
								<option value="skip" <?php selected( $s['dedup'], 'skip' ); ?>>跳过（目标站已有同名文章则不推）</option>
							</select>
							<p class="description">默认查重策略；「推送管理」页每次推送可临时切换。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">默认推送内容</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[include_default][]" value="cover" <?php checked( in_array( 'cover', $s['include_default'], true ) ); ?> /> 封面</label>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[include_default][]" value="excerpt" <?php checked( in_array( 'excerpt', $s['include_default'], true ) ); ?> /> 摘要</label>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[include_default][]" value="comments" <?php checked( in_array( 'comments', $s['include_default'], true ) ); ?> /> 评论</label>
							<label><input type="checkbox" name="<?php echo esc_attr( CP_OPTION ); ?>[include_default][]" value="topics" <?php checked( in_array( 'topics', $s['include_default'], true ) ); ?> /> 话题</label>
							<p class="description">推送默认包含的内容（文章本体与分类/标签始终推送）；「推送管理」页每次可临时勾选。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">超时 / 重试</th>
						<td>
							<input type="number" name="<?php echo esc_attr( CP_OPTION ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="10" max="120" class="small-text" /> 秒/请求，
							<input type="number" name="<?php echo esc_attr( CP_OPTION ); ?>[retries]" value="<?php echo esc_attr( $s['retries'] ); ?>" min="0" max="5" class="small-text" /> 次重试
						</td>
					</tr>
				</table>

				<?php submit_button( '保存设置' ); ?>
			</form>

			<h2 class="title">③ 推送日志</h2>
			<?php self::render_log(); ?>
		</div>

		<script>
		(function () {
			var btn = document.getElementById('cp-test-btn');
			var box = document.getElementById('cp-test-result');
			if (!btn || !box) { return; }
			btn.addEventListener('click', function () {
				box.className = 'cp-test-result cp-test-running';
				box.textContent = '测试中…';
				btn.disabled = true;
				var body = new URLSearchParams();
				body.set('action', 'cp_test_connection');
				body.set('nonce', <?php echo wp_json_encode( wp_create_nonce( 'cp_test' ) ); ?>);
				body.set('target_url', document.getElementById('cp_target_url').value);
				body.set('app_user', document.getElementById('cp_app_user').value);
				body.set('app_password', document.getElementById('cp_app_password').value);
				fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.text(); })
					.then(function (t) {
						var j = null;
						try { j = JSON.parse(t); } catch (e) { j = null; }
						if (j && j.ok) {
							var tax = (j.taxonomies || []).join(', ') || '（无）';
							var topicNote = (j.taxonomies || []).indexOf('abp_topic') >= 0
								? '目标站已有 abp_topic 话题分类法，话题将按话题推送'
								: '目标站暂无 abp_topic 话题分类法，话题将落为标签';
							box.className = 'cp-test-result cp-test-ok';
							box.innerHTML = '✅ 连接成功：用户「' + j.user + '」（' + (j.roles || []).join(',') + '）'
								+ (j.can_edit ? '，可写文章' : '，⚠️ 无编辑权限') + '。分类法：' + tax + '。' + topicNote;
						} else if (j) {
							box.className = 'cp-test-result cp-test-fail';
							box.textContent = '❌ ' + (j.error || JSON.stringify(j));
						} else {
							box.className = 'cp-test-result cp-test-fail';
							box.textContent = '❌ 响应无法解析：' + String(t).slice(0, 300);
						}
					})
					.catch(function (e) { box.className = 'cp-test-result cp-test-fail'; box.textContent = '❌ 请求失败：' + (e && e.message ? e.message : e); })
					.finally(function () { btn.disabled = false; });
			});
		})();
		</script>
		<style>
			.cp-sub { font-size: 12px; font-weight: normal; color: #666; margin-left: 8px; }
			.cp-test-result { margin-left: 10px; font-weight: 600; }
			.cp-test-ok { color: #00a32a; }
			.cp-test-fail { color: #d63638; }
			.cp-test-running { color: #666; }
			.cp-log-table td { vertical-align: top; }
			.cp-log-error { color: #d63638; }
			.cp-log-warn { color: #b26a00; }
		</style>
		<?php
	}

	/**
	 * 日志表格。
	 */
	private static function render_log() {
		$logs = CP_Log::get( 100 );
		if ( ! $logs ) {
			echo '<p>暂无推送日志。</p>';
			return;
		}
		echo '<table class="widefat striped cp-log-table"><thead><tr><th style="width:140px">时间</th><th style="width:90px">通道</th><th style="width:60px">级别</th><th>消息</th></tr></thead><tbody>';
		foreach ( $logs as $l ) {
			$cls = 'cp-log-' . ( isset( $l['level'] ) ? $l['level'] : 'info' );
			echo '<tr>'
				. '<td>' . esc_html( isset( $l['time'] ) ? $l['time'] : '' ) . '</td>'
				. '<td>' . esc_html( isset( $l['channel'] ) ? $l['channel'] : '' ) . '</td>'
				. '<td class="' . esc_attr( $cls ) . '">' . esc_html( isset( $l['level'] ) ? $l['level'] : '' ) . '</td>'
				. '<td>' . esc_html( isset( $l['message'] ) ? $l['message'] : '' ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button" href="'
			. esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cp_clear_log' ), 'cp_clear_log' ) )
			. '" onclick="return confirm(\'确定清空全部推送日志？\');">清空日志</a></p>';
	}

	/**
	 * AJAX：测试连接。优先用表单当前值（无需先保存）；密码留空回退已保存密码。
	 */
	public static function handle_test() {
		check_ajax_referer( 'cp_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '无权限', 403 );
		}
		$saved = get_option( CP_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$form = array(
			'target_url'   => isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '',
			'app_user'     => isset( $_POST['app_user'] ) ? sanitize_text_field( wp_unslash( $_POST['app_user'] ) ) : '',
			'app_password' => isset( $_POST['app_password'] ) ? trim( wp_unslash( $_POST['app_password'] ) ) : '',
		);
		if ( '' === $form['app_password'] ) {
			$form['app_password'] = isset( $saved['app_password'] ) ? $saved['app_password'] : '';
		}
		if ( '' === $form['app_user'] ) {
			$form['app_user'] = isset( $saved['app_user'] ) ? $saved['app_user'] : '';
		}

		$url_err = CP_Client::validate_url( $form['target_url'] );
		if ( '' !== $url_err ) {
			wp_send_json( array( 'ok' => false, 'error' => $url_err ) );
		}
		$form['target_url'] = rtrim( preg_replace( '#/wp-json/?.*$#i', '', $form['target_url'] ), '/' );

		$settings = wp_parse_args( $form, CP_Settings::defaults() );
		$client   = new CP_Client( $settings );
		try {
			wp_send_json( $client->test_connection() );
		} catch ( Throwable $e ) {
			wp_send_json( array( 'ok' => false, 'error' => '服务器异常：' . $e->getMessage() ) );
		}
	}

	/**
	 * 清空日志。
	 */
	public static function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		check_admin_referer( 'cp_clear_log' );
		CP_Log::clear();
		wp_safe_redirect( admin_url( 'admin.php?page=content-pusher' ) );
		exit;
	}
}
