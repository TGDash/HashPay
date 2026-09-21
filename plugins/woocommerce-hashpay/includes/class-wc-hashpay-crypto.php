<?php
/**
 * HashPay cryptographic protocol helpers.
 */

defined( 'ABSPATH' ) || exit;

final class WC_HashPay_Crypto {
	public const ENVELOPE_ALGORITHM = 'RSA-OAEP-256+A256GCM';

	public static function sign( string $private_key_pem, string $message ): string {
		$key       = self::private_key( $private_key_pem );
		$signature = '';

		if ( ! openssl_sign( $message, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'HashPay RSA-SHA256 signing failed: ' . self::openssl_error() );
		}

		return base64_encode( $signature );
	}

	public static function decrypt_envelope( string $private_key_pem, array $envelope ): array {
		foreach ( array( 'alg', 'key', 'iv', 'data' ) as $field ) {
			if ( empty( $envelope[ $field ] ) || ! is_string( $envelope[ $field ] ) ) {
				throw new RuntimeException( 'Invalid HashPay callback envelope field: ' . $field );
			}
		}

		if ( ! hash_equals( self::ENVELOPE_ALGORITHM, $envelope['alg'] ) ) {
			throw new RuntimeException( 'Unsupported HashPay callback encryption algorithm' );
		}

		$encrypted_key  = self::decode_base64( $envelope['key'], 'key' );
		$iv             = self::decode_base64( $envelope['iv'], 'iv' );
		$encrypted_data = self::decode_base64( $envelope['data'], 'data' );

		if ( 12 !== strlen( $iv ) || strlen( $encrypted_data ) < 17 ) {
			throw new RuntimeException( 'Invalid HashPay AES-GCM payload' );
		}

		$aes_key = self::rsa_oaep_sha256_decrypt( $private_key_pem, $encrypted_key );
		if ( 32 !== strlen( $aes_key ) ) {
			throw new RuntimeException( 'Invalid HashPay AES-256 content key' );
		}

		$tag        = substr( $encrypted_data, -16 );
		$ciphertext = substr( $encrypted_data, 0, -16 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag, '' );
		if ( false === $plaintext ) {
			throw new RuntimeException( 'HashPay AES-256-GCM authentication failed' );
		}

		$decoded = json_decode( $plaintext, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Decrypted HashPay callback is not valid JSON' );
		}

		return $decoded;
	}

	private static function rsa_oaep_sha256_decrypt( string $pem, string $ciphertext ): string {
		$key     = self::private_key( $pem );
		$details = openssl_pkey_get_details( $key );
		$length  = isset( $details['bits'] ) ? intdiv( (int) $details['bits'] + 7, 8 ) : 0;

		if ( $length < 66 || strlen( $ciphertext ) !== $length ) {
			throw new RuntimeException( 'HashPay RSA ciphertext/key size mismatch' );
		}

		$encoded = '';
		if ( ! openssl_private_decrypt( $ciphertext, $encoded, $key, OPENSSL_NO_PADDING ) ) {
			throw new RuntimeException( 'HashPay raw RSA decryption failed: ' . self::openssl_error() );
		}
		if ( strlen( $encoded ) !== $length || 0 !== ord( $encoded[0] ) ) {
			throw new RuntimeException( 'Invalid HashPay RSA-OAEP encoded block' );
		}

		$hash_length = 32;
		$masked_seed = substr( $encoded, 1, $hash_length );
		$masked_db   = substr( $encoded, 1 + $hash_length );
		$seed        = $masked_seed ^ self::mgf1( $masked_db, $hash_length );
		$db          = $masked_db ^ self::mgf1( $seed, $length - $hash_length - 1 );

		if ( ! hash_equals( hash( 'sha256', '', true ), substr( $db, 0, $hash_length ) ) ) {
			throw new RuntimeException( 'HashPay RSA-OAEP label hash mismatch' );
		}

		$rest      = substr( $db, $hash_length );
		$separator = strpos( $rest, "\x01" );
		if ( false === $separator || '' !== trim( substr( $rest, 0, $separator ), "\x00" ) ) {
			throw new RuntimeException( 'Invalid HashPay RSA-OAEP padding' );
		}

		return substr( $rest, $separator + 1 );
	}

	private static function mgf1( string $seed, int $length ): string {
		$mask = '';
		for ( $counter = 0; strlen( $mask ) < $length; ++$counter ) {
			$mask .= hash( 'sha256', $seed . pack( 'N', $counter ), true );
		}
		return substr( $mask, 0, $length );
	}

	private static function private_key( string $pem ) {
		$normalized = trim( str_replace( array( '\\r\\n', '\\n' ), "\n", $pem ) );
		if ( '' === $normalized || false === strpos( $normalized, '-----BEGIN ' ) ) {
			throw new RuntimeException( 'HashPay RSA private key is invalid' );
		}

		$key = openssl_pkey_get_private( $normalized );
		if ( false === $key ) {
			throw new RuntimeException( 'Unable to load HashPay RSA private key: ' . self::openssl_error() );
		}
		return $key;
	}

	private static function decode_base64( string $value, string $field ): string {
		$decoded = base64_decode( preg_replace( '/\s+/', '', $value ), true );
		if ( false === $decoded ) {
			throw new RuntimeException( 'Invalid Base64 in HashPay callback ' . $field );
		}
		return $decoded;
	}

	private static function openssl_error(): string {
		$errors = array();
		while ( false !== ( $error = openssl_error_string() ) ) {
			$errors[] = $error;
		}
		return $errors ? implode( '; ', $errors ) : 'unknown OpenSSL error';
	}
}
