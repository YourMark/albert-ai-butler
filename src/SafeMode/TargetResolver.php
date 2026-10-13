<?php
/**
 * Resolves the real-world target of a staged ability call.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an ability call into a description of what it will actually change.
 *
 * `delete-post {id: 47}` tells a reviewer nothing; "Delete the post 'Pricing'
 * (published)" tells them everything. The approvals screen resolves this fresh
 * at render time so the reviewer approves against the object as it is now, not a
 * stale echo of the request.
 *
 * The same descriptor, captured at stage time, carries `modified_at` so the
 * screen can warn when a post has been edited between staging and approval —
 * approving an hour-old `update-post` would otherwise clobber those edits
 * silently.
 *
 * Best-effort and read-only: an unknown ability, or a target that no longer
 * exists, returns null and the screen falls back to showing the raw request.
 *
 * @since 1.5.0
 */
class TargetResolver {

	/**
	 * Describe the target of a call, or null when it can't be resolved.
	 *
	 * @param string               $ability_name The ability id.
	 * @param array<string, mixed> $input        Its resolved input.
	 *
	 * @return array{type: string, id: int, label: string, status: string, modified_at: string|null}|null
	 * @since 1.5.0
	 */
	public function describe( string $ability_name, array $input ): ?array {
		$id = $this->target_id( $input );

		if ( $id <= 0 ) {
			return null;
		}

		$family = $this->family( $ability_name );

		switch ( $family ) {
			case 'post':
				return $this->describe_post( $id );
			case 'user':
				return $this->describe_user( $id );
			case 'term':
				return $this->describe_term( $id );
			default:
				return null;
		}
	}

	/**
	 * Whether a staged snapshot's target has changed since it was captured.
	 *
	 * Only meaningful for a target that reports a `modified_at`; anything else
	 * (users, terms) has no reliable modified timestamp, so drift is not claimed.
	 *
	 * @param array{modified_at?: string|null}|null $staged  Snapshot taken at stage time.
	 * @param array{modified_at?: string|null}|null $current Fresh description now.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function has_drifted( ?array $staged, ?array $current ): bool {
		$was = $staged['modified_at'] ?? null;
		$now = $current['modified_at'] ?? null;

		if ( $was === null || $now === null ) {
			return false;
		}

		return $was !== $now;
	}

	/**
	 * The target object id carried by the input, if any.
	 *
	 * @param array<string, mixed> $input Resolved input.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	private function target_id( array $input ): int {
		foreach ( [ 'id', 'post_id', 'term_id', 'user_id', 'attachment_id' ] as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				return (int) $input[ $key ];
			}
		}

		return 0;
	}

	/**
	 * The object family an ability operates on, from its id suffix.
	 *
	 * @param string $ability_name The ability id.
	 *
	 * @return string One of post|user|term|'' (unknown).
	 * @since 1.5.0
	 */
	private function family( string $ability_name ): string {
		$name = strtolower( $ability_name );

		if ( str_contains( $name, 'post' ) || str_contains( $name, 'page' ) || str_contains( $name, 'media' ) || str_contains( $name, 'featured-image' ) ) {
			return 'post';
		}

		if ( str_contains( $name, 'user' ) || str_contains( $name, 'customer' ) ) {
			return 'user';
		}

		if ( str_contains( $name, 'term' ) ) {
			return 'term';
		}

		return '';
	}

	/**
	 * Describe a post/page/attachment target.
	 *
	 * @param int $id Post id.
	 *
	 * @return array{type: string, id: int, label: string, status: string, modified_at: string|null}|null
	 * @since 1.5.0
	 */
	private function describe_post( int $id ): ?array {
		$post = get_post( $id );

		if ( $post === null ) {
			return null;
		}

		$title = get_the_title( $post );

		return [
			'type'        => (string) $post->post_type,
			'id'          => $id,
			'label'       => $title !== '' ? $title : sprintf( '#%d', $id ),
			'status'      => (string) get_post_status( $post ),
			'modified_at' => (string) $post->post_modified_gmt,
		];
	}

	/**
	 * Describe a user target.
	 *
	 * @param int $id User id.
	 *
	 * @return array{type: string, id: int, label: string, status: string, modified_at: string|null}|null
	 * @since 1.5.0
	 */
	private function describe_user( int $id ): ?array {
		$user = get_userdata( $id );

		if ( $user === false ) {
			return null;
		}

		return [
			'type'        => 'user',
			'id'          => $id,
			'label'       => sprintf( '%s <%s>', $user->user_login, $user->user_email ),
			'status'      => implode( ', ', (array) $user->roles ),
			'modified_at' => null,
		];
	}

	/**
	 * Describe a taxonomy term target.
	 *
	 * @param int $id Term id.
	 *
	 * @return array{type: string, id: int, label: string, status: string, modified_at: string|null}|null
	 * @since 1.5.0
	 */
	private function describe_term( int $id ): ?array {
		$term = get_term( $id );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return [
			'type'        => (string) $term->taxonomy,
			'id'          => $id,
			'label'       => (string) $term->name,
			'status'      => '',
			'modified_at' => null,
		];
	}
}
