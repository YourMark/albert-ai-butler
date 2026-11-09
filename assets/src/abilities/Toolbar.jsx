/**
 * Custom toolbar for the Abilities DataViews screen.
 *
 * DataViews renders `children ?? DefaultUI`, so by composing our own children we
 * replace DataViews' default toolbar (search + funnel toggle + chip filters on a
 * second row) with a single aligned row of accessible <select> filters
 * (SelectControl). The data engine is unchanged: each select writes to the
 * `view` object (filters/sort), which filterSortAndPaginate consumes.
 *
 * Search, density (ViewConfig) and the table/grid switch (LayoutSwitcher) reuse
 * DataViews' own sub-components, which read the shared context.
 */
import { DataViews } from '@wordpress/dataviews/wp';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	FilterSelect,
	filterValue,
	setSort,
	withAll,
	STATUS_OPTIONS,
} from '../shared/ToolbarControls';

const OPERATION_OPTIONS = [
	{ value: '', label: __( 'All operations', 'albert-ai-butler' ) },
	{ value: 'read', label: __( 'Read', 'albert-ai-butler' ) },
	{ value: 'write', label: __( 'Write', 'albert-ai-butler' ) },
	{ value: 'destructive', label: __( 'Destructive', 'albert-ai-butler' ) },
];

const SORT_OPTIONS = [
	{ value: 'label', label: __( 'Sort: Name', 'albert-ai-butler' ) },
	{ value: 'category', label: __( 'Sort: Category', 'albert-ai-butler' ) },
	{ value: 'operation', label: __( 'Sort: Operation', 'albert-ai-butler' ) },
	{ value: 'lastUsed', label: __( 'Sort: Last used', 'albert-ai-butler' ) },
];

/**
 * The custom toolbar.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.view         The current DataViews view.
 * @param {Function} props.onChangeView View setter.
 * @param {Array}    props.categories   Category filter elements.
 * @param {Array}    props.sources      Source filter elements.
 * @param {Array}    props.badges       Badge filter elements.
 * @return {Element} The toolbar.
 */
export default function Toolbar( {
	view,
	onChangeView,
	categories,
	sources,
	badges,
} ) {
	// Badges live in an array on each row, so they use DataViews' `isAny`
	// operator (array filter value), unlike the single-value ("is") filters
	// {@see FilterSelect} handles. Read back the single selected id and write
	// it as a one-element array.
	const badgeValue = () => {
		const value = filterValue( view, 'badges' );
		return Array.isArray( value ) ? value[ 0 ] ?? '' : '';
	};

	const setBadgeFilter = ( value ) => {
		const others = ( view.filters || [] ).filter(
			( filter ) => filter.field !== 'badges'
		);
		const filters =
			value === ''
				? others
				: [ ...others, { field: 'badges', operator: 'isAny', value: [ value ] } ];
		onChangeView( { ...view, filters, page: 1 } );
	};

	return (
		<div className="albert-toolbar">
			<div className="albert-toolbar__group">
				<DataViews.Search />
				<FilterSelect
					view={ view }
					onChangeView={ onChangeView }
					label={ __( 'Filter by category', 'albert-ai-butler' ) }
					field="category"
					options={ withAll(
						categories,
						__( 'All categories', 'albert-ai-butler' )
					) }
				/>
				<FilterSelect
					view={ view }
					onChangeView={ onChangeView }
					label={ __( 'Filter by operation', 'albert-ai-butler' ) }
					field="operation"
					options={ OPERATION_OPTIONS }
				/>
				<FilterSelect
					view={ view }
					onChangeView={ onChangeView }
					label={ __( 'Filter by status', 'albert-ai-butler' ) }
					field="status"
					options={ STATUS_OPTIONS }
				/>
				<FilterSelect
					view={ view }
					onChangeView={ onChangeView }
					label={ __( 'Filter by source', 'albert-ai-butler' ) }
					field="source"
					options={ withAll(
						sources,
						__( 'All sources', 'albert-ai-butler' )
					) }
				/>
				{ badges?.length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Filter by badge', 'albert-ai-butler' ) }
						value={ badgeValue() }
						options={ withAll(
							badges,
							__( 'All badges', 'albert-ai-butler' )
						) }
						onChange={ setBadgeFilter }
					/>
				) }
			</div>
			<div className="albert-toolbar__group albert-toolbar__group--end">
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Sort abilities', 'albert-ai-butler' ) }
					value={ view.sort?.field || 'label' }
					options={ SORT_OPTIONS }
					onChange={ ( field ) => setSort( view, onChangeView, field ) }
				/>
				<DataViews.ViewConfig />
				<DataViews.LayoutSwitcher />
			</div>
		</div>
	);
}
