/**
 * Permissions section without Premium.
 *
 * Mirrors Albert Premium's editor: an "Advanced permissions" switch, disabled
 * here, above a locked preview of the rule builder and an upgrade link. Albert
 * cannot read an ability's own permission check, so it says nothing about it.
 *
 * When Premium is active it replaces this whole section via the
 * `albert.abilities.permissions_section` filter.
 *
 * The locked preview is decorative (aria-hidden); the pitch and CTA carry the
 * accessible content.
 */
import { Button, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const UPGRADE_URL =
	'https://albertwp.com/add-ons/premium-service/?utm_source=plugin&utm_medium=abilities-flyin&utm_campaign=advanced-permissions';

export default function AdvancedPermissionsUpsell() {
	return (
		<div className="albert-perms-upsell albert-perms-panel">
			<ToggleControl
				__nextHasNoMarginBottom
				label={
					<span className="albert-perms-upsell__custom-label">
						{ __( 'Advanced permissions', 'albert-ai-butler' ) }
						<span
							className="dashicons dashicons-lock"
							aria-hidden="true"
						/>
						<span className="screen-reader-text">
							{ __( '(Premium)', 'albert-ai-butler' ) }
						</span>
					</span>
				}
				checked={ false }
				disabled
				onChange={ () => {} }
			/>

			<div className="albert-perms-upsell__custom">
				<ul className="albert-perms-upsell__benefits">
					<li>
						{ __(
							'Allow only specific roles or users',
							'albert-ai-butler'
						) }
					</li>
					<li>
						{ __(
							'Block everyone else automatically',
							'albert-ai-butler'
						) }
					</li>
				</ul>

				<span className="albert-perms-upsell__example-label">
					{ __( 'Example', 'albert-ai-butler' ) }
				</span>

				{ /* The example rules are a decorative showcase (aria-hidden);
				     the Upgrade button sits over the lower part of the box so
				     it reads as a locked preview, not an editable rule list. */ }
				<div className="albert-perms-upsell__preview">
					<ul
						className="albert-perms-upsell__rules"
						aria-hidden="true"
					>
						<li className="albert-perms-upsell__rule">
							<span className="albert-perms-upsell__token">
								{ __( 'Enable', 'albert-ai-butler' ) }
							</span>
							<span className="albert-perms-upsell__word">
								{ __( 'when role is', 'albert-ai-butler' ) }
							</span>
							<span className="albert-perms-upsell__chip">
								{ __( 'Editor', 'albert-ai-butler' ) }
							</span>
						</li>
						<li className="albert-perms-upsell__rule">
							<span className="albert-perms-upsell__token">
								{ __( 'Disable', 'albert-ai-butler' ) }
							</span>
							<span className="albert-perms-upsell__word">
								{ __( 'when user is', 'albert-ai-butler' ) }
							</span>
							<span className="albert-perms-upsell__chip">
								jane@example.com
							</span>
						</li>
					</ul>

					<div className="albert-perms-upsell__cta">
						<Button
							variant="primary"
							href={ UPGRADE_URL }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Upgrade to Premium', 'albert-ai-butler' ) }
							<span className="screen-reader-text">
								{ __(
									'(opens in a new tab)',
									'albert-ai-butler'
								) }
							</span>
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
}
