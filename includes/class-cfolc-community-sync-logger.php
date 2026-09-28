<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CFOLC_Community_Sync_Logger {
	const OPTION_NAME = 'cfolc_community_sync_recent_log';
	const MAX_EVENTS  = 250;

	private $events = array();

	public static function activate() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, array(), '', 'no' );
		}
	}

	public function log( $level, $message, array $context = array() ) {
		$this->events[] = array_merge(
			array(
				'timestamp' => time(),
				'level'     => sanitize_key( $level ),
				'message'   => sanitize_text_field( $message ),
			),
			$context
		);
	}

	public function flush() {
		if ( empty( $this->events ) ) {
			return;
		}

		$recent = get_option( self::OPTION_NAME, array() );
		$recent = is_array( $recent ) ? $recent : array();
		$recent = array_filter(
			array_merge( $recent, $this->events ),
			static function ( $event ) {
				return ! isset( $event['level'] ) || 'found' !== $event['level'];
			}
		);
		$recent = array_slice( array_values( $recent ), -self::MAX_EVENTS );
		update_option( self::OPTION_NAME, $recent, false );
		$this->events = array();
	}

	public static function recent( $limit = 100 ) {
		$events = get_option( self::OPTION_NAME, array() );
		$events = is_array( $events ) ? $events : array();
		$events = array_filter(
			$events,
			static function ( $event ) {
				return ! isset( $event['level'] ) || 'found' !== $event['level'];
			}
		);
		return array_reverse( array_slice( $events, -max( 1, (int) $limit ) ) );
	}

	public static function clear() {
		update_option( self::OPTION_NAME, array(), false );
	}
}
