/**
 * Shared constants for the Abilities screen.
 *
 * Operation labels are used by both the table cells (fields.js) and the detail
 * fly-in (FlyInPanel.jsx); keep them in one place so a new operation type only
 * has to be added once.
 */
import { __ } from '@wordpress/i18n';

/**
 * Human labels for the operation types, keyed by their stable value.
 *
 * @type {Object<string, string>}
 */
export const OPERATION_LABELS = {
	read: __( 'Read', 'albert-ai-butler' ),
	write: __( 'Write', 'albert-ai-butler' ),
	delete: __( 'Delete', 'albert-ai-butler' ),
	undeclared: __( 'Not declared', 'albert-ai-butler' ),
};

/**
 * The tooltip for an operation badge: the chip's description, but only for
 * "Not declared", the one label that needs explaining.
 *
 * @param {Object} ability The ability row.
 * @return {string|undefined} The tooltip text.
 */
export function operationTitle( ability ) {
	if ( ability.operation !== 'undeclared' ) {
		return undefined;
	}
	return ability.annotations?.[ 0 ]?.description;
}
