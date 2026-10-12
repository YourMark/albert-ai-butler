<?php
/**
 * Change-journal storage.
 *
 * @package Albert
 * @subpackage Undo\Storage
 * @since      1.5.0
 */

namespace Albert\Undo\Storage;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;
use Albert\Undo\Entity;
use Albert\Undo\Recorder;
use Albert\Undo\Retention\Registrar;
use Albert\Undo\WindowContext;

/**
 * Reads and writes `albert_changes` and `albert_change_items`.
 *
 * Retention prunes whole sessions (`run_id`), never part of one, so "revert
 * this session" never finds half its before-images gone. A session goes when
 * its last call is past the window, or when an item it changed was changed
 * later by as many other sessions as the policy keeps.
 *
 * @since 1.5.0
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Albert's own tables; never cached.
 */
class Repository {

	/**
	 * Longest gap between two calls from one connection that still makes one session.
	 *
	 * @var int
	 */
	public const RUN_GAP_SECONDS = 600;

	/**
	 * Start an entry, joining the connection's current session when there is one.
	 *
	 * @param WindowContext $context The call.
	 *
	 * @return array{id: int, run_id: int}|null Null when the insert failed.
	 * @since 1.5.0
	 */
	public function create_entry( WindowContext $context ): ?array {
		global $wpdb;

		$table   = Tables::changes();
		$blog_id = get_current_blog_id();

		$run_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT run_id FROM %i WHERE client_id = %s AND user_id = %d AND blog_id = %d AND COALESCE(finished_at, created_at) >= %s ORDER BY id DESC LIMIT 1',
				$table,
				$context->client_id,
				$context->user_id,
				$blog_id,
				gmdate( 'Y-m-d H:i:s', time() - self::RUN_GAP_SECONDS )
			)
		);

		$inserted = $wpdb->insert(
			$table,
			[
				'run_id'            => $run_id,
				'blog_id'           => $blog_id,
				'source'            => $context->source,
				'ability_name'      => $context->ability_name,
				'user_id'           => $context->user_id,
				'client_id'         => $context->client_id,
				'client_name'       => $context->client_name,
				'session_id'        => $context->session_id,
				'pending_action_id' => $context->pending_action_id,
				'status'            => 'recording',
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		if ( ! $inserted ) {
			return null;
		}

		$id = (int) $wpdb->insert_id;

		if ( $run_id === 0 ) {
			$run_id = $id;
			if ( ! $wpdb->update( $table, [ 'run_id' => $run_id ], [ 'id' => $id ] ) ) {
				$wpdb->delete( $table, [ 'id' => $id ] );
				return null;
			}
		}

		return [
			'id'     => $id,
			'run_id' => $run_id,
		];
	}

	/**
	 * Whether an entry row exists. A rollback in the call can remove one.
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function entry_exists( int $change_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE id = %d', Tables::changes(), $change_id ) );
	}

	/**
	 * Store one changed entity.
	 *
	 * @param int                   $change_id    Entry id.
	 * @param int                   $run_id       Session id.
	 * @param Entity                $entity       The entity.
	 * @param string|null           $image        Encoded before-image; null for a create, or when not kept.
	 * @param string                $operation    create, update or delete.
	 * @param array<string, string> $after_hashes Field => hash of the state the call left.
	 * @param string                $captured_by  `hook` or `handler`.
	 * @param string|null           $claimed_by   Handler that restores this entity itself.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function insert_item( int $change_id, int $run_id, Entity $entity, ?string $image, string $operation, array $after_hashes, string $captured_by, ?string $claimed_by ): bool {
		global $wpdb;

		return (bool) $wpdb->insert(
			Tables::change_items(),
			[
				'change_id'    => $change_id,
				'run_id'       => $run_id,
				'blog_id'      => $entity->blog_id,
				'entity_type'  => $entity->type,
				'entity_key'   => $entity->key(),
				'entity_hash'  => self::entity_hash( $entity ),
				'operation'    => $operation,
				'captured_by'  => $captured_by,
				'before_image' => $image,
				'image_bytes'  => $image === null ? 0 : strlen( $image ),
				'after_hashes' => (string) wp_json_encode( $after_hashes ),
				'claimed_by'   => $claimed_by,
			]
		);
	}

	/**
	 * Close an entry.
	 *
	 * @param int                  $change_id    Entry id.
	 * @param string               $status       recorded or interrupted.
	 * @param string|null          $reason       Why it cannot be reverted, or null.
	 * @param array<int, string>   $other_tables What the journal does not capture.
	 * @param array<string, mixed> $effects      What no revert takes back (emails, secrets by name).
	 * @param int                  $item_count   Items stored.
	 * @param int|null             $log_id       The ability-log row for the same call.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function finish_entry( int $change_id, string $status, ?string $reason, array $other_tables, array $effects, int $item_count, ?int $log_id ): void {
		global $wpdb;

		$wpdb->update(
			Tables::changes(),
			[
				'status'       => $status,
				'reason'       => $reason,
				'other_tables' => $other_tables === [] ? null : (string) wp_json_encode( array_values( $other_tables ) ),
				'effects'      => $effects === [] ? null : (string) wp_json_encode( $effects ),
				'item_count'   => $item_count,
				'log_id'       => $log_id,
				'finished_at'  => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $change_id ]
		);
	}

	/**
	 * Delete an entry and its items (the call changed nothing).
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function delete_entry( int $change_id ): void {
		global $wpdb;

		$wpdb->delete( Tables::change_items(), [ 'change_id' => $change_id ] );
		$wpdb->delete( Tables::changes(), [ 'id' => $change_id ] );
	}

	/**
	 * One entry row.
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.5.0
	 */
	public function entry( int $change_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::changes(), $change_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * An entry's items, with before-images decoded.
	 *
	 * Retention is enforced here too, so a nightly prune that has not run yet
	 * never stretches it: an item of a session past the window or the per-item
	 * session count comes back without its before-image, as if pruned.
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.5.0
	 */
	public function items( int $change_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE change_id = %d ORDER BY id', Tables::change_items(), $change_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || $rows === [] ) {
			return [];
		}

		$policy  = Registrar::current();
		$run_id  = (int) $rows[0]['run_id'];
		$expired = null;
		if ( $this->run_last_seen( $run_id ) < self::cutoff( $policy->max_age_days() ) ) {
			$expired = 'age';
		} elseif ( $this->is_superseded( $run_id, $policy->runs_per_item() ) ) {
			$expired = 'runs';
		}

		return array_map(
			static function ( array $row ) use ( $expired ): array {
				if ( $expired !== null && $row['pruned'] === null ) {
					$row['before_image'] = null;
					$row['pruned']       = $expired;
				}
				$row['before'] = is_string( $row['before_image'] ) ? self::decode( $row['before_image'] ) : null;
				return $row;
			},
			$rows
		);
	}

	/**
	 * Mark entries still `recording` long after they started as interrupted.
	 *
	 * The shutdown fallback closes a window a fatal left open; this catches a
	 * process that died without running shutdown at all.
	 *
	 * @param string $before UTC datetime; entries started earlier are closed.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function close_abandoned( string $before ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, reason = %s, finished_at = created_at WHERE status = 'recording' AND created_at < %s",
				Tables::changes(),
				Recorder::STATUS_INTERRUPTED,
				Recorder::REASON_INTERRUPTED,
				$before
			)
		);
	}

	/**
	 * Prune whole sessions whose last call is older than a cutoff.
	 *
	 * @param string $before UTC datetime.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function prune_older_than( string $before ): void {
		global $wpdb;

		$runs = $wpdb->get_col(
			$wpdb->prepare( 'SELECT run_id FROM %i GROUP BY run_id HAVING MAX(created_at) < %s', Tables::changes(), $before )
		);

		$this->prune_runs( array_map( 'intval', $runs ), 'age' );
	}

	/**
	 * Prune whole sessions that changed an item later changed by `$keep` other sessions.
	 *
	 * Sessions are ranked per item by their last change to it, not by when they
	 * started: two assistants working at once overlap.
	 *
	 * @param int $keep Sessions kept per item; 0 for no limit.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function prune_beyond_runs( int $keep ): void {
		global $wpdb;

		if ( $keep <= 0 ) {
			return;
		}

		$runs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT run_id FROM (SELECT run_id, ROW_NUMBER() OVER (PARTITION BY entity_hash ORDER BY last_change DESC) AS newer FROM (SELECT entity_hash, run_id, MAX(change_id) AS last_change FROM %i GROUP BY entity_hash, run_id) per_run) ranked WHERE newer > %d',
				Tables::change_items(),
				$keep
			)
		);

		$this->prune_runs( array_map( 'intval', $runs ), 'runs' );
	}

	/**
	 * Prune oldest sessions first until the stored before-images fit the ceiling.
	 *
	 * @param int $max_bytes Ceiling.
	 *
	 * @return bool Whether anything was pruned.
	 * @since 1.5.0
	 */
	public function prune_to_bytes( int $max_bytes ): bool {
		global $wpdb;

		$items  = Tables::change_items();
		$excess = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(image_bytes), 0) FROM %i WHERE pruned IS NULL', $items ) ) - $max_bytes;

		if ( $excess <= 0 ) {
			return false;
		}

		$sizes = $wpdb->get_results(
			$wpdb->prepare( 'SELECT run_id, SUM(image_bytes) AS bytes FROM %i WHERE pruned IS NULL GROUP BY run_id ORDER BY run_id', $items ),
			ARRAY_A
		);

		$prune = [];
		foreach ( is_array( $sizes ) ? $sizes : [] as $size ) {
			if ( $excess <= 0 ) {
				break;
			}
			$prune[] = (int) $size['run_id'];
			$excess -= (int) $size['bytes'];
		}

		$this->prune_runs( $prune, 'size' );

		return $prune !== [];
	}

	/**
	 * Delete entries, and their items, of sessions whose last call is older than a cutoff.
	 *
	 * @param string $before UTC datetime.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function delete_older_than( string $before ): void {
		global $wpdb;

		$runs = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT run_id FROM %i GROUP BY run_id HAVING MAX(created_at) < %s', Tables::changes(), $before ) )
		);

		foreach ( array_chunk( $runs, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built to match the chunk.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id IN ($in)", Tables::change_items(), ...$chunk ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id IN ($in)", Tables::changes(), ...$chunk ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}
	}

	/**
	 * The UTC cutoff for a retention window in days.
	 *
	 * @param int $days Window.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Hash identifying an entity across calls and blogs.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function entity_hash( Entity $entity ): string {
		return hash( 'sha256', $entity->id() );
	}

	/**
	 * Serialize and compress a before-image.
	 *
	 * `serialize()`, not JSON: stored values are raw strings that may not be
	 * valid UTF-8, which JSON cannot carry.
	 *
	 * @param array<string, mixed> $state The state.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function encode( array $state ): string {
		$compressed = gzcompress( serialize( $state ), 6 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Binary-safe; decoded with allowed_classes false.

		return $compressed === false ? '' : $compressed;
	}

	/**
	 * Decode a before-image.
	 *
	 * @param string $image Stored image.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.5.0
	 */
	public static function decode( string $image ): ?array {
		$raw = gzuncompress( $image );
		if ( $raw === false ) {
			return null;
		}

		$state = unserialize( $raw, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Our own payload, no classes allowed.

		return is_array( $state ) ? $state : null;
	}

	/**
	 * When a session's last call was made.
	 *
	 * @param int $run_id Session id.
	 *
	 * @return string UTC datetime.
	 */
	private function run_last_seen( int $run_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM %i WHERE run_id = %d', Tables::changes(), $run_id ) );
	}

	/**
	 * Whether an item this session changed was changed later by `$keep` other sessions.
	 *
	 * @param int $run_id Session id.
	 * @param int $keep   Sessions kept per item; 0 for no limit.
	 *
	 * @return bool
	 */
	private function is_superseded( int $run_id, int $keep ): bool {
		global $wpdb;

		if ( $keep <= 0 ) {
			return false;
		}

		$items = Tables::change_items();

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM (SELECT entity_hash, MAX(change_id) AS last_change FROM %i WHERE run_id = %d GROUP BY entity_hash) mine JOIN %i later ON later.entity_hash = mine.entity_hash AND later.change_id > mine.last_change AND later.run_id <> %d GROUP BY mine.entity_hash HAVING COUNT(DISTINCT later.run_id) >= %d LIMIT 1',
				$items,
				$run_id,
				$items,
				$run_id,
				$keep
			)
		);
	}

	/**
	 * Drop the before-images of whole sessions, keeping their rows.
	 *
	 * @param array<int, int> $runs   Session ids.
	 * @param string          $reason age, runs or size.
	 *
	 * @return void
	 */
	private function prune_runs( array $runs, string $reason ): void {
		global $wpdb;

		foreach ( array_chunk( $runs, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built to match the chunk.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET before_image = NULL, image_bytes = 0, pruned = %s WHERE pruned IS NULL AND run_id IN ($in)",
					Tables::change_items(),
					$reason,
					...$chunk
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}
	}
}
