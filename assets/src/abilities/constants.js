/**
 * Shared constants for the Abilities screen.
 *
 * Operation labels are used by both the table cells (fields.js) and the detail
 * fly-in (FlyInPanel.jsx); keep them in one place so a new operation type only
 * has to be added once.
 */
import { __ } from '@wordpress/i18n';

/**
 * Human labels for the three operation types, keyed by their stable value.
 *
 * @type {Object<string, string>}
 */
export const OPERATION_LABELS = {
	read: __( 'Read', 'albert-ai-butler' ),
	write: __( 'Write', 'albert-ai-butler' ),
	destructive: __( 'Destructive', 'albert-ai-butler' ),
};
