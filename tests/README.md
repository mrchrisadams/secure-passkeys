# Tests

PHPUnit tests for the plugin, using the standard WordPress test library
(`wp-phpunit/wp-phpunit` + `WP_UnitTestCase`). They run against a real WordPress
install and a real database, so AJAX handlers, `$wpdb` queries and the WebAuthn
verification all behave as they do in production. Nothing is mocked.

## Running

```sh
cp tests/wp-tests-config.sample.php tests/wp-tests-config.php
# edit the DB_* constants to point at a throwaway database
WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php composer test
```

`composer install` fetches WordPress itself (`roots/wordpress-full`), the test
library and PHPUnit, so no svn checkout or manual download is needed. The
database is wiped on every run; never point the config at a real site.

To run a single test:

```sh
WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php vendor/bin/phpunit --filter test_a_failed_insert_is_reported_as_an_error
```

CI runs the same command across PHP 8.3, 8.2 and 8.1 against MariaDB; see
`.github/workflows/testing.yml`.

## What is covered

`tests/test-registration.php` drives the real `register_passkey` AJAX action:

* `test_it_stores_a_credential_on_success` — a valid registration stores a
  credential.
* `test_a_failed_insert_is_reported_as_an_error` — when the insert fails, the
  handler must not report success.

The second test is the regression test for a bug in 1.3.0. `register_passkey()`
wrapped the credential insert in `try/catch (Exception)`, but `$wpdb->insert()`
returns `false` on failure instead of throwing, and the return value was
discarded. So a failed insert produced `{"success":true}`, a `register` log row
with `webauthn_id = 0`, no stored credential, and a login failure the user could
not explain.

The test fails on 1.3.0 with the actual response in the message, and passes once
the handler checks the insert result.

## How a registration is verified without a browser

WebAuthn normally needs a browser and an authenticator, because the authenticator
holds a private key and signs the challenge. That is hard to automate. Two
properties of this plugin make it unnecessary here:

1. **`origin` is not required to be HTTPS for `localhost`.**
   `Web_Authn::_checkOrigin()` skips the scheme check when the RP ID is
   `localhost`, so the test sets the site URL to `http://localhost`.
2. **The `none` attestation format verifies no signature.**
   `Attestation\Format\None::validateAttestation()` returns `true`
   unconditionally, and the plugin never calls `addRootCertificates()`, so no
   trust anchor is checked either.

`tests/webauthn-fixture.php` therefore builds the payload bytes directly:
`clientDataJSON`, `authenticatorData` with a valid RP ID hash and UP/UV/AT flags,
a COSE EC2 key, and a CBOR `attestationObject`. The bytes are still passed to
`Web_Authn::processCreate()`, the same verifier a real registration goes through,
so a wrong challenge, origin or RP ID hash fails the test.

Only the `none` format is covered. The signature-verifying formats (`packed`,
`fido-u2f`, `apple`, `tpm`, `android-*`) and the plugin's JavaScript still need a
real browser and authenticator.

## Notes for future tests

`WP_UnitTestCase` wraps each test in a transaction and rolls it back, so database
writes do not leak between tests. **That does not cover schema changes:** MySQL
does not transact DDL, so a test that runs `ALTER TABLE` will permanently modify
the test database. Prefer triggering a DML error (as the failure test does with
an over-length value) or use a temporary table.

`wp_doing_ajax()` is filterable, so a test can simulate an AJAX request with
`add_filter( 'wp_doing_ajax', '__return_true' )` rather than defining the
`DOING_AJAX` constant.
