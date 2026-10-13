<?php
/**
 * Change-journal handler contract.
 *
 * @package Albert
 * @subpackage Undo\Handlers
 * @since      1.5.0
 */

namespace Albert\Undo\Handlers;

defined( 'ABSPATH' ) || exit;

use Albert\Undo\Entity;
use Albert\Undo\Recorder;

/**
 * Covers data the journal cannot see: a plugin's own tables.
 *
 * Register one through `albert/undo/handlers`. While a call is recorded, the
 * handler captures its entities through the plugin's own API (reporting them to
 * the {@see Recorder}), and its tables then count as captured rather than as a
 * gap. Without a handler, writes to those tables leave the entry unrevertable,
 * which is the honest default.
 *
 * @since 1.5.0
 */
interface Handler {

	/**
	 * Unique handler id. No colons.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function id(): string;

	/**
	 * Tables this handler captures itself, without the site prefix (`wc_orders`).
	 *
	 * @return array<int, string>
	 * @since 1.5.0
	 */
	public function tables(): array;

	/**
	 * Tables the plugin rebuilds from its own data (`wc_order_stats`), so writes
	 * to them are not gaps. Without the site prefix.
	 *
	 * @return array<int, string>
	 * @since 1.5.0
	 */
	public function derived_tables(): array;

	/**
	 * Whether this handler owns an entity the core capture recorded, so it is
	 * restored once, by the handler (an order handler claims the order's meta).
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function claims( Entity $entity ): bool;

	/**
	 * Start capturing: attach the plugin's own before-write hooks and report each
	 * entity through `$recorder->capture( Entity::custom( $this->id(), $key ) )`.
	 *
	 * @param Recorder $recorder The open window's recorder.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function open( Recorder $recorder ): void;

	/**
	 * Stop capturing: detach what {@see self::open()} attached.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function close(): void;

	/**
	 * The current state of one of this handler's entities, or null when it does
	 * not exist. Field => value, with no credentials.
	 *
	 * @param string $key The handler's own key.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.5.0
	 */
	public function read( string $key ): ?array;
}
