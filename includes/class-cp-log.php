<?php
/**
 * 推送日志（option 环形缓冲，最多保留 CP_Log::MAX 条）。
 *
 * @package Content_Pusher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Log {

	const MAX = 300;

	/**
	 * 写入一条日志。
	 *
	 * @param string $channel 通道（push/media/comment/term/connection/...）。
	 * @param string $message 消息。
	 * @param string $level   级别 info/warn/error。
	 */
	public static function add( $channel, $message, $level = 'info' ) {
		$log = get_option( CP_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'    => current_time( 'mysql' ),
			'channel' => sanitize_key( $channel ),
			'level'   => in_array( $level, array( 'info', 'warn', 'error' ), true ) ? $level : 'info',
			'message' => is_string( $message ) ? $message : wp_json_encode( $message ),
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( CP_LOG_OPTION, $log, false );
	}

	/**
	 * @param string $channel 通道。
	 * @param string $message 消息。
	 */
	public static function info( $channel, $message ) {
		self::add( $channel, $message, 'info' );
	}

	/**
	 * @param string $channel 通道。
	 * @param string $message 消息。
	 */
	public static function warn( $channel, $message ) {
		self::add( $channel, $message, 'warn' );
	}

	/**
	 * @param string $channel 通道。
	 * @param string $message 消息。
	 */
	public static function error( $channel, $message ) {
		self::add( $channel, $message, 'error' );
	}

	/**
	 * 取最近 N 条（新的在前）。
	 *
	 * @param int $limit 条数。
	 * @return array
	 */
	public static function get( $limit = 100 ) {
		$log = get_option( CP_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		return array_slice( array_reverse( $log ), 0, max( 1, $limit ) );
	}

	/**
	 * 清空日志。
	 */
	public static function clear() {
		delete_option( CP_LOG_OPTION );
	}
}
