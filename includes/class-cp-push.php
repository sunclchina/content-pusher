<?php
/**
 * 核心推送逻辑：文章（含分类/标签/话题/特色图/正文图片/摘要）+ 评论（真实评论与 AI 已生成评论）。
 *
 * 匹配规则：优先用本地记录的远端 ID（_cp_remote_id）；无记录则按 slug 匹配目标站文章（同名更新，不重复建）；
 * 仍未命中则新建。评论按本地 ID 记录远端评论 ID（_cp_remote_comment_id），重推自动跳过已推评论。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Push {

	/**
	 * 合并默认设置。
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( CP_OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), CP_Settings::defaults() );
	}

	/**
	 * 推送一篇文章（含评论）。
	 *
	 * @param int   $post_id 本地文章 ID。
	 * @param array $opts    预留选项。
	 * @return array{ok:bool, action:string, remote_id?:int, remote_url?:string, error?:string, summary:array}
	 */
	public static function push_post( $post_id, $opts = array() ) {
		$settings = self::settings();
		$client   = new CP_Client( $settings );
		if ( ! $client->is_configured() ) {
			self::mark_error( $post_id, '未配置目标站（设置 → 内容推送）' );
			CP_Log::error( 'push', sprintf( '文章 %d 推送跳过：未配置目标站', $post_id ) );
			return array( 'ok' => false, 'action' => 'error', 'error' => '未配置目标站', 'summary' => array() );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return array( 'ok' => false, 'action' => 'error', 'error' => '文章不存在', 'summary' => array() );
		}
		if ( 'trash' === $post->post_status ) {
			return array( 'ok' => false, 'action' => 'error', 'error' => '回收站文章不推送', 'summary' => array() );
		}

		@set_time_limit( 0 );
		$summary = array(
			'media_uploaded'   => 0,
			'comments_pushed'  => 0,
			'comments_skipped' => 0,
			'terms_created'    => 0,
			'details'          => array(),
		);

		try {
			$remote_id = self::resolve_remote_post( $client, $post );
			if ( $remote_id ) {
				$action = 'update';
				self::update_remote_post( $client, $remote_id, $post, $settings, $summary );
			} else {
				$action = 'create';
				$remote_id = self::create_remote_post( $client, $post, $settings, $summary );
			}

			update_post_meta( $post_id, CP_META_REMOTE_ID, $remote_id );
			update_post_meta( $post_id, CP_META_LAST_PUSH, current_time( 'mysql' ) );
			delete_post_meta( $post_id, CP_META_LAST_ERROR );

			if ( ! empty( $settings['push_comments'] ) ) {
				$cr = self::push_comments( $client, $post_id, $remote_id, $settings );
				$summary['comments_pushed']  = $cr['pushed'];
				$summary['comments_skipped'] = $cr['skipped'];
			}

			$remote_url = (string) get_post_meta( $post_id, CP_META_REMOTE_URL, true );
			CP_Log::info(
				'push',
				sprintf(
					'文章 %d《%s》推送%s：远端 ID=%d，评论 +%d（跳过 %d），图片上传 %d，新建术语 %d%s',
					$post_id,
					$post->post_title,
					'create' === $action ? '新建' : '更新',
					$remote_id,
					$summary['comments_pushed'],
					$summary['comments_skipped'],
					$summary['media_uploaded'],
					$summary['terms_created'],
					$remote_url ? '，链接 ' . $remote_url : ''
				)
			);
			return array(
				'ok'         => true,
				'action'     => $action,
				'remote_id'  => $remote_id,
				'remote_url' => $remote_url,
				'summary'    => $summary,
			);
		} catch ( CP_Error $e ) {
			self::mark_error( $post_id, $e->getMessage() );
			CP_Log::error( 'push', sprintf( '文章 %d《%s》推送失败：%s', $post_id, $post->post_title, $e->getMessage() ) );
			return array( 'ok' => false, 'action' => 'error', 'error' => $e->getMessage(), 'summary' => $summary );
		}
	}

	/**
	 * 仅补推评论（文章已在远端时）。
	 *
	 * @param int $post_id 本地文章 ID。
	 * @return array{ok:bool, error?:string, pushed?:int, skipped?:int}
	 */
	public static function push_comments_only( $post_id ) {
		$remote_id = (int) get_post_meta( $post_id, CP_META_REMOTE_ID, true );
		if ( ! $remote_id ) {
			return array( 'ok' => false, 'error' => '该文章尚未推送过，请先推送文章本体' );
		}
		$client = new CP_Client( self::settings() );
		if ( ! $client->is_configured() ) {
			return array( 'ok' => false, 'error' => '未配置目标站' );
		}
		try {
			$cr = self::push_comments( $client, $post_id, $remote_id, self::settings() );
			CP_Log::info( 'comment', sprintf( '文章 %d 评论补推：新推 %d，跳过 %d', $post_id, $cr['pushed'], $cr['skipped'] ) );
			return array( 'ok' => true, 'pushed' => $cr['pushed'], 'skipped' => $cr['skipped'] );
		} catch ( CP_Error $e ) {
			CP_Log::error( 'comment', sprintf( '文章 %d 评论补推失败：%s', $post_id, $e->getMessage() ) );
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	/* ================= 文章主体 ================= */

	/**
	 * 解析远端文章：本地记录 ID → slug 匹配 → 0（需新建）。
	 *
	 * @param CP_Client $client 客户端。
	 * @param WP_Post   $post   本地文章。
	 * @return int 远端文章 ID，0 表示不存在。
	 * @throws CP_Error
	 */
	private static function resolve_remote_post( $client, $post ) {
		$rid = (int) get_post_meta( $post->ID, CP_META_REMOTE_ID, true );
		if ( $rid ) {
			try {
				$r = $client->get( '/wp-json/wp/v2/posts/' . $rid, array( 'context' => 'edit' ) );
				if ( is_array( $r ) && ! empty( $r['id'] ) ) {
					return (int) $r['id'];
				}
			} catch ( CP_Error $e ) {
				// 远端已删除（404/410）→ 重新创建；其余错误上抛。
				if ( 404 !== $e->getCode() && 410 !== $e->getCode() ) {
					throw $e;
				}
			}
		}
		$found = $client->get(
			'/wp-json/wp/v2/posts',
			array(
				'slug'     => $post->post_name,
				'status'   => 'publish,draft,future,pending',
				'per_page' => 1,
				'context'  => 'edit',
			)
		);
		if ( is_array( $found ) && ! empty( $found[0]['id'] ) ) {
			return (int) $found[0]['id'];
		}
		return 0;
	}

	/**
	 * 新建远端文章。
	 *
	 * @param CP_Client $client   客户端。
	 * @param WP_Post   $post     本地文章。
	 * @param array     $settings 设置。
	 * @param array     $summary  汇总（引用）。
	 * @return int 远端文章 ID。
	 * @throws CP_Error
	 */
	private static function create_remote_post( $client, $post, $settings, &$summary ) {
		$payload = self::build_payload( $client, $post, $settings, $summary, true );
		try {
			$r = $client->post( '/wp-json/wp/v2/posts', $payload );
		} catch ( CP_Error $e ) {
			// slug 被占用（远端存在同名但状态不符/在回收站）→ 去掉 slug 让 WP 自动取唯一。
			if ( 400 === $e->getCode() && ! empty( $payload['slug'] ) ) {
				unset( $payload['slug'] );
				$r = $client->post( '/wp-json/wp/v2/posts', $payload );
			} else {
				throw $e;
			}
		}
		if ( ! is_array( $r ) || empty( $r['id'] ) ) {
			throw new CP_Error( '创建远端文章未返回 id：' . CP_Client::snippet( $r ) );
		}
		$rid = (int) $r['id'];
		update_post_meta( $post->ID, CP_META_REMOTE_URL, isset( $r['link'] ) ? (string) $r['link'] : '' );
		return $rid;
	}

	/**
	 * 更新远端文章。
	 *
	 * @param CP_Client $client    客户端。
	 * @param int       $remote_id 远端文章 ID。
	 * @param WP_Post   $post      本地文章。
	 * @param array     $settings  设置。
	 * @param array     $summary   汇总（引用）。
	 * @throws CP_Error
	 */
	private static function update_remote_post( $client, $remote_id, $post, $settings, &$summary ) {
		// 更新不传日期：远端日期以首次创建为准（本地副本日期一致，避免误改）。
		$payload = self::build_payload( $client, $post, $settings, $summary, false );
		$r = $client->put( '/wp-json/wp/v2/posts/' . $remote_id, $payload );
		if ( ! is_array( $r ) || empty( $r['id'] ) ) {
			throw new CP_Error( '更新远端文章未返回 id：' . CP_Client::snippet( $r ) );
		}
		update_post_meta( $post->ID, CP_META_REMOTE_URL, isset( $r['link'] ) ? (string) $r['link'] : '' );
	}

	/**
	 * 组装远端文章 payload。
	 *
	 * @param CP_Client $client   客户端。
	 * @param WP_Post   $post     本地文章。
	 * @param array     $settings 设置。
	 * @param array     $summary  汇总（引用）。
	 * @param bool      $is_create 是否新建（新建带日期，更新不带）。
	 * @return array
	 * @throws CP_Error
	 */
	private static function build_payload( $client, $post, $settings, &$summary, $is_create ) {
		$content = $post->post_content;
		if ( ! empty( $settings['sync_images'] ) ) {
			$content = self::rewrite_content_images( $client, $content, $summary );
		}

		$payload = array(
			'title'          => $post->post_title,
			'content'        => $content,
			'excerpt'        => $post->post_excerpt,
			'status'         => self::remote_status( $post, $settings ),
			'comment_status' => $post->comment_status ? $post->comment_status : 'open',
			'ping_status'    => $post->ping_status ? $post->ping_status : 'open',
		);
		// 仅在新建时传 slug（中文别名已是编码态，更新时重传可能被二次清洗导致远端链接变化；
		// 新建后本地即记录远端 ID，后续推送走 ID 匹配，不会因 slug 差异重复建文）。
		if ( $is_create && $post->post_name ) {
			$payload['slug'] = $post->post_name;
		}
		if ( $is_create && $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
			$payload['date_gmt'] = $post->post_date_gmt;
		}

		// 分类（按名称匹配，缺失则创建）。空数组不传：更新时避免清空远端分类。
		$cat_ids = array();
		foreach ( wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) as $name ) {
			$id = self::ensure_term( $client, 'category', $name, $settings, $summary );
			if ( $id ) {
				$cat_ids[] = $id;
			}
		}
		if ( $cat_ids ) {
			$payload['categories'] = $cat_ids;
		}

		// 标签
		$tag_ids = array();
		foreach ( wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) as $name ) {
			$id = self::ensure_term( $client, 'post_tag', $name, $settings, $summary );
			if ( $id ) {
				$tag_ids[] = $id;
			}
		}
		if ( $tag_ids ) {
			$payload['tags'] = $tag_ids;
		}

		// 话题：目标站有 abp_topic 分类法（相关插件）→ 建术语；否则落为标签；off → 不推。
		$topic_ids = self::push_topics( $client, $post, $settings, $summary );
		if ( $topic_ids ) {
			$payload[ self::topic_param( $settings ) ] = $topic_ids;
		}

		// 特色图
		if ( ! empty( $settings['sync_images'] ) ) {
			$thumb = get_post_thumbnail_id( $post->ID );
			if ( $thumb ) {
				$media = self::upload_attachment( $client, (int) $thumb, $summary );
				if ( $media ) {
					$payload['featured_media'] = $media['id'];
				}
			}
		}

		return $payload;
	}

	/**
	 * 本地状态 → 远端状态。
	 *
	 * @param WP_Post $post     本地文章。
	 * @param array   $settings 设置。
	 * @return string
	 */
	private static function remote_status( $post, $settings ) {
		$mode = isset( $settings['push_status'] ) ? $settings['push_status'] : 'follow';
		if ( 'publish' === $mode ) {
			return 'publish';
		}
		if ( 'draft' === $mode ) {
			return 'draft';
		}
		// follow：跟随本地状态。
		return in_array( $post->post_status, array( 'publish', 'future', 'pending' ), true )
			? ( 'future' === $post->post_status ? 'future' : ( 'pending' === $post->post_status ? 'pending' : 'publish' ) )
			: 'draft';
	}

	/* ================= 评论 ================= */

	/**
	 * 推送文章评论（真实评论 + AI 已生成评论）。按时间正序推，父评论先建以便映射 parent。
	 *
	 * @param CP_Client $client         客户端。
	 * @param int       $post_id        本地文章 ID。
	 * @param int       $remote_post_id 远端文章 ID。
	 * @param array     $settings       设置。
	 * @return array{pushed:int, skipped:int}
	 * @throws CP_Error
	 */
	private static function push_comments( $client, $post_id, $remote_post_id, $settings ) {
		$statuses = array( 'approve' );
		if ( ! empty( $settings['comments_include_pending'] ) ) {
			$statuses[] = 'hold';
		}
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => $statuses,
				'type'    => 'comment',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
				'number'  => 0,
			)
		);
		if ( ! $comments ) {
			return array( 'pushed' => 0, 'skipped' => 0 );
		}

		$pushed     = 0;
		$skipped    = 0;
		$parent_map = array(); // 本地评论 ID → 远端评论 ID
		foreach ( $comments as $c ) {
			$rid = (int) get_comment_meta( $c->comment_ID, CP_META_REMOTE_COMMENT, true );
			if ( $rid ) {
				$skipped++;
				$parent_map[ (int) $c->comment_ID ] = $rid;
				continue;
			}
			if ( '' === trim( (string) $c->comment_content ) ) {
				$skipped++;
				continue;
			}
			$payload = array(
				'post'         => $remote_post_id,
				'parent'       => isset( $parent_map[ (int) $c->comment_parent ] ) ? $parent_map[ (int) $c->comment_parent ] : 0,
				'author_name'  => $c->comment_author ? $c->comment_author : '访客',
				'author_email' => $c->comment_author_email,
				'author_url'   => $c->comment_author_url,
				'content'      => $c->comment_content,
				'status'       => 'approve' === $c->comment_approved ? 'approve' : 'hold',
			);
			if ( $c->comment_date_gmt && '0000-00-00 00:00:00' !== $c->comment_date_gmt ) {
				$payload['date_gmt'] = $c->comment_date_gmt;
			}
			try {
				$r = $client->post( '/wp-json/wp/v2/comments', $payload );
				if ( is_array( $r ) && ! empty( $r['id'] ) ) {
					$remote_cid = (int) $r['id'];
					update_comment_meta( $c->comment_ID, CP_META_REMOTE_COMMENT, $remote_cid );
					$parent_map[ (int) $c->comment_ID ] = $remote_cid;
					$pushed++;
				} else {
					CP_Log::warn( 'comment', sprintf( '评论 %d 推送未返回 id：%s', $c->comment_ID, CP_Client::snippet( $r ) ) );
				}
			} catch ( CP_Error $e ) {
				CP_Log::warn( 'comment', sprintf( '评论 %d 推送失败：%s', $c->comment_ID, $e->getMessage() ) );
			}
		}
		return array( 'pushed' => $pushed, 'skipped' => $skipped );
	}

	/* ================= 术语（分类/标签/话题） ================= */

	/**
	 * 话题推送方式：auto = 目标站有 abp_topic 分类法（相关插件）用 abp_topic，否则用标签。
	 *
	 * @param array $settings 设置。
	 * @return string off|post_tag|abp_topic
	 */
	private static function topic_mode( $settings ) {
		$mode = isset( $settings['topic_mode'] ) ? $settings['topic_mode'] : 'auto';
		if ( 'auto' === $mode ) {
			$taxes = get_option( CP_TAX_CACHE, array() );
			return in_array( 'abp_topic', (array) $taxes, true ) ? 'abp_topic' : 'post_tag';
		}
		return $mode;
	}

	/**
	 * 话题对应的 REST 字段名。
	 *
	 * @param array $settings 设置。
	 * @return string
	 */
	private static function topic_param( $settings ) {
		return 'abp_topic' === self::topic_mode( $settings ) ? 'abp_topic' : 'tags';
	}

	/**
	 * 推送话题（本地 abp_topic 术语）。
	 *
	 * @param CP_Client $client   客户端。
	 * @param WP_Post   $post     本地文章。
	 * @param array     $settings 设置。
	 * @param array     $summary  汇总（引用）。
	 * @return int[] 远端术语 ID 列表。
	 * @throws CP_Error
	 */
	private static function push_topics( $client, $post, $settings, &$summary ) {
		$mode = self::topic_mode( $settings );
		if ( 'off' === $mode ) {
			return array();
		}
		$names = wp_get_post_terms( $post->ID, 'abp_topic', array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || ! $names ) {
			return array();
		}
		$tax = 'abp_topic' === $mode ? 'abp_topic' : 'post_tag';
		$ids = array();
		foreach ( (array) $names as $name ) {
			$id = self::ensure_term( $client, $tax, $name, $settings, $summary );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * 确保目标站存在该术语：按名称精确匹配，缺失则创建。结果缓存到 CP_TERM_MAP。
	 *
	 * @param CP_Client $client   客户端。
	 * @param string    $tax      分类法（category / post_tag / abp_topic）。
	 * @param string    $name     术语名称。
	 * @param array     $settings 设置。
	 * @param array     $summary  汇总（引用）。
	 * @return int 远端术语 ID，0 表示跳过。
	 * @throws CP_Error
	 */
	private static function ensure_term( $client, $tax, $name, $settings, &$summary ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$map = get_option( CP_TERM_MAP, array() );
		$map = is_array( $map ) ? $map : array();
		if ( isset( $map[ $tax ][ $name ] ) ) {
			return (int) $map[ $tax ][ $name ];
		}

		$rest_base = self::rest_base( $tax );
		$found = $client->get( '/wp-json/wp/v2/' . $rest_base, array( 'search' => $name, 'per_page' => 100 ) );
		$id = 0;
		if ( is_array( $found ) ) {
			foreach ( $found as $item ) {
				if ( is_array( $item ) && isset( $item['name'] ) && $item['name'] === $name ) {
					$id = (int) $item['id'];
					break;
				}
			}
		}
		if ( ! $id ) {
			$r = $client->post( '/wp-json/wp/v2/' . $rest_base, array( 'name' => $name ) );
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$id = (int) $r['id'];
				$summary['terms_created']++;
			} else {
				// 极端并发/已存在：再查一次。
				$found2 = $client->get( '/wp-json/wp/v2/' . $rest_base, array( 'search' => $name, 'per_page' => 100 ) );
				if ( is_array( $found2 ) ) {
					foreach ( $found2 as $item ) {
						if ( is_array( $item ) && isset( $item['name'] ) && $item['name'] === $name ) {
							$id = (int) $item['id'];
							break;
						}
					}
				}
				if ( ! $id ) {
					throw new CP_Error( '创建术语失败：' . CP_Client::snippet( $r ) );
				}
			}
		}
		if ( $id ) {
			$map[ $tax ][ $name ] = $id;
			update_option( CP_TERM_MAP, $map, false );
		}
		return $id;
	}

	/**
	 * 分类法 REST base。
	 *
	 * @param string $tax 分类法。
	 * @return string
	 */
	private static function rest_base( $tax ) {
		$t = get_taxonomy( $tax );
		if ( $t && $t->rest_base ) {
			return $t->rest_base;
		}
		return $tax;
	}

	/* ================= 媒体（特色图 + 正文图片） ================= */

	/**
	 * 重写正文图片：本地图片 → 上传目标站 → 替换 URL；外部图片 → 下载转存。
	 * srcset/sizes 一并移除（远端只存原图，无尺寸变体）。
	 *
	 * @param CP_Client $client  客户端。
	 * @param string    $content 正文 HTML。
	 * @param array     $summary 汇总（引用）。
	 * @return string
	 */
	private static function rewrite_content_images( $client, $content, &$summary ) {
		if ( '' === trim( (string) $content ) ) {
			return $content;
		}
		$home       = home_url();
		$home_https = str_replace( 'http://', 'https://', $home );
		$map        = get_option( CP_MEDIA_MAP, array() );
		$map        = is_array( $map ) ? $map : array();
		$att_map    = isset( $map['att'] ) && is_array( $map['att'] ) ? $map['att'] : array();
		$url_map    = isset( $map['url'] ) && is_array( $map['url'] ) ? $map['url'] : array();
		$changed    = false;

		$content = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $m ) use ( $client, $home, $home_https, &$att_map, &$url_map, &$changed, &$summary ) {
				$tag = $m[0];
				if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
					return $tag;
				}
				$src    = html_entity_decode( $sm[1], ENT_QUOTES, 'UTF-8' );
				$hint   = preg_match( '/\bwp-image-(\d+)\b/i', $tag, $hm ) ? (int) $hm[1] : 0;
				$remote = self::resolve_image_url( $client, $src, $hint, $home, $home_https, $att_map, $url_map, $changed, $summary );
				if ( '' === $remote ) {
					return $tag; // 解析失败（日志已记），原样保留。
				}
				$tag = preg_replace( '/\bsrcset=["\'][^"\']*["\']/i', '', $tag );
				$tag = preg_replace( '/\bsizes=["\'][^"\']*["\']/i', '', $tag );
				$tag = preg_replace( '/\bsrc=["\'][^"\']*["\']/i', 'src="' . esc_attr( $remote ) . '"', $tag, 1 );
				return $tag;
			},
			$content
		);

		if ( $changed ) {
			$map['att'] = $att_map;
			$map['url'] = $url_map;
			update_option( CP_MEDIA_MAP, $map, false );
		}
		return $content;
	}

	/**
	 * 解析单个图片 URL → 远端 URL（带缓存）。
	 *
	 * @param CP_Client $client        客户端。
	 * @param string    $src           图片 URL。
	 * @param int       $hint_id       img 标签上的 wp-image-{id} 提示。
	 * @param string    $home          本地 home URL。
	 * @param string    $home_https    本地 home URL（https 形态）。
	 * @param array     $att_map       附件缓存（引用）。
	 * @param array     $url_map       URL 缓存（引用）。
	 * @param bool      $changed       是否有变更（引用）。
	 * @param array     $summary       汇总（引用）。
	 * @return string 远端 URL；失败返回空串。
	 */
	private static function resolve_image_url( $client, $src, $hint_id, $home, $home_https, &$att_map, &$url_map, &$changed, &$summary ) {
		if ( isset( $url_map[ $src ] ) ) {
			return $url_map[ $src ];
		}
		$is_local = ( $home && 0 === strpos( $src, $home ) ) || ( $home_https && 0 === strpos( $src, $home_https ) );
		if ( ! $is_local && ! preg_match( '#^https?://#i', $src ) ) {
			return ''; // 相对路径 / data URI 不处理。
		}

		$attachment_id = $hint_id;
		if ( ! $attachment_id && $is_local ) {
			$attachment_id = attachment_url_to_postid( $src );
		}
		if ( $attachment_id && isset( $att_map[ $attachment_id ] ) ) {
			$url_map[ $src ] = $att_map[ $attachment_id ];
			return $att_map[ $attachment_id ];
		}

		try {
			if ( $attachment_id ) {
				$media = self::upload_attachment( $client, $attachment_id, $summary );
			} else {
				$media = self::upload_external_image( $client, $src, $summary );
			}
		} catch ( CP_Error $e ) {
			CP_Log::warn( 'media', '图片推送跳过 ' . $src . '：' . $e->getMessage() );
			return '';
		}
		if ( ! $media || '' === $media['source_url'] ) {
			return '';
		}
		if ( $attachment_id ) {
			$att_map[ $attachment_id ] = $media['source_url'];
		}
		$url_map[ $src ] = $media['source_url'];
		$changed = true;
		return $media['source_url'];
	}

	/**
	 * 上传本地附件到目标站（按附件 ID 缓存，只传一次）。
	 *
	 * @param CP_Client $client        客户端。
	 * @param int       $attachment_id 本地附件 ID。
	 * @param array     $summary       汇总（引用）。
	 * @return array{id:int, source_url:string}|null
	 * @throws CP_Error
	 */
	private static function upload_attachment( $client, $attachment_id, &$summary ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			throw new CP_Error( '附件文件不存在（ID ' . $attachment_id . '）' );
		}
		$bytes = file_get_contents( $file );
		if ( false === $bytes ) {
			throw new CP_Error( '附件读取失败（ID ' . $attachment_id . '）' );
		}
		$mime     = get_post_mime_type( $attachment_id );
		$filename = basename( $file );
		$media    = $client->upload_media( $bytes, $filename, $mime ? $mime : 'image/jpeg' );
		$summary['media_uploaded']++;
		return $media;
	}

	/**
	 * 下载外部图片并转存目标站。
	 *
	 * @param CP_Client $client  客户端。
	 * @param string    $url     图片 URL。
	 * @param array     $summary 汇总（引用）。
	 * @return array{id:int, source_url:string}|null
	 * @throws CP_Error
	 */
	private static function upload_external_image( $client, $url, &$summary ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'    => 60,
				'sslverify'  => true,
				'user-agent' => 'Content-Pusher/' . CP_VERSION,
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new CP_Error( '下载外部图片失败：' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			throw new CP_Error( '下载外部图片失败 HTTP ' . $code );
		}
		$bytes = wp_remote_retrieve_body( $resp );
		$ct    = (string) wp_remote_retrieve_header( $resp, 'content-type' );
		$mime  = 'image/jpeg';
		$ext   = 'jpg';
		if ( preg_match( '#image/([a-z0-9+.-]+)#i', $ct, $m ) ) {
			$mime = strtolower( $m[0] );
			$ext  = strtolower( $m[1] );
			if ( 'jpeg' === $ext ) {
				$ext = 'jpg';
			}
		}
		$media = $client->upload_media( $bytes, 'ext-' . md5( $url ) . '.' . $ext, $mime );
		$summary['media_uploaded']++;
		return $media;
	}

	/* ================= 辅助 ================= */

	/**
	 * 记录推送失败（meta）。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param string $msg     错误信息。
	 */
	private static function mark_error( $post_id, $msg ) {
		update_post_meta( $post_id, CP_META_LAST_ERROR, mb_substr( (string) $msg, 0, 300 ) );
		update_post_meta( $post_id, CP_META_LAST_PUSH, current_time( 'mysql' ) );
	}
}
