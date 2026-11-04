/**
 * Abilities (DataViews) admin app.
 *
 * Renders the registered abilities as a DataViews table/grid with search,
 * filters, sort, density, layout switch, pagination, and an in-row enabled
 * toggle. Data is loaded once over REST and filtered/sorted/paginated
 * client-side via filterSortAndPaginate. Clicking an ability opens the detail
 * fly-in (FlyInPanel); rows can be bulk enabled/disabled from the selection
 * toolbar, and the header offers Enable all / Disable all.
 *
 * The accent follows the site's WordPress admin color scheme — DataViews and
 * wp-components read --wp-admin-theme-color, which we do not override.
 */
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import {
	Button,
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import {
	fetchAbilities,
	setAbilityEnabled,
	setAbilitiesEnabledBulk,
} from './api';
import { getFields } from './fields';
import { viewFromUrl, syncViewToUrl, openIdFromUrl } from './url';
import Toolbar from './Toolbar';
import useNarrowLayout from '../shared/useNarrowLayout';
import FlyInPanel from './FlyInPanel';
import PolicyNotice from './PolicyNotice';
import InfoPopover from '../shared/InfoPopover';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	titleField: 'label',
	showMedia: false,
	fields: [ 'category', 'operation', 'source', 'lastUsed', 'status' ],
	sort: { field: 'label', direction: 'asc' },
	filters: [],
	layout: {
		styles: {
			// Advisory only: the table is `table-layout: auto`, so the browser
			// sizes columns by content and treats these as hints. What actually
			// keeps the table inside its container is the Ability cell's
			// description wrapping rather than running on one line
			// (admin-abilities.css). These stay as the intent for any column
			// whose content leaves the browser a choice.
			label: { minWidth: 200 },
			category: { minWidth: 96, maxWidth: 150 },
			operation: { width: 116 },
			source: { minWidth: 100, maxWidth: 160 },
			lastUsed: { minWidth: 110, maxWidth: 180 },
			status: { width: 84 },
		},
	},
};

const DEFAULT_LAYOUTS = {
	table: {},
	grid: { layout: { badgeFields: [ 'operation' ] } },
	list: {},
};


export default function AbilitiesApp() {
	const [ items, setItems ] = useState( [] );
	const [ categories, setCategories ] = useState( [] );
	const [ sources, setSources ] = useState( [] );
	const [ roles, setRoles ] = useState( [] );
	const [ policy, setPolicy ] = useState( null );
	// Initialise from the URL so a shared/bookmarked link opens pre-filtered.
	const [ view, setView ] = useState( () => viewFromUrl( DEFAULT_VIEW ) );

	const onChangeView = useNarrowLayout( view, setView );
	const [ selection, setSelection ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	// Seeded from the URL so something outside this screen can link at an
	// ability rather than at the list. Cleared below if nothing matches, so a
	// stale or hand-edited link degrades to the plain list rather than to a
	// panel that never opens.
	const [ openId, setOpenId ] = useState( () => openIdFromUrl() );
	// 'enable' | 'disable' | null — drives the bulk "all" confirm dialog.
	const [ bulkConfirm, setBulkConfirm ] = useState( null );

	// Move focus to the error banner when one appears so it isn't missed.
	const errorRef = useRef( null );
	useEffect( () => {
		if ( error ) {
			errorRef.current?.focus();
		}
	}, [ error ] );

	// Mirror the view (search, filters, sort, layout, page) into the URL so it
	// can be shared and bookmarked. replaceState keeps history clean.
	useEffect( () => {
		syncViewToUrl( view, openId );
	}, [ view, openId ] );

	useEffect( () => {
		let active = true;
		fetchAbilities()
			.then( ( payload ) => {
				if ( ! active ) {
					return;
				}
				setItems( payload.abilities );
				setCategories( payload.categories );
				setSources( payload.sources );
				setRoles( payload.roles || [] );
				setPolicy( payload.policy || null );
			} )
			.catch(
				( err ) => active && setError( err.message || String( err ) )
			)
			.finally( () => active && setIsLoading( false ) );
		return () => {
			active = false;
		};
	}, [] );

	const onToggle = useCallback( ( item, enabled ) => {
		setError( null );
		setItems( ( prev ) =>
			prev.map( ( row ) =>
				row.id === item.id ? { ...row, enabled } : row
			)
		);
		setAbilityEnabled( item.id, enabled )
			.then( ( updated ) =>
				setItems( ( prev ) =>
					prev.map( ( row ) =>
						row.id === updated.id ? updated : row
					)
				)
			)
			.catch( ( err ) => {
				// Revert the optimistic change.
				setItems( ( prev ) =>
					prev.map( ( row ) =>
						row.id === item.id
							? { ...row, enabled: item.enabled }
							: row
					)
				);
				setError( err.message || String( err ) );
			} );
	}, [] );

	const bulkToggle = useCallback( ( selectedItems, enabled ) => {
		setError( null );
		const ids = selectedItems.map( ( item ) => item.id );
		if ( ids.length === 0 ) {
			return;
		}
		// Optimistic update, then one bulk request. Only re-fetch on failure to
		// repair the optimistic state — the happy path already matches the server.
		setItems( ( prev ) =>
			prev.map( ( row ) =>
				ids.includes( row.id ) ? { ...row, enabled } : row
			)
		);
		setAbilitiesEnabledBulk( ids, enabled ).catch( () => {
			setError(
				__(
					'Could not save your changes. Please try again.',
					'albert-ai-butler'
				)
			);
			fetchAbilities()
				.then( ( payload ) => setItems( payload.abilities ) )
				.catch( () => {} );
		} );
		setSelection( [] );
	}, [] );

	// Both "all" actions confirm via a styled dialog — enabling can expose
	// write/delete abilities, and disabling all cuts off every integration.
	const setAllEnabled = useCallback( ( enabled ) => {
		setBulkConfirm( enabled ? 'enable' : 'disable' );
	}, [] );

	const confirmBulkAll = useCallback( () => {
		bulkToggle( items, bulkConfirm === 'enable' );
		setBulkConfirm( null );
	}, [ items, bulkConfirm, bulkToggle ] );

	const actions = useMemo(
		() => [
			{
				id: 'enable',
				label: __( 'Enable', 'albert-ai-butler' ),
				supportsBulk: true,
				isEligible: ( item ) => ! item.enabled,
				callback: ( selectedItems, { onActionPerformed } = {} ) => {
					bulkToggle( selectedItems, true );
					onActionPerformed?.( selectedItems );
				},
			},
			{
				id: 'disable',
				label: __( 'Disable', 'albert-ai-butler' ),
				supportsBulk: true,
				isEligible: ( item ) => item.enabled,
				callback: ( selectedItems, { onActionPerformed } = {} ) => {
					bulkToggle( selectedItems, false );
					onActionPerformed?.( selectedItems );
				},
			},
		],
		[ bulkToggle ]
	);

	// Badges are contributed per-row by add-ons (via the PHP
	// `albert/abilities/payload_row` filter), so the filter options are the
	// deduped union of every badge present in the loaded data.
	const badges = useMemo( () => {
		const seen = new Map();
		items.forEach( ( item ) => {
			if ( ! Array.isArray( item.badges ) ) {
				return;
			}
			item.badges.forEach( ( badge ) => {
				if ( ! seen.has( badge.id ) ) {
					seen.set( badge.id, {
						value: badge.id,
						label: badge.label,
					} );
				}
			} );
		} );
		return [ ...seen.values() ];
	}, [ items ] );

	const fields = useMemo(
		() => getFields( { categories, sources, badges, onToggle } ),
		[ categories, sources, badges, onToggle ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	// Search/filter/sort change the table's content without a page reload or
	// a focus change, so nothing tells a screen-reader user the result count
	// changed unless something announces it. Skipped on the initial load
	// (isFirstResult) since that isn't a filter changing.
	const isFirstResult = useRef( true );
	useEffect( () => {
		if ( isLoading ) {
			return;
		}
		if ( isFirstResult.current ) {
			isFirstResult.current = false;
			return;
		}
		speak(
			sprintf(
				/* translators: %d: number of matching abilities. */
				_n(
					'%d ability found.',
					'%d abilities found.',
					paginationInfo.totalItems,
					'albert-ai-butler'
				),
				paginationInfo.totalItems
			)
		);
	}, [ paginationInfo.totalItems, isLoading ] );

	const getItemId = useCallback( ( item ) => item.id, [] );

	useEffect( () => {
		if ( ! isLoading && openId && ! items.some( ( item ) => item.id === openId ) ) {
			setOpenId( null );
		}
	}, [ isLoading, items, openId ] );

	const onClickItem = useCallback( ( item ) => setOpenId( item.id ), [] );

	const onCloseFlyIn = useCallback( () => setOpenId( null ), [] );

	const enabledCount = useMemo(
		() => items.filter( ( item ) => item.enabled ).length,
		[ items ]
	);

	const openItem = useMemo(
		() => items.find( ( item ) => item.id === openId ) || null,
		[ items, openId ]
	);

	return (
		<div className="albert-page albert-page--full albert-abilities">
			{ /*
			 * `inert` as well as `aria-hidden`, and the pair is the point.
			 * `aria-hidden` only hides from assistive tech; it leaves the
			 * content tabbable, which is a WCAG 4.1.2 failure the moment
			 * anything can reach it. Today nothing can, because the fly-in traps
			 * focus (verified: 30 tabs, focus never left the dialog), but that
			 * makes correctness depend on a JS trap holding. `inert` is the
			 * browser enforcing it, so the trap becomes belt rather than
			 * load-bearing. Passed as a string because this is React 18, where
			 * `inert` is not yet a known boolean prop.
			 */ }
			<header
				className="albert-page__header albert-abilities__header"
				aria-hidden={ openItem ? true : undefined }
				inert={ openItem ? '' : undefined }
			>
				<div className="albert-page__text">
					<h1 className="albert-page__title">
						{ __( 'Abilities', 'albert-ai-butler' ) }
					</h1>
					<p className="albert-page__description">
						{ __(
							'Choose which abilities are available through Albert’s MCP endpoint. Open an ability to review its schema and permissions.',
							'albert-ai-butler'
						) }{ ' ' }
						<InfoPopover
							label={ __(
								'About what these switches change',
								'albert-ai-butler'
							) }
						>
							<p>
								{ __(
									'A switched-off ability is left out of Albert’s MCP endpoint, and refused there if an assistant calls it anyway.',
									'albert-ai-butler'
								) }
							</p>
							<p>
								{ __(
									'The ability stays registered in WordPress. Other plugins, the MCP Adapter default server and other MCP servers on this site can still use it.',
									'albert-ai-butler'
								) }
							</p>
						</InfoPopover>
					</p>
				</div>
			</header>

			{ /*
			 * Counts and the two bulk actions, on one full-width row directly
			 * above the list.
			 *
			 * Not in `.albert-page__actions`. That slot is documented as
			 * "primary control and/or save state" and is used for a Back link
			 * and a save status elsewhere; WordPress puts the *create* action
			 * beside a page title ("Add Plugin", "Add Post", "Add User", all
			 * hugging the heading) and puts bulk operations in the tablenav
			 * directly above the table. Enabling or disabling 118 abilities is
			 * a bulk operation on the list below, so the header anchor is the
			 * wrong home for it twice over: wrong kind of action, and far from
			 * the data it changes.
			 *
			 * It also cannot live inside `.albert-page__text`, which is where it
			 * started: that block is capped at the description's measure, so
			 * the buttons right-aligned to 822px while the card, the toolbar and
			 * the table all ended at 1380px. Aligning to an edge nothing draws
			 * is what read as broken.
			 *
			 * Full width, `space-between`: the counts on the left and the
			 * actions that change those counts on the right, sharing the list's
			 * own measure.
			 */ }
			<PolicyNotice policy={ policy } hidden={ !! openItem } />

			{ ! isLoading && (
				<div
					className="albert-abilities__summary-row"
					aria-hidden={ openItem ? true : undefined }
					inert={ openItem ? '' : undefined }
				>
					<p className="albert-abilities__summary" aria-live="polite">
						<span>
							<strong>{ items.length }</strong>{ ' ' }
							{ __( 'registered', 'albert-ai-butler' ) }
						</span>
						<span aria-hidden="true">·</span>
						<span>
							<strong>{ enabledCount }</strong>{ ' ' }
							{ __( 'enabled', 'albert-ai-butler' ) }
						</span>
					</p>
					<div className="albert-abilities__bulk-all">
						<Button
							variant="secondary"
							size="compact"
							disabled={ enabledCount === items.length }
							onClick={ () => setAllEnabled( true ) }
						>
							{ __( 'Enable all', 'albert-ai-butler' ) }
						</Button>
						<Button
							variant="secondary"
							size="compact"
							disabled={ enabledCount === 0 }
							onClick={ () => setAllEnabled( false ) }
						>
							{ __( 'Disable all', 'albert-ai-butler' ) }
						</Button>
					</div>
				</div>
			) }

			{ error && (
				<div
					className="albert-abilities__error notice notice-error"
					role="alert"
					tabIndex={ -1 }
					ref={ errorRef }
				>
					<p>{ error }</p>
				</div>
			) }

			{ isLoading ? (
				<div className="albert-abilities__loading">
					<Spinner />
				</div>
			) : (
				<div
					className="albert-abilities__card"
					aria-hidden={ openItem ? true : undefined }
					inert={ openItem ? '' : undefined }
				>
					<DataViews
						data={ data }
						fields={ fields }
						view={ view }
						onChangeView={ onChangeView }
						actions={ actions }
						defaultLayouts={ DEFAULT_LAYOUTS }
						paginationInfo={ paginationInfo }
						getItemId={ getItemId }
						onClickItem={ onClickItem }
						selection={ selection }
						onChangeSelection={ setSelection }
					>
						<Toolbar
							view={ view }
							onChangeView={ onChangeView }
							categories={ categories }
							sources={ sources }
							badges={ badges }
						/>
						{ selection.length > 0 && (
							<div className="albert-abilities__bulkbar">
								<DataViews.BulkActionToolbar />
							</div>
						) }
						<DataViews.Layout />
						<DataViews.Footer />
					</DataViews>
				</div>
			) }

			{ openItem && (
				<FlyInPanel
					ability={ openItem }
					roles={ roles }
					onClose={ onCloseFlyIn }
					onToggle={ onToggle }
				/>
			) }

			{ bulkConfirm && (
				<ConfirmDialog
					isOpen
					onConfirm={ confirmBulkAll }
					onCancel={ () => setBulkConfirm( null ) }
					confirmButtonText={
						bulkConfirm === 'enable'
							? __( 'Enable all', 'albert-ai-butler' )
							: __( 'Disable all', 'albert-ai-butler' )
					}
				>
					{ bulkConfirm === 'enable'
						? __(
								'Enable every ability? This includes abilities that can create, change, or permanently delete data.',
								'albert-ai-butler'
						  )
						: __(
								'Disable every ability? None will be available through Albert’s MCP endpoint until you enable them again.',
								'albert-ai-butler'
						  ) }
				</ConfirmDialog>
			) }
		</div>
	);
}
