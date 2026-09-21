<?php
/**
 * Plugin Name: HashPay for WooCommerce
 * Plugin URI: https://github.com/TGDash/HashPay
 * Description: Accept cryptocurrency payments through a self-hosted HashPay instance.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 * WC tested up to: 11.0.1
 * Author: HashPay contributors
 * License: Apache-2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain: woocommerce-hashpay
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_HASHPAY_VERSION', '0.1.1' );
define( 'WC_HASHPAY_FILE', __FILE__ );
define( 'WC_HASHPAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_HASHPAY_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'woocommerce-hashpay',
			false,
			dirname( plugin_basename( WC_HASHPAY_FILE ) ) . '/languages'
		);

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once WC_HASHPAY_PATH . 'includes/class-wc-hashpay-crypto.php';
		require_once WC_HASHPAY_PATH . 'includes/class-wc-hashpay-client.php';
		require_once WC_HASHPAY_PATH . 'includes/class-wc-hashpay-callback.php';
		require_once WC_HASHPAY_PATH . 'includes/class-wc-gateway-hashpay.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'WC_Gateway_HashPay';
				return $gateways;
			}
		);

		add_action( 'woocommerce_api_wc_gateway_hashpay', array( 'WC_HashPay_Callback', 'handle' ) );

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ): void {
				if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
					return;
				}
				require_once WC_HASHPAY_PATH . 'includes/class-wc-hashpay-blocks.php';
				$registry->register( new WC_HashPay_Blocks() );
			}
		);
	}
);
