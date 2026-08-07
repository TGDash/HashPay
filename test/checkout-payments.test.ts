import { afterEach, describe, expect, it, vi } from "vitest";
import { checkOrderPayment, checkPendingPayments, confirmOrder, markPaid, selectCheckoutPayment } from "@/server/services/orders/checkout";
import { trc20Assets } from "@/shared/payments";
import type { AppEnv } from "@/server/types/env";
import type { PaymentSnapshot } from "@/shared/types/domain";

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

const trc20Snapshot: PaymentSnapshot = {
  address: "TY1ykSFu8N4mZgxsJABLGdhcs91h1N2qR2",
  amount: 10,
  currency: "usdt",
  driver: "trc20",
};

const bscSnapshot: PaymentSnapshot = {
  address: "0x78235da44022c614cbf25a26200cca47e2a61752",
  amount: 0.01,
  currency: "usdt",
  driver: "bep20",
};

describe("checkout payment selection", () => {
  it("bumps the payable amount when an active order already uses the same amount", async () => {
    const env = checkoutEnv();

    const snapshot = await selectCheckoutPayment(env, "new-order", "usdt", "trc20");

    expect(snapshot.amount).toBe(10.01);
    expect(JSON.parse(env.updatedPayment).amount).toBe(10.01);
  });

  it("ignores the current order amount when the same order changes payment", async () => {
    const env = checkoutEnv({
      currentPayment: trc20Snapshot,
      includeExisting: false,
    });

    const snapshot = await selectCheckoutPayment(env, "new-order", "usdt", "trc20");

    expect(snapshot.amount).toBe(10);
    expect(JSON.parse(env.updatedPayment).amount).toBe(10);
  });
});

describe("checkout payment check", () => {
  it("runs a server-side check instead of trusting browser candidates", async () => {
    const env = checkoutEnv({
      currentPayment: trc20Snapshot,
      includeExisting: false,
    });
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({ data: [] }))));

    await expect(checkOrderPayment(env, "new-order")).rejects.toMatchObject({ key: "errors.tx_not_found" });

    expect(env.paidOrders).toEqual(new Set());
  });

  it("rejects manual checks after the payment window expires", async () => {
    const env = checkoutEnv({
      currentPayment: trc20Snapshot,
      expired: true,
      includeExisting: false,
    });
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    await expect(checkOrderPayment(env, "new-order")).rejects.toMatchObject({ key: "errors.order_unavailable" });

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("returns provider error details on manual checks", async () => {
    const env = checkoutEnv({
      currentPayment: bscSnapshot,
      includeExisting: false,
    });
    vi.stubGlobal("fetch", vi.fn(async () => new Response("bad gateway", { status: 502 })));

    await expect(checkOrderPayment(env, "new-order")).rejects.toMatchObject({
      key: "errors.payment_check_failed",
      params: { detail: expect.stringContaining("HTTP 502 bad gateway") },
      status: 502,
    });
  });

  it("does not create duplicate notifications when the paid update loses a race", async () => {
    const ts = Math.floor(Date.now() / 1000);
    const order = orderRow("new-order", {
      callback: "https://merchant.example/callback",
      payment: JSON.stringify(trc20Snapshot),
      payway: 1,
      ts,
    });
    const inserts: string[] = [];
    const env = {
      DB: db({
        first(sql) {
          if (sql.includes("SELECT * FROM orders WHERE id = ?")) return { ...order, status: "paid" };
          return null;
        },
        run(sql) {
          if (sql.startsWith("INSERT INTO notify")) inserts.push(sql);
          if (sql.startsWith("UPDATE orders SET status = 'paid'")) return { meta: { changes: 0 } };
          return { meta: { changes: 1, last_row_id: 1 } };
        },
      }),
    } as unknown as AppEnv;

    await markPaid(env, orderFromRow(order), { txid: "tx" });

    expect(inserts).toEqual([]);
  });

  it("treats a concurrent txid uniqueness conflict as an unmatched transaction", async () => {
    const ts = Math.floor(Date.now() / 1000);
    const order = orderRow("new-order", {
      payment: JSON.stringify(trc20Snapshot),
      payway: 1,
      ts,
    });
    const env = {
      DB: db({
        run(sql) {
          if (sql.startsWith("UPDATE orders SET status = 'paid'")) {
            throw new Error("D1_ERROR: UNIQUE constraint failed: orders.driver, orders.txid");
          }
          return { meta: { changes: 1, last_row_id: 1 } };
        },
      }),
    } as unknown as AppEnv;

    await expect(markPaid(env, orderFromRow(order), { txid: "same-tx" })).resolves.toBeNull();
  });

  it("does not confirm a second order with the same driver and txid", async () => {
    const ts = Math.floor(Date.now() / 1000);
    const env = checkoutEnv({
      claimed: [{ driver: "trc20", txid: "reused-tx" }],
      currentPayment: trc20Snapshot,
      includeExisting: false,
    });
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({
      data: [trc20Tx({ hash: "reused-tx", timestamp: ts * 1000, value: "10000000" })],
    }))));

    await expect(checkOrderPayment(env, "new-order")).rejects.toMatchObject({ key: "errors.tx_not_found" });

    expect(env.paidOrders).toEqual(new Set());
    expect(env.notifications).toBe(0);
  });

  it("allows the same txid on a different driver", async () => {
    const ts = Math.floor(Date.now() / 1000);
    const env = checkoutEnv({
      claimed: [{ driver: "bep20", txid: "shared-tx" }],
      currentPayment: trc20Snapshot,
      includeExisting: false,
    });
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({
      data: [trc20Tx({ hash: "shared-tx", timestamp: ts * 1000, value: "10000000" })],
    }))));

    await expect(checkOrderPayment(env, "new-order")).resolves.toMatchObject({
      tx: { txid: "shared-tx" },
    });

    expect(env.paidOrders).toEqual(new Set(["new-order"]));
  });

  it("keeps the order paid when the Telegram administrator notice fails", async () => {
    const ts = Math.floor(Date.now() / 1000);
    const env = checkoutEnv({
      adminId: "123456",
      currentPayment: trc20Snapshot,
      includeExisting: false,
    });
    const consoleMock = vi.spyOn(console, "error").mockImplementation(() => undefined);
    vi.stubGlobal("fetch", vi.fn(async (url: string) => {
      if (url.includes("api.trongrid.io")) {
        return new Response(JSON.stringify({
          data: [trc20Tx({ hash: "paid-tx", timestamp: ts * 1000, value: "10000000" })],
        }));
      }
      throw new Error("Telegram unavailable");
    }));

    await expect(checkOrderPayment(env, "new-order")).resolves.toMatchObject({
      tx: { txid: "paid-tx" },
    });

    expect(env.paidOrders).toEqual(new Set(["new-order"]));
    expect(consoleMock).toHaveBeenCalledWith("telegram:payment_notice_failed", expect.any(Error));
  });
});

describe("admin payment confirmation", () => {
  it("allows expired orders to be confirmed and clears review images", async () => {
    const ts = Math.floor(Date.now() / 1000) - 3600;
    const order = orderRow("expired-order", {
      payment: JSON.stringify(trc20Snapshot),
      payway: 1,
      ts,
    });
    order.expire_at = ts + 60;
    order.status = "expired";
    let paidSql = "";
    let paidValues: unknown[] = [];
    let reviewCleared = false;

    const env = {
      DB: db({
        first(sql) {
          if (sql.includes("SELECT * FROM orders WHERE id = ?")) return order;
          return null;
        },
        run(sql, values) {
          if (sql.startsWith("UPDATE orders SET status = 'paid'")) {
            paidSql = sql;
            paidValues = values;
          }
          if (sql.startsWith("UPDATE review SET image = NULL")) reviewCleared = true;
          return { meta: { changes: 1, last_row_id: 1 } };
        },
      }),
    } as unknown as AppEnv;

    const payment = await confirmOrder(env, "expired-order");

    expect(payment.tx).toMatchObject({ confirmedBy: "admin" });
    expect(payment.tx?.txid).toBeUndefined();
    expect(paidSql).toContain("status IN ('pending', 'expired')");
    expect(paidValues[1]).toBe("manual");
    expect(paidValues[2]).toMatch(/^[0-9a-f-]{36}$/);
    expect(reviewCleared).toBe(true);
  });
});

describe("scheduled checkout payment checks", () => {
  it("checks pending orders once per payment channel", async () => {
    const env = scheduledCheckEnv();
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({
      data: [
        trc20Tx({ hash: "tx-a", value: "10000000" }),
        trc20Tx({ hash: "tx-b", timestamp: 121_000, value: "10010000" }),
      ],
    })));
    vi.stubGlobal("fetch", fetchMock);

    await checkPendingPayments(env);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(env.paidOrders).toEqual(new Set(["order-a", "order-b"]));
    expect(env.paymentChecks).toBe(1);
  });

  it("still scans errored payment channels so a working scan can heal them", async () => {
    const env = scheduledCheckEnv("error");
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({
      data: [
        trc20Tx({ hash: "tx-a", value: "10000000" }),
        trc20Tx({ hash: "tx-b", timestamp: 121_000, value: "10010000" }),
      ],
    })));
    vi.stubGlobal("fetch", fetchMock);

    await checkPendingPayments(env);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(env.paidOrders).toEqual(new Set(["order-a", "order-b"]));
    // recordCheck() runs, so a successful scan flips the channel back to enabled
    expect(env.paymentChecks).toBe(1);
  });

  it("skips admin-disabled payment channels", async () => {
    const env = scheduledCheckEnv("disabled");
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    await checkPendingPayments(env);

    expect(fetchMock).not.toHaveBeenCalled();
    expect(env.paymentChecks).toBe(0);
  });
});

function checkoutEnv(options: { adminId?: string; claimed?: Array<{ driver: string; txid: string }>; currentPayment?: PaymentSnapshot; expired?: boolean; includeExisting?: boolean } = {}) {
  const ts = Math.floor(Date.now() / 1000);
  const claims = [...(options.claimed ?? [])];
  const orders = new Map<string, Record<string, unknown>>([
    ["new-order", orderRow("new-order", {
      payment: options.currentPayment ? JSON.stringify(options.currentPayment) : "{}",
      payway: options.currentPayment ? 1 : null,
      ts: options.expired ? ts - 3600 : ts,
    })],
  ]);

  if (options.includeExisting ?? true) {
    orders.set("existing-order", orderRow("existing-order", {
      payment: JSON.stringify(trc20Snapshot),
      payway: 1,
      ts,
    }));
  }

  const env = {
    notifications: 0,
    paidOrders: new Set<string>(),
    TGBOT_TOKEN: "token",
    updatedPayment: "",
    DB: db({
      all(sql, values) {
        if (sql.includes("FROM payments")) return [paymentRow(ts, options.currentPayment ?? trc20Snapshot)];
        if (sql.includes("FROM orders WHERE status = 'pending'")) {
          const [currentTime, payway, orderId] = values;
          return Array.from(orders.values())
            .filter((order) => order.status === "pending")
            .filter((order) => Number(order.expire_at) > Number(currentTime))
            .filter((order) => order.payway === payway)
            .filter((order) => order.id !== orderId)
            .map(({ id, payment }) => ({ id, payment }));
        }
        return [];
      },
      first(sql, values) {
        if (sql.includes("SELECT value FROM configs") && values[0] === "admin_id") {
          return options.adminId ? { value: options.adminId } : null;
        }
        if (sql.includes("SELECT * FROM orders WHERE id = ?")) return orders.get(String(values[0])) ?? null;
        return null;
      },
      run(sql, values) {
        if (sql.startsWith("UPDATE orders SET payway = ?")) env.updatedPayment = String(values[1]);
        if (sql.startsWith("UPDATE orders SET status = 'paid'")) {
          const driver = String(values[1]);
          const txid = String(values[2]);
          if (claims.some((claim) => claim.driver === driver && claim.txid === txid)) return { meta: { changes: 0, last_row_id: 0 } };
          const id = String(values[5]);
          const order = orders.get(id);
          if (order) {
            order.driver = driver;
            order.payment = values[0];
            order.status = "paid";
            order.txid = txid;
          }
          claims.push({ driver, txid });
          env.paidOrders.add(id);
        }
        if (sql.startsWith("INSERT INTO notify")) env.notifications += 1;
        return { meta: { changes: 1, last_row_id: 1 } };
      },
    }),
  } as unknown as AppEnv & { notifications: number; paidOrders: Set<string>; updatedPayment: string };

  return env;
}

function scheduledCheckEnv(paymentStatus = "enabled") {
  const orders = new Map<string, Record<string, unknown>>([
    ["order-a", orderRow("order-a", { amount: 20, payment: JSON.stringify({ ...trc20Snapshot, amount: 10 }), payway: 1, ts: 100 })],
    ["order-b", orderRow("order-b", { amount: 20, payment: JSON.stringify({ ...trc20Snapshot, amount: 10.01 }), payway: 1, ts: 100 })],
  ]);

  const env = {
    paidOrders: new Set<string>(),
    paymentChecks: 0,
    DB: db({
      all(sql) {
        if (sql.includes("FROM payments")) return [paymentRow(100, trc20Snapshot, paymentStatus)];
        if (sql.includes("FROM orders WHERE status = 'pending'")) return Array.from(orders.values());
        return [];
      },
      first(sql, values) {
        if (sql.includes("SELECT value FROM configs")) return null;
        if (sql.includes("SELECT * FROM orders WHERE id = ?")) return orders.get(String(values[0])) ?? null;
        return null;
      },
      run(sql, values) {
        if (sql.startsWith("UPDATE payments SET status")) env.paymentChecks += 1;
        if (sql.startsWith("UPDATE orders SET status = 'paid'")) {
          const id = String(values[5]);
          env.paidOrders.add(id);
          const order = orders.get(id);
          if (order) {
            order.driver = values[1];
            order.status = "paid";
            order.payment = values[0];
            order.txid = values[2];
          }
          return { meta: { changes: 1, last_row_id: 1 } };
        }
        return { meta: { changes: 1, last_row_id: 1 } };
      },
    }),
  } as unknown as AppEnv & { paidOrders: Set<string>; paymentChecks: number };

  return env;
}

function db(handlers: {
  all?: (sql: string, values: unknown[]) => unknown[];
  first?: (sql: string, values: unknown[]) => unknown;
  run?: (sql: string, values: unknown[]) => unknown;
}) {
  return {
    prepare(sql: string) {
      let values: unknown[] = [];
      return {
        bind(...args: unknown[]) {
          values = args;
          return this;
        },
        async all() {
          return { results: handlers.all?.(sql, values) ?? [] };
        },
        async first() {
          return handlers.first?.(sql, values) ?? null;
        },
        async run() {
          return handlers.run?.(sql, values) ?? { meta: { changes: 1, last_row_id: 1 } };
        },
      };
    },
  };
}

function orderRow(id: string, input: { amount?: number; callback?: string | null; payment: string; payway: number | null; ts: number }) {
  return {
    amount: input.amount ?? 10,
    callback: input.callback ?? null,
    created_at: input.ts,
    currency: "USDT",
    description: id,
    expire_at: input.ts + 1800,
    id,
    merchant: "INLINE",
    merchant_no: id,
    paid_at: null,
    payment: input.payment,
    payway: input.payway,
    redirect_url: null,
    status: "pending",
    updated_at: input.ts,
  };
}

function orderFromRow(row: Record<string, unknown>) {
  return {
    amount: Number(row.amount),
    callback: row.callback as string | null,
    createdAt: Number(row.created_at),
    currency: String(row.currency),
    description: row.description as string | null,
    expireAt: Number(row.expire_at),
    id: String(row.id),
    merchant: String(row.merchant),
    merchantNo: String(row.merchant_no),
    paidAt: row.paid_at as number | null,
    payment: String(row.payment),
    payway: row.payway as number | null,
    redirectUrl: row.redirect_url as string | null,
    status: row.status as "pending",
    updatedAt: Number(row.updated_at),
  };
}

function paymentRow(ts: number, payment: PaymentSnapshot = trc20Snapshot, status = "enabled") {
  return {
    address: payment.address,
    assets: JSON.stringify([payment.currency]),
    createdAt: ts,
    credentials: "{}",
    driver: payment.driver,
    id: 1,
    name: payment.driver,
    status,
    updatedAt: ts,
  };
}

function trc20Tx(input: { hash: string; timestamp?: number; value: string }) {
  return {
    block_timestamp: input.timestamp ?? 120_000,
    to: trc20Snapshot.address,
    token_info: { address: trc20Assets.usdt.contract, decimals: 6, symbol: "USDT" },
    transaction_id: input.hash,
    value: input.value,
  };
}
