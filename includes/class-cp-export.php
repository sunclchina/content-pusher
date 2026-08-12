<?php
/**
 * 备用渠道：文件导出（WXR / WordPress eXtended RSS 标准格式，与 WP 自带「工具→导出」同格式）。
 *
 * 与实时推送（HTTPS REST）互为独立渠道，互不影响：
 *   - 渠道一（实时）：本地 → 目标站，自动、零插件、图片重写、评论/话题全保真。
 *   - 渠道二（文件）：导出 WXR 文件，人工在目标站 工具→导入 上传。
 *     注意：目标站导入 WXR 需先安装相应导入插件（如 wordpress-importer），
 *     且正文图片/特色图不随文件搬运（目标站无法访问本地 localhost 图片）。
 *
 * 导出范围：post 类型文章（发布/草稿/定时/待审）+ 分类/标签/abp_topic 话题 + 文章评论。
 * 有意不导出：_thumbnail_id 等 postmeta（避免导入后出现指向不存在附件的坏引用）。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Export {

	/**
	 * 注册 admin-post 处理器。
	 */
	public static function init() {
		add_action( 'admin_post_cp_export_wxr', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * 下载 WXR 文件。
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}
		check_admin_referer( 'cp_export_wxr' );

		$xml = self::build_wxr();
		if ( '' === $xml ) {
			wp_die( '导出内容为空。' );
		}
		$filename = 'content-pusher-export-' . current_time( 'Ymd-His' ) . '.xml';
		nocache_headers();
		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xml ) );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WXR 为结构化 XML。
		exit;
	}

	/**
	 * 生成 WXR 1.2 XML。
	 *
	 * @return string
	 */
	public static function build_wxr() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'future', 'pending' ),
				'numberposts'    => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		if ( ! $posts ) {
			return '';
		}

		$blogname  = get_option( 'blogname' );
		$home      = home_url();
		$siteurl   = get_option( 'siteurl' );
		$date      = gmdate( 'D, d M Y H:i:s +0000' );
		$language  = get_option( 'rss_language' ) ? get_option( 'rss_language' ) : 'zh-CN';

		$xml  = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
		$xml .= '<rss version="2.0"'
			. ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:wfw="http://wellformedweb.org/CommentAPI/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">' . "\n";
		$xml .= '<channel>' . "\n";
		$xml .= '<title>' . self::cdata( $blogname ) . '</title>' . "\n";
		$xml .= '<link>' . esc_url( $home ) . '</link>' . "\n";
		$xml .= '<description>' . self::cdata( get_bloginfo( 'description' ) ) . '</description>' . "\n";
		$xml .= '<pubDate>' . $date . '</pubDate>' . "\n";
		$xml .= '<language>' . esc_html( $language ) . '</language>' . "\n";
		$xml .= '<wp:wxr_version>1.2</wp:wxr_version>' . "\n";
		$xml .= '<wp:base_site_url>' . esc_url( $siteurl ) . '</wp:base_site_url>' . "\n";
		$xml .= '<wp:base_blog_url>' . esc_url( $home ) . '</wp:base_blog_url>' . "\n";

		// 作者（导出涉及文章的作者，导入端会按需创建用户）。
		$author_ids = array();
		foreach ( $posts as $p ) {
			$author_ids[ (int) $p->post_author ] = (int) $p->post_author;
		}
		foreach ( $author_ids as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			$xml .= '<wp:author><wp:author_id>' . (int) $u->ID . '</wp:author_id>'
				. '<wp:author_login>' . esc_html( $u->user_login ) . '</wp:author_login>'
				. '<wp:author_email>' . esc_html( $u->user_email ) . '</wp:author_email>'
				. '<wp:author_display_name>' . self::cdata( $u->display_name ) . '</wp:author_display_name>'
				. '<wp:author_first_name>' . self::cdata( $u->first_name ) . '</wp:author_first_name>'
				. '<wp:author_last_name>' . self::cdata( $u->last_name ) . '</wp:author_last_name></wp:author>' . "\n";
		}

		// 分类 / 标签 / 话题术语。
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
			$xml .= '<wp:category><wp:term_id>' . (int) $c->term_id . '</wp:term_id>'
				. '<wp:category_nicename>' . esc_html( $c->slug ) . '</wp:category_nicename>'
				. '<wp:category_parent>' . esc_html( $c->parent ? get_term( $c->parent, 'category' )->slug : '' ) . '</wp:category_parent>'
				. '<wp:cat_name>' . self::cdata( $c->name ) . '</wp:cat_name></wp:category>' . "\n";
		}
		foreach ( get_tags( array( 'hide_empty' => false ) ) as $t ) {
			$xml .= '<wp:tag><wp:term_id>' . (int) $t->term_id . '</wp:term_id>'
				. '<wp:tag_slug>' . esc_html( $t->slug ) . '</wp:tag_slug>'
				. '<wp:tag_name>' . self::cdata( $t->name ) . '</wp:tag_name></wp:tag>' . "\n";
		}
		$topics = get_terms( array( 'taxonomy' => 'abp_topic', 'hide_empty' => false ) );
		if ( $topics && ! is_wp_error( $topics ) ) {
			foreach ( $topics as $t ) {
				$xml .= '<wp:term><wp:term_id>' . (int) $t->term_id . '</wp:term_id>'
					. '<wp:term_taxonomy>abp_topic</wp:term_taxonomy>'
					. '<wp:term_slug>' . esc_html( $t->slug ) . '</wp:term_slug>'
					. '<wp:term_name>' . self::cdata( $t->name ) . '</wp:term_name></wp:term>' . "\n";
			}
		}

		// 文章。
		foreach ( $posts as $post ) {
			$xml .= self::post_item( $post );
		}

		$xml .= '</channel>' . "\n";
		$xml .= '</rss>' . "\n";
		return $xml;
	}

	/**
	 * 单篇文章 item（含评论）。
	 *
	 * @param WP_Post $post 文章。
	 * @return string
	 */
	private static function post_item( $post ) {
		$xml  = '<item>' . "\n";
		$xml .= '<title>' . self::cdata( $post->post_title ) . '</title>' . "\n";
		$xml .= '<link>' . esc_url( get_permalink( $post ) ) . '</link>' . "\n";
		$xml .= '<pubDate>' . mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt, false ) . '</pubDate>' . "\n";
		$xml .= '<dc:creator>' . self::cdata( get_the_author_meta( 'display_name', $post->post_author ) ) . '</dc:creator>' . "\n";
		$xml .= '<guid isPermaLink="false">' . esc_url( $post->guid ) . '</guid>' . "\n";
		$xml .= '<description></description>' . "\n";
		$xml .= '<content:encoded>' . self::cdata( $post->post_content ) . '</content:encoded>' . "\n";
		$xml .= '<excerpt:encoded>' . self::cdata( $post->post_excerpt ) . '</excerpt:encoded>' . "\n";
		$xml .= '<wp:post_id>' . (int) $post->ID . '</wp:post_id>' . "\n";
		$xml .= '<wp:post_date>' . esc_html( $post->post_date ) . '</wp:post_date>' . "\n";
		$xml .= '<wp:post_date_gmt>' . esc_html( $post->post_date_gmt ) . '</wp:post_date_gmt>' . "\n";
		$xml .= '<wp:comment_status>' . esc_html( $post->comment_status ) . '</wp:comment_status>' . "\n";
		$xml .= '<wp:ping_status>' . esc_html( $post->ping_status ) . '</wp:ping_status>' . "\n";
		$xml .= '<wp:post_name>' . esc_html( $post->post_name ) . '</wp:post_name>' . "\n";
		$xml .= '<wp:status>' . esc_html( $post->post_status ) . '</wp:status>' . "\n";
		$xml .= '<wp:post_parent>' . (int) $post->post_parent . '</wp:post_parent>' . "\n";
		$xml .= '<wp:menu_order>' . (int) $post->menu_order . '</wp:menu_order>' . "\n";
		$xml .= '<wp:post_type>post</wp:post_type>' . "\n";
		$xml .= '<wp:post_password>' . esc_html( $post->post_password ) . '</wp:post_password>' . "\n";
		$xml .= '<wp:is_sticky>' . ( is_sticky( $post->ID ) ? 1 : 0 ) . '</wp:is_sticky>' . "\n";

		foreach ( wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) ) as $c ) {
			$xml .= '<category domain="category" nicename="' . esc_attr( $c->slug ) . '">' . self::cdata( $c->name ) . '</category>' . "\n";
		}
		foreach ( wp_get_post_tags( $post->ID, array( 'fields' => 'all' ) ) as $t ) {
			$xml .= '<category domain="post_tag" nicename="' . esc_attr( $t->slug ) . '">' . self::cdata( $t->name ) . '</category>' . "\n";
		}
		$terms = wp_get_post_terms( $post->ID, 'abp_topic', array( 'fields' => 'all' ) );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$xml .= '<category domain="abp_topic" nicename="' . esc_attr( $t->slug ) . '">' . self::cdata( $t->name ) . '</category>' . "\n";
			}
		}

		// 评论（approved + hold，不含垃圾）。
		$comments = get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => array( 'approve', 'hold' ),
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
				'number'  => 0,
			)
		);
		foreach ( $comments as $c ) {
			$xml .= '<wp:comment>' . "\n";
			$xml .= '<wp:comment_id>' . (int) $c->comment_ID . '</wp:comment_id>' . "\n";
			$xml .= '<wp:comment_author>' . self::cdata( $c->comment_author ) . '</wp:comment_author>' . "\n";
			$xml .= '<wp:comment_author_email>' . esc_html( $c->comment_author_email ) . '</wp:comment_author_email>' . "\n";
			$xml .= '<wp:comment_author_url>' . esc_url( $c->comment_author_url ) . '</wp:comment_author_url>' . "\n";
			$xml .= '<wp:comment_author_IP>' . esc_html( $c->comment_author_IP ) . '</wp:comment_author_IP>' . "\n";
			$xml .= '<wp:comment_date>' . esc_html( $c->comment_date ) . '</wp:comment_date>' . "\n";
			$xml .= '<wp:comment_date_gmt>' . esc_html( $c->comment_date_gmt ) . '</wp:comment_date_gmt>' . "\n";
			$xml .= '<wp:comment_content>' . self::cdata( $c->comment_content ) . '</wp:comment_content>' . "\n";
			$xml .= '<wp:comment_approved>' . esc_html( $c->comment_approved ) . '</wp:comment_approved>' . "\n";
			$xml .= '<wp:comment_type>' . esc_html( $c->comment_type ) . '</wp:comment_type>' . "\n";
			$xml .= '<wp:comment_parent>' . (int) $c->comment_parent . '</wp:comment_parent>' . "\n";
			$xml .= '<wp:comment_user_id>0</wp:comment_user_id>' . "\n";
			$xml .= '</wp:comment>' . "\n";
		}

		$xml .= '</item>' . "\n";
		return $xml;
	}

	/**
	 * CDATA 包裹（内容含 ]]> 时拆开避免破坏 XML）。
	 *
	 * @param string $str 内容。
	 * @return string
	 */
	private static function cdata( $str ) {
		$str = (string) $str;
		$str = str_replace( ']]>', ']]]]><![CDATA[>', $str );
		return '<![CDATA[' . $str . ']]>';
	}
}
