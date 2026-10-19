/**
 * Permissions section — Free default (no Premium).
 *
 * Mirrors the structure of Albert Premium's real editor: a Capability/Custom
 * toggle. "Capability only" shows the required-capability card; "Custom rules"
 * is gated behind Premium and shows a locked preview of the rule builder plus an
 * upgrade call to action.
 *
 * When Premium is active it replaces this whole section via the
 * `albert.abilities.permissions_section` filter, so this is only ever seen
 * without Premium — no add-on dependency and no activation check here.
 *
 * The locked preview is decorative (aria-hidden); the pitch and CTA carry the
 * accessible content.
 */
import {
	Button,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const UPGRADE_URL =
	'https://albertwp.com/add-ons/premium-service/?utm_source=plugin&utm_medium=abilities-flyin&utm_campaign=advanced-permissions';

export default function AdvancedPermissionsUpsell( { capabilityContent } ) {
	const [ mode, setMode ] = useState( 'capability' );

	return (
		<div className="albert-perms-upsell albert-perms-panel">
			<p className="albert-perms-panel__intro">
				{ __(
					'Control who can use this ability, by its required capability or with custom per-role and per-user rules.',
					'albert-ai-butler'
				) }
			</p>

			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				hideLabelFromVision
				label={ __( 'Permission mode', 'albert-ai-butler' ) }
				value={ mode }
				onChange={ ( value ) =>
					setMode( value === 'custom' ? 'custom' : 'capability' )
				}
			>
				<ToggleGroupControlOption
					value="capability"
					label={ __( 'Capability only', 'albert-ai-butler' ) }
				/>
				<ToggleGroupControlOption
					value="custom"
					aria-label={ __(
						'Custom rules (Premium)',
						'albert-ai-butler'
					) }
					label={
						<span className="albert-perms-upsell__custom-label">
							{ __( 'Custom rules', 'albert-ai-butler' ) }
							<span
								className="dashicons dashicons-lock"
								aria-hidden="true"
							/>
						</span>
					}
				/>
			</ToggleGroupControl>

			{ mode === 'capability' && (
				<div className="albert-perms-upsell__capability">
					{ capabilityContent }
				</div>
			) }

			{ mode === 'custom' && (
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
						<li>
							{ __(
								'Override the required-capability check',
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
								{ __(
									'Upgrade to Premium',
									'albert-ai-butler'
								) }
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
			) }
		</div>
	);
}
