<?php
/**
 * Ability outcome classifier
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.4.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Outcome class
 *
 * Turns an ability result into the value written to the log's `status` column.
 *
 * `WP_Error` is the only channel an ability has for saying "no", so a genuine
 * fault, a deliberate refusal and a truthful negative answer all arrive in an
 * identical wrapper. Asking `is_wp_error()` and stopping there records
 * `ViewTerm( 999 )` — asked whether a term exists, looked, answered honestly —
 * as a red **Failed** beside "the CRM rejected our credentials". This class is
 * the one place that tells the three apart.
 *
 * The column is called `status`, so every value in it has to answer the single
 * question a status column asks: *what happened to this run?*
 *
 *   - `success` — the ability ran and answered. An empty result set, a null, a
 *                 "that does not exist" and a "there was nothing to do" are all
 *                 answers, and all of them are this.
 *   - `warning` — the run was blocked by policy. Nothing broke: the site said
 *                 no on purpose, because permission was refused or the ability
 *                 is switched off.
 *   - `error`   — the site could not do what was asked. Something broke, timed
 *                 out, or was malformed.
 *
 * `term_not_found` is a `success`, not a status of its own. An earlier draft of
 * this class gave it a `no_match` value, and that was wrong for a reason worth
 * writing down: *succeeded* and *failed* describe the run, while *not found*
 * describes the answer. Two kinds of statement in one column is what made the
 * value read as ambiguous — an owner could not tell whether a `no_match` row
 * meant the call went badly or the site simply has no such term. `warning`
 * earns the place `no_match` did not: *blocked* is the same grammatical kind as
 * *succeeded* and *failed*, and painting a correctly configured permission
 * system red every time it does its job is the same misinformation this class
 * exists to remove. Amber says "you may want to look"; red says "something is
 * broken". Only one of those is true of a refusal.
 *
 * Nothing is lost by folding a truthful "no" into `success`. `error_code` is
 * still stored, so `status = 'success' AND error_code IS NOT NULL` still finds
 * the burst of `product_not_found` from somebody enumerating IDs. That signal
 * never needed to spend a status value.
 *
 * **The test for the next `*_not_found` code somebody adds: is the thing
 * advertised to the caller?** Abilities, tools and skills are handed to the
 * assistant as a list, so naming one that is not on that list is a client bug —
 * an `error`. Posts, users, terms, orders, products, media and sessions are not
 * enumerated up front, so asking whether one exists is a fair question and an
 * empty answer is a `success`.
 *
 * Stage says *where* an invocation stopped; status says *whether it matters*.
 * The two are orthogonal, which is why a `success` carries no stage at all
 * while a `warning` keeps the `permission` stage it genuinely stopped at.
 *
 * @since 1.4.0
 */
class Outcome {

	/**
	 * The ability ran and answered.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const SUCCESS = 'success';

	/**
	 * The run was blocked by policy: nothing broke, the site said no on purpose.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const WARNING = 'warning';

	/**
	 * The site could not do what was asked: something broke or was malformed.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const ERROR = 'error';

	/**
	 * Every value the log's `status` column may hold.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const STATUSES = [ self::SUCCESS, self::WARNING, self::ERROR ];

	/**
	 * The failure stage that always means "blocked by policy".
	 *
	 * The robust signal, and the reason {@see self::for_error()} accepts a
	 * stage at all: a writer that knows the invocation died in the permission
	 * check knows more than any error code can say. The Abilities API's own
	 * refusal carries the generic `ability_invalid_permissions`, which a code
	 * list can recognise, but an add-on's permission callback may return
	 * anything it likes and the stage still names it correctly.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PERMISSION_STAGE = 'permission';

	/**
	 * Error-code suffix that marks a refused request.
	 *
	 * The mirror of {@see self::NOT_FOUND_SUFFIX}, and the same bargain: Albert
	 * and WordPress both spell a refusal `*_permission_denied`, so an add-on
	 * that follows the convention is classified correctly without being added
	 * to {@see self::POLICY_CODES}.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const POLICY_SUFFIX = '_permission_denied';

	/**
	 * Exact error codes that mean the site refused on purpose.
	 *
	 * The refusals whose codes do not carry {@see self::POLICY_SUFFIX}. Each
	 * one was traced back to the code that raises it:
	 *
	 *   - `ability_invalid_permissions` — the WordPress Abilities API's own
	 *     generic refusal, raised before the ability callback runs.
	 *   - `ability_disabled` — {@see \Albert\Abstracts\BaseAbility::is_executable()}
	 *     for an ability the owner switched off. The owner's own setting doing
	 *     exactly what it was set to do is the clearest possible warning-not-error.
	 *   - `capability_revoked` — an upload link whose author has since lost the
	 *     capability. The link is fine; the person behind it no longer qualifies.
	 *   - `rest_forbidden` — WordPress core's REST refusal, reached through the
	 *     `rest_do_request()` abilities.
	 *   - `forbidden` — Albert's own per-object read refusal in ViewPost and
	 *     ViewPage ("You do not have permission to view this post.").
	 *
	 * Deliberately *not* here: `ability_invalid_input` and `rest_invalid_param`
	 * are malformed requests rather than policy; `link_expired` and
	 * `token_expired` are refusals someone may genuinely need to act on;
	 * `rate_limit_exceeded` is the site failing to serve a request it would
	 * otherwise have allowed.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const POLICY_CODES = [
		'ability_invalid_permissions',
		'ability_disabled',
		'capability_revoked',
		'rest_forbidden',
		'forbidden',
	];

	/**
	 * Safe mode held the call for a person to approve.
	 *
	 * A `warning`, but deliberately not a member of {@see self::POLICY_CODES}
	 * and classified on its own branch. That set means "you may not" or "this is
	 * switched off", both of them final. This one means *not yet decided*: the
	 * same call may well run in a minute once somebody approves it. Filing it
	 * under policy would quietly widen a set whose docblock is precise about
	 * what belongs in it.
	 *
	 * It shares the outcome because the question `status` asks is what happened
	 * to this run, and nothing broke: the site stopped it on purpose. Recording
	 * it as `error` painted the Dashboard activity card red and fired
	 * `albert/logging/ability_failed` every single time the gate did its job,
	 * which is precisely the pressure that gets a safety feature switched off.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const HELD_CODE = 'albert_awaiting_approval';

	/**
	 * Error-code suffix that marks a truthful negative answer.
	 *
	 * Albert's own convention: `post_not_found`, `term_not_found`,
	 * `product_not_found`. Add-ons that follow the convention inherit the
	 * classification for free; third-party abilities that do not have the
	 * `albert/logging/outcome` filter.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const NOT_FOUND_SUFFIX = '_not_found';

	/**
	 * Exact error codes treated as a truthful negative answer.
	 *
	 * Deliberately short. Every entry was checked against every site that
	 * raises it, and each one means "the record you named is not there" and
	 * nothing else:
	 *
	 *   - `not_found`          — the bare, unprefixed form of the suffix rule.
	 *   - `invalid_post`       — "The specified post does not exist."
	 *   - `invalid_attachment` — "The specified attachment does not exist."
	 *
	 * Codes deliberately left as `error` even though they read close to this
	 * list: `token_already_used` and `link_already_used` both say "invalid **or**
	 * has already been used", so the negative reading is not the only one and a
	 * real rejection would be silenced along with the benign case;
	 * `invalid_taxonomy` names a taxonomy the site does not register, which is a
	 * malformed request rather than an absent record; `link_expired` and
	 * `token_expired` are refusals someone may need to act on.
	 *
	 * The REST codes are left as `error` for the same reason. `rest_post_invalid_id`
	 * is the interesting one: core raises it for an id of zero or less, for a
	 * post that is genuinely absent, *and* for a post whose type does not match
	 * the route — two of those three readings are a malformed request. Albert's
	 * own abilities check existence first and answer with `post_not_found`,
	 * `page_not_found` or `user_not_found`, so `rest_post_invalid_id` never
	 * carries the plain "does it exist?" question anyway; by the time it appears
	 * the ability believed the id was good and REST disagreed.
	 *
	 * Erring toward `error` is the safe direction: a misclassified failure that
	 * shouts is better than a real failure that whispers.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const NOT_FOUND_CODES = [ 'not_found', 'invalid_post', 'invalid_attachment' ];

	/**
	 * `*_not_found` codes that are API-surface misses, not content misses.
	 *
	 * The suffix rule over-reaches on its own, and the class docblock's test —
	 * *is the thing advertised to the caller?* — is what separates these out.
	 * Abilities, tools and skills are all enumerated to the assistant before it
	 * calls anything, so naming one that is not on the list is a client bug and
	 * belongs in red:
	 *
	 *   - `albert_ability_not_found`, `albert_premium_ability_not_found`,
	 *     `ability_not_found` — the ability id is not registered.
	 *   - `tool_not_found` — the MCP tool name is not one the server exposes.
	 *   - `skill_not_found` — a judgement call, written down rather than left to
	 *     the suffix rule: skills *are* enumerated to the assistant, so asking
	 *     for one that does not exist is the same client bug as the others.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	const API_SURFACE_CODES = [
		'albert_ability_not_found',
		'albert_premium_ability_not_found',
		'ability_not_found',
		'tool_not_found',
		'skill_not_found',
	];

	/**
	 * Classify an ability result.
	 *
	 * @param array<string, mixed>|WP_Error|mixed $result       The value the ability returned.
	 * @param string                              $ability_name The ability identifier.
	 *
	 * @return string One of {@see self::STATUSES}.
	 * @since 1.4.0
	 */
	public static function classify( mixed $result, string $ability_name ): string {
		if ( ! is_wp_error( $result ) ) {
			return self::SUCCESS;
		}

		return self::for_error( $result, $ability_name );
	}

	/**
	 * Classify a failed invocation from its WP_Error.
	 *
	 * Separate from {@see self::classify()} because not every writer holds the
	 * original result. {@see Repository::insert()} has a status, an error code
	 * and a failure stage, and rebuilds a faithful `WP_Error` from the first two
	 * so the filter below always receives the same shape.
	 *
	 * Two signals decide the answer, and the stage wins. A writer that recorded
	 * `permission` watched the invocation stop in the permission check; that is
	 * a fact about the run, where an error code is only a convention about how
	 * somebody spelled it. Free never stamps a stage — it has nowhere to learn
	 * one — so the code rules below are what classify its own rows, and Premium
	 * gets both.
	 *
	 * @param WP_Error    $error         The error the invocation produced.
	 * @param string      $ability_name  The ability identifier.
	 * @param string|null $failure_stage Where the invocation died, when the caller knows. Default null.
	 *
	 * @return string Either {@see self::SUCCESS}, {@see self::WARNING} or {@see self::ERROR}.
	 * @since 1.4.0
	 */
	public static function for_error( WP_Error $error, string $ability_name, ?string $failure_stage = null ): string {
		$code = (string) $error->get_error_code();

		if ( self::HELD_CODE === $code ) {
			$status = self::WARNING;
		} elseif ( $failure_stage === self::PERMISSION_STAGE || self::is_policy_code( $code ) ) {
			$status = self::WARNING;
		} elseif ( self::is_not_found_code( $code ) ) {
			$status = self::SUCCESS;
		} else {
			$status = self::ERROR;
		}

		/**
		 * Filters the outcome recorded for a failed ability invocation.
		 *
		 * Albert classifies by its own error-code conventions — anything ending
		 * in `_not_found` is a truthful negative answer rather than a fault,
		 * anything ending in `_permission_denied` is the site refusing on
		 * purpose. Third-party abilities have no reason to follow either
		 * convention, and this filter is their way out: return `success` for a
		 * negative answer Albert reads as a fault, `warning` for a refusal it
		 * cannot recognise, or `error` for a code it recognises wrongly.
		 *
		 * Returning `success` also suppresses `albert/logging/ability_failed`
		 * for that invocation and leaves `failure_stage` null, because an
		 * answer is not a failure and should not wake anyone. Returning
		 * `warning` suppresses the same hook but keeps the stage: a policy
		 * block did stop somewhere, and where is worth showing.
		 *
		 * Only fires for failed invocations; a successful one is never
		 * reclassified. A returned value that is not one of `success`,
		 * `warning` or `error` is ignored.
		 *
		 * @since 1.4.0
		 *
		 * @param string   $status       The computed status: `success`, `warning` or `error`.
		 * @param string   $ability_name The ability identifier.
		 * @param WP_Error $error        The error the invocation produced.
		 */
		$filtered = apply_filters( 'albert/logging/outcome', $status, $ability_name, $error );

		if ( is_string( $filtered ) && in_array( $filtered, self::STATUSES, true ) ) {
			return $filtered;
		}

		return $status;
	}

	/**
	 * Whether an error code names a request the site refused on purpose.
	 *
	 * @param string $code The WP_Error code.
	 *
	 * @return bool True when the code means "you may not" or "this is switched off".
	 * @since 1.4.0
	 */
	public static function is_policy_code( string $code ): bool {
		if ( $code === '' ) {
			return false;
		}

		if ( in_array( $code, self::POLICY_CODES, true ) ) {
			return true;
		}

		return str_ends_with( $code, self::POLICY_SUFFIX );
	}

	/**
	 * Whether an error code names a truthful negative answer.
	 *
	 * @param string $code The WP_Error code.
	 *
	 * @return bool True when the code means "it is not there" or "there was nothing to do".
	 * @since 1.4.0
	 */
	public static function is_not_found_code( string $code ): bool {
		if ( $code === '' ) {
			return false;
		}

		/**
		 * Filters the error codes that name something the caller was told exists.
		 *
		 * An ability, tool or skill is enumerated to the assistant, so asking
		 * for one that is not there is a client bug and stays an `error`. A
		 * post or a customer is not enumerated, so asking is a fair question
		 * and the honest "no" is a `success`.
		 *
		 * The constant can only list what Free knows about. An add-on that
		 * enumerates its own surface — routes, resources, connectors — registers
		 * its codes here, rather than having them silently classified as a
		 * truthful negative answer and disappear from the failure counts.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, string> $codes Error codes naming an enumerated surface.
		 */
		$api_surface = (array) apply_filters( 'albert/logging/api_surface_codes', self::API_SURFACE_CODES );

		if ( in_array( $code, $api_surface, true ) ) {
			return false;
		}

		if ( in_array( $code, self::NOT_FOUND_CODES, true ) ) {
			return true;
		}

		return str_ends_with( $code, self::NOT_FOUND_SUFFIX );
	}
}
