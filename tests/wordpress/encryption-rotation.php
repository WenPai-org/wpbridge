<?php
/** WP-CLI eval-file test for encryption key rotation and fail-closed decryption. */

use WPBridge\Security\Encryption;

$old_key = 'round2-previous-key-material';
$plain   = 'rotation-secret';
$iv      = random_bytes( 12 );
$tag     = '';
$key     = hash_hkdf( 'sha256', $old_key, 32, 'wpbridge-encrypt' );
$cipher  = openssl_encrypt( $plain, Encryption::METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag, '', Encryption::TAG_LENGTH );
$value   = Encryption::PREFIX . base64_encode( $iv . $tag . $cipher );

$failures = 0;
if ( $plain !== Encryption::decrypt( $value ) ) {
	echo "FAIL: previous key cannot decrypt\n";
	++$failures;
} else {
	echo "PASS: previous key decrypts rotated data\n";
}

$event = '';
add_action(
	'wpbridge_decryption_failed',
	static function ( string $format ) use ( &$event ): void {
		$event = $format;
	}
);
if ( '' !== Encryption::decrypt( Encryption::PREFIX . base64_encode( str_repeat( 'x', 40 ) ) ) || 'gcm' !== $event ) {
	echo "FAIL: undecryptable value did not fail closed\n";
	++$failures;
} else {
	echo "PASS: undecryptable value fails closed and emits event\n";
}

$roundtrip = Encryption::encrypt( 'current-secret' );
if ( 'current-secret' !== Encryption::decrypt( $roundtrip ) ) {
	echo "FAIL: current key round trip\n";
	++$failures;
} else {
	echo "PASS: current key round trip\n";
}

exit( $failures > 0 ? 1 : 0 );
