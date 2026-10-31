import type { SQLiteDatabase } from 'expo-sqlite';

/**
 * The offline restaurant's tables: those of restaurant-app (one branch, the
 * restaurant itself), plus its people with their passwords. Amounts are
 * integers in minor units; times are ISO strings in UTC, so they sort as
 * text. Each step runs once, in order; the version is PRAGMA user_version.
 */
const STEPS: string[] = [
  `
  CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
  CREATE TABLE sequences (name TEXT PRIMARY KEY, value INTEGER NOT NULL);

  CREATE TABLE users (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    role TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );

  CREATE TABLE menu_items (
    id TEXT PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('dish', 'drink')),
    name TEXT NOT NULL,
    section TEXT,
    unit TEXT NOT NULL,
    barcode TEXT UNIQUE,
    price_minor INTEGER NOT NULL,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );

  CREATE TABLE dining_tables (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    area TEXT,
    seats INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1
  );

  CREATE TABLE orders (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    service TEXT NOT NULL CHECK (service IN ('dine_in', 'takeaway')),
    dining_table_id TEXT REFERENCES dining_tables (id),
    guests INTEGER,
    customer_name TEXT,
    status TEXT NOT NULL CHECK (status IN ('open', 'paid', 'cancelled')),
    opened_by TEXT NOT NULL REFERENCES users (id),
    opened_at TEXT NOT NULL,
    closed_by TEXT REFERENCES users (id),
    closed_at TEXT,
    cancel_reason TEXT
  );
  -- One open order per table.
  CREATE UNIQUE INDEX orders_open_table ON orders (dining_table_id) WHERE status = 'open' AND dining_table_id IS NOT NULL;
  CREATE INDEX orders_opened ON orders (opened_at);

  CREATE TABLE kitchen_tickets (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL REFERENCES orders (id),
    number TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL CHECK (status IN ('preparing', 'ready', 'served')),
    sent_by TEXT NOT NULL REFERENCES users (id),
    sent_at TEXT NOT NULL,
    ready_at TEXT,
    served_at TEXT
  );
  CREATE INDEX kitchen_tickets_status ON kitchen_tickets (status, sent_at);

  CREATE TABLE order_lines (
    id TEXT PRIMARY KEY,
    seq INTEGER NOT NULL,
    order_id TEXT NOT NULL REFERENCES orders (id),
    menu_item_id TEXT NOT NULL REFERENCES menu_items (id),
    quantity INTEGER NOT NULL,
    note TEXT,
    kitchen_ticket_id TEXT REFERENCES kitchen_tickets (id),
    added_by TEXT NOT NULL REFERENCES users (id),
    added_at TEXT NOT NULL,
    voided_by TEXT REFERENCES users (id),
    voided_at TEXT,
    void_reason TEXT
  );
  CREATE INDEX order_lines_order ON order_lines (order_id, seq);

  CREATE TABLE till_shifts (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL CHECK (status IN ('open', 'closed')),
    opening_float_minor INTEGER NOT NULL,
    opened_by TEXT NOT NULL REFERENCES users (id),
    opened_at TEXT NOT NULL,
    closed_by TEXT REFERENCES users (id),
    closed_at TEXT,
    expected_cash_minor INTEGER,
    counted_cash_minor INTEGER,
    closing_note TEXT
  );
  -- One open till per cashier.
  CREATE UNIQUE INDEX till_shifts_open ON till_shifts (opened_by) WHERE status = 'open';

  CREATE TABLE bills (
    id TEXT PRIMARY KEY,
    order_id TEXT NOT NULL UNIQUE REFERENCES orders (id),
    till_shift_id TEXT NOT NULL REFERENCES till_shifts (id),
    number TEXT NOT NULL UNIQUE,
    currency TEXT NOT NULL,
    total_minor INTEGER NOT NULL,
    cost_minor INTEGER NOT NULL DEFAULT 0,
    payment_method TEXT NOT NULL,
    tendered_minor INTEGER,
    change_minor INTEGER NOT NULL DEFAULT 0,
    payment_reference TEXT,
    customer_name TEXT,
    status TEXT NOT NULL CHECK (status IN ('paid', 'voided')),
    paid_by TEXT NOT NULL REFERENCES users (id),
    paid_at TEXT NOT NULL,
    voided_by TEXT REFERENCES users (id),
    voided_at TEXT,
    void_reason TEXT,
    refund_till_shift_id TEXT REFERENCES till_shifts (id)
  );
  CREATE INDEX bills_paid ON bills (paid_at);

  CREATE TABLE bill_lines (
    id TEXT PRIMARY KEY,
    seq INTEGER NOT NULL,
    bill_id TEXT NOT NULL REFERENCES bills (id),
    menu_item_id TEXT NOT NULL REFERENCES menu_items (id),
    name TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price_minor INTEGER NOT NULL,
    total_minor INTEGER NOT NULL,
    cost_minor INTEGER NOT NULL DEFAULT 0
  );

  CREATE TABLE stock_levels (
    menu_item_id TEXT PRIMARY KEY REFERENCES menu_items (id),
    quantity INTEGER NOT NULL DEFAULT 0,
    average_cost_minor INTEGER NOT NULL DEFAULT 0
  );

  CREATE TABLE stock_movements (
    id TEXT PRIMARY KEY,
    menu_item_id TEXT NOT NULL REFERENCES menu_items (id),
    type TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    unit_cost_minor INTEGER NOT NULL,
    reason TEXT,
    note TEXT,
    bill_id TEXT REFERENCES bills (id),
    created_by TEXT NOT NULL REFERENCES users (id),
    created_at TEXT NOT NULL
  );
  CREATE INDEX stock_movements_item ON stock_movements (menu_item_id, created_at);
  `,
];

export function migrate(db: SQLiteDatabase) {
  const version = db.getFirstSync<{ user_version: number }>('PRAGMA user_version')?.user_version ?? 0;
  for (let step = version; step < STEPS.length; step++) {
    db.withTransactionSync(() => {
      db.execSync(STEPS[step]);
      db.execSync(`PRAGMA user_version = ${step + 1}`);
    });
  }
}
