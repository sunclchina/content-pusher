<?php
/**
 * 极简 WP REST API 模拟器（本地功能测试专用，勿用于生产）。
 *
 * 用法：php -S 127.0.0.1:8799 -t tests/mock-server tests/mock-server/mock.php
 * 数据存 tests/mock-server/store.json 与 uploads/。
 *
 * @package Content_Pusher
 */

$storeFile = __DIR__ . '/store.json';
$store = array(
	'next_post'    => 7000,
	'next_comment' => 9000,
	'next_media'   => 8000,
	'next_term'    => 600,
	'posts'        => array(),
	'comments'     => array(),
	'terms'        => array( 'categories' => array(), 'tags' => array() ),
	'media'        => array(),
);
if ( file_exists( $storeFile ) ) {
	$store = json_decode( file_get_contents( $storeFile ), true );
}

function mock_save() {
	global $store, $storeFile;
	file_put_contents( $storeFile, json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
}

function mock_out( $data, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE );
	exit;
}

$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$query  = array();
parse_str( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ), $query );
$method = $_SERVER['REQUEST_METHOD'];
$body   = file_get_contents( 'php://input' );
$json   = json_decode( $body, true );

// 文章单条 GET/PUT
if ( preg_match( '#^/wp-json/wp/v2/posts/(\d+)$#', $path, $m ) ) {
	$id = (int) $m[1];
	if ( 'GET' === $method ) {
		foreach ( $store['posts'] as $p ) {
			if ( $p['id'] === $id ) {
				mock_out( $p );
			}
		}
		mock_out( array( 'code' => 'rest_post_invalid_id', 'message' => '无效的文章ID。', 'data' => array( 'status' => 404 ) ), 404 );
	}
	if ( 'PUT' === $method ) {
		foreach ( $store['posts'] as $k => $p ) {
			if ( $p['id'] === $id ) {
				foreach ( $json as $fk => $fv ) {
					$store['posts'][ $k ][ $fk ] = $fv;
				}
				mock_save();
				mock_out( $store['posts'][ $k ] );
			}
		}
		mock_out( array( 'code' => 'rest_post_invalid_id' ), 404 );
	}
}

// 文章列表/创建
if ( '/wp-json/wp/v2/posts' === $path ) {
	if ( 'GET' === $method ) {
		$out = array_values( $store['posts'] );
		if ( ! empty( $query['slug'] ) ) {
			$out = array_values( array_filter( $out, function ( $p ) use ( $query ) {
				return isset( $p['slug'] ) && $p['slug'] === $query['slug'];
			} ) );
		}
		mock_out( $out );
	}
	if ( 'POST' === $method ) {
		$id = $store['next_post']++;
		foreach ( $store['posts'] as $p ) {
			if ( ! empty( $json['slug'] ) && isset( $p['slug'] ) && $p['slug'] === $json['slug'] ) {
				mock_out( array( 'code' => 'rest_post_slug_exists', 'message' => 'slug 已存在', 'data' => array( 'status' => 400 ) ), 400 );
			}
		}
		$p = array( 'id' => $id, 'link' => 'https://mock.test/?p=' . $id );
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'status', 'date_gmt', 'categories', 'tags', 'featured_media', 'comment_status', 'ping_status' ) as $k ) {
			if ( isset( $json[ $k ] ) ) {
				$p[ $k ] = $json[ $k ];
			}
		}
		$store['posts'][ $id ] = $p;
		mock_save();
		mock_out( $p, 201 );
	}
}

// 评论
if ( '/wp-json/wp/v2/comments' === $path ) {
	if ( 'POST' === $method ) {
		$id = $store['next_comment']++;
		$c  = array( 'id' => $id );
		foreach ( array( 'post', 'parent', 'author_name', 'author_email', 'author_url', 'content', 'status', 'date_gmt' ) as $k ) {
			if ( isset( $json[ $k ] ) ) {
				$c[ $k ] = $json[ $k ];
			}
		}
		$store['comments'][ $id ] = $c;
		mock_save();
		mock_out( $c, 201 );
	}
	if ( 'GET' === $method ) {
		mock_out( array_values( $store['comments'] ) );
	}
}

// 媒体（原始字节上传）
if ( '/wp-json/wp/v2/media' === $path && 'POST' === $method ) {
	$id  = $store['next_media']++;
	$cd  = isset( $_SERVER['HTTP_CONTENT_DISPOSITION'] ) ? (string) $_SERVER['HTTP_CONTENT_DISPOSITION'] : '';
	$fn  = 'upload.bin';
	if ( preg_match( '/filename="?([^";]+)"?/', $cd, $fm ) ) {
		$fn = $fm[1];
	}
	$dir = __DIR__ . '/uploads';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $dir . '/' . $id . '_' . $fn, $body );
	$m                  = array( 'id' => $id, 'source_url' => 'https://mock.test/wp-content/uploads/' . $id . '_' . $fn );
	$store['media'][ $id ] = $m;
	mock_save();
	mock_out( $m, 201 );
}

// 分类/标签
if ( preg_match( '#^/wp-json/wp/v2/(categories|tags)$#', $path, $tm ) ) {
	$tax = 'tags' === $tm[1] ? 'tags' : 'categories';
	if ( 'GET' === $method ) {
		$out = array_values( array_filter( $store['terms'][ $tax ], function ( $t ) use ( $query ) {
			if ( empty( $query['search'] ) ) {
				return true;
			}
			return isset( $t['name'] ) && ( $t['name'] === $query['search'] || false !== strpos( $t['name'], $query['search'] ) );
		} ) );
		mock_out( $out );
	}
	if ( 'POST' === $method ) {
		foreach ( $store['terms'][ $tax ] as $t ) {
			if ( $t['name'] === $json['name'] ) {
				mock_out( $t, 200 );
			}
		}
		$id = $store['next_term']++;
		$t  = array( 'id' => $id, 'name' => $json['name'] );
		$store['terms'][ $tax ][] = $t;
		mock_save();
		mock_out( $t, 201 );
	}
}

// 用户/分类法
if ( '/wp-json/wp/v2/users/me' === $path ) {
	mock_out( array(
		'id'           => 1,
		'name'         => 'mockadmin',
		'slug'         => 'mockadmin',
		'roles'        => array( 'administrator' ),
		'capabilities' => array( 'manage_options' => true, 'edit_posts' => true ),
	) );
}
if ( '/wp-json/wp/v2/taxonomies' === $path ) {
	mock_out( array(
		'category' => array( 'name' => '分类' ),
		'post_tag' => array( 'name' => '标签' ),
	) );
}

mock_out( array( 'code' => 'not_found', 'path' => $path ), 404 );
