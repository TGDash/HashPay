<?php
/**
 * WooCommerce Checkout Block payment method registration.
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_HashPay_Blocks extends AbstractPaymentMethodType {
	protected $name = 'hashpay';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_hashpay_settings', array() );
	}

	public function is_active(): bool {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['hashpay'] ) && $gateways['hashpay']->is_available();
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'wc-hashpay-blocks',
			WC_HASHPAY_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			WC_HASHPAY_VERSION,
			true
		);
		return array( 'wc-hashpay-blocks' );
	}

	public function get_payment_method_script_handles_for_admin(): array {
		return $this->get_payment_method_script_handles();
	}

	public function get_payment_method_data(): array {
		return array(
			'description' => (string) $this->get_setting( 'description', '' ),
			'supports'    => array( 'products' ),
			'title'       => (string) $this->get_setting( 'title', __( 'Cryptocurrency', 'woocommerce-hashpay' ) ),
		);
	}
}
