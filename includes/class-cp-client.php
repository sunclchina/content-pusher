<?php
/**
 * HTTPS REST 客户端：目标站零插件，走 WordPress 核心 REST API（wp/v2）。
 *
 * - 认证：应用密码（Basic Auth，WP 5.6+ 核心功能，目标站无需任何插件）
 * - 传输：强制 https + sslverify=true（证书校验，配合目标站 HSTS）
 * - 重试：网络错误 / 5xx 指数退避重试；4xx 直接抛错
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 推送异常。code 存放目标站 HTTP 状态码（0 表示网络/本地错误）。
 */
class CP_Error extends Exception {}

class CP_Client {

	/** @var string 目标站地址，如 https://sunclnas.cn */
	protected $base_url = '';

	/** @var string 应用密码用户名 */
	protected $username = '';

	/** @var string 应用密码 */
	protected $password = '';

	/** @var int 单请求超时（秒） */
	protected $timeout = 60;

	/** @var int 重试次数（网络错误/5xx） */
	protected $retries = 2;

	/**
	 * @param array $settings 插件设置（CP_OPTION）。
	 */
	public function __construct( $settings = array() ) {
		$this->base_url = rtrim( isset( $settings['target_url'] ) ? (string) $settings['target_url'] : '', '/' );
		$this->username = isset( $settings['app_user'] ) ? (string) $settings['app_user'] : '';
		$this->password = isset( $settings['app_password'] ) ? (string) $settings['app_password'] : '';
		if ( isset( $settings['timeout'] ) ) {
			$this->timeout = max( 10, min( 120, (int) $settings['timeout'] ) );
		}
		if ( isset( $settings['retries'] ) ) {
			$this->retries = max( 0, min( 5, (int) $settings['retries'] ) );
		}
	}

	/**
	 * 从选项构造客户端。
	 *
	 * @return CP_Client
	 */
	public static function from_options() {
		$settings = get_option( CP_OPTION, array() );
		return new self( is_array( $settings ) ? $settings : array() );
	}

	/**
	 * 是否已配置（地址 + 用户名 + 密码齐全）。
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base_url && '' !== $this->username && '' !== $this->password;
	}

	/**
	 * 校验目标站地址：必须 https。
	 *
	 * @param string $url 用户输入地址。
	 * @return string 空串=合法；否则返回错误信息。
	 */
	public static function validate_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '目标站地址不能为空';
		}
		// 允许直接粘贴 /wp-json 开头的完整 API 地址，自动剥掉。
		$url = preg_replace( '#/wp-json/?.*$#i', '', $url );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '目标站地址格式无效（需 https://域名）';
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return '推送必须走 HTTPS：目标站地址需以 https:// 开头';
		}
		return '';
	}

	/**
	 * 统一请求。4xx 抛 CP_Error（code=HTTP 状态）；网络错误与 5xx 重试后仍失败抛 CP_Error。
	 *
	 * @param string     $method        GET/POST/PUT/DELETE。
	 * @param string     $path          API 路径，如 /wp-json/wp/v2/posts。
	 * @param array|null $data          数组→JSON 正文；字符串→原始字节（媒体上传）。
	 * @param array      $extra_headers 附加请求头。
	 * @return array|string 解析后的 JSON 数组；非 JSON 时返回原始字符串。
	 * @throws CP_Error
	 */
	public function request( $method, $path, $data = null, $extra_headers = array() ) {
		if ( ! $this->is_configured() ) {
			throw new CP_Error( '未配置目标站（设置 → 内容推送 → 目标站地址 / 应用密码）' );
		}
		$url  = $this->base_url . $path;
		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => $this->timeout,
			'sslverify'   => true, // HTTPS 强制校验证书，不允许关闭
			'redirection' => 0,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36', // 常规浏览器 UA，避免被安全插件按可疑 UA 拦截
			'headers'     => array_merge(
				array(
					'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
					'Accept'        => 'application/json',
				),
				$extra_headers
			),
		);
		if ( null !== $data ) {
			if ( is_array( $data ) ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $data );
			} else {
				$args['body'] = $data; // 原始字节
			}
		}

		$last_error = '';
		for ( $attempt = 0; $attempt <= $this->retries; $attempt++ ) {
			$resp = wp_remote_request( $url, $args );
			if ( is_wp_error( $resp ) ) {
				$last_error = '网络错误：' . $resp->get_error_message();
				if ( $attempt < $this->retries ) {
					usleep( 500000 * ( $attempt + 1 ) );
					continue;
				}
				throw new CP_Error( $last_error );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = wp_remote_retrieve_body( $resp );
			if ( $code >= 200 && $code < 300 ) {
				$json = json_decode( $body, true );
				return null === $json ? $body : $json;
			}
			// Wordfence 速率限制（403「访问过于频繁，请稍后再试」，消息为 Unicode 转义 JSON）：等待后重试。
			if ( 403 === $code && $attempt < 2 && false !== strpos( $body, 'wp_die' ) ) {
				$decoded = json_decode( $body, true );
				$msg     = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : $body;
				if ( false !== strpos( $msg, '频繁' ) || false !== strpos( $msg, '稍后' ) ) {
					$wait = 60 * ( $attempt + 1 );
					CP_Log::warn( 'connection', sprintf( '目标站限流（403），等待 %d 秒后重试：%s', $wait, self::snippet( $msg, 120 ) ) );
					sleep( $wait );
					continue;
				}
			}
			if ( $code >= 500 ) {
				if ( $attempt < $this->retries ) {
					usleep( 500000 * ( $attempt + 1 ) );
					continue;
				}
				throw new CP_Error( sprintf( '目标站 %d 错误：%s', $code, self::snippet( $body ) ), $code );
			}
			throw new CP_Error( sprintf( '目标站拒绝（%d）：%s', $code, self::snippet( $body ) ), $code );
		}
		throw new CP_Error( $last_error ? $last_error : '未知请求错误' );
	}

	/**
	 * 截断响应片段（日志用，避免刷屏）。
	 *
	 * @param mixed $body 响应体。
	 * @param int   $len  最大长度。
	 * @return string
	 */
	public static function snippet( $body, $len = 200 ) {
		$t = is_string( $body ) ? $body : wp_json_encode( $body );
		$t = preg_replace( '/\s+/', ' ', trim( (string) $t ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $t ) > $len ) {
			return mb_substr( $t, 0, $len ) . '…';
		}
		return strlen( $t ) > $len ? substr( $t, 0, $len ) . '…' : $t;
	}

	/**
	 * GET 请求。
	 *
	 * @param string $path  路径。
	 * @param array  $query 查询参数。
	 * @return array|string
	 * @throws CP_Error
	 */
	public function get( $path, $query = array() ) {
		if ( $query ) {
			$path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}
		return $this->request( 'GET', $path );
	}

	/**
	 * POST 请求（JSON）。
	 *
	 * @param string $path 路径。
	 * @param array  $data 数据。
	 * @return array|string
	 * @throws CP_Error
	 */
	public function post( $path, $data ) {
		return $this->request( 'POST', $path, $data );
	}

	/**
	 * PUT 请求（JSON）。
	 *
	 * @param string $path 路径。
	 * @param array  $data 数据。
	 * @return array|string
	 * @throws CP_Error
	 */
	public function put( $path, $data ) {
		return $this->request( 'PUT', $path, $data );
	}

	/**
	 * 上传图片字节到目标站媒体库（wp/v2/media）。
	 *
	 * @param string $bytes    图片二进制。
	 * @param string $filename 文件名。
	 * @param string $mime     MIME 类型。
	 * @return array{id:int, source_url:string}
	 * @throws CP_Error
	 */
	public function upload_media( $bytes, $filename, $mime = '' ) {
		if ( '' === $bytes || strlen( $bytes ) < 10 ) {
			throw new CP_Error( '媒体文件内容为空：' . $filename );
		}
		$fn = sanitize_file_name( $filename );
		if ( '' === $fn ) {
			$fn = 'image-' . time() . '.jpg';
		}
		$headers = array(
			'Content-Disposition' => 'attachment; filename="' . $fn . '"',
			'Content-Type'        => $mime ? $mime : 'application/octet-stream',
		);
		$media = $this->request( 'POST', '/wp-json/wp/v2/media', $bytes, $headers );
		if ( ! is_array( $media ) || empty( $media['id'] ) ) {
			throw new CP_Error( '媒体上传未返回 id：' . self::snippet( $media ) );
		}
		return array(
			'id'         => (int) $media['id'],
			'source_url' => isset( $media['source_url'] ) ? (string) $media['source_url'] : '',
		);
	}

	/**
	 * 测试连接：验证应用密码、探测目标站分类法（决定话题映射方式）。
	 *
	 * @return array{ok:bool, error?:string, user?:string, roles?:array, can_edit?:bool, taxonomies?:array}
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'error' => '请先填写目标站地址与应用密码' );
		}
		try {
			// context=edit + _fields 明确请求 roles/capabilities（默认 view 上下文不返回这两个字段，会误判权限）。
			$me  = $this->get( '/wp-json/wp/v2/users/me', array( 'context' => 'edit', '_fields' => 'id,name,slug,roles,capabilities' ) );
			$tax = $this->get( '/wp-json/wp/v2/taxonomies' );
			$taxes = is_array( $tax ) ? array_keys( $tax ) : array();
			update_option( CP_TAX_CACHE, $taxes, false );
			$caps = isset( $me['capabilities'] ) ? (array) $me['capabilities'] : array();
			return array(
				'ok'         => true,
				'user'       => isset( $me['name'] ) ? $me['name'] : ( isset( $me['slug'] ) ? $me['slug'] : '?' ),
				'roles'      => isset( $me['roles'] ) ? $me['roles'] : array(),
				'can_edit'   => ! empty( $caps['edit_posts'] ) || ! empty( $caps['manage_options'] ),
				'taxonomies' => $taxes,
			);
		} catch ( CP_Error $e ) {
			CP_Log::error( 'connection', '连接测试失败：' . $e->getMessage() );
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}
}
