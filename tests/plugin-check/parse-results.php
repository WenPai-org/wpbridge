<?php
/**
 * Parse Plugin Check's FILE + JSON output and make errors blocking.
 */

declare(strict_types=1);

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php parse-results.php <raw-output> <summary-json> <profile>\n" );
	exit( 64 );
}

$raw_path     = $argv[1];
$summary_path = $argv[2];
$profile      = $argv[3];
$raw          = is_file( $raw_path ) ? file_get_contents( $raw_path ) : false;

if ( false === $raw ) {
	fwrite( STDERR, "Plugin Check output is missing: {$raw_path}\n" );
	exit( 66 );
}

$findings = [];
$file     = '';
foreach ( preg_split( '/\R/', $raw ) as $line ) {
	if ( 0 === strpos( $line, 'FILE: ' ) ) {
		$file = trim( substr( $line, 6 ) );
		continue;
	}
	if ( '' === $file || '' === trim( $line ) || '[' !== substr( ltrim( $line ), 0, 1 ) ) {
		continue;
	}
	$decoded = json_decode( $line, true );
	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, "Invalid Plugin Check JSON block for {$file}\n" );
		exit( 65 );
	}
	foreach ( $decoded as $finding ) {
		if ( is_array( $finding ) ) {
			$finding['file'] = $file;
			$findings[]       = $finding;
		}
	}
	$file = '';
}

$by_type = [];
$by_code = [];
foreach ( $findings as $finding ) {
	$type = (string) ( $finding['type'] ?? 'UNKNOWN' );
	$code = (string) ( $finding['code'] ?? 'unknown' );
	$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
	$by_code[ $code ] = ( $by_code[ $code ] ?? 0 ) + 1;
}
ksort( $by_type );
ksort( $by_code );

$summary = [
	'profile'  => $profile,
	'total'    => count( $findings ),
	'by_type'  => $by_type,
	'by_code'  => $by_code,
	'findings' => $findings,
];
file_put_contents(
	$summary_path,
	json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
);

printf(
	"Plugin Check profile=%s total=%d errors=%d warnings=%d summary=%s\n",
	$profile,
	count( $findings ),
	(int) ( $by_type['ERROR'] ?? 0 ),
	(int) ( $by_type['WARNING'] ?? 0 ),
	$summary_path
);

exit( empty( $by_type['ERROR'] ) ? 0 : 1 );
