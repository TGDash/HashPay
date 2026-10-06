<?php
/**
 * WooCommerce HashPay redirect gateway.
 */

defined( 'ABSPATH' ) || exit;

final class WC_Gateway_HashPay extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'hashpay';
		$this->has_fields         = false;
		$this->method_title       = __( 'HashPay', 'woocommerce-hashpay' );
		$this->method_description = __( 'Accept cryptocurrency through your self-hosted HashPay checkout.', 'woocommerce-hashpay' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Cryptocurrency', 'woocommerce-hashpay' ) );
		$this->description = $this->get_option( 'description', __( 'Pay securely with cryptocurrency through HashPay.', 'woocommerce-hashpay' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-hashpay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable HashPay payments', 'woocommerce-hashpay' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce-hashpay' ),
				'type'        => 'text',
				'default'     => __( 'Cryptocurrency', 'woocommerce-hashpay' ),
				'desc_tip'    => true,
				'description' => __( 'The payment method name shown at checkout.', 'woocommerce-hashpay' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'woocommerce-hashpay' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with cryptocurrency through HashPay.', 'woocommerce-hashpay' ),
			),
			'api_url' => array(
				'title'       => __( 'HashPay URL', 'woocommerce-hashpay' ),
				'type'        => 'url',
				'placeholder' => 'https://pay.example.com',
				'description' => __( 'Your HashPay instance URL without /api.', 'woocommerce-hashpay' ),
			),
			'merchant_id' => array(
				'title' => __( 'Merchant ID', 'woocommerce-hashpay' ),
				'type'  => 'text',
			),
			'private_key' => array(
				'title'       => __( 'RSA Private Key', 'woocommerce-hashpay' ),
				'type'        => 'textarea',
				'css'         => 'font-family: monospace; min-height: 180px;',
				'description' => __( 'The PKCS#8 private key generated when the HashPay merchant was created.', 'woocommerce-hashpay' ),
			),
			'timeout' => array(
				'title'             => __( 'API timeout', 'woocommerce-hashpay' ),
				'type'              => 'number',
				'default'           => '30',
				'custom_attributes' => array( 'min' => '5', 'max' => '120' ),
				'description'       => __( 'Request timeout in seconds.', 'woocommerce-hashpay' ),
			),
			'debug' => array(
				'title'   => __( 'Debug log', 'woocommerce-hashpay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write non-sensitive API events to WooCommerce logs', 'woocommerce-hashpay' ),
				'default' => 'no',
			),
			'callback_url' => array(
				'title'       => __( 'Callback URL', 'woocommerce-hashpay' ),
				'type'        => 'title',
				'description' => '<code>' . esc_html( WC_HashPay_Callback::url() ) . '</code><br>' . esc_html__( 'Set this URL as the merchant callback in HashPay.', 'woocommerce-hashpay' ),
			),
		);
	}

	public function is_available(): bool {
		return parent::is_available()
			&& extension_loaded( 'openssl' )
			&& '' !== trim( (string) $this->get_option( 'api_url' ) )
			&& '' !== trim( (string) $this->get_option( 'merchant_id' ) )
			&& '' !== trim( (string) $this->get_option( 'private_key' ) );
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Unable to load the WooCommerce order.', 'woocommerce-hashpay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$merchant_no = self::merchant_no( $order );
			$client      = new WC_HashPay_Client( $this->settings );
			$response    = $client->create_order(
				array(
					'merchantNo' => $merchant_no,
					'amount'     => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
					'currency'   => strtoupper( $order->get_currency() ),
					'description'=> sprintf( 'WooCommerce order #%s', $order->get_order_number() ),
					'return_url' => $this->get_return_url( $order ),
				)
			);

			$hashpay_order = $response['order'] ?? null;
			$checkout_url  = isset( $response['checkoutUrl'] ) ? wp_http_validate_url( $response['checkoutUrl'] ) : false;
			if ( ! is_array( $hashpay_order ) || empty( $hashpay_order['id'] ) || ! $checkout_url || ! $this->checkout_url_allowed( $checkout_url ) ) {
				throw new RuntimeException( 'HashPay create-order response is incomplete' );
			}
			if ( ! isset( $hashpay_order['amount'], $hashpay_order['currency'] )
				|| wc_format_decimal( $hashpay_order['amount'], wc_get_price_decimals() ) !== wc_format_decimal( $order->get_total(), wc_get_price_decimals() )
				|| ! hash_equals( strtoupper( $order->get_currency() ), strtoupper( trim( (string) $hashpay_order['currency'] ) ) ) ) {
				throw new RuntimeException( 'HashPay create-order amount or currency mismatch' );
			}

			$order->update_meta_data( '_hashpay_order_id', sanitize_text_field( (string) $hashpay_order['id'] ) );
			$order->update_meta_data( '_hashpay_merchant_no', $merchant_no );
			$order->update_meta_data( '_hashpay_checkout_url', esc_url_raw( $checkout_url ) );
			$order->save();
			$order->add_order_note( __( 'HashPay payment order created. Awaiting payment confirmation.', 'woocommerce-hashpay' ) );

			return array( 'result' => 'success', 'redirect' => esc_url_raw( $checkout_url ) );
		} catch ( Throwable $error ) {
			$this->log_error( $error );
			wc_add_notice( __( 'HashPay could not create the payment. Please try again or choose another payment method.', 'woocommerce-hashpay' ), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		$settings = get_option( $this->get_option_key(), array() );
		if ( isset( $settings['api_url'] ) ) {
			$settings['api_url'] = untrailingslashit( esc_url_raw( trim( (string) $settings['api_url'] ) ) );
			update_option( $this->get_option_key(), $settings );
		}
		return $saved;
	}

	public static function merchant_no( WC_Order $order ): string {
		return 'wc-' . $order->get_id() . '-' . substr( hash_hmac( 'sha256', $order->get_order_key(), wp_salt( 'auth' ) ), 0, 20 );
	}

	private function checkout_url_allowed( string $checkout_url ): bool {
		$api      = wp_parse_url( (string) $this->get_option( 'api_url' ) );
		$checkout = wp_parse_url( $checkout_url );
		if ( ! is_array( $api ) || ! is_array( $checkout ) || 'https' !== strtolower( (string) ( $checkout['scheme'] ?? '' ) ) ) {
			return false;
		}

		return strtolower( (string) ( $api['scheme'] ?? '' ) ) === strtolower( (string) ( $checkout['scheme'] ?? '' ) )
			&& strtolower( (string) ( $api['host'] ?? '' ) ) === strtolower( (string) ( $checkout['host'] ?? '' ) )
			&& (int) ( $api['port'] ?? 443 ) === (int) ( $checkout['port'] ?? 443 );
	}

	private function log_error( Throwable $error ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $error->getMessage(), array( 'source' => 'hashpay' ) );
		}
	}
}
