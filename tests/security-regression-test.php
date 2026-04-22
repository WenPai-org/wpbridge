<?php
/**
 * Security regression tests for WPBridge.
 *
 * Usage:
 *   php tests/security-regression-test.php
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'AUTH_KEY' ) ) {
    define( 'AUTH_KEY', 'wpbridge-test-auth-key' );
}

$GLOBALS['wpbridge_test_options'] = [];

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = '' ): string {
        unset( $domain );
        return $text;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $name, $default = false ) {
        return $GLOBALS['wpbridge_test_options'][ $name ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $name, $value, bool $autoload = true ): bool {
        unset( $autoload );
        $GLOBALS['wpbridge_test_options'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $name ): bool {
        unset( $GLOBALS['wpbridge_test_options'][ $name ] );
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        unset( $hook_name, $callback, $priority, $accepted_args );
        return true;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

require_once dirname( __DIR__ ) . '/includes/Security/Encryption.php';
require_once dirname( __DIR__ ) . '/includes/Core/BackupManager.php';

use WPBridge\Core\BackupManager;
use WPBridge\Security\Encryption;

$failures = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( $condition ) {
        echo "[PASS] {$message}\n";
        return;
    }

    echo "[FAIL] {$message}\n";
    $failures++;
};

$legacy_encrypt = static function ( string $plain ): string {
    $key       = hash( 'sha256', AUTH_KEY, true );
    $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
    $iv        = random_bytes( $iv_length );
    $encrypted = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

    return base64_encode( $iv . (string) $encrypted );
};

// Encryption regression checks.
$payload   = 'encryption-regression';
$cipher    = Encryption::encrypt( $payload );
$decrypted = Encryption::decrypt( $cipher );

$assert( '' !== $cipher, 'Encryption returns non-empty ciphertext' );
$assert( Encryption::is_encrypted( $cipher ), 'Generated ciphertext is recognized as encrypted' );
$assert( $decrypted === $payload, 'Generated ciphertext decrypts to original plaintext' );

$legacy_payload = 'legacy-cbc-compatible';
$legacy_cipher  = $legacy_encrypt( $legacy_payload );
$assert( Encryption::decrypt( $legacy_cipher ) === $legacy_payload, 'Legacy CBC ciphertext remains decryptable' );
$assert( Encryption::is_encrypted( $legacy_cipher ), 'Legacy CBC ciphertext is still recognized as encrypted' );

if ( strpos( $cipher, Encryption::PREFIX ) === 0 ) {
    $tampered = substr( $cipher, 0, -1 ) . ( substr( $cipher, -1 ) === 'A' ? 'B' : 'A' );
    $assert( Encryption::decrypt( $tampered ) === '', 'Tampered AEAD ciphertext fails decryption' );
}

$assert( Encryption::decrypt( 'not-encrypted-text' ) === '', 'Plaintext input does not decrypt as ciphertext' );
$assert( ! Encryption::is_encrypted( 'not-encrypted-text' ), 'Plaintext input is not marked encrypted' );

// Zip Slip regression checks.
if ( class_exists( 'ZipArchive' ) ) {
    $tmp_root = sys_get_temp_dir() . '/wpbridge-security-' . bin2hex( random_bytes( 4 ) );
    $target   = $tmp_root . '/target';
    mkdir( $target, 0777, true );

    $malicious_zip = $tmp_root . '/malicious.zip';
    $zip           = new ZipArchive();
    $zip->open( $malicious_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    $zip->addFromString( '../escape.php', '<?php echo "x";' );
    $zip->close();

    $backup_manager = BackupManager::get_instance();
    $reflection     = new ReflectionClass( BackupManager::class );
    $extract_method = $reflection->getMethod( 'extract_zip' );
    $extract_method->setAccessible( true );

    $result = $extract_method->invoke( $backup_manager, $malicious_zip, $target );
    $assert( $result instanceof WP_Error, 'Zip Slip archive is rejected with WP_Error' );

    if ( $result instanceof WP_Error ) {
        $assert( 'invalid_backup_archive' === $result->get_error_code(), 'Zip Slip rejection uses expected error code' );
    }

    $safe_zip = $tmp_root . '/safe.zip';
    $zip      = new ZipArchive();
    $zip->open( $safe_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    $zip->addFromString( 'plugin/readme.txt', 'ok' );
    $zip->close();

    $safe_result = $extract_method->invoke( $backup_manager, $safe_zip, $target );
    $assert( true === $safe_result, 'Safe backup archive extracts successfully' );
    $assert( file_exists( $target . '/plugin/readme.txt' ), 'Safe archive content extracted to target directory' );
} else {
    echo "[SKIP] ZipArchive extension unavailable, skipping Zip Slip checks.\n";
}

if ( $failures > 0 ) {
    echo "\nSecurity regression tests failed: {$failures}\n";
    exit( 1 );
}

echo "\nSecurity regression tests passed.\n";
