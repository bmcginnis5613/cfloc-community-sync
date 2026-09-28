<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches users by email and syncs their chapter BuddyBoss groups.
 */
final class CFOLC_Community_Sync_Service {
	const DESTINATION_BUDDYBOSS_GROUPS_FIELD = 'field_6ab6e8fd7cd53';
	const DESTINATION_BUDDYBOSS_GROUPS_META  = 'buddyboss_groups';

	private $reporter;

	public function __construct( ?callable $reporter = null ) {
		$this->reporter = $reporter ? $reporter : static function () {};
	}

	public static function empty_stats() {
		return array(
			'fetched' => 0, 'examined' => 0, 'found' => 0, 'updated' => 0,
			'unchanged' => 0, 'not_found' => 0, 'invalid' => 0, 'failed' => 0,
		);
	}

	public function check_batch( array $source_users, $requested_email = '' ) {
		$stats            = self::empty_stats();
		$requested_email  = strtolower( sanitize_email( $requested_email ) );
		$stats['fetched'] = count( $source_users );
		foreach ( $source_users as $source_user ) {
			$source_email = isset( $source_user['email'] ) ? strtolower( sanitize_email( $source_user['email'] ) ) : '';
			if ( $requested_email !== '' && $source_email !== $requested_email ) {
				continue;
			}
			++$stats['examined'];
			if ( ! is_email( $source_email ) ) {
				++$stats['invalid'];
				$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
				$this->report( 'invalid', sprintf( 'Source user %s has a missing or invalid email.', $source_id ), array( 'source_user_id' => $source_id ) );
				continue;
			}
			$destination_user = get_user_by( 'email', $source_email );
			if ( ! $destination_user ) {
				++$stats['not_found'];
				$this->report( 'not_found', sprintf( '%s was not found on the destination.', $source_email ), array( 'email' => $source_email ) );
				continue;
			}
			++$stats['found'];
			$this->sync_buddyboss_groups( $source_user, $destination_user, $stats );
		}
		return $stats;
	}

	private function sync_buddyboss_groups( array $source_user, $destination_user, array &$stats ) {
		$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
		if ( ! isset( $source_user['_cfolc_buddyboss_groups'] ) || ! is_array( $source_user['_cfolc_buddyboss_groups'] ) ) {
			++$stats['failed'];
			$this->report( 'error', sprintf( 'BuddyBoss groups for source user %s could not be read. The destination was not changed.', $source_id ), array( 'source_user_id' => $source_id ) );
			return;
		}

		$value               = implode( ', ', $source_user['_cfolc_buddyboss_groups'] );
		$destination_user_id = (int) $destination_user->ID;
		$current_value       = trim( sanitize_text_field( (string) get_user_meta( $destination_user_id, self::DESTINATION_BUDDYBOSS_GROUPS_META, true ) ) );
		if ( $current_value === $value ) {
			++$stats['unchanged'];
			return;
		}

		$this->update_acf_user_field( $destination_user_id, self::DESTINATION_BUDDYBOSS_GROUPS_META, self::DESTINATION_BUDDYBOSS_GROUPS_FIELD, $value );
		$stored_value = trim( sanitize_text_field( (string) get_user_meta( $destination_user_id, self::DESTINATION_BUDDYBOSS_GROUPS_META, true ) ) );
		if ( $stored_value !== $value ) {
			++$stats['failed'];
			$this->report( 'error', sprintf( 'The BuddyBoss groups update could not be verified for destination user %d.', $destination_user_id ), array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id ) );
			return;
		}
		++$stats['updated'];
		$this->report( 'updated', sprintf( 'Updated BuddyBoss groups for destination user %d.', $destination_user_id ), array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id ) );
	}

	private function update_acf_user_field( $user_id, $meta_key, $field_key, $value ) {
		if ( function_exists( 'update_field' ) ) {
			update_field( $field_key, $value, 'user_' . $user_id );
		}
		if ( get_user_meta( $user_id, $meta_key, true ) !== $value ) {
			update_user_meta( $user_id, $meta_key, $value );
		}
		if ( get_user_meta( $user_id, '_' . $meta_key, true ) !== $field_key ) {
			update_user_meta( $user_id, '_' . $meta_key, $field_key );
		}
	}

	public function run( CFOLC_Community_Sync_API_Client $client, array $options = array() ) {
		$options = wp_parse_args( $options, array( 'email' => '', 'batch_size' => 100 ) );
		$stats           = self::empty_stats();
		$requested_email = strtolower( sanitize_email( $options['email'] ) );
		if ( $options['email'] !== '' && ! is_email( $requested_email ) ) {
			return new WP_Error( 'cfolc_sync_invalid_requested_email', 'The --email value is not a valid email address.' );
		}
		$page = 1;
		$total_pages = 1;
		do {
			$result = $client->get_users_page( $page, $requested_email, $options['batch_size'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$total_pages = $result['total_pages'];
			$this->report( 'batch', sprintf( 'Checking source batch %1$d of %2$d (%3$d record(s)).', $page, $total_pages, count( $result['users'] ) ), array( 'page' => $page ) );
			$batch_stats = $this->check_batch( $result['users'], $requested_email );
			foreach ( $batch_stats as $key => $value ) {
				$stats[ $key ] += $value;
			}
			++$page;
		} while ( $page <= $total_pages );
		if ( $requested_email !== '' && 0 === $stats['examined'] ) {
			++$stats['not_found'];
			$this->report( 'not_found', sprintf( '%s was not found on the source site.', $requested_email ), array( 'email' => $requested_email ) );
		}
		return $stats;
	}

	private function report( $level, $message, array $context = array() ) {
		call_user_func( $this->reporter, $level, $message, $context );
	}
}
