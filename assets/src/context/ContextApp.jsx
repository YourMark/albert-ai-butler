/**
 * Albert → Context screen.
 *
 * The site owner tells connected assistants what this site is and how to behave.
 *
 * Token counts used to sit alongside every section and in a dedicated cost
 * card, priced by an estimator calibrated against one reference tokeniser.
 * Removed: different assistants tokenise differently, the estimate could only
 * ever be shown as a wide, provider-specific error band, and it did not change
 * what an owner should do, the section descriptions already say what each one
 * includes, well enough to decide on without a number attached. What is
 * actually sent is still shown in full, in the preview card below.
 *
 * Every write returns the full recomputed state, so the screen is always the
 * server's answer rather than the client's guess about what the server would
 * say.
 */
import { FormToggle } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchContext, saveContext } from './api';
import InstructionsCard from './InstructionsCard';
import PreviewCard from './PreviewCard';
import SectionsCard from './SectionsCard';

/**
 * Render the screen.
 *
 * @return {Element} The app.
 */
export default function ContextApp() {
	const [ state, setState ] = useState( null );
	const [ status, setStatus ] = useState( 'loading' );

	// What the last save tried to write, kept so "Try again" can replay it.
	const pending = useRef( null );

	// Saves are fired by typing and by toggles, so two can be in flight at once.
	// Only the newest may write state; an older response landing second would
	// otherwise overwrite the newer answer with a staler one.
	const latest = useRef( 0 );

	useEffect( () => {
		fetchContext()
			.then( ( payload ) => {
				setState( payload );
				setStatus( 'idle' );
			} )
			.catch( () => setStatus( 'load-error' ) );
	}, [] );

	/**
	 * Write a change and report whether it landed.
	 *
	 * Returns a boolean rather than the promise, because a caller that keeps its
	 * own copy of the value has to know. The instructions field learned this the
	 * hard way: it advanced its "last sent" marker when the request was fired
	 * rather than when it succeeded, and this function swallowed the rejection,
	 * so a failed save left the typing unsent while the screen said "Saved".
	 *
	 * @param {Object} changes Partial settings to write.
	 * @return {Promise<boolean>} Whether the write succeeded.
	 */
	const save = ( changes ) => {
		const id = ++latest.current;

		pending.current = changes;
		setStatus( 'saving' );

		return saveContext( changes )
			.then( ( payload ) => {
				if ( id !== latest.current ) {
					return true;
				}

				pending.current = null;
				setState( payload );
				setStatus( 'saved' );

				return true;
			} )
			.catch( () => {
				if ( id === latest.current ) {
					setStatus( 'error' );
				}

				return false;
			} );
	};

	// Replays the change that failed. Retrying with an empty body used to
	// "succeed" without writing anything, flipping the indicator to Saved for
	// work that was never stored.
	const retry = () => save( pending.current || {} );

	if ( status === 'loading' ) {
		return (
			<div className="albert-page albert-page--narrow">
				<p>{ __( 'Loading…', 'albert-ai-butler' ) }</p>
			</div>
		);
	}

	if ( status === 'load-error' || ! state ) {
		return (
			<div className="albert-page albert-page--narrow">
				<div className="notice notice-error">
					<p>
						{ __(
							'The Context settings could not be loaded. Reload the page to try again.',
							'albert-ai-butler'
						) }
					</p>
				</div>
			</div>
		);
	}

	const hasCommerce = state.sections.some(
		( section ) => section.key === 'commerce'
	);

	return (
		<div className="albert-page albert-page--narrow albert-context">
			{ /*
			 * A div, not a <header>. A <header> is only scoped out of the banner
			 * role by an article/aside/main/nav/section *element* ancestor, and
			 * every ancestor here is a div, so wp-admin's role="main" does not
			 * scope it. It would expose as a banner nested inside main.
			 */ }
			<div className="albert-page__header">
				<div className="albert-page__text">
					<h1 className="albert-page__title">
						{ __( 'Context', 'albert-ai-butler' ) }
					</h1>
					<p className="albert-page__description">
						{ __(
							'Tell connected assistants what this site is and how to behave, so they stop guessing. Everything you include here is sent with each conversation.',
							'albert-ai-butler'
						) }
					</p>
					{ /* Saving cannot reach a conversation already open, and the "Saved" beside it reads as if it did. */ }
					<p className="albert-page__description albert-context__reach-note">
						{ __(
							'Changes reach an assistant the next time it asks what this site can do. An open conversation keeps what it started with, so start a new one to use them.',
							'albert-ai-butler'
						) }
					</p>
				</div>
				<div className="albert-page__actions albert-context__master">
					{ /*
					 * Two regions, not one. A failed save is the only status here
					 * that should interrupt, and the retry button sits outside
					 * both: a control inside a live region gets re-announced with
					 * every status change.
					 */ }
					<div aria-live="polite" aria-atomic="true">
						{ status !== 'error' && (
							<SaveState status={ status } />
						) }
					</div>
					{ status === 'error' && (
						<>
							<div role="alert">
								<SaveState status={ status } />
							</div>
							<button
								type="button"
								className="albert-link-button"
								onClick={ retry }
							>
								{ __( 'Try again', 'albert-ai-butler' ) }
							</button>
						</>
					) }
					<span className="albert-context__master-label">
						{ __( 'Send context', 'albert-ai-butler' ) }
					</span>
					<FormToggle
						checked={ state.enabled }
						disabled={ state.managed.enabled }
						aria-label={ __(
							'Send context to connected assistants',
							'albert-ai-butler'
						) }
						onChange={ () => save( { enabled: ! state.enabled } ) }
					/>
				</div>
			</div>

			{ /*
			 * `.albert-context__off-notice`, the same class the notice below it
			 * uses, not `.albert-context__managed`. That class carries a
			 * horizontal margin to line up with a card's own header/body
			 * padding, correct where the other two managed hints sit (inside a
			 * card, InstructionsCard and SectionsCard), wrong here, where this
			 * hint is a page-level sibling of the notice below it and needs to
			 * align edge to edge with it instead.
			 */ }
			{ state.managed.enabled && (
				<p className="albert-hint albert-hint--info albert-context__off-notice">
					{ __(
						'Whether context is sent is set in code on this site, so it cannot be changed here.',
						'albert-ai-butler'
					) }
				</p>
			) }

			{ /*
			 * role="status" because flipping the master switch also empties the
			 * preview and stops every control below accepting input. Without it
			 * a screen reader hears "not checked", then finds the screen changed
			 * underneath with nothing said about why.
			 */ }
			{ ! state.enabled && (
				<div
					role="status"
					className="albert-hint albert-hint--info albert-context__off-notice"
				>
					{ __(
						'Context is switched off. Assistants still work, but they receive nothing about this site: no language, no brand, no content model.',
						'albert-ai-butler'
					) }
				</div>
			) }

			{ /*
			 * The controls below are individually disabled rather than the body
			 * being `inert`. `inert` removed the whole subtree from the
			 * accessibility tree, so a screen-reader user lost what a sighted
			 * user keeps: their own instructions and which sections are on.
			 * The state is signalled structurally instead, by the notice above
			 * and the sunken surfaces, and text stays at full contrast, an
			 * earlier draft dimmed the body to 1.99:1 and failed AA.
			 */ }
			<div
				className={ `albert-page__body${
					state.enabled ? '' : ' is-off'
				}` }
			>
				<InstructionsCard
					value={ state.instructions }
					managed={ state.managed.instructions }
					off={ ! state.enabled }
					onChange={ ( instructions ) => save( { instructions } ) }
				/>

				<SectionsCard
					sections={ state.sections }
					managed={ state.managed.sections }
					off={ ! state.enabled }
					hasCommerce={ hasCommerce }
					onToggle={ ( key, enabled ) =>
						save( { sections: { [ key ]: enabled } } )
					}
				/>

				<PreviewCard segments={ state.preview } />
			</div>
		</div>
	);
}

/**
 * The instant-save indicator that replaces a submit button.
 *
 * Renders nothing until something has actually been saved. There used to be no
 * branch for the idle state, so the fallback ran on first paint and the screen
 * claimed "Saved" before the owner had changed anything, which some screen
 * readers announced on load.
 *
 * The retry control is the caller's, not this component's, so it can sit outside
 * the live region that announces this text.
 *
 * @param {Object} props        Props.
 * @param {string} props.status Current save status.
 * @return {Element|null} The indicator, or null when nothing has been saved yet.
 */
function SaveState( { status } ) {
	if ( status === 'saving' ) {
		return (
			<span className="albert-savestate">
				<span className="albert-savestate__dot" />
				{ __( 'Saving…', 'albert-ai-butler' ) }
			</span>
		);
	}

	if ( status === 'error' ) {
		return (
			<span className="albert-savestate albert-savestate--error">
				<span className="albert-savestate__dot" />
				{ __( 'Not saved.', 'albert-ai-butler' ) }
			</span>
		);
	}

	if ( status !== 'saved' ) {
		return null;
	}

	return (
		<span className="albert-savestate albert-savestate--saved">
			<span className="albert-savestate__dot" />
			{ __( 'Saved', 'albert-ai-butler' ) }
		</span>
	);
}
