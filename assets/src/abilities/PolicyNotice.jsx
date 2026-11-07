/**
 * Says when an environment policy overrides the toggles on this screen.
 *
 * The rows show the saved toggles, which the policy never writes, so without
 * this a switched-off toggle would look like it does nothing.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const SOURCES = {
	constant: 'ALBERT_ENVIRONMENT_ABILITIES',
	filter: 'albert/abilities/environment_policy',
};

/**
 * Translated name for a WordPress environment type.
 *
 * @param {string} environment Value of wp_get_environment_type().
 * @return {string} Lower-case label.
 */
function environmentLabel( environment ) {
	switch ( environment ) {
		case 'local':
			return __( 'local', 'albert-ai-butler' );
		case 'development':
			return __( 'development', 'albert-ai-butler' );
		case 'staging':
			return __( 'staging', 'albert-ai-butler' );
		default:
			return __( 'production', 'albert-ai-butler' );
	}
}

/**
 * The notice shown while an environment policy is in force.
 *
 * @param {Object}                                                   props
 * @param {{environment: string, policy: string, source: string}|null} props.policy
 * @param {boolean}                                                  props.hidden Hidden while the fly-in is open.
 * @return {JSX.Element|null} The notice, or nothing when the toggles decide.
 */
export default function PolicyNotice( { policy, hidden } ) {
	if ( ! policy ) {
		return null;
	}

	const environment = environmentLabel( policy.environment );

	const effect =
		policy.policy === 'all'
			? sprintf(
					/* translators: %s: environment type, e.g. "staging". */
					__(
						'This is a %s site, and its environment policy switches on every ability for assistants connected through Albert.',
						'albert-ai-butler'
					),
					environment
			  )
			: sprintf(
					/* translators: %s: environment type, e.g. "staging". */
					__(
						'This is a %s site, and its environment policy switches on every Read and Write ability for assistants connected through Albert. Destructive abilities, and abilities that do not say what they do, follow their toggle.',
						'albert-ai-butler'
					),
					environment
			  );

	return (
		<div
			className="albert-abilities__policy notice notice-info"
			aria-hidden={ hidden ? true : undefined }
			inert={ hidden ? '' : undefined }
		>
			<p>
				<strong>
					{ __( 'Environment policy in use.', 'albert-ai-butler' ) }
				</strong>{ ' ' }
				{ effect }{ ' ' }
				{ __(
					'The toggles below are not changed, and apply again when the policy is removed.',
					'albert-ai-butler'
				) }
			</p>
			{ SOURCES[ policy.source ] && (
				<p>
					{ createInterpolateElement(
						sprintf(
							/* translators: %s: constant or filter name. */
							__( 'Set in code by <code>%s</code>.', 'albert-ai-butler' ),
							SOURCES[ policy.source ]
						),
						{ code: <code /> }
					) }
				</p>
			) }
		</div>
	);
}
