<?php
/**
 * Records one assistant call.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

use Albert\Logging\ExecutionLogMarker;
use Albert\Undo\Capture\CoreHooks;
use Albert\Undo\Coverage\QueryMonitor;
use Albert\Undo\Coverage\WpdbState;
use Albert\Undo\Handlers\Registry;
use Albert\Undo\Storage\Repository;

/**
 * The journal entry for one call while it runs: before-images in, a verdict out.
 *
 * Capture is by entity, first touch wins: the first before-image taken during
 * the call is the state before the call, whatever writes follow. Before-images
 * are held in memory and written when the call ends, because a plugin may wrap
 * the call's writes in a transaction (WooCommerce does by default) and a
 * rollback would take journal rows written in between with it. Only the entry
 * row is written early, so a process killed outright still leaves a trace the
 * nightly sweep marks interrupted; if a rollback removed it, it is written again.
 *
 * When the call ends each entity is read again. One the call left as it found
 * it is dropped, so a read-only call leaves no entry at all.
 *
 * @since 1.5.0
 */
final class Recorder {

	public const STATUS_RECORDED    = 'recorded';
	public const STATUS_INTERRUPTED = 'interrupted';

	public const REASON_INTERRUPTED   = 'interrupted';
	public const REASON_RECORD_FAILED = 'record_failed';
	public const REASON_TOO_LARGE     = 'too_large';
	public const REASON_OTHER_TABLE   = 'other_table';
	public const REASON_REMOVED       = 'removed';
	public const REASON_SECRET        = 'secret';

	/**
	 * What a query the monitor could not read is noted as.
	 *
	 * @var string
	 */
	public const UNREADABLE = 'unreadable';

	/**
	 * Compressed before-images one call may hold before it is too large to revert.
	 *
	 * @var int
	 */
	public const MAX_CALL_BYTES = 8388608;

	/**
	 * Entities one call may record before it is too large to revert.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 2000;

	/**
	 * The entry, once something was captured.
	 *
	 * @var array{id: int, run_id: int}|null
	 */
	private ?array $entry = null;

	/**
	 * Whether creating the entry failed, which turns the recorder into a no-op.
	 *
	 * @var bool
	 */
	private bool $failed = false;

	/**
	 * Failures to report, so "nothing recorded" never looks like "nothing changed".
	 *
	 * @var int
	 */
	private int $failures = 0;

	/**
	 * Captured entities by id.
	 *
	 * @var array<string, array{entity: Entity, image: string|null, before: array<string, string>, existed: bool, by: string}>
	 */
	private array $items = [];

	/**
	 * Tables written that the journal does not capture, and unreadable queries.
	 *
	 * @var array<string, true>
	 */
	private array $untracked = [];

	/**
	 * Credentials the call changed, by name. Never their values.
	 *
	 * @var array<string, true>
	 */
	private array $secrets = [];

	/**
	 * In-memory fingerprints of captured users' credentials.
	 *
	 * @var array<int, string>
	 */
	private array $credentials = [];

	/**
	 * Compressed bytes held so far.
	 *
	 * @var int
	 */
	private int $bytes = 0;

	/**
	 * Whether the call outgrew what one entry may hold.
	 *
	 * @var bool
	 */
	private bool $too_large = false;

	/**
	 * Emails WordPress tried to send during the call, and how many failed.
	 *
	 * @var array{attempted: int, failed: int}
	 */
	private array $emails = [
		'attempted' => 0,
		'failed'    => 0,
	];

	/**
	 * Depth of the journal's own database work, which the monitor ignores.
	 *
	 * @var int
	 */
	private int $busy = 0;

	/**
	 * Mail listeners: hook => callback.
	 *
	 * @var array<string, callable>
	 */
	private array $mail = [];

	/**
	 * Before-write hooks on WordPress's own data.
	 *
	 * @var CoreHooks
	 */
	private CoreHooks $hooks;

	/**
	 * The wpdb `query` filter listener.
	 *
	 * @var QueryMonitor
	 */
	private QueryMonitor $monitor;

	/**
	 * Build a recorder for one call.
	 *
	 * @param WindowContext $context    The call.
	 * @param Repository    $repository Storage.
	 * @param Registry      $handlers   Custom-table handlers.
	 * @param Snapshot      $snapshot   State reader.
	 */
	public function __construct(
		private WindowContext $context,
		private Repository $repository,
		private Registry $handlers,
		private Snapshot $snapshot,
	) {
		$this->hooks   = new CoreHooks( $this );
		$this->monitor = new QueryMonitor( $this, $handlers );
	}

	/**
	 * Start listening. A handler that fails to open is dropped, so its tables
	 * count as gaps rather than as covered.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function start(): void {
		$this->hooks->attach();
		$this->monitor->attach();

		$this->mail = [
			'wp_mail'        => function ( $atts ) {
				++$this->emails['attempted'];
				return $atts;
			},
			'wp_mail_failed' => function (): void {
				++$this->emails['failed'];
			},
		];
		foreach ( $this->mail as $hook => $callback ) {
			add_filter( $hook, $callback, PHP_INT_MAX );
		}

		foreach ( $this->handlers->all() as $id => $handler ) {
			try {
				$handler->open( $this );
			} catch ( \Throwable $e ) {
				$this->handlers->drop( $id );
				++$this->failures;
			}
		}
	}

	/**
	 * Take an entity's before-image, unless it was taken already this call.
	 *
	 * @param Entity $entity      The entity about to change.
	 * @param string $captured_by `hook` or `handler`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function capture( Entity $entity, string $captured_by = 'handler' ): void {
		if ( ! $this->admit( $entity ) ) {
			return;
		}

		$this->safely(
			$entity,
			function () use ( $entity, $captured_by ): void {
				$before = $this->snapshot->read( $entity );

				if ( $entity->type === Entity::USER && $before !== null ) {
					$this->credentials[ (int) $entity->parts[0] ] = Snapshot::credential_fingerprint( (int) $entity->parts[0] );
				}

				$this->take( $entity, $before, $captured_by );
			}
		);
	}

	/**
	 * Record an entity the call created, so its before-image is "did not exist".
	 *
	 * @param Entity $entity      The new entity.
	 * @param string $captured_by `hook` or `handler`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function created( Entity $entity, string $captured_by = 'handler' ): void {
		if ( ! $this->admit( $entity ) ) {
			return;
		}

		$this->safely( $entity, fn() => $this->take( $entity, null, $captured_by ) );
	}

	/**
	 * Whether an entity was captured this call, or is never captured.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function has( Entity $entity ): bool {
		return isset( $this->items[ $entity->id() ] ) || Exclusions::classify( $entity ) !== null;
	}

	/**
	 * Note a write the journal does not capture.
	 *
	 * @param string $what A table name, {@see self::UNREADABLE}, or a plain description (`media files`).
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function untracked( string $what ): void {
		$this->untracked[ $what ] = true;
	}

	/**
	 * Run the journal's own database work unseen: the monitor does not count it,
	 * and wpdb's per-query state is left as the caller had it.
	 *
	 * @param callable $work The work.
	 *
	 * @return void
	 */
	public function quietly( callable $work ): void {
		if ( $this->busy > 0 ) {
			$work();
			return;
		}

		++$this->busy;

		try {
			WpdbState::preserve( $work );
		} finally {
			--$this->busy;
		}
	}

	/**
	 * Whether the journal is doing its own database work.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function is_busy(): bool {
		return $this->busy > 0;
	}

	/**
	 * Stop listening, work out what changed, and write the entry.
	 *
	 * @param string $status One of the STATUS_ constants.
	 *
	 * @return int|null The entry id, or null when the call changed nothing.
	 * @since 1.5.0
	 */
	public function finish( string $status ): ?int {
		$this->detach();

		$entry_id = null;

		if ( $this->entry !== null || $this->items !== [] || $this->untracked !== [] || $this->secrets !== [] ) {
			try {
				$this->quietly(
					function () use ( $status, &$entry_id ): void {
						$entry_id = $this->write( $status );
					}
				);
			} catch ( \Throwable $e ) {
				++$this->failures;
			}
		}

		if ( $this->failures > 0 ) {
			FailureCounter::record( $this->failures );
		}

		return $entry_id;
	}

	/**
	 * One hash per field, so a later revert can tell which fields were edited since.
	 *
	 * @param array<string, mixed>|null $state A comparable state.
	 *
	 * @return array<string, string>
	 * @since 1.5.0
	 */
	public static function field_hashes( ?array $state ): array {
		$hashes = [];

		foreach ( $state ?? [] as $field => $value ) {
			$hashes[ (string) $field ] = hash( 'sha256', serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hash input only.
		}

		return $hashes;
	}

	/**
	 * Whether an entity should be captured now. Noise is ignored; a secret is
	 * noted by name and never read.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return bool
	 */
	private function admit( Entity $entity ): bool {
		if ( $this->failed || isset( $this->items[ $entity->id() ] ) ) {
			return false;
		}

		$class = Exclusions::classify( $entity );

		if ( $class === Exclusions::SECRET ) {
			$this->secrets[ $entity->parts[ count( $entity->parts ) - 1 ] ] = true;
		}

		return $class === null;
	}

	/**
	 * Run capture work; a failure becomes a noted gap, never an error in the call.
	 *
	 * @param Entity   $entity The entity.
	 * @param callable $work   The work.
	 *
	 * @return void
	 */
	private function safely( Entity $entity, callable $work ): void {
		try {
			$this->quietly( $work );
		} catch ( \Throwable $e ) {
			$this->untracked( $entity->type === Entity::CUSTOM ? $entity->parts[0] : $entity->type );
			++$this->failures;
		}
	}

	/**
	 * Hold a before-image in memory.
	 *
	 * @param Entity                    $entity      The entity.
	 * @param array<string, mixed>|null $before      Its state, null when it did not exist.
	 * @param string                    $captured_by How it was captured.
	 *
	 * @return void
	 */
	private function take( Entity $entity, ?array $before, string $captured_by ): void {
		if ( ! $this->ensure_entry() ) {
			return;
		}

		$image = null;

		if ( count( $this->items ) >= self::MAX_ITEMS ) {
			$this->too_large();
		} elseif ( $before !== null && ! $this->too_large ) {
			$image        = Repository::encode( $before );
			$this->bytes += strlen( $image );

			if ( $this->bytes > self::MAX_CALL_BYTES ) {
				$image = null;
				$this->too_large();
			}
		}

		$this->items[ $entity->id() ] = [
			'entity'  => $entity,
			'image'   => $image,
			'before'  => self::field_hashes( Snapshot::comparable( $entity, $before ) ),
			'existed' => $before !== null,
			'by'      => $captured_by,
		];
	}

	/**
	 * Give up keeping before-images for this call, and free what was held.
	 *
	 * @return void
	 */
	private function too_large(): void {
		$this->too_large = true;

		foreach ( $this->items as $id => $item ) {
			$this->items[ $id ]['image'] = null;
		}
	}

	/**
	 * Re-read what was captured and write the entry.
	 *
	 * @param string $status One of the STATUS_ constants.
	 *
	 * @return int|null
	 */
	private function write( string $status ): ?int {
		$kept = [];

		foreach ( $this->items as $item ) {
			$after  = $this->snapshot->read( $item['entity'] );
			$hashes = self::field_hashes( Snapshot::comparable( $item['entity'], $after ) );

			if ( ( $after !== null ) === $item['existed'] && $hashes === $item['before'] ) {
				continue;
			}

			$item['after']     = $hashes;
			$item['operation'] = ! $item['existed'] ? 'create' : ( $after === null ? 'delete' : 'update' );
			$kept[]            = $item;
		}

		foreach ( $this->credentials as $user_id => $fingerprint ) {
			if ( Snapshot::credential_fingerprint( $user_id ) !== $fingerprint ) {
				$this->secrets['user_pass'] = true;
			}
		}

		if ( $kept === [] && $this->untracked === [] && $this->secrets === [] ) {
			if ( $this->entry !== null ) {
				$this->repository->delete_entry( $this->entry['id'] );
			}
			return null;
		}

		if ( $this->entry === null || ! $this->repository->entry_exists( $this->entry['id'] ) ) {
			$this->entry = $this->repository->create_entry( $this->context );
		}

		if ( $this->entry === null ) {
			++$this->failures;
			return null;
		}

		$stored = 0;
		$lost   = false;

		foreach ( $kept as $item ) {
			$inserted = $this->repository->insert_item(
				$this->entry['id'],
				$this->entry['run_id'],
				$item['entity'],
				$item['image'],
				$item['operation'],
				$item['after'],
				$item['by'],
				$this->handlers->claimant( $item['entity'] )
			);

			if ( $inserted ) {
				++$stored;
			} else {
				$lost = true;
				++$this->failures;
			}
		}

		$this->repository->finish_entry(
			$this->entry['id'],
			$status,
			$this->reason( $status, $kept, $lost ),
			array_keys( $this->untracked ),
			$this->effects(),
			$stored,
			ExecutionLogMarker::row_id( $this->context->ability_name )
		);

		return $this->entry['id'];
	}

	/**
	 * Why the entry cannot be reverted, most serious first, or null when it can.
	 *
	 * @param string                           $status One of the STATUS_ constants.
	 * @param array<int, array<string, mixed>> $kept   The items that changed.
	 * @param bool                             $lost   Whether an item failed to store.
	 *
	 * @return string|null
	 */
	private function reason( string $status, array $kept, bool $lost ): ?string {
		if ( $status === self::STATUS_INTERRUPTED ) {
			return self::REASON_INTERRUPTED;
		}

		if ( $lost ) {
			return self::REASON_RECORD_FAILED;
		}

		if ( $this->too_large ) {
			return self::REASON_TOO_LARGE;
		}

		if ( $this->untracked !== [] ) {
			return self::REASON_OTHER_TABLE;
		}

		foreach ( $kept as $item ) {
			if ( $item['entity']->type === Entity::USER && $item['operation'] === 'delete' ) {
				return self::REASON_REMOVED;
			}
		}

		return $this->secrets !== [] ? self::REASON_SECRET : null;
	}

	/**
	 * What the call did that no revert takes back, named but never valued.
	 *
	 * @return array<string, mixed>
	 */
	private function effects(): array {
		$effects = [];

		if ( $this->emails['attempted'] > 0 ) {
			$effects['emails'] = $this->emails;
		}

		if ( $this->secrets !== [] ) {
			$effects['secrets'] = array_keys( $this->secrets );
		}

		return $effects;
	}

	/**
	 * Create the entry row on the first capture.
	 *
	 * @return bool Whether an entry exists.
	 */
	private function ensure_entry(): bool {
		if ( $this->entry !== null ) {
			return true;
		}

		if ( $this->failed ) {
			return false;
		}

		$this->entry = $this->repository->create_entry( $this->context );

		if ( $this->entry === null ) {
			$this->failed = true;
			++$this->failures;
			return false;
		}

		return true;
	}

	/**
	 * Stop every listener, handlers included.
	 *
	 * @return void
	 */
	private function detach(): void {
		$this->monitor->detach();
		$this->hooks->detach();

		foreach ( $this->mail as $hook => $callback ) {
			remove_filter( $hook, $callback, PHP_INT_MAX );
		}
		$this->mail = [];

		foreach ( $this->handlers->all() as $handler ) {
			try {
				$handler->close();
			} catch ( \Throwable $e ) {
				++$this->failures;
			}
		}
	}
}
