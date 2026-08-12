<?php
/**
 * 核心推送逻辑：文章（含分类/标签/话题/特色图/正文图片/摘要）+ 评论（真实评论与 AI 已生成评论）。
 *
 * 推送选项（opts）：
 *   include: array 可选内容，cover=封面 / excerpt=摘要 / comments=评论 / topics=话题；
 *            缺省（null）用设置默认 include_default。
 *   dedup:   'overwrite' 同名覆盖更新（默认）| 'skip' 同名跳过。
 *
 * 匹配规则：优先本地记录的远端 ID（_cp_remote_id）→ 按 slug 匹配目标站文章 → 仍未命中新建。
 * 评论按本地 ID 记录远端评论 ID（_cp_remote_comment_id），重推自动跳过已推评论。
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
	 * 解析可选内容（include）。
	 *
	 * @param mixed $opts    推送选项。
	 * @param array $settings 设置。
	 * @return string[]
	 */
	private static function parse_include( $opts, $settings ) {
		$include = ( is_array( $opts ) && isset( $opts['include'] ) && null !== $opts['include'] )
			? $opts['include']
			: ( isset( $settings['include_default'] ) && is_array( $settings['include_default'] )
				? $settings['include_default']
				: array( 'cover', 'excerpt', 'comments', 'topics' ) );
		$include = array_map( 'sanitize_key', (array) $include );
		return array_values( array_intersect( $include, array( 'cover', 'excerpt', 'comments', 'topics' ) ) );
	}

	/**
	 * 解析查重策略（dedup）。
	 *
	 * @param mixed $opts    推送选项。
	 * @param array $settings 设置。
	 * @return string overwrite|skip
	 */
	private static function parse_dedup( $opts, $settings ) {
		$dedup = ( is_array( $opts ) && isset( $opts['dedup'] ) && $opts['dedup'] )
			? $opts['dedup']
			: ( isset( $settings['dedup'] ) ? $settings['dedup'] : 'overwrite' );
		return in_array( $dedup, array( 'skip', 'overwrite' ), true ) ? $dedup : 'overwrite';
	}

	/**
	 * 推送一篇文章（可选项：封面/摘要/评论/话题；查重：跳过/覆盖）。
	 *
	 * @param int   $post_id 本地文章 ID。
	 * @param array $opts    推送选项（include/dedup）。
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

		$include = self::parse_include( $opts, $settings );
		$dedup   = self::parse_dedup( $opts, $settings );

		@set_time_limit( 0 );
		$summary = array(
			'media_uploaded'   => 0,
			'comments_pushed'  => 0,
			'comments_skipped' => 0,
			'terms_created'    => 0,
			'details'          => array(),
		);

		try {
			$res       = self::resolve_remote_post( $client, $post );
			$remote_id = $res['remote_id'];

			// 查重：同名/已存在，且策略为跳过 → 不推送。
			if ( $remote_id && 'skip' === $dedup ) {
				$remote_url = (string) get_post_meta( $post_id, CP_META_REMOTE_URL, true );
				update_post_meta( $post_id, CP_META_LAST_PUSH, current_time( 'mysql' ) );
				CP_Log::info( 'push', sprintf( '文章 %d《%s》查重跳过：目标站已有（远端 ID=%d，匹配=%s）', $post_id, $post->post_title, $remote_id, $res['matched'] ) );
				return array(
					'ok'         => true,
					'action'     => 'skipped',
					'remote_id'  => $remote_id,
					'remote_url' => $remote_url,
					'summary'    => $summary,
				);
			}

			if ( $remote_id ) {
				$action = 'update';
				self::update_remote_post( $client, $remote_id, $post, $settings, $summary, $include );
			} else {
				$action = 'create';
				$remote_id = self::create_remote_post( $client, $post, $settings, $summary, $include );
			}

			update_post_meta( $post_id, CP_META_REMOTE_ID, $remote_id );
			update_post_meta( $post_id, CP_META_LAST_PUSH, current_time( 'mysql' ) );
			delete_post_meta( $post_id, CP_META_LAST_ERROR );

			// 星河兼容话题：文章就位后，把本地话题建为生产站 thread 话题帖（需要远端文章 ID 做关联）。
			if ( in_array( 'topics', $include, true ) && 'thread' === self::topic_mode( $settings ) ) {
				self::push_thread_topics( $client, $remote_id, $post, $settings, $summary );
			}

			if ( in_array( 'comments', $include, true ) && ! empty( $settings['push_comments'] ) ) {
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
	 * 解析远端文章：本地记录 ID → slug 匹配 → 无。
	 *
	 * @param CP_Client $client 客户端。
	 * @param WP_Post   $post   本地文章。
	 * @return array{remote_id:int, matched:string} matched: none|meta|slug
	 * @throws CP_Error
	 */
	private static function resolve_remote_post( $client, $post ) {
		$rid = (int) get_post_meta( $post->ID, CP_META_REMOTE_ID, true );
		if ( $rid ) {
			try {
				$r = $client->get( '/wp-json/wp/v2/posts/' . $rid, array( 'context' => 'edit' ) );
				if ( is_array( $r ) && ! empty( $r['id'] ) ) {
					return array( 'remote_id' => (int) $r['id'], 'matched' => 'meta' );
				}
			} catch ( CP_Error $e ) {
				// 远端已删除（404/410）→ 视为不存在；其余错误上抛。
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
			return array( 'remote_id' => (int) $found[0]['id'], 'matched' => 'slug' );
		}
		return array( 'remote_id' => 0, 'matched' => 'none' );
	}

	/**
	 * 新建远端文章。
	 *
	 * @param CP_Client $client   客户端。
	 * @param WP_Post   $post     本地文章。
	 * @param array     $settings 设置。
	 * @param array     $summary  汇总（引用）。
	 * @param string[]  $include  可选内容。
	 * @return int 远端文章 ID。
	 * @throws CP_Error
	 */
	private static function create_remote_post( $client, $post, $settings, &$summary, $include ) {
		$payload = self::build_payload( $client, $post, $settings, $summary, true, $include );
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
	 * @param string[]  $include   可选内容。
	 * @throws CP_Error
	 */
	private static function update_remote_post( $client, $remote_id, $post, $settings, &$summary, $include ) {
		// 更新不传日期：远端日期以首次创建为准（本地副本日期一致，避免误改）。
		$payload = self::build_payload( $client, $post, $settings, $summary, false, $include );
		$r = $client->put( '/wp-json/wp/v2/posts/' . $remote_id, $payload );
		if ( ! is_array( $r ) || empty( $r['id'] ) ) {
			throw new CP_Error( '更新远端文章未返回 id：' . CP_Client::snippet( $r ) );
		}
		update_post_meta( $post->ID, CP_META_REMOTE_URL, isset( $r['link'] ) ? (string) $r['link'] : '' );
	}

	/**
	 * 组装远端文章 payload。
	 *
	 * @param CP_Client $client    客户端。
	 * @param WP_Post   $post      本地文章。
	 * @param array     $settings  设置。
	 * @param array     $summary   汇总（引用）。
	 * @param bool      $is_create 是否新建（新建带日期与 slug）。
	 * @param string[]  $include   可选内容（cover/excerpt/topics）。
	 * @return array
	 * @throws CP_Error
	 */
	private static function build_payload( $client, $post, $settings, &$summary, $is_create, $include ) {
		$content = $post->post_content;
		if ( ! empty( $settings['sync_images'] ) ) {
			$content = self::rewrite_content_images( $client, $content, $summary );
		}

		$payload = array(
			'title'          => $post->post_title,
			'content'        => $content,
			'status'         => self::remote_status( $post, $settings ),
			'comment_status' => $post->comment_status ? $post->comment_status : 'open',
			'ping_status'    => $post->ping_status ? $post->ping_status : 'open',
		);
		// 摘要：未勾选时不传（更新时保留远端已有摘要）。
		if ( in_array( 'excerpt', $include, true ) ) {
			$payload['excerpt'] = $post->post_excerpt;
		}
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

		// 话题（勾选 topics 才推）：目标站有 abp_topic 分类法（相关插件）→ 建术语；否则落为标签。
		// thread（星河兼容）模式不在这里建标签，由主流程推完文章后建 thread 话题帖。
		if ( in_array( 'topics', $include, true ) && 'thread' !== self::topic_mode( $settings ) ) {
			$topic_ids = self::push_topics( $client, $post, $settings, $summary );
			if ( $topic_ids ) {
				$payload[ self::topic_param( $settings ) ] = $topic_ids;
			}
		}

		// 特色图（勾选 cover 或全局图片同步开启时）。
		$thumb = get_post_thumbnail_id( $post->ID );
		if ( $thumb && ( ! empty( $settings['sync_images'] ) || in_array( 'cover', $include, true ) ) ) {
			$media = self::upload_attachment( $client, (int) $thumb, $summary );
			if ( $media ) {
				$payload['featured_media'] = $media['id'];
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
	 * 话题推送方式：auto = 目标站有 abp_topic 分类法（相关插件）用 abp_topic，否则用标签；
	 * thread = 星河兼容（目标站有星河插件时，话题建为 thread 话题帖）；off = 不推。
	 *
	 * @param array $settings 设置。
	 * @return string off|post_tag|abp_topic|thread
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
	 * 星河兼容话题：本地 abp_topic 话题 → 生产站 thread 话题帖（按标题查重复用）。
	 * 关联：thread 帖写 xhai_postparent=远端文章 ID；文章写 xhai_thread=threadID 列表（覆盖式）。
	 * 目标站需装配套兼容插件（target/），否则创建/关联会被目标站拒绝或忽略。
	 *
	 * @param CP_Client $client          客户端。
	 * @param int       $remote_post_id  远端文章 ID。
	 * @param WP_Post   $post            本地文章。
	 * @param array     $settings        设置。
	 * @param array     $summary         汇总（引用）。
	 * @return int[] 生产站 thread 帖 ID 列表。
	 * @throws CP_Error
	 */
	private static function push_thread_topics( $client, $remote_post_id, $post, $settings, &$summary ) {
		$names = wp_get_post_terms( $post->ID, 'abp_topic', array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || ! $names ) {
			return array();
		}
		$ids = array();
		foreach ( (array) $names as $name ) {
			$tid = self::ensure_thread( $client, $name, $summary );
			if ( ! $tid ) {
				continue;
			}
			$ids[] = $tid;
			// thread 帖 → 文章关联（星河数据结构：thread 帖 xhai_postparent = 文章 ID）。
			try {
				$client->put( '/wp-json/wp/v2/thread/' . $tid, array( 'meta' => array( 'xhai_postparent' => $remote_post_id ) ) );
			} catch ( CP_Error $e ) {
				CP_Log::warn( 'topic', sprintf( '话题《%s》thread 帖关联文章 %d 失败（目标站需装配套兼容插件）：%s', $name, $remote_post_id, $e->getMessage() ) );
			}
		}
		// 文章 → 话题关联（覆盖式写入本次话题集合）。
		if ( $ids ) {
			try {
				$client->put( '/wp-json/wp/v2/posts/' . $remote_post_id, array( 'meta' => array( 'xhai_thread' => $ids ) ) );
			} catch ( CP_Error $e ) {
				CP_Log::warn( 'topic', sprintf( '文章 %d 写入 xhai_thread 话题关联失败（目标站需装配套兼容插件）：%s', $remote_post_id, $e->getMessage() ) );
			}
		}
		return $ids;
	}

	/**
	 * 确保目标站存在该话题帖（thread 类型），按标题精确匹配，缺失则创建。结果缓存到 CP_TERM_MAP['thread']。
	 *
	 * @param CP_Client $client  客户端。
	 * @param string    $name    话题名。
	 * @param array     $summary 汇总（引用）。
	 * @return int thread 帖 ID，0 表示跳过。
	 * @throws CP_Error
	 */
	private static function ensure_thread( $client, $name, &$summary ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$map = get_option( CP_TERM_MAP, array() );
		$map = is_array( $map ) ? $map : array();
		if ( isset( $map['thread'][ $name ] ) ) {
			return (int) $map['thread'][ $name ];
		}

		$found = $client->get( '/wp-json/wp/v2/thread', array( 'search' => $name, 'per_page' => 100 ) );
		$id    = 0;
		if ( is_array( $found ) ) {
			foreach ( $found as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$t = isset( $item['title'] ) ? $item['title'] : '';
				if ( is_array( $t ) && isset( $t['rendered'] ) ) {
					$t = $t['rendered'];
				}
				if ( $t === $name ) {
					$id = (int) $item['id'];
					break;
				}
			}
		}
		if ( ! $id ) {
			$r = $client->post(
				'/wp-json/wp/v2/thread',
				array(
					'title'   => $name,
					'content' => '',
					'status'  => 'publish',
				)
			);
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$id = (int) $r['id'];
				$summary['terms_created']++;
			} else {
				throw new CP_Error( '创建话题帖（thread）失败：' . CP_Client::snippet( $r ) );
			}
		}
		if ( $id ) {
			$map['thread'][ $name ] = $id;
			update_option( CP_TERM_MAP, $map, false );
		}
		return $id;
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
