<?php
/**
 * class-cp-updater.php — GitHub Release 自动升级。
 *
 * 原理：接入 WordPress 标准更新通道（update_plugins transient + plugins_api），
 * 从 GitHub Releases API 拉取最新版本，匹配 zip 包：
 *   优先 Release Asset（zip 根目录即 content-pusher，WP 直接识别），
 *   无 Asset 时回退 Source code zip（配合 upgrader_source_selection 重命名目录）。
 * 后台「插件」页出现标准「有可用更新」提示，一键走 WP 自带升级流程。
 *
 * 仓库：sunclchina/content-pusher（GitHub Releases）。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Updater {

	const CACHE_KEY = 'cp_gh_release_cache';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	const OWNER = 'sunclchina';
	const REPO  = 'content-pusher';

	/**
	 * 初始化：钩子挂载（由主文件调用一次）。
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * 本插件 basename（content-pusher/content-pusher.php）。
	 *
	 * @return string
	 */
	public static function plugin_basename() {
		return plugin_basename( CP_PLUGIN_FILE );
	}

	/**
	 * 拉取 GitHub 最新 Release（带 12h 缓存；force 强制刷新）。
	 *
	 * @param bool $force 是否忽略缓存。
	 * @return array|null 失败返回 null（静默，不影响站点）。
	 */
	public static function get_remote_release( $force = false ) {
		$cache = $force ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cache ) && ! empty( $cache['tag_name'] ) ) {
			return $cache;
		}
		$url = 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest';
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'User-Agent' => 'Content-Pusher/' . CP_VERSION,
				'Accept'     => 'application/vnd.github+json',
			),
		);
		$resp = wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) || 0 === wp_remote_retrieve_response_code( $resp ) ) {
			$args2              = $args;
			$args2['sslverify'] = false;
			$args2['timeout']   = 20;
			$resp = wp_remote_get( $url, $args2 );
		}
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}
		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * 注入标准更新通道（pre_set_site_transient_update_plugins）。
	 *
	 * @param object $transient 更新 transient。
	 * @return object
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$base    = self::plugin_basename();
		$release = self::get_remote_release();
		if ( ! $release ) {
			return $transient;
		}
		$remote_ver = ltrim( (string) $release['tag_name'], 'vV' );
		if ( version_compare( $remote_ver, CP_VERSION, '<=' ) ) {
			return $transient;
		}
		$package = self::package_url( $release );
		if ( ! $package ) {
			return $transient;
		}
		$obj               = new stdClass();
		$obj->slug         = dirname( $base );
		$obj->plugin       = $base;
		$obj->new_version  = $remote_ver;
		$obj->url          = isset( $release['html_url'] ) ? $release['html_url'] : '';
		$obj->package      = $package;
		$obj->tested       = '7.0';
		$obj->requires_php = '7.4';
		$obj->id           = 'github.com/' . self::OWNER . '/' . self::REPO . '/' . $remote_ver;
		$transient->response[ $base ] = $obj;
		return $transient;
	}

	/**
	 * 计算下载包地址。
	 *
	 * @param array $release GitHub release 数据。
	 * @return string 空串表示无可用包。
	 */
	public static function package_url( $release ) {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
		foreach ( $assets as $a ) {
			$name = isset( $a['name'] ) ? (string) $a['name'] : '';
			if ( false !== strpos( $name, 'content-pusher' ) && '.zip' === substr( $name, -4 ) ) {
				return isset( $a['browser_download_url'] ) ? $a['browser_download_url'] : '';
			}
		}
		if ( ! empty( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}
		return '';
	}

	/**
	 * Source code zip 顶层目录是 {repo}-{tag}，重命名为 content-pusher（仅处理本插件升级）。
	 *
	 * @param string      $source        解压后源目录。
	 * @param string      $remote_source 远端临时目录。
	 * @param WP_Upgrader $upgrader      升级器实例。
	 * @param array       $hook_extra    额外参数。
	 * @return string
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
		if ( ! $source || ! is_dir( $source ) ) {
			return $source;
		}
		$base = self::plugin_basename();
		if ( ! $hook_extra || empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $base ) {
			return $source;
		}
		$slug = dirname( $base );
		$src  = rtrim( $source, '/\\' );
		$new  = rtrim( dirname( $source ), '/\\' ) . DIRECTORY_SEPARATOR . $slug;
		if ( $src === rtrim( $new, '/\\' ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $new, true );
			$wp_filesystem->move( $src, $new );
		} elseif ( @rename( $src, $new ) ) { // phpcs:ignore
			// PHP 原生 rename 兜底。
		} else {
			return $source;
		}
		return $new;
	}

	/**
	 * 插件「查看详情」数据（plugins_api）。
	 *
	 * @param mixed  $res    默认结果。
	 * @param string $action 动作名。
	 * @param object $args   请求参数。
	 * @return mixed
	 */
	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $res;
		}
		if ( dirname( self::plugin_basename() ) !== $args->slug ) {
			return $res;
		}
		$release = self::get_remote_release();
		if ( ! $release ) {
			return $res;
		}
		$info                = new stdClass();
		$info->name          = '内容推送 Content Pusher';
		$info->slug          = $args->slug;
		$info->version       = ltrim( (string) $release['tag_name'], 'vV' );
		$info->author        = '<a href="https://sunclnas.cn/">qingya</a>';
		$info->homepage      = 'https://github.com/' . self::OWNER . '/' . self::REPO;
		$info->requires      = '6.0';
		$info->tested        = '7.0';
		$info->requires_php  = '7.4';
		$info->download_link = self::package_url( $release );
		$info->sections      = array(
			'description' => '站间内容推送：本地发送站 → 目标生产站（目标站零插件，HTTPS 实时推送文章/评论/话题）。',
			'changelog'   => isset( $release['body'] ) ? nl2br( esc_html( (string) $release['body'] ) ) : '',
		);
		return $info;
	}

	/**
	 * 强制检查更新（设置页「检查更新」用）。
	 *
	 * @return array
	 */
	public static function force_check() {
		delete_site_transient( self::CACHE_KEY );
		$release = self::get_remote_release( true );
		if ( ! $release ) {
			return array(
				'ok'    => false,
				'error' => 'GitHub 不可达或仓库不存在（检查网络与 sunclchina/content-pusher）',
			);
		}
		$remote_ver = ltrim( (string) $release['tag_name'], 'vV' );
		$has_update = version_compare( $remote_ver, CP_VERSION, '>' );
		return array(
			'ok'          => true,
			'current'     => CP_VERSION,
			'latest'      => $remote_ver,
			'has_update'  => $has_update,
			'release_url' => isset( $release['html_url'] ) ? $release['html_url'] : '',
			'package'     => self::package_url( $release ),
			'update_url'  => $has_update ? admin_url( 'update-core.php' ) : '',
		);
	}
}
