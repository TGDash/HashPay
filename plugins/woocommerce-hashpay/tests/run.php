<?php
/**
 * Standalone cryptographic protocol regression tests.
 *
 * Run: php tests/run.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-wc-hashpay-crypto.php';

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function mgf1( string $seed, int $length ): string {
	$mask = '';
	for ( $counter = 0; strlen( $mask ) < $length; ++$counter ) {
		$mask .= hash( 'sha256', $seed . pack( 'N', $counter ), true );
	}
	return substr( $mask, 0, $length );
}

function oaep_sha256_encode( string $message, int $length ): string {
	$hash_length = 32;
	$padding_length = $length - strlen( $message ) - ( 2 * $hash_length ) - 2;
	if ( $padding_length < 0 ) {
		throw new RuntimeException( 'Test OAEP message is too long' );
	}
	$data_block  = hash( 'sha256', '', true ) . str_repeat( "\x00", $padding_length ) . "\x01" . $message;
	$seed        = random_bytes( $hash_length );
	$masked_db   = $data_block ^ mgf1( $seed, $length - $hash_length - 1 );
	$masked_seed = $seed ^ mgf1( $masked_db, $hash_length );
	return "\x00" . $masked_seed . $masked_db;
}

$key_pair = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
assert_true( false !== $key_pair, 'Unable to generate test RSA key' );
assert_true( openssl_pkey_export( $key_pair, $private_key ), 'Unable to export test RSA key' );
$details = openssl_pkey_get_details( $key_pair );
assert_true( is_array( $details ) && isset( $details['key'], $details['bits'] ), 'Unable to read test RSA key details' );

$message   = "POST\n/api/merchant/new\n1700000000\n{\"merchantNo\":\"wc-1-test\"}";
$signature = base64_decode( WC_HashPay_Crypto::sign( $private_key, $message ), true );
assert_true( is_string( $signature ), 'Signature is not valid Base64' );
assert_true( 1 === openssl_verify( $message, $signature, $details['key'], OPENSSL_ALGO_SHA256 ), 'RSA-SHA256 signature verification failed' );

$plaintext = json_encode(
	array(
		'timestamp' => time(),
		'payload'   => array( 'orderId' => 'test-order', 'status' => 'paid' ),
	),
	JSON_UNESCAPED_SLASHES
);
$aes_key = random_bytes( 32 );
$iv      = random_bytes( 12 );
$tag     = '';
$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag, '' );
assert_true( is_string( $ciphertext ) && 16 === strlen( $tag ), 'Unable to encrypt AES-GCM test payload' );

$encoded_key = oaep_sha256_encode( $aes_key, intdiv( (int) $details['bits'] + 7, 8 ) );
assert_true( openssl_public_encrypt( $encoded_key, $encrypted_key, $details['key'], OPENSSL_NO_PADDING ), 'Unable to encrypt OAEP test key' );
$envelope = array(
	'alg'  => WC_HashPay_Crypto::ENVELOPE_ALGORITHM,
	'key'  => base64_encode( $encrypted_key ),
	'iv'   => base64_encode( $iv ),
	'data' => base64_encode( $ciphertext . $tag ),
);
$decoded = WC_HashPay_Crypto::decrypt_envelope( $private_key, $envelope );
assert_true( $decoded['payload']['orderId'] === 'test-order', 'Callback envelope decryption failed' );

$tampered = $envelope;
$tampered_data = base64_decode( $tampered['data'], true );
$tampered_data[0] = $tampered_data[0] ^ "\x01";
$tampered['data'] = base64_encode( $tampered_data );
$rejected = false;
try {
	WC_HashPay_Crypto::decrypt_envelope( $private_key, $tampered );
} catch ( RuntimeException $error ) {
	$rejected = true;
}
assert_true( $rejected, 'Tampered AES-GCM callback was accepted' );

echo "HashPay WooCommerce crypto tests passed\n";
