<?php
/**
 * Reading private Google Sheets through a service account.
 *
 * A service account is a Google identity that belongs to the site. The sheet
 * is shared with its email address like with any colleague, so it never has
 * to be readable by anyone with the link. The site proves who it is with a
 * signed token (RS256, signed here with PHP's OpenSSL, no library) and reads
 * the sheet through the Sheets API.
 *
 * The key comes from the SHEET_TABLES_GOOGLE_CREDENTIALS constant when it is
 * defined, otherwise from Settings > Sheet Tables. Only the short-lived access
 * token is ever cached; the key itself is never printed or cached.
 *
 * @package SheetTables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fixed by Google: the Sheets API and the read-only permission requested.
// The token endpoint is not here because it comes from the key itself.
define( 'SHEET_TABLES_SHEETS_API', 'https://sheets.googleapis.com/v4/spreadsheets/' );
define( 'SHEET_TABLES_SHEETS_SCOPE', 'https://www.googleapis.com/auth/spreadsheets.readonly' );

/**
 * Option holding a key pasted on the settings page.
 */
define( 'SHEET_TABLES_CREDENTIALS_OPTION', 'sheet_tables_google_credentials' );

/**
 * Reduce a service account key to the three fields used, or explain why not.
 *
 * @param mixed $key Decoded JSON key, or a JSON string.
 * @return array{client_email: string, private_key: string, token_uri: string}|WP_Error
 */
function sheet_tables_google_parse_key( $key ) {
	if ( is_string( $key ) ) {
		$key = json_decode( $key, true );
	}

	if ( ! is_array( $key ) || 'service_account' !== ( $key['type'] ?? '' ) ) {
		return new WP_Error( 'sheet_tables_key_type', __( 'This is not a Google service account key. Download the key as JSON from the service account\'s Keys tab in Google Cloud.', 'sheet-tables' ) );
	}

	$parsed = array(
		'client_email' => sanitize_email( (string) ( $key['client_email'] ?? '' ) ),
		'private_key'  => (string) ( $key['private_key'] ?? '' ),
		'token_uri'    => esc_url_raw( (string) ( $key['token_uri'] ?? '' ), array( 'https' ) ),
	);

	if ( '' === $parsed['client_email'] || '' === $parsed['token_uri'] || ! openssl_pkey_get_private( $parsed['private_key'] ) ) {
		return new WP_Error( 'sheet_tables_key_incomplete', __( 'The service account key is incomplete or its private key cannot be read.', 'sheet-tables' ) );
	}

	return $parsed;
}

/**
 * Whether the key comes from wp-config.php.
 *
 * @return bool
 */
function sheet_tables_google_key_in_config() {
	return defined( 'SHEET_TABLES_GOOGLE_CREDENTIALS' );
}

/**
 * The site's service account key.
 *
 * @return array{client_email: string, private_key: string, token_uri: string}|WP_Error
 */
function sheet_tables_google_credentials() {
	if ( sheet_tables_google_key_in_config() ) {
		return sheet_tables_google_parse_key( SHEET_TABLES_GOOGLE_CREDENTIALS );
	}

	$stored = get_option( SHEET_TABLES_CREDENTIALS_OPTION );

	if ( ! is_array( $stored ) ) {
		return new WP_Error( 'sheet_tables_no_key', __( 'No Google service account is set up. An administrator can add one under Settings > Sheet Tables.', 'sheet-tables' ) );
	}

	// Stored already reduced, so this only re-checks it.
	return sheet_tables_google_parse_key( array( 'type' => 'service_account' ) + $stored );
}

/**
 * The email address sheets must be shared with, or an empty string.
 *
 * @return string
 */
function sheet_tables_google_email() {
	$credentials = sheet_tables_google_credentials();

	return is_wp_error( $credentials ) ? '' : $credentials['client_email'];
}

/**
 * Transient holding the access token for one service account.
 *
 * Keyed by the account so a replaced key never reuses the old one's token.
 *
 * @param string $email Service account email.
 * @return string
 */
function sheet_tables_google_token_key( $email ) {
	return 'sheet_tables_google_token_' . md5( $email );
}

/**
 * Base64url, as JSON Web Tokens use it.
 *
 * @param string $data Raw bytes.
 * @return string
 */
function sheet_tables_base64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation.
}

/**
 * An access token for the Sheets API, cached until shortly before it expires.
 *
 * @return string|WP_Error
 */
function sheet_tables_google_token() {
	$credentials = sheet_tables_google_credentials();

	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$cache_key = sheet_tables_google_token_key( $credentials['client_email'] );
	$cached    = get_transient( $cache_key );

	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$now   = time();
	$input = sheet_tables_base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' . sheet_tables_base64url(
		wp_json_encode(
			array(
				'iss'   => $credentials['client_email'],
				'scope' => SHEET_TABLES_SHEETS_SCOPE,
				'aud'   => $credentials['token_uri'],
				'iat'   => $now,
				'exp'   => $now + HOUR_IN_SECONDS,
			)
		)
	);

	if ( ! openssl_sign( $input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'sheet_tables_sign', __( 'The service account key could not sign a request.', 'sheet-tables' ) );
	}

	$response = wp_safe_remote_post(
		$credentials['token_uri'],
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $input . '.' . sheet_tables_base64url( $signature ),
			),
		)
	);

	$body = sheet_tables_google_json( $response );

	if ( is_wp_error( $body ) ) {
		return $body;
	}

	if ( empty( $body['access_token'] ) ) {
		return new WP_Error( 'sheet_tables_token', __( 'Google did not return an access token for the service account.', 'sheet-tables' ) );
	}

	// Renewed a minute early so a token never expires mid-request.
	$lifetime = max( MINUTE_IN_SECONDS, (int) ( $body['expires_in'] ?? HOUR_IN_SECONDS ) - MINUTE_IN_SECONDS );
	set_transient( $cache_key, (string) $body['access_token'], $lifetime );

	return (string) $body['access_token'];
}

/**
 * Decode a Google API response, turning failures into readable errors.
 *
 * @param array|WP_Error $response From wp_safe_remote_*().
 * @return array|WP_Error
 */
function sheet_tables_google_json( $response ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 === $code && is_array( $body ) ) {
		return $body;
	}

	// A 403 also means the Sheets API was never enabled in the account's
	// project, which sharing the sheet would not fix.
	$reasons = wp_list_pluck( (array) ( $body['error']['details'] ?? array() ), 'reason' );

	if ( 403 === $code && in_array( 'SERVICE_DISABLED', $reasons, true ) ) {
		return new WP_Error( 'sheet_tables_api_disabled', __( 'The Google Sheets API is not enabled for the service account\'s Google Cloud project. Enable it under APIs & Services, then try again in a few minutes.', 'sheet-tables' ) );
	}

	if ( 403 === $code ) {
		return new WP_Error(
			'sheet_tables_forbidden',
			/* translators: %s: service account email address. */
			sprintf( __( 'The service account cannot open this sheet. Share the sheet with %s as a Viewer.', 'sheet-tables' ), sheet_tables_google_email() )
		);
	}

	if ( 404 === $code ) {
		return new WP_Error( 'sheet_tables_not_found', __( 'Google could not find this sheet or tab. Check the link.', 'sheet-tables' ) );
	}

	$detail = '';

	if ( is_array( $body ) ) {
		$detail = (string) ( $body['error']['message'] ?? $body['error_description'] ?? '' );
	}

	return new WP_Error(
		'sheet_tables_google_http',
		/* translators: 1: HTTP status code, 2: Google's explanation, possibly empty. */
		trim( sprintf( __( 'Google answered with HTTP status %1$d. %2$s', 'sheet-tables' ), $code, $detail ) )
	);
}

/**
 * Read one tab of a private sheet as rows of strings.
 *
 * @param string $url The sheet link an editor pasted.
 * @return string[][]|WP_Error
 */
function sheet_tables_google_read( $url ) {
	$ref = sheet_tables_google_ref( $url );

	if ( ! $ref ) {
		return new WP_Error( 'sheet_tables_not_google', __( 'Reading through a service account needs a Google Sheets link.', 'sheet-tables' ) );
	}

	$token = sheet_tables_google_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$args = array(
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $token ),
	);
	$base = SHEET_TABLES_SHEETS_API . rawurlencode( $ref['id'] );

	// The link names its tab by number (gid); the API wants the tab's title.
	$meta = sheet_tables_google_json( wp_safe_remote_get( $base . '?fields=' . rawurlencode( 'sheets.properties(sheetId,title)' ), $args ) );

	if ( is_wp_error( $meta ) ) {
		return $meta;
	}

	$title = null;

	foreach ( (array) ( $meta['sheets'] ?? array() ) as $sheet ) {
		$properties = $sheet['properties'] ?? array();

		if ( null === $ref['gid'] || (string) ( $properties['sheetId'] ?? '' ) === $ref['gid'] ) {
			$title = (string) ( $properties['title'] ?? '' );
			break;
		}
	}

	if ( null === $title ) {
		return new WP_Error( 'sheet_tables_no_tab', __( 'The tab in this link no longer exists in the sheet.', 'sheet-tables' ) );
	}

	// A quoted title is a range covering the whole tab. Formatted values are
	// what the sheet shows, the same as its CSV export.
	$range  = "'" . str_replace( "'", "''", $title ) . "'";
	$values = sheet_tables_google_json( wp_safe_remote_get( $base . '/values/' . rawurlencode( $range ), $args ) );

	if ( is_wp_error( $values ) ) {
		return $values;
	}

	return array_map(
		function ( $row ) {
			return array_map( 'trim', array_map( 'strval', (array) $row ) );
		},
		(array) ( $values['values'] ?? array() )
	);
}

add_action( 'admin_menu', 'sheet_tables_add_settings_page' );
add_action( 'admin_post_sheet_tables_credentials', 'sheet_tables_save_credentials' );

/**
 * Add Settings > Sheet Tables.
 */
function sheet_tables_add_settings_page() {
	add_options_page(
		__( 'Sheet Tables', 'sheet-tables' ),
		__( 'Sheet Tables', 'sheet-tables' ),
		'manage_options',
		'sheet-tables-settings',
		'sheet_tables_render_settings_page'
	);
}

/**
 * Print the settings page. The key is never printed back.
 */
function sheet_tables_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$credentials = sheet_tables_google_credentials();
	$in_config   = sheet_tables_google_key_in_config();
	$stored      = is_array( get_option( SHEET_TABLES_CREDENTIALS_OPTION ) );

	// Display only: these read flags this plugin set on its own redirect.
	$saved = ! empty( $_GET['sheet_tables_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error = isset( $_GET['sheet_tables_error'] ) ? sanitize_key( wp_unslash( $_GET['sheet_tables_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Sheet Tables', 'sheet-tables' ); ?></h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sheet-tables' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'invalid' === $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The key was not saved: it is not a complete Google service account key in JSON format. The previous key, if any, is unchanged.', 'sheet-tables' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Google service account', 'sheet-tables' ); ?></h2>
		<p><?php esc_html_e( 'Needed only for tables set to read a private sheet. Share each such sheet with the service account\'s email address as a Viewer. Tables that read a sheet shared by link do not use it.', 'sheet-tables' ); ?></p>

		<?php if ( ! is_wp_error( $credentials ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: service account email address. */
					esc_html__( 'Configured as %s', 'sheet-tables' ),
					'<code>' . esc_html( $credentials['client_email'] ) . '</code>'
				);
				?>
			</p>
		<?php elseif ( $in_config ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $credentials->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<?php if ( $in_config ) : ?>
			<p><?php esc_html_e( 'The key is set by the SHEET_TABLES_GOOGLE_CREDENTIALS constant in wp-config.php, which takes precedence. Change it there.', 'sheet-tables' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sheet_tables_credentials">
				<?php wp_nonce_field( 'sheet_tables_credentials', 'sheet_tables_credentials_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sheet-tables-key"><?php esc_html_e( 'Key (JSON)', 'sheet-tables' ); ?></label></th>
						<td>
							<textarea id="sheet-tables-key" name="sheet_tables_key" class="large-text code" rows="8" autocomplete="off" spellcheck="false"></textarea>
							<p class="description">
								<?php
								echo $stored
									? esc_html__( 'Paste a new key to replace the current one. The current key is never shown.', 'sheet-tables' )
									: esc_html__( 'Paste the whole JSON key file downloaded from Google Cloud.', 'sheet-tables' );
								?>
								<?php esc_html_e( 'A key pasted here is stored in the database. Defining SHEET_TABLES_GOOGLE_CREDENTIALS in wp-config.php instead keeps it out of the database and its backups.', 'sheet-tables' ); ?>
							</p>
							<?php if ( $stored ) : ?>
								<p><label><input type="checkbox" name="sheet_tables_remove_key" value="1"> <?php esc_html_e( 'Remove the stored key', 'sheet-tables' ); ?></label></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Save or remove the pasted key.
 */
function sheet_tables_save_credentials() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change these settings.', 'sheet-tables' ), 403 );
	}

	check_admin_referer( 'sheet_tables_credentials', 'sheet_tables_credentials_nonce' );

	$redirect = admin_url( 'options-general.php?page=sheet-tables-settings' );
	$previous = sheet_tables_google_email();

	if ( ! empty( $_POST['sheet_tables_remove_key'] ) ) {
		delete_option( SHEET_TABLES_CREDENTIALS_OPTION );
	} else {
		// Not sanitized as text: the private key's line breaks must survive.
		// It is validated as a key and reduced to three fields instead.
		$raw = isset( $_POST['sheet_tables_key'] ) && is_string( $_POST['sheet_tables_key'] ) ? trim( wp_unslash( $_POST['sheet_tables_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' !== $raw ) {
			$key = sheet_tables_google_parse_key( $raw );

			if ( is_wp_error( $key ) ) {
				wp_safe_redirect( add_query_arg( 'sheet_tables_error', 'invalid', $redirect ) );
				exit;
			}

			update_option( SHEET_TABLES_CREDENTIALS_OPTION, $key, false );
		}
	}

	if ( '' !== $previous ) {
		delete_transient( sheet_tables_google_token_key( $previous ) );
	}

	wp_safe_redirect( add_query_arg( 'sheet_tables_saved', 1, $redirect ) );
	exit;
}

add_filter(
	'removable_query_args',
	function ( $args ) {
		$args[] = 'sheet_tables_saved';
		$args[] = 'sheet_tables_error';
		return $args;
	}
);
