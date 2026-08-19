<p align="center">
  <img src="https://github.com/TGDash/HashPay/raw/main/images/banner.webp" alt="HashPay" width="100%">
</p>

<h1 align="center">HashPay</h1>

<p align="center">
  运行在 Cloudflare Wokers 上的加密货币收款网关
</p>

<p align="center">
  <img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-green?style=for-the-badge">
  <img alt="Runtime" src="https://img.shields.io/badge/runtime-Cloudflare%20Workers-F38020?style=for-the-badge&logo=cloudflare">
  <img alt="Frontend" src="https://img.shields.io/badge/frontend-Vue%203-42b883?style=for-the-badge&logo=vuedotjs">
  <img alt="Human" src="https://img.shields.io/badge/HUMAN- VERFIED-f6a10a?style=for-the-badge">
  <img alt="Telegram" src="https://img.shields.io/badge/Telegram-Mini%20App-2CA5E0?style=for-the-badge&logo=telegram">
</p>

<p align="center">
  <a href="https://deploy.workers.cloudflare.com/?url=https://github.com/TGDash/HashPay">
    <img alt="Deploy to Cloudflare" src="https://deploy.workers.cloudflare.com/button">
  </a>
</p>

<!-- dash-content-start -->

## 🤔 这是什么

HashPay 是一款运行在 Cloudflare Wokers 上的加密货币收款网关。

支持多种区块链、交易所及第三方钱包收款。支持 Telegram Inline 发起收款以及 REST API 下单，轻松接入您的网站或机器人。

运行在 Cloudflare Wokers上，意味着你不需要准备服务器，一切都以高可用的状态准备着。
~~全球宕机不在高可用范围内~~

## 🚀 快速预览

<img src="https://github.com/TGDash/HashPay/raw/main/images/index.png" alt="Index" width="100%">
<img src="https://github.com/TGDash/HashPay/raw/main/images/web.png" alt="Index" width="100%">

### 支持支付方式/网络

| 类型 | 通道 | 支持资产 |
| --- | --- | --- |
| 链上网络 | TRON / TRC20 | USDT、TRX |
| 链上网络 | Ethereum / ERC20 | USDT、USDC、ETH |
| 链上网络 | Base | USDT、USDC、ETH |
| 链上网络 | BNB Smart Chain / BEP20 | USDT、USDC、BNB |
| 链上网络 | Polygon | USDT、USDC、MATIC |
| 链上网络 | TON | USDT、GRAM |
| 链上网络 | Aptos | USDT、USDC |
| 链上网络 | Solana | USDT、USDC |
| 交易所 | Binance 币安 | USDT、USDC |
| 交易所 | 欧易 OKX | USDT、USDC |
| 钱包 | OKPay | USDT、TRX |

## 🧩 技术栈

| 模块 | 技术 |
| --- | --- |
| Runtime | Cloudflare Workers |
| API | Hono |
| 前端 | Vue 3、Vue Router、Naive UI、Vite、SCSS |
| 数据库 | Cloudflare D1 |
| 静态资源 | Cloudflare Worker Assets |
| 队列 | Cloudflare Queues |
| 定时任务 | Cloudflare Cron Triggers |
| Telegram | grammY + Telegram Bot API |
| 测试 | Vitest |

<!-- dash-content-end -->

## ⚡ 极速开始

<a href="https://deploy.workers.cloudflare.com/?url=https://github.com/TGDash/HashPay">
    <img alt="Deploy to Cloudflare" src="https://deploy.workers.cloudflare.com/button">
</a>



完成配置：

| 字段 | 获取方法 |
| --- | --- |
| `TGBOT_TOKEN` | Telegram Bot Token，在 @Botfather 中创建机器人获取，这将作为通知及创建收款单的机器人。
| `APP_SECRET` | 随机生成，推荐32位，用于整个系统的加密，请勿设置过于简单 |

<img src="https://github.com/TGDash/HashPay/raw/main/images/setup.png" alt="Index" width="50%">

然后你就可以为 workers 配置域名，然后访问页面以开始配置。

## 💻 本地开发

环境要求：

- Node.js 20+
- npm
- Wrangler

安装依赖：

```sh
npm install
```

创建本地环境变量：

```sh
cp .dev.vars.example .dev.vars
```

填写 `.dev.vars`：

```text
TGBOT_TOKEN=
APP_SECRET=
```

启动 Vite 开发服务：

```sh
npm run dev
```

默认访问地址为 `http://localhost:8183`。

单独启动 Worker runtime：

```sh
npm run dev:worker
```

默认 Worker 端口为 `8787`。

本地应用 D1 迁移：

```sh
npm run db:migrate:local
```

常用检查：

```sh
npm run check
npm run test
npm run build
npm run deploy:dry
```

## 🔌 支付接入

### API文档

后台新增商户后，系统会生成 RSA 密钥对：

- **公钥**保存在 HashPay，用于验证商户请求签名，并加密回调通知。
- **私钥**只在创建或轮换时显示一次，由商户自行保存，用于请求签名和回调解密。

错误响应统一为：

```json
{
  "error": {
    "key": "errors.bad_request",
    "params": {}
  }
}
```

#### 签名方式

支付 API 请求需携带以下 Headers：

| Header | 说明 |
| --- | --- |
| `X-Merchant-Id` | 商户 ID |
| `X-Timestamp` | 当前 Unix 秒时间戳，与服务器时间偏差不超过 5 分钟 |
| `X-Signature` | RSA-SHA256 签名（Base64） |

签名原文由以下部分以换行符 `\n` 拼接：

```text
method
path
timestamp
body
```

例如创建订单时签名原文为：

```text
POST
/api/merchant/new
1782000000
{"merchantNo":"ORDER-10001","amount":1,"currency":"USD"}
```

使用商户私钥以 `RSASSA-PKCS1-v1_5 SHA-256` 对原文签名，将签名结果 Base64 编码后放入 `X-Signature`。

#### 创建订单

```http
POST /api/merchant/new
X-Merchant-Id: <merchant-id>
X-Timestamp: <unix-seconds>
X-Signature: <base64-rsa-sha256-signature>
Content-Type: application/json

{
  "merchantNo": "ORDER-10001",
  "amount": 1,
  "currency": "USD",
  "description": "Test order",
  "return_url": "https://merchant.example.com/return"
}
```

请求参数：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `merchantNo` | ✅ | 商户系统唯一订单号，相同 `merchantNo` 会复用已有订单 |
| `amount` | ✅ | 订单金额，必须大于 0 |
| `currency` | ❌ | 货币类型，默认使用系统设置的基础货币 |
| `description` | ❌ | 订单描述 |
| `return_url` | ❌ | 支付完成后用户跳转地址 |

响应：

```json
{
  "checkoutUrl": "https://pay.example.com/pay/example",
  "order": {
    "amount": 1,
    "currency": "USD",
    "expiresAt": 1783220963,
    "id": "example",
    "status": "pending"
  },
  "reused": false
}
```

| 字段 | 说明 |
| --- | --- |
| `checkoutUrl` | 用户访问的收银台地址 |
| `order` | 订单摘要（`id`、`amount`、`currency`、`expiresAt`、`status`） |
| `reused` | 同一商户、同一 `merchantNo` 重复请求时为 `true` |

#### 查询订单

```http
GET /api/order/:orderId
X-Merchant-Id: <merchant-id>
X-Timestamp: <unix-seconds>
X-Signature: <base64-rsa-sha256-signature>
```

返回订单完整信息，包括支付状态、金额、支付快照等。GET 请求签名时 body 为空字符串。

#### 回调通知

订单支付成功后，HashPay 会向商户 `callback` 地址发送 POST 请求。

**请求头：**

| Header | 说明 |
| --- | --- |
| `X-HashPay-Merchant` | 商户 ID |
| `X-HashPay-Timestamp` | 投递时的 Unix 秒时间戳 |
| `X-HashPay-Encryption` | 加密算法，固定为 `RSA-OAEP-256+A256GCM` |

**请求体**为 JSON 格式的加密信封：

```json
{
  "alg": "RSA-OAEP-256+A256GCM",
  "key": "<base64-encrypted-aes-key>",
  "iv": "<base64-iv>",
  "data": "<base64-encrypted-data>"
}
```

**解密流程：**

1. 使用商户私钥以 RSA-OAEP (SHA-256) 解密 `key` 字段，得到 AES-256 内容密钥。
2. 使用内容密钥和 `iv` 以 AES-256-GCM 解密 `data` 字段。
3. 解密后的明文是 JSON，包含 `timestamp` 和 `payload`。
4. `payload` 包含 `orderId`、`merchantNo`、`amount`、`currency`、`status` 和 `payment` 快照。

商户系统应校验时间戳窗口，并在处理成功后返回 HTTP 2xx。失败时 HashPay 最多重试 8 次，间隔逐次递增。

#### 易支付兼容（Ezfp）

项目也支持兼容易支付接口，需在后台创建“易支付兼容 (Ezfp)”商户。

- 平台地址填写 HashPay 站点地址，支持 `GET/POST /submit.php` 和 `POST /mapi.php`。
- 额外的参数 `fiat` 指定订单法币，支持 `CNY` 和 `USD`，不传时默认为 `CNY`。

---

### 交给AI

可以直接将下面的 prompt 发给 AI，让它为你的项目生成 HashPay 支付接入模块。

> 帮我的项目接入 HashPay 加密货币支付网关，生成一个可复用的支付模块。
>
> 接入要求：
> - 创建订单：`POST /api/merchant/new`
> - 查询订单：`GET /api/order/:orderId`
> - 请求签名：`METHOD + "\n" + path + "\n" + timestamp + "\n" + body`，使用商户 RSA 私钥做 `RSASSA-PKCS1-v1_5 SHA-256` 签名，并把 Base64 签名放入 `X-Signature`
> - 请求头：`X-Merchant-Id`、`X-Timestamp`、`X-Signature`
> - 回调通知使用 `RSA-OAEP-256+A256GCM` 加密信封，使用商户私钥解密后读取 `{ timestamp, payload }`
>
> 配置部分根据当前项目代码决定，可作为支付配置项目、独立配置项目或环境变量。
>
> API 文档：https://raw.githubusercontent.com/TGDash/HashPay/refs/heads/main/README.md

---

### 已适配平台

| 平台/方式 | 说明 |
| --- | --- |
| [EgdeKey](https://github.com/TGDash/HashPay/wiki/配置教程-%E2%80%90-EgdeKey) | 运行在Cloudflare Wokers 上的发卡系统 |
| [易支付接口](https://ezfp.cn/doc.html) | 大部分发卡、商城项目均支持易支付接口 |
| [WHMCS](https://github.com/ZeroTwoDa/whmcs-hashpay-gateway) | 由 [@ZeroTwoDa](https://github.com/ZeroTwoDa) 维护 |
| [WooCommerce](plugins/woocommerce-hashpay/README.md) | WordPress插件 支持经典收银台 |

## 🔧 维护

### 更新

访问 [Update HashPay](../../actions/workflows/update-hashpay.yml)，点击 **Run workflow** 即可从上游 `tgdash/HashPay` 同步最新代码并自动部署。

更新流程会保留当前实例的 `wrangler.jsonc` 部署资源配置，但自定义代码可能被覆盖。

[没有找到更新的动作？](https://github.com/TGDash/HashPay/wiki/补全更新组件)

### 安全注意

- 不要提交 `.dev.vars`、Bot Token、`APP_SECRET`、商户私钥或交易所 API 密钥。
- 交易所 API Key 只需要读取权限，不要开启提现权限。
- 商户私钥由商户系统保存，HashPay 只保存公钥。
- 生产环境应使用 HTTPS 域名完成初始化和 Telegram webhook 配置。

## 🙌 License

HashPay 使用 Apache-2.0 协议。
