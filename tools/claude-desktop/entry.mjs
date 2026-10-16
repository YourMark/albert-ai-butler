// Albert's Claude Desktop bridge: mcp-remote with this site's MCP URL.
//
// Claude Desktop runs it on its own built-in Node, so nothing is installed.
// The site address comes from the install file's manifest; there is no
// secret in it. The person approves the connection once, in their browser.
import { fileURLToPath } from 'node:url';

const url = process.env.ALBERT_MCP_URL;

if ( ! url ) {
	process.stderr.write( 'Albert: no site address configured.\n' );
	process.exit( 1 );
}

const args = [ url, '--header', `User-Agent:${ process.env.ALBERT_USER_AGENT || 'Albert-ClaudeDesktop' }` ];

process.argv = [ process.argv[ 0 ], fileURLToPath( import.meta.url ), ...args ];

await import( 'mcp-remote/dist/proxy.js' );
