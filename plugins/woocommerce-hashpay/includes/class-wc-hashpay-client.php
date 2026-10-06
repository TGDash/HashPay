<?php
/**
 * HashPay API client.
 */

defined( 'ABSPATH' ) || exit;

final class WC_HashPay_Client {
	private string $base_url;
	private string $merchant_id;
	private string $private_key;
	private int $timeout;
	private bool $debug;

	public function __construct( array $settings ) {
		$this->base_url   = untrailingslashit( trim( (string) ( $settings['api_url'] ?? '' ) ) );
		$this->merchant_id = trim( (string) ( $settings['merchant_id'] ?? '' ) );
		$this->private_key = (string) ( $settings['private_key'] ?? '' );
		$this->timeout     = max( 5, min( 120, (int) ( $settings['timeout'] ?? 30 ) ) );
		$this->debug       = 'yes' === ( $settings['debug'] ?? 'no' );

		if ( ! wp_http_validate_url( $this->base_url ) || 'https' !== strtolower( (string) wp_parse_url( $this->base_url, PHP_URL_SCHEME ) ) ) {
			throw new RuntimeException( 'HashPay API URL must be a valid HTTPS URL' );
		}
		if ( '' === $this->merchant_id ) {
			throw new RuntimeException( 'HashPay Merchant ID is empty' );
		}
	}

	public function create_order( array $order ): array {
		return $this->request( 'POST', '/api/merchant/new', $order );
	}

	public function get_order( string $order_id ): array {
		return $this->request( 'GET', '/api/order/' . rawurlencode( $order_id ) );
	}

	private function request( string $method, string $path, ?array $payload = null ): array {
		$body = null === $payload ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $body ) {
			throw new RuntimeException( 'Unable to encode HashPay request JSON' );
		}

		$timestamp = (string) time();
		$signature = WC_HashPay_Crypto::sign( $this->private_key, $method . "\n" . $path . "\n" . $timestamp . "\n" . $body );
		$args      = array(
			'body'        => $body,
			'data_format' => 'body',
			'headers'     => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'X-Merchant-Id' => $this->merchant_id,
				'X-Signature'   => $signature,
				'X-Timestamp'   => $timestamp,
			),
			'method'      => $method,
			'timeout'     => $this->timeout,
		);

		$this->log( 'HashPay API request', array( 'method' => $method, 'path' => $path ) );
		$response = wp_safe_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'HashPay network error: ' . $response->get_error_message() );
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->log( 'HashPay API response', array( 'status' => $status, 'path' => $path ) );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'HashPay returned invalid JSON (HTTP ' . $status . ')' );
		}
		if ( $status < 200 || $status >= 300 ) {
			$error_key = sanitize_key( (string) ( $decoded['error']['key'] ?? 'unknown_error' ) );
			throw new RuntimeException( 'HashPay API error HTTP ' . $status . ': ' . $error_key );
		}

		return $decoded;
	}

	private function log( string $message, array $context = array() ): void {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->debug( $message, array_merge( array( 'source' => 'hashpay' ), $context ) );
	}
}
