<?php
/** Protected package source contract. */

namespace WPBridge\UpdateSource\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ProtectedPackageHandlerInterface {
	public function can_handle_download( string $package, string $slug ): bool;
	/** @return string|\WP_Error */
	public function download_package( string $slug, array $integrity );
}
