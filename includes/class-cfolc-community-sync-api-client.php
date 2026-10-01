<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CFOLC_Community_Sync_API_Client {
	const ALLOWED_GROUP_TYPES = array( 'chapter', 'local-chapter', 'virtual-chapter' );

	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function validate_configuration() {
		if ( empty( $this->settings['source_url'] ) ) {
			return new WP_Error( 'cfolc_sync_missing_url', 'The source site URL is not configured.' );
		}
		if ( 0 !== stripos( $this->settings['source_url'], 'https://' ) ) {
			return new WP_Error( 'cfolc_sync_insecure_url', 'The source site URL must use HTTPS.' );
		}
		if ( empty( $this->settings['source_username'] ) || empty( $this->settings['source_app_password'] ) ) {
			return new WP_Error( 'cfolc_sync_missing_credentials', 'The source username and Application Password are required.' );
		}
		return true;
	}

	public function test_connection() {
		$result = $this->request(
			'/wp-json/wp/v2/users',
			array( 'context' => 'edit', 'per_page' => 1, '_fields' => 'id,email' ),
			true
		);
		if ( is_wp_error( $result ) || empty( $result['users'] ) ) {
			return $result;
		}
		return $this->add_buddyboss_groups( $result );
	}

	public function get_users_page( $page, $email = '', $per_page = 100 ) {
		$query = array(
			'context'  => 'edit',
			'per_page' => max( 1, min( 100, (int) $per_page ) ),
			'page'     => max( 1, (int) $page ),
			'orderby'  => 'id',
			'order'    => 'asc',
			'_fields'  => 'id,email',
		);
		if ( $email !== '' ) {
			$query['search']         = $email;
			$query['search_columns'] = array( 'email' );
		}

		$result = $this->request( '/wp-json/wp/v2/users', $query, true );
		if ( is_wp_error( $result ) || empty( $result['users'] ) ) {
			return $result;
		}
		return $this->add_buddyboss_groups( $result );
	}

	private function add_buddyboss_groups( array $result ) {
		$user_ids = array();
		foreach ( $result['users'] as $source_user ) {
			if ( ! is_array( $source_user ) || empty( $source_user['id'] ) || (int) $source_user['id'] < 1 ) {
				return new WP_Error( 'cfolc_sync_invalid_response', 'The source returned a user without a valid ID.' );
			}
			$user_ids[] = (int) $source_user['id'];
		}
		if ( empty( $user_ids ) ) {
			return $result;
		}

		$response = $this->request(
			'/wp-json/cfolc-sync/v1/user-groups',
			array( 'user_ids' => implode( ',', array_unique( $user_ids ) ) )
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cfolc_sync_groups_request_failed', 'Bulk BuddyBoss groups could not be read: ' . $response->get_error_message() );
		}
		if ( ! is_array( $response ) || ! isset( $response['users'] ) || ! is_array( $response['users'] ) ) {
			return new WP_Error( 'cfolc_sync_invalid_groups_response', 'The bulk groups endpoint returned an unexpected response.' );
		}

		$groups_by_user = array();
		foreach ( $response['users'] as $user ) {
			if ( ! is_array( $user ) || ! isset( $user['id'], $user['groups'] ) || ! is_int( $user['id'] ) || ! in_array( $user['id'], $user_ids, true ) || ! is_array( $user['groups'] ) || array_key_exists( $user['id'], $groups_by_user ) ) {
				return new WP_Error( 'cfolc_sync_invalid_groups_response', 'The bulk groups endpoint returned an invalid or duplicate user entry.' );
			}
			$names = array();
			foreach ( $user['groups'] as $name ) {
				if ( ! is_string( $name ) || '' === trim( sanitize_text_field( $name ) ) ) {
					return new WP_Error( 'cfolc_sync_invalid_groups_response', 'The bulk groups endpoint returned an invalid group name.' );
				}
				$names[] = trim( sanitize_text_field( $name ) );
			}
			$names = array_unique( $names );
			natcasesort( $names );
			$groups_by_user[ $user['id'] ] = array_values( $names );
		}

		// Missing users are a failed read, never an empty membership list.
		foreach ( $user_ids as $user_id ) {
			if ( ! array_key_exists( $user_id, $groups_by_user ) ) {
				return new WP_Error( 'cfolc_sync_invalid_groups_response', sprintf( 'The bulk groups endpoint omitted source user %d. The batch was not applied.', $user_id ) );
			}
		}
		foreach ( $result['users'] as &$source_user ) {
			$source_user['_cfolc_buddyboss_groups'] = $groups_by_user[ (int) $source_user['id'] ];
		}
		unset( $source_user );
		return $result;
	}

	private function request( $path, array $query = array(), $include_pagination = false ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$url = untrailingslashit( $this->settings['source_url'] ) . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'redirection' => 0,
				'headers' => array(
					'Accept' => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $this->settings['source_username'] . ':' . $this->settings['source_app_password'] ),
					'User-Agent' => 'CFOLC-Community-Sync/' . CFOLC_COMMUNITY_SYNC_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cfolc_sync_http_error', 'Could not reach the source site: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : 'Unexpected response from the source site.';
			return new WP_Error( 'cfolc_sync_source_response', sprintf( 'Source returned HTTP %1$d: %2$s', $status, $message ), $status );
		}
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'cfolc_sync_invalid_json', 'The source site returned invalid JSON.' );
		}
		if ( ! $include_pagination ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cfolc_sync_invalid_response', 'The source endpoint returned an unexpected response.' );
		}
		return array(
			'items'       => $data,
			'users'       => $data,
			'total'       => (int) wp_remote_retrieve_header( $response, 'x-wp-total' ),
			'total_pages' => max( 1, (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) ),
		);
	}
}
