<?php
/**
 * Fails when an em dash appears in text a person or an assistant reads.
 *
 * Comments and docblocks are exempt: nobody outside the code reads them. Everything
 * else counts, because JSX text, an error_log() line and a schema description are all
 * read by somebody. Usage: `php bin/check-em-dashes.php [root]`, root defaulting to
 * the plugin.
 *
 * @package Albert
 */

$root = $argv[1] ?? dirname( __DIR__ );

$prose = [ 'readme.txt', 'README.md', 'changelog.txt' ];
$trees = [
	'albert-ai-butler.php' => [ 'php' ],
	'src'                  => [ 'php' ],
	'assets/src'           => [ 'js', 'jsx' ],
	'assets/js'            => [ 'js' ],
	'assets/css'           => [ 'css' ],
	'skills'               => [ 'md' ],
];

$files = array_values( array_filter( $prose, fn( $file ) => is_file( "$root/$file" ) ) );
foreach ( $trees as $path => $extensions ) {
	if ( is_file( "$root/$path" ) ) {
		$files[] = $path;
		continue;
	}
	if ( ! is_dir( "$root/$path" ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$path", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( in_array( $file->getExtension(), $extensions, true ) ) {
			$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
		}
	}
}
sort( $files );

$hits = [];
foreach ( $files as $file ) {
	$source = file_get_contents( "$root/$file" );
	if ( false === $source || ! str_contains( $source, '—' ) ) {
		continue;
	}
	$readable = match ( pathinfo( $file, PATHINFO_EXTENSION ) ) {
		'php'              => strip_php_comments( $source ),
		'js', 'jsx', 'css' => strip_js_comments( $source ),
		default            => $source,
	};
	foreach ( explode( "\n", $readable ) as $index => $line ) {
		if ( str_contains( $line, '—' ) ) {
			$hits[] = sprintf( '%s:%d: %s', $file, $index + 1, trim( $line ) );
		}
	}
}

if ( $hits ) {
	fwrite( STDERR, "Em dashes in text people or assistants read. Reword each by hand (colon, comma, full stop or parentheses):\n\n" );
	fwrite( STDERR, implode( "\n", $hits ) . "\n" );
	exit( 1 );
}

echo 'No em dashes in ' . count( $files ) . " files.\n";

/**
 * Blanks PHP comments, keeping newlines so line numbers still match the file.
 *
 * @param string $source PHP source.
 * @return string
 */
function strip_php_comments( string $source ): string {
	$out = '';
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
			$out .= preg_replace( '/[^\n]/', '', $token[1] );
			continue;
		}
		$out .= is_array( $token ) ? $token[1] : $token;
	}
	return $out;
}

/**
 * Blanks JS and CSS comments, keeping newlines. Tracks strings so a `//` inside a URL
 * is not mistaken for a comment. Regex literals are not recognised; none hold a quote.
 *
 * @param string $source JS, JSX or CSS source.
 * @return string
 */
function strip_js_comments( string $source ): string {
	$out    = '';
	$length = strlen( $source );
	$quote  = null;
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $source[ $i ];
		$next = $source[ $i + 1 ] ?? '';
		if ( null !== $quote ) {
			$out .= $char;
			if ( '\\' === $char ) {
				$out .= $next;
				++$i;
			} elseif ( $char === $quote ) {
				$quote = null;
			}
			continue;
		}
		if ( '/' === $char && '/' === $next ) {
			$end = strpos( $source, "\n", $i );
			$i   = ( false === $end ? $length : $end ) - 1;
			continue;
		}
		if ( '/' === $char && '*' === $next ) {
			$end  = strpos( $source, '*/', $i + 2 );
			$end  = false === $end ? $length : $end + 2;
			$out .= str_repeat( "\n", substr_count( $source, "\n", $i, $end - $i ) );
			$i    = $end - 1;
			continue;
		}
		if ( in_array( $char, [ '"', "'", '`' ], true ) ) {
			$quote = $char;
		}
		$out .= $char;
	}
	return $out;
}
