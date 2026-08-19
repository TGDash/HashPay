<?php
/**
 * HashPay encrypted payment callback handler.
 */

defined( 'ABSPATH' ) || exit;

final class WC_HashPay_Callback {
	private const MAX_BODY_BYTES = 1048576;
	private const TIMESTAMP_WINDOW = 300;

	public static function url(): string {
		return add_query_arg( 'wc-api', 'wc_gateway_hashpay', home_url( '/' ) );
	}

	public static function handle(): void {
		try {
			if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
				throw new WC_HashPay_Callback_Exception( 'Method not allowed', 405 );
			}
			if ( false === strpos( strtolower( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) ), 'application/json' ) ) {
				throw new WC_HashPay_Callback_Exception( 'Content-Type must be application/json', 415 );
			}

			$settings = get_option( 'woocommerce_hashpay_settings', array() );
			if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
				throw new WC_HashPay_Callback_Exception( 'HashPay gateway is disabled', 503 );
			}

			$merchant_id = trim( (string) ( $settings['merchant_id'] ?? '' ) );
			$private_key = (string) ( $settings['private_key'] ?? '' );
			$header_merchant = trim( self::header( 'HTTP_X_HASHPAY_MERCHANT' ) );
			$header_timestamp = trim( self::header( 'HTTP_X_HASHPAY_TIMESTAMP' ) );
			$header_algorithm = trim( self::header( 'HTTP_X_HASHPAY_ENCRYPTION' ) );

			if ( '' === $merchant_id || '' === $header_merchant || ! hash_equals( $merchant_id, $header_merchant ) ) {
				throw new WC_HashPay_Callback_Exception( 'HashPay merchant header mismatch', 401 );
			}
			if ( ! hash_equals( WC_HashPay_Crypto::ENVELOPE_ALGORITHM, $header_algorithm ) ) {
				throw new WC_HashPay_Callback_Exception( 'HashPay encryption header mismatch', 400 );
			}
			if ( ! self::timestamp_valid( $header_timestamp ) ) {
				throw new WC_HashPay_Callback_Exception( 'HashPay callback timestamp is outside the allowed window', 408 );
			}

			$raw = file_get_contents( 'php://input' );
			if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_BODY_BYTES ) {
				throw new WC_HashPay_Callback_Exception( 'Invalid HashPay callback body', 400 );
			}
			$envelope = json_decode( $raw, true );
			if ( ! is_array( $envelope ) || JSON_ERROR_NONE !== json_last_error() ) {
				throw new WC_HashPay_Callback_Exception( 'Invalid HashPay callback JSON', 400 );
			}

			$message = WC_HashPay_Crypto::decrypt_envelope( $private_key, $envelope );
			$payload = $message['payload'] ?? null;
			if ( ! is_array( $payload ) || ! self::timestamp_valid( $message['timestamp'] ?? null ) || ! hash_equals( $header_timestamp, (string) $message['timestamp'] ) ) {
				throw new WC_HashPay_Callback_Exception( 'Invalid HashPay decrypted callback structure or timestamp', 408 );
			}

			$order          = self::validate_order( $payload );
			$transaction_id = self::transaction_id( $payload );
			if ( $order->is_paid() ) {
				$stored_transaction_id = trim( (string) $order->get_meta( '_hashpay_transaction_id', true ) );
				if ( '' === $stored_transaction_id || ! hash_equals( $stored_transaction_id, $transaction_id ) ) {
					throw new WC_HashPay_Callback_Exception( 'Paid WooCommerce order has a different HashPay transaction ID', 409 );
				}
				self::respond( 200, array( 'ok' => true, 'duplicate' => true ) );
			}

			$duplicate = false;
			$lock      = self::acquire_lock();
			try {
				$order = wc_get_order( $order->get_id() );
				if ( ! $order instanceof WC_Order ) {
					throw new WC_HashPay_Callback_Exception( 'WooCommerce order disappeared during callback processing', 500 );
				}
				if ( $order->is_paid() ) {
					$stored_transaction_id = trim( (string) $order->get_meta( '_hashpay_transaction_id', true ) );
					if ( '' === $stored_transaction_id || ! hash_equals( $stored_transaction_id, $transaction_id ) ) {
						throw new WC_HashPay_Callback_Exception( 'Paid WooCommerce order has a different HashPay transaction ID', 409 );
					}
					$duplicate = true;
				} else {
					self::assert_transaction_available( $transaction_id, $order->get_id() );
					$order->update_meta_data( '_hashpay_transaction_id', $transaction_id );
					$order->payment_complete( $transaction_id );
					if ( ! $order->is_paid() ) {
						throw new WC_HashPay_Callback_Exception( 'WooCommerce did not accept the HashPay payment transition', 409 );
					}
					$order->add_order_note( __( 'HashPay payment confirmed by encrypted callback.', 'woocommerce-hashpay' ) );
					$order->save();
					self::log( 'info', 'HashPay payment completed', array( 'order_id' => $order->get_id() ) );
				}
			} finally {
				self::release_lock( $lock );
			}
			self::respond( 200, $duplicate ? array( 'ok' => true, 'duplicate' => true ) : array( 'ok' => true ) );
		} catch ( Throwable $error ) {
			$status = $error instanceof WC_HashPay_Callback_Exception ? $error->getCode() : 500;
			self::log( 'error', $error->getMessage(), array( 'status' => $status ) );
			self::respond( $status, array( 'ok' => false ) );
		}
	}

	private static function validate_order( array $payload ): WC_Order {
		foreach ( array( 'orderId', 'merchantNo', 'amount', 'currency', 'status', 'payment' ) as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				throw new WC_HashPay_Callback_Exception( 'HashPay callback payload is incomplete', 400 );
			}
		}
		if ( ! hash_equals( 'paid', strtolower( trim( (string) $payload['status'] ) ) ) ) {
			throw new WC_HashPay_Callback_Exception( 'HashPay order is not paid', 409 );
		}

		$merchant_no = trim( (string) $payload['merchantNo'] );
		if ( 1 !== preg_match( '/^wc-([1-9][0-9]*)-[a-f0-9]{20}$/D', $merchant_no, $matches ) ) {
			throw new WC_HashPay_Callback_Exception( 'Invalid HashPay WooCommerce merchant number', 400 );
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order instanceof WC_Order || 'hashpay' !== $order->get_payment_method() ) {
			throw new WC_HashPay_Callback_Exception( 'WooCommerce order was not found for HashPay', 404 );
		}
		$stored_merchant_no = trim( (string) $order->get_meta( '_hashpay_merchant_no', true ) );
		if ( '' === $stored_merchant_no || ! hash_equals( $stored_merchant_no, $merchant_no ) ) {
			throw new WC_HashPay_Callback_Exception( 'HashPay merchant number does not match the WooCommerce order', 401 );
		}

		$stored_order_id = trim( (string) $order->get_meta( '_hashpay_order_id', true ) );
		if ( '' === $stored_order_id || ! hash_equals( $stored_order_id, trim( (string) $payload['orderId'] ) ) ) {
			throw new WC_HashPay_Callback_Exception( 'HashPay order ID mismatch', 409 );
		}
		if ( ! is_numeric( $payload['amount'] ) || ! self::amount_matches( $order->get_total(), $payload['amount'] ) ) {
			throw new WC_HashPay_Callback_Exception( 'HashPay payment amount mismatch', 422 );
		}
		$currency = strtoupper( trim( (string) $payload['currency'] ) );
		if ( '' === $currency || ! hash_equals( strtoupper( $order->get_currency() ), $currency ) ) {
			throw new WC_HashPay_Callback_Exception( 'HashPay payment currency mismatch', 422 );
		}

		return $order;
	}

	private static function amount_matches( $expected, $received ): bool {
		$precision = wc_get_price_decimals();
		return wc_format_decimal( $expected, $precision ) === wc_format_decimal( $received, $precision );
	}

	private static function transaction_id( array $payload ): string {
		$payment = is_array( $payload['payment'] ?? null ) ? $payload['payment'] : array();
		$tx      = is_array( $payment['tx'] ?? null ) ? $payment['tx'] : array();
		foreach ( array( $tx['txid'] ?? null, $payment['out_id'] ?? null, $payload['orderId'] ?? null ) as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				return substr( sanitize_text_field( (string) $candidate ), 0, 255 );
			}
		}
		throw new WC_HashPay_Callback_Exception( 'HashPay transaction ID is missing', 400 );
	}

	private static function assert_transaction_available( string $transaction_id, int $order_id ): void {
		$orders = wc_get_orders(
			array(
				'exclude'    => array( $order_id ),
				'limit'      => 1,
				'meta_query' => array(
					array(
						'key'   => '_hashpay_transaction_id',
						'value' => $transaction_id,
					),
				),
				'return'     => 'ids',
			)
		);
		if ( ! empty( $orders ) ) {
			throw new WC_HashPay_Callback_Exception( 'HashPay transaction ID is already assigned to another order', 409 );
		}
	}

	private static function acquire_lock(): string {
		$lock = 'wc_hashpay_callback_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			$created_at = (int) get_option( $lock, 0 );
			if ( $created_at <= time() - 60 ) {
				delete_option( $lock );
				if ( add_option( $lock, time(), '', false ) ) {
					return $lock;
				}
			}
			throw new WC_HashPay_Callback_Exception( 'HashPay callback is already being processed', 503 );
		}
		return $lock;
	}

	private static function release_lock( string $lock ): void {
		delete_option( $lock );
	}

	private static function timestamp_valid( $timestamp ): bool {
		return ( is_int( $timestamp ) || is_string( $timestamp ) )
			&& 1 === preg_match( '/^[0-9]+$/D', (string) $timestamp )
			&& abs( time() - (int) $timestamp ) <= self::TIMESTAMP_WINDOW;
	}

	private static function header( string $name ): string {
		return isset( $_SERVER[ $name ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) : '';
	}

	private static function log( string $level, string $message, array $context ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'hashpay' ), $context ) );
		}
	}

	private static function respond( int $status, array $body ): void {
		wp_send_json( $body, $status );
	}
}

final class WC_HashPay_Callback_Exception extends RuntimeException {
	public function __construct( string $message, int $status ) {
		parent::__construct( $message, $status );
	}
}
