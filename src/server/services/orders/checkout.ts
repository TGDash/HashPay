import { all, getConfig, jsonParseObject, now, run } from "@/server/db";
import { AppError } from "@/server/http/api";
import { getMerchant } from "@/server/services/merchants";
import { assignPayment, checkPayment, checksOnSchedule, createPayment, paymentOptions } from "@/server/payments/driver";
import { listPayments, recordCheck, type PaymentChannel } from "@/server/payments/channels";
import { notifyData as okpayNotifyData, verify as verifyOkpay } from "@/server/payments/providers/okpay";
import { getOrder, listPendingPaymentOrders, publicOrder, refreshOrderPaymentWindow, setOrderPayment } from "@/server/services/orders/repository";
import { createNotify } from "@/server/services/orders/notifications";
import { notifyPayment, notifyReview } from "@/server/services/telegram/notify";
import { clearReviewImage, imageData, saveReview } from "@/server/services/orders/review";
import { ezfpReturnUrl, preserveEzfpContext } from "@/server/services/merchants/ezfp";
import { payAmount, rateContext, systemSettings } from "@/server/services/app/settings";
import { key } from "@/shared/payments";
import { ceilAmount, sameAmount } from "@/shared/amount";
import type { Order } from "@/server/services/orders/repository";
import type { PaymentSnapshot, PaymentTxEvidence } from "@/shared/types/domain";
import type { AppEnv } from "@/server/types/env";

type PaymentTxInput = {
  confirmedBy?: PaymentTxEvidence["confirmedBy"];
  timestamp?: number;
  txid?: string;
};

export async function checkoutData(env: AppEnv, orderId: string) {
  const order = await getOrder(env, orderId);
  const [merchant, payments, rate, title] = await Promise.all([
    order.merchant === "INLINE" ? null : getMerchant(env, order.merchant),
    listPayments(env),
    rateContext(env),
    getConfig(env, "title"),
  ]);
  const channels = payments.filter((item) => item.status === "enabled");
  const options = [];
  for (const channel of channels) {
    for (const option of paymentOptions(channel)) {
      options.push({
        amount: payAmount(order.amount, order.currency, option.asset, rate),
        asset: option.asset,
        network: option.network,
      });
    }
  }
  return {
    fastConfirm: rate.settings.fastConfirm,
    merchant: merchant ? { id: merchant.id, name: merchant.name } : { id: "INLINE", name: "Telegram" },
    options,
    order: checkoutOrder(order, merchant),
    title: title || "HashPay",
  };
}

export async function checkoutStatus(env: AppEnv, orderId: string) {
  let order = await getOrder(env, orderId);
  const snapshot = jsonParseObject<PaymentSnapshot>(order.payment, {} as PaymentSnapshot);
  if (order.status === "pending" && snapshot.url) {
    await checkOrderPayment(env, order.id).catch(() => undefined);
    order = await getOrder(env, orderId);
  }
  const merchant = order.merchant === "INLINE" ? null : await getMerchant(env, order.merchant);
  return checkoutOrder(order, merchant);
}

function checkoutOrder(order: Order, merchant: Awaited<ReturnType<typeof getMerchant>> | null) {
  const returnUrl = merchant?.type === "ezfp" ? ezfpReturnUrl(order, merchant) : order.redirectUrl;
  return { ...publicOrder(order), returnUrl };
}

function randomIndex(length: number) {
  const data = new Uint32Array(1);
  crypto.getRandomValues(data);
  return data[0] % length;
}

export async function selectCheckoutPayment(env: AppEnv, orderId: string, asset: string, network: string) {
  const order = await getOrder(env, orderId);
  if (order.status !== "pending" || now() > order.expireAt) throw new AppError(400, "errors.order_unavailable");
  return selectPayment(env, order, asset, network, false);
}

export async function selectTelegramPayment(env: AppEnv, orderId: string, asset: string, network: string) {
  const order = await getOrder(env, orderId);
  if (order.merchant !== "INLINE") throw new AppError(400, "errors.order_unavailable");
  if (order.status === "paid") throw new AppError(400, "errors.order_paid");
  if (order.status === "invalid") throw new AppError(400, "errors.order_unavailable");
  return selectPayment(env, order, asset, network, true);
}

async function selectPayment(env: AppEnv, order: Order, asset: string, network: string, refreshWindow: boolean) {
  const targetAsset = key(asset);
  const targetNetwork = key(network);
  const channels = [];
  for (const channel of (await listPayments(env)).filter((item) => item.status === "enabled")) {
    for (const quote of paymentOptions(channel)) {
      if (key(quote.asset) === targetAsset && key(quote.network) === targetNetwork) {
        channels.push(channel);
      }
    }
  }
  if (!channels.length) throw new AppError(400, "errors.payment_network_unavailable");
  const channel = channels[randomIndex(channels.length)];
  const rate = await rateContext(env);
  const amount = await uniqueAmount(env, channel, order.id, targetAsset, payAmount(order.amount, order.currency, targetAsset, rate));
  const snapshot = preserveEzfpContext(
    order.payment,
    await createPayment(channel, order, assignPayment(channel, amount, targetAsset)),
  );
  const ts = now();
  if (refreshWindow) {
    await refreshOrderPaymentWindow(env, order.id, channel.id, snapshot, rate.settings.timeout, ts);
  } else {
    await setOrderPayment(env, order.id, channel.id, snapshot, ts);
  }
  return snapshot;
}

async function uniqueAmount(env: AppEnv, channel: PaymentChannel, orderId: string, asset: string, amount: number) {
  const rows = await all<{ id: string; payment: string }>(
    env,
    "SELECT id, payment FROM orders WHERE status = 'pending' AND expire_at > ? AND payway = ? AND id <> ?",
    now(),
    channel.id,
    orderId,
  );
  const used = rows.flatMap((row) => {
    const snapshot = jsonParseObject<Partial<PaymentSnapshot>>(row.payment, {});
    if (snapshot.address !== channel.address) return [];
    if (key(snapshot.currency) !== asset) return [];
    const value = Number(snapshot.amount);
    return Number.isFinite(value) && value > 0 ? [value] : [];
  });

  let next = ceilAmount(amount);
  while (used.some((value) => sameAmount(value, next))) next = ceilAmount(next + 0.01);
  return next;
}

export async function markPaid(env: AppEnv, order: Order, tx: PaymentTxInput) {
  const snapshot = jsonParseObject<PaymentSnapshot>(order.payment, {} as PaymentSnapshot);
  const manual = tx.confirmedBy === "admin";
  const driver = manual ? "manual" : key(snapshot.driver);
  const txid = manual ? crypto.randomUUID() : normalizeTxid(driver, tx.txid);
  if (!driver || !txid) return null;
  const paidPayment = {
    ...snapshot,
    tx: {
      confirmedBy: tx.confirmedBy ?? "system",
      timestamp: tx.timestamp ?? now(),
      ...(manual ? {} : { txid }),
    },
  };
  const ts = now();
  const sql = manual
    ? "UPDATE orders SET status = 'paid', payment = ?, driver = ?, txid = ?, paid_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending', 'expired') AND NOT EXISTS (SELECT 1 FROM orders claimed WHERE claimed.driver = ? AND claimed.txid = ?)"
    : "UPDATE orders SET status = 'paid', payment = ?, driver = ?, txid = ?, paid_at = ?, updated_at = ? WHERE id = ? AND status = 'pending' AND NOT EXISTS (SELECT 1 FROM orders claimed WHERE claimed.driver = ? AND claimed.txid = ?)";
  let result;
  try {
    result = await run(
      env,
      sql,
      JSON.stringify(paidPayment),
      driver,
      txid,
      ts,
      ts,
      order.id,
      driver,
      txid,
    );
  } catch (error) {
    if (txConflict(error)) return null;
    throw error;
  }
  if (!result.meta.changes) {
    const current = await getOrder(env, order.id);
    return current.status === "paid" ? jsonParseObject<PaymentSnapshot>(current.payment, {} as PaymentSnapshot) : null;
  }
  if (manual) await clearReviewImage(env, order.id);
  await createNotify(env, order.id);
  await notifyPayment(env, order, paidPayment);
  return paidPayment;
}

function normalizeTxid(driver: string, value: unknown) {
  const txid = String(value ?? "").trim();
  if (!txid) return "";
  return ["aptos", "base", "bep20", "erc20", "polygon", "trc20"].includes(driver) ? txid.toLowerCase() : txid;
}

function txConflict(error: unknown) {
  const message = error instanceof Error ? error.message : String(error ?? "");
  return message.includes("orders.driver, orders.txid") || message.includes("orders_driver_txid_unique");
}

export async function submitPaymentReview(env: AppEnv, orderId: string, input: Record<string, unknown>) {
  const order = await getOrder(env, orderId);
  if (order.status === "paid") throw new AppError(400, "errors.order_paid");
  if (order.status === "invalid") throw new AppError(400, "errors.order_unavailable");
  const image = String(input.image ?? "").trim();
  const answer = String(input.answer ?? "").trim();
  if (!answer) throw new AppError(400, "errors.review_answer_required");
  if (image.length > 2_800_000) throw new AppError(400, "errors.review_image_too_large");
  const data = imageData(image);
  if (!data) throw new AppError(400, "errors.review_image_invalid");
  const review = await saveReview(env, order.id, answer, data);
  await notifyReview(env, order, answer, data);
  return { review };
}

export async function checkOrderPayment(env: AppEnv, orderId: string) {
  const order = await getOrder(env, orderId);
  if (order.status === "paid") return jsonParseObject<PaymentSnapshot>(order.payment, {} as PaymentSnapshot);
  if (order.status !== "pending" || now() > order.expireAt) throw new AppError(400, "errors.order_unavailable");
  const snapshot = jsonParseObject<PaymentSnapshot>(order.payment, {} as PaymentSnapshot);
  if (!snapshot.driver) throw new AppError(400, "errors.payment_not_selected");
  const result = await checkPayment({
    channel: await orderChannel(env, order),
    fastConfirm: (await systemSettings(env)).fastConfirm,
    orders: [checkOrder(order, snapshot)],
  });
  if (order.payway) await recordCheck(env, order.payway, result);
  if (result.status === "error") throw new AppError(502, "errors.payment_check_failed", { detail: result.error ?? "unknown" });
  const match = result.matches.find((item) => item.orderId === order.id);
  if (match) {
    const payment = await markPaid(env, order, { timestamp: match.time, txid: match.txid });
    if (payment) return payment;
  }
  throw new AppError(404, "errors.tx_not_found");
}

export async function checkPendingPayments(env: AppEnv) {
  const orders = await listPendingPaymentOrders(env, 200);
  if (!orders.length) return;
  const [channels, settings] = await Promise.all([
    listPayments(env),
    systemSettings(env),
  ]);
  // Include "error" channels, not just "enabled": a channel is flagged "error" by
  // the health check (a different endpoint than the payment scan), so a transient
  // health-check failure would otherwise strand real payments that the scan can
  // still confirm. A successful scan's recordCheck() heals the status back to
  // "enabled". Only admin-disabled channels are skipped — matching the manual
  // check path (orderChannel) and the checkChannels() convention.
  const channelById = new Map(channels
    .filter((channel) => channel.status !== "disabled")
    .map((channel) => [channel.id, channel]));
  const groups = new Map<number, Array<{ order: Order; snapshot: PaymentSnapshot }>>();
  for (const order of orders) {
    const snapshot = jsonParseObject<PaymentSnapshot>(order.payment, {} as PaymentSnapshot);
    if (!order.payway || !snapshot.driver || !checksOnSchedule(snapshot.driver)) continue;
    if (!channelById.has(order.payway)) continue;
    groups.set(order.payway, [...(groups.get(order.payway) ?? []), { order, snapshot }]);
  }

  for (const [payway, items] of groups) {
    const channel = channelById.get(payway);
    if (!channel) continue;
    const result = await checkPayment({
      channel,
      fastConfirm: settings.fastConfirm,
      orders: items.map((item) => checkOrder(item.order, item.snapshot)),
    });
    await recordCheck(env, payway, result);
    if (result.status === "error") continue;
    for (const match of result.matches) {
      const item = items.find((entry) => entry.order.id === match.orderId);
      if (item) await markPaid(env, item.order, { timestamp: match.time, txid: match.txid });
    }
  }
}

async function orderChannel(env: AppEnv, order: Order) {
  if (!order.payway) return undefined;
  return (await listPayments(env)).find((item) => item.id === order.payway);
}

function checkOrder(order: Order, snapshot: PaymentSnapshot) {
  return {
    createdAt: order.createdAt,
    expireAt: order.expireAt,
    id: order.id,
    snapshot,
  };
}

export async function confirmOrder(env: AppEnv, orderId: string) {
  const order = await getOrder(env, orderId);
  if (order.status !== "pending" && order.status !== "expired") throw new AppError(400, "errors.order_unavailable");
  const payment = await markPaid(env, order, {
    confirmedBy: "admin",
    timestamp: now(),
  });
  if (!payment) throw new AppError(400, "errors.order_unavailable");
  return payment;
}

export async function okpayNotify(env: AppEnv, input: Record<string, unknown>) {
  const channel = (await listPayments(env)).find((item) => item.driver === "okpay" && item.address === String(input.id ?? ""));
  if (!channel || !verifyOkpay(channel, input)) throw new AppError(400, "errors.bad_request");
  if (input.status && String(input.status) !== "success") throw new AppError(400, "errors.bad_request");
  if (input.code && Number(input.code) !== 10000) throw new AppError(400, "errors.bad_request");
  const data = okpayNotifyData(input);
  const order = await getOrder(env, data.uniqueId);
  const snapshot = jsonParseObject<PaymentSnapshot>(order.payment, {} as PaymentSnapshot);
  if (snapshot.driver !== "okpay" || order.payway !== channel.id) throw new AppError(400, "errors.bad_request");
  if (!sameAmount(data.amount, snapshot.amount) || data.coin !== key(snapshot.currency)) throw new AppError(400, "errors.bad_request");
  if (order.status === "paid") return { status: "success" };
  await markPaid(env, order, { txid: data.orderId });
  return { status: "success" };
}
