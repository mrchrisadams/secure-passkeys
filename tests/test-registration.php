<?php
/**
 * Tests for the passkey registration AJAX handler.
 *
 * These drive the real AJAX action, so the handler's validation, WebAuthn
 * verification and database writes all run as they do in production.
 */
require_once __DIR__ . '/webauthn-fixture.php';

class Test_Registration extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private $user_id;

	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// Web_Authn::_checkOrigin() only permits plain HTTP for localhost, so the
		// site has to look like localhost for a test registration to verify.
		update_option( 'siteurl', 'http://localhost' );
		update_option( 'home', 'http://localhost' );

		// The handler rejects non-AJAX requests. wp_doing_ajax() is filterable,
		// so no DOING_AJAX constant is needed.
		add_filter( 'wp_doing_ajax', '__return_true' );

		// The plugin registers its AJAX handlers by globbing src/ajax and deriving
		// class names from the file paths, which assumes a normal install layout.
		// Fall back to instantiating the handler directly if that did not happen,
		// so the test also runs from unusual checkout locations.
		if ( ! has_action( 'wp_ajax_secure_passkeys_frontend_get_register_options' ) ) {
			new \Secure_Passkeys\Ajax\Secure_Passkeys_Frontend_Ajax();
		}

		$_SERVER['REQUEST_METHOD']       = 'POST';
		$_SERVER['HTTP_REFERER']         = admin_url();
		$_SERVER['HTTP_USER_AGENT']      = 'secure-passkeys-tests';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
	}

	public function tear_down() {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_it_stores_a_credential_on_success() {
		global $wpdb;

		$table  = $wpdb->prefix . 'secure_passkeys_webauthns';
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$response = $this->register_passkey( 'Success' );

		$this->assertTrue( $response['success'], 'A valid registration should succeed. Got: ' . wp_json_encode( $response ) );
		$this->assertSame(
			$before + 1,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'A credential should have been stored.'
		);
	}

	/**
	 * A failed insert must be reported as an error.
	 *
	 * $wpdb->insert() returns false on failure rather than throwing, so the
	 * handler has to check the result. When it did not, the browser was told
	 * the passkey was added while nothing was stored.
	 *
	 * The failure is triggered with a value too long for the column: that is a
	 * DML error, so the test transaction rolls it back. Mutating the schema
	 * instead would not roll back, because MySQL does not transact DDL.
	 */
	public function test_a_failed_insert_is_reported_as_an_error() {
		global $wpdb;

		$table = $wpdb->prefix . 'secure_passkeys_webauthns';

		add_filter( 'secure_passkeys_web_authn_enable_data', array( $this, 'break_the_insert' ) );

		$response = $this->register_passkey( 'Broken' );

		remove_filter( 'secure_passkeys_web_authn_enable_data', array( $this, 'break_the_insert' ) );

		$this->assertArrayHasKey( 'success', $response );
		$this->assertFalse(
			$response['success'],
			'A failed insert must not be reported as success. Response was: ' . wp_json_encode( $response )
		);
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE security_key_name = 'Broken'" ),
			'No credential should have been stored.'
		);
	}

	/**
	 * Make the credential insert fail, without touching the schema.
	 *
	 * @param array $data Credential data about to be inserted.
	 * @return array
	 */
	public function break_the_insert( $data ) {
		// security_key_name is varchar(255); an over-length value is a DML error,
		// which the test transaction can roll back.
		$data['credential_id'] = str_repeat( 'x', 3000 );
		return $data;
	}

	/**
	 * Run one full registration through the AJAX action.
	 *
	 * @param string $name Security key name.
	 * @return array Decoded JSON response.
	 */
	private function register_passkey( string $name ): array {
		$options = $this->invoke_ajax( 'secure_passkeys_frontend_get_register_options', array(
			'nonce' => wp_create_nonce( SECURE_PASSKEYS_NONCE ),
		) );

		$this->assertTrue( $options['success'], 'Should have received register options.' );

		$challenge = $options['data']['challenge'];
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$payload = sp_make_none_attestation(
			$challenge,
			untrailingslashit( home_url() ),
			$host,
			'cred-' . $name . '-' . wp_generate_password( 12, false )
		);

		return $this->invoke_ajax( 'secure_passkeys_frontend_register_passkey', array(
			'nonce'             => wp_create_nonce( SECURE_PASSKEYS_NONCE ),
			'challenge'         => $challenge,
			'security_key_name' => 'Passkey-' . $name,
			'rawId'             => $payload['rawId'],
			'response'          => $payload['response'],
		) );
	}

	/**
	 * Fire an AJAX action and capture its JSON response.
	 *
	 * wp_send_json_*() echo JSON then call wp_die(), so wp_die() is swapped for
	 * an exception to return the response as data instead of ending the process.
	 *
	 * @param string $action Action name, without the wp_ajax_ prefix.
	 * @param array  $post   POST fields.
	 * @return array
	 */
	private function invoke_ajax( string $action, array $post ): array {
		$_POST = $_REQUEST = $post;

		$handler = static function () {
			return static function () {
				throw new RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $handler );

		ob_start();
		try {
			do_action( "wp_ajax_{$action}" );
		} catch ( RuntimeException $e ) {
			$body = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $handler );

			$decoded = json_decode( $body, true );
			$this->assertIsArray( $decoded, "Handler {$action} did not return JSON: {$body}" );
			return $decoded;
		}

		ob_end_clean();
		remove_filter( 'wp_die_ajax_handler', $handler );
		$this->fail( "Handler {$action} returned no response." );
	}
}
