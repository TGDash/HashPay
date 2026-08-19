# HashPay for WooCommerce

HashPay cryptocurrency payment gateway for WooCommerce. The plugin creates a
HashPay order when the customer places an order, redirects the customer to the
hosted checkout, and completes the WooCommerce order after an authenticated,
encrypted callback.

## Requirements

- WordPress 6.8 or newer
- WooCommerce 9.0 through 11.0.1
- PHP 8.1 or newer
- PHP OpenSSL extension
- A public HTTPS WordPress site
- A public HTTPS HashPay instance

The plugin supports classic checkout, Checkout Block, and High-Performance
Order Storage (HPOS). 

## Installation

1. Package this directory so the ZIP contains a top-level `woocommerce-hashpay`
   directory.
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin** and upload
   the ZIP.
3. Activate **HashPay for WooCommerce**.
4. Open **WooCommerce > Settings > Payments > HashPay**.
5. Configure and enable HashPay as described in [Configure the payment method](#configure-the-payment-method).

Example package layout:

```text
woocommerce-hashpay.zip
└── woocommerce-hashpay/
    ├── woocommerce-hashpay.php
    ├── includes/
    ├── assets/
    ├── tests/
    └── README.md
```

HashPay's URL must be the instance root, for example
`https://pay.example.com`, without `/api`.

## Configure the payment method

In WordPress, open **WooCommerce > Settings > Payments**, find **HashPay**, and
select **Manage**. Configure the fields as follows:

| Setting | Configuration |
| --- | --- |
| Enable/Disable | Enable **HashPay payments** for customers to see the gateway at checkout. |
| Title | The payment method name shown to customers at checkout, such as `Cryptocurrency payment`. |
| Description | The description shown when customers select the gateway, such as `Pay securely with cryptocurrency through HashPay.` |
| HashPay URL | The HashPay instance root, such as `https://pay.example.com`; do not enter `/api` or a checkout order URL. |
| Merchant ID | The merchant ID from the merchant created in the HashPay admin panel. |
| RSA Private Key | The PKCS#8 RSA private key generated once when that HashPay merchant was created. Paste the complete key, including the `BEGIN PRIVATE KEY` and `END PRIVATE KEY` lines. |
| API timeout | The HashPay API request timeout. The default is `30` seconds; the allowed range is `5` to `120` seconds. |
| Debug log | Enable temporarily while investigating connection or order-creation issues. Logs are under **WooCommerce > Status > Logs**, source `hashpay`; the plugin does not log private keys or complete callback bodies. |

Save the WooCommerce settings, then copy the **Callback URL** displayed on the
same page. In the HashPay admin panel, edit this merchant, enter that value as
its callback URL, and save it. It normally has this form:

```text
https://shop.example.com/?wc-api=wc_gateway_hashpay
```

Finally, return to **WooCommerce > Settings > Payments** and confirm HashPay is
enabled. Place a small test order with a supported store currency to verify the
checkout title and description, then confirm a completed payment updates the
WooCommerce order status.

## Simplified Chinese

The plugin includes a Simplified Chinese translation. Set **Settings > General >
Site Language** to **Simplified Chinese** and the plugin's payment settings,
default checkout title and description, error notices, and order notes will be
displayed in Chinese.

Saved **Title** and **Description** values are store configuration and are not
automatically translated when the site language changes. Update them under
**WooCommerce > Settings > Payments > HashPay** as needed, for example to
`加密货币支付` and `通过 HashPay 安全地使用加密货币付款`.

## Payment flow

1. WooCommerce calls `POST /api/merchant/new` with the order amount, currency,
   return URL, and a signed `merchantNo` derived from the WooCommerce order.
2. The plugin stores the returned HashPay order ID through the WooCommerce CRUD
   API and redirects the customer to `checkoutUrl`.
3. HashPay sends an `RSA-OAEP-256+A256GCM` encrypted callback after payment.
4. The plugin verifies the merchant, timestamps, encryption, merchant number,
   HashPay order ID, amount, currency, and transaction uniqueness.
5. The plugin calls `WC_Order::payment_complete()` and acknowledges duplicate
   callbacks without applying payment twice.

The callback URL has this form:

```text
https://shop.example.com/?wc-api=wc_gateway_hashpay
```

Do not put the callback URL in the create-order request. Configure it on the
HashPay merchant because HashPay takes callbacks from the merchant record.

## Testing

Run the standalone RSA signing and encrypted callback regression tests:

```bash
php tests/run.php
```

Before production use, place a small real WooCommerce order and verify:

- Checkout redirects to the correct HashPay instance.
- The HashPay order amount and currency match WooCommerce.
- A successful payment changes the WooCommerce order to Processing or
  Completed.
- Resending the HashPay notification does not duplicate the payment.
- Invalid or tampered callbacks are rejected and recorded under
  **WooCommerce > Status > Logs**, source `hashpay`.

## Security

- Keep the merchant private key out of Git, support tickets, screenshots, and
  public logs.
- Use HTTPS for WordPress and HashPay.
- Keep both servers synchronized with NTP; callback timestamps have a five
  minute acceptance window.
- Rotate the HashPay merchant key and update this plugin together. Existing
  callbacks encrypted for an old key cannot be decrypted with the new key.
- The plugin never logs private keys, request signatures, encrypted content
  keys, or callback bodies.

## License

This plugin follows the HashPay repository's Apache-2.0 license.
