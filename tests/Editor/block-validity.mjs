/**
 * Editor validity check for Albert's block serializer.
 *
 * Serializes the specs in cases.php with Albert on a local site, then loads
 * the block editor in headless Chromium and requires wp.blocks.parse() to
 * report every block valid against the editor's own save().
 *
 * Usage: npm run test:editor
 * Env:   WP_CLI   WP-CLI command (default "wp"), e.g. "herd php ~/bin/wp-cli.phar"
 *        SITE_URL site to check (default: the site's home option)
 *
 * A throwaway administrator is created for the session and deleted at the end,
 * with the auto-draft the editor opens for it.
 */

import { execSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = dirname( fileURLToPath( import.meta.url ) );
const wpCli = process.env.WP_CLI || 'wp';

const wp = ( args ) =>
	execSync( `${ wpCli } ${ args }`, {
		cwd: here,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} ).trim();

const siteUrl = ( process.env.SITE_URL || wp( 'option get home' ) ).replace( /\/$/, '' );
const lastLine = ( output ) => output.split( '\n' ).filter( Boolean ).pop();

const cases = JSON.parse( lastLine( wp( `eval-file ${ join( here, 'cases.php' ) } --url=${ siteUrl }` ) ) );
const session = JSON.parse( lastLine( wp( `eval-file ${ join( here, 'session.php' ) } --url=${ siteUrl }` ) ) );

let failures = 0;
let browser;

try {
	browser = await chromium.launch();
	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const host = new URL( siteUrl ).hostname;

	await context.addCookies(
		session.cookies.map( ( cookie ) => ( {
			...cookie,
			domain: host,
			secure: session.secure,
			httpOnly: true,
		} ) )
	);

	const page = await context.newPage();
	await page.goto( `${ siteUrl }/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.wp?.blocks?.getBlockType?.( 'core/paragraph' ), null, { timeout: 60000 } );

	for ( const { label, markup, issues } of cases ) {
		const errors = issues.filter( ( issue ) => issue.severity === 'error' );

		const invalid = errors.length
			? errors.map( ( issue ) => ( { path: 'serializer', message: issue.message } ) )
			: await page.evaluate( ( content ) => {
					const found = [];
					const walk = ( blocks, path ) =>
						blocks.forEach( ( block, index ) => {
							const at = `${ path }[${ index }] ${ block.name }`;
							const [ current ] = window.wp.blocks.validateBlock( block );

							if ( ! block.isValid || ! current || block.name === 'core/missing' ) {
								found.push( {
									path: at,
									message: block.isValid ? 'valid only through a deprecated save()' : 'invalid',
									saved: block.originalContent,
									expected: window.wp.blocks.getSaveContent( block.name, block.attributes, block.innerBlocks ),
								} );
							}

							walk( block.innerBlocks, at + ' >' );
						} );

					walk( window.wp.blocks.parse( content ), '' );
					return found;
			  }, markup );

		if ( invalid.length ) {
			failures += invalid.length;
			console.log( `FAIL ${ label }` );
			for ( const item of invalid ) {
				console.log( `  ${ item.path }: ${ item.message }` );
				if ( item.saved !== undefined ) {
					console.log( `    saved:    ${ item.saved }` );
					console.log( `    expected: ${ item.expected }` );
				}
			}
		} else {
			console.log( `ok   ${ label }` );
		}
	}
} finally {
	await browser?.close();
	wp( `user delete ${ session.user } --yes --url=${ siteUrl }` );
}

console.log( failures ? `\n${ failures } invalid block(s).` : `\nAll ${ cases.length } cases valid.` );
process.exit( failures ? 1 : 0 );
