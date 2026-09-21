# HashPay for WooCommerce

这是 HashPay 的 WooCommerce 加密货币支付网关插件。客户提交订单后，插件会创建 HashPay 支付订单并跳转到托管收银台；HashPay 确认付款后，通过经过认证的加密回调完成 WooCommerce 订单。

## 环境要求

- WordPress 6.8 或更高版本
- WooCommerce 9.0 至 11.0.1
- PHP 8.1 或更高版本
- PHP OpenSSL 扩展
- 可通过公网 HTTPS 访问的 WordPress 站点
- 可通过公网 HTTPS 访问的 HashPay 实例

插件支持经典结账、Checkout Block 和高性能订单存储（HPOS）。

## 安装

1. 将本目录打包，并确保 ZIP 内的顶层目录名为 `woocommerce-hashpay`。
2. 在 WordPress 后台打开“插件 > 安装插件 > 上传插件”，上传 ZIP 文件。
3. 启用 **HashPay for WooCommerce**。
4. 打开“WooCommerce > 设置 > 付款 > HashPay”。
5. 按照“配置支付方式”填写并启用 HashPay。

ZIP 文件结构示例：

```text
woocommerce-hashpay.zip
└── woocommerce-hashpay/
    ├── woocommerce-hashpay.php
    ├── includes/
    ├── assets/
    ├── tests/
    └── README.md
```

HashPay 地址应填写实例根地址，例如 `https://pay.example.com`，不要追加 `/api`。

## 配置支付方式

在 WordPress 后台打开“WooCommerce > 设置 > 支付”，找到 **HashPay** 并点击“管理”。按以下方式配置：

| 设置项 | 配置说明 |
| --- | --- |
| 启用/禁用 | 勾选“启用 HashPay 支付”后，客户才会在结账页看到该支付方式。 |
| 标题 | 客户在结账页看到的支付方式名称，例如“加密货币支付”。 |
| 描述 | 客户选择该支付方式时显示的说明，例如“通过 HashPay 安全支付加密货币”。 |
| HashPay URL | HashPay 实例根地址，例如 `https://pay.example.com`；不要填写 `/api` 或收银台订单链接。 |
| Merchant ID | 在 HashPay 后台创建商户后取得的商户 ID。 |
| RSA Private Key | 创建该 HashPay 商户时一次性生成的 PKCS#8 RSA 私钥。请完整粘贴，包括 `BEGIN PRIVATE KEY` 和 `END PRIVATE KEY` 行。 |
| API timeout | HashPay API 请求超时时间，默认 `30` 秒；可设置范围为 `5` 至 `120` 秒。 |
| Debug log | 排查连接或建单问题时临时启用。日志位于“WooCommerce > 状态 > 日志”，来源为 `hashpay`，不记录私钥或完整回调内容。 |

保存 WooCommerce 设置后，复制同一页面显示的 **Callback URL**。在 HashPay 后台打开该商户的编辑页面，将此地址填写为商户的回调地址并保存。回调地址通常如下：

```text
https://shop.example.com/?wc-api=wc_gateway_hashpay
```

最后返回“WooCommerce > 设置 > 支付”，确认 HashPay 已启用。使用支持的订单币种完成一次小额测试订单，确认结账页显示的标题和描述符合设置，且付款后订单状态能够更新。

## 中文显示

插件内置简体中文翻译。将 WordPress 的“设置 > 常规 > 站点语言”设为“简体中文”后，插件的支付设置、默认结账标题、默认说明、错误提示和订单备注会自动显示为中文。

已保存的“标题”和“说明”是商店配置，不会因切换站点语言而自动翻译。请在“WooCommerce > 设置 > 支付 > HashPay”中将它们分别改为所需中文，例如“加密货币支付”和“通过 HashPay 安全地使用加密货币付款”。

## 支付流程

1. WooCommerce 调用 `POST /api/merchant/new`，提交订单金额、币种、返回地址，以及根据 WooCommerce 订单生成的已签名 `merchantNo`。
2. 插件通过 WooCommerce CRUD API 保存 HashPay 订单 ID，并将客户跳转到 `checkoutUrl`。
3. 支付成功后，HashPay 发送使用 `RSA-OAEP-256+A256GCM` 加密的回调通知。
4. 插件校验商户、时间戳、加密算法、商户订单号、HashPay 订单 ID、金额、币种和交易唯一性。
5. 校验通过后，插件调用 `WC_Order::payment_complete()`；重复回调会直接确认成功，不会重复入账。

回调地址格式如下：

```text
https://shop.example.com/?wc-api=wc_gateway_hashpay
```

创建订单时无需提交回调地址。HashPay 从商户配置中读取回调地址，因此必须在 HashPay 商户后台完成配置。

## 测试

可使用以下命令运行独立的 RSA 签名和加密回调回归测试：

```bash
php tests/run.php
```

正式使用前，建议创建一笔小额 WooCommerce 订单并确认：

- 结账后跳转到正确的 HashPay 实例。
- HashPay 订单金额和币种与 WooCommerce 订单一致。
- 支付成功后，WooCommerce 订单变为“处理中”或“已完成”。
- 在 HashPay 后台重新发送通知不会造成重复入账。
- 无效或被篡改的回调会被拒绝，并记录在“WooCommerce > 状态 > 日志”中，日志来源为 `hashpay`。

## 安全说明

- 不要将商户私钥提交到 Git、工单、截图或公开日志中。
- WordPress 和 HashPay 均应使用 HTTPS。
- 两台服务器应通过 NTP 保持时间同步；回调时间戳允许的误差为 5 分钟。
- 轮换 HashPay 商户密钥时，需要同时更新插件配置。使用旧公钥加密的已有回调无法通过新私钥解密。
- 插件不会记录私钥、请求签名、加密内容密钥或完整回调请求体。
- 私钥会保存在 WordPress 数据库配置中，应将数据库和备份按敏感数据处理。

## 许可证

本插件遵循 HashPay 仓库的 Apache-2.0 许可证。
