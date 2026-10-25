import type { SQLiteDatabase } from 'expo-sqlite';

/**
 * The database's tables, one migration a version (PRAGMA user_version).
 * Ids are UUIDs, amounts integers in the currency's minor unit, times ISO
 * 8601 in UTC, days (expiry, issued on) YYYY-MM-DD.
 */
const MIGRATIONS: string[] = [
  `
  CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
  );

  CREATE TABLE sequences (
    name TEXT PRIMARY KEY,
    value INTEGER NOT NULL
  );

  CREATE TABLE users (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    role TEXT NOT NULL CHECK (role IN ('manager', 'pharmacist', 'cashier')),
    password_hash TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );

  CREATE TABLE medicines (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    generic_name TEXT NOT NULL,
    strength TEXT,
    form TEXT NOT NULL,
    unit TEXT NOT NULL,
    barcode TEXT UNIQUE,
    category TEXT,
    storage TEXT NOT NULL DEFAULT 'room',
    prescription_only INTEGER NOT NULL DEFAULT 0,
    controlled INTEGER NOT NULL DEFAULT 0,
    price_minor INTEGER NOT NULL,
    last_unit_cost_minor INTEGER,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    tracks_expiry INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  );
  CREATE INDEX medicines_name ON medicines (name);

  CREATE TABLE packs (
    id TEXT PRIMARY KEY,
    medicine_id TEXT NOT NULL REFERENCES medicines (id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    units INTEGER NOT NULL,
    price_minor INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
  );
  CREATE INDEX packs_medicine ON packs (medicine_id);

  CREATE TABLE suppliers (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );

  CREATE TABLE receipts (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    supplier_id TEXT REFERENCES suppliers (id),
    invoice_number TEXT,
    received_on TEXT NOT NULL,
    note TEXT,
    total_minor INTEGER NOT NULL,
    user_id TEXT NOT NULL REFERENCES users (id),
    created_at TEXT NOT NULL
  );

  CREATE TABLE batches (
    id TEXT PRIMARY KEY,
    medicine_id TEXT NOT NULL REFERENCES medicines (id),
    batch_number TEXT NOT NULL,
    expires_on TEXT,
    quantity INTEGER NOT NULL CHECK (quantity >= 0),
    unit_cost_minor INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'quarantined', 'recalled')),
    hold_reason TEXT,
    receipt_id TEXT REFERENCES receipts (id),
    received_at TEXT NOT NULL
  );
  CREATE INDEX batches_medicine ON batches (medicine_id, expires_on);

  CREATE TABLE receipt_lines (
    id TEXT PRIMARY KEY,
    receipt_id TEXT NOT NULL REFERENCES receipts (id),
    medicine_id TEXT NOT NULL REFERENCES medicines (id),
    batch_id TEXT NOT NULL REFERENCES batches (id),
    quantity INTEGER NOT NULL,
    unit_cost_minor INTEGER NOT NULL
  );
  CREATE INDEX receipt_lines_receipt ON receipt_lines (receipt_id);

  CREATE TABLE stock_movements (
    id TEXT PRIMARY KEY,
    medicine_id TEXT NOT NULL REFERENCES medicines (id),
    batch_id TEXT NOT NULL REFERENCES batches (id),
    type TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    reason TEXT,
    note TEXT,
    reference TEXT,
    user_id TEXT NOT NULL REFERENCES users (id),
    at TEXT NOT NULL
  );
  CREATE INDEX stock_movements_medicine ON stock_movements (medicine_id, at);

  CREATE TABLE tills (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    user_id TEXT NOT NULL REFERENCES users (id),
    status TEXT NOT NULL CHECK (status IN ('open', 'closed')),
    opened_at TEXT NOT NULL,
    closed_at TEXT,
    opening_float_minor INTEGER NOT NULL,
    counted_cash_minor INTEGER,
    closing_note TEXT,
    blind INTEGER NOT NULL DEFAULT 0
  );
  CREATE INDEX tills_user ON tills (user_id, status);

  CREATE TABLE cash_movements (
    id TEXT PRIMARY KEY,
    till_id TEXT NOT NULL REFERENCES tills (id),
    submission_id TEXT NOT NULL UNIQUE,
    direction TEXT NOT NULL CHECK (direction IN ('in', 'out')),
    counterpart TEXT NOT NULL CHECK (counterpart IN ('safe', 'bank', 'expense')),
    amount_minor INTEGER NOT NULL,
    reason TEXT NOT NULL,
    user_id TEXT NOT NULL REFERENCES users (id),
    at TEXT NOT NULL
  );

  CREATE TABLE prescriptions (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    patient_name TEXT NOT NULL,
    patient_age INTEGER,
    prescriber_name TEXT NOT NULL,
    prescriber_registration TEXT,
    issued_on TEXT NOT NULL,
    expires_on TEXT NOT NULL,
    notes TEXT,
    status TEXT NOT NULL,
    recorded_by TEXT NOT NULL REFERENCES users (id),
    verified_by TEXT REFERENCES users (id),
    rejection_reason TEXT,
    created_at TEXT NOT NULL
  );

  CREATE TABLE prescription_items (
    id TEXT PRIMARY KEY,
    prescription_id TEXT NOT NULL REFERENCES prescriptions (id),
    medicine_id TEXT NOT NULL REFERENCES medicines (id),
    name TEXT NOT NULL,
    unit TEXT NOT NULL,
    controlled INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    dispensed INTEGER NOT NULL DEFAULT 0,
    unit_price_minor INTEGER NOT NULL,
    directions TEXT NOT NULL,
    position INTEGER NOT NULL
  );
  CREATE INDEX prescription_items_prescription ON prescription_items (prescription_id);

  CREATE TABLE sales (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    submission_id TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL CHECK (status IN ('completed', 'voided')),
    sold_at TEXT NOT NULL,
    user_id TEXT NOT NULL REFERENCES users (id),
    till_id TEXT REFERENCES tills (id),
    customer_name TEXT,
    customer_phone TEXT,
    prescription_id TEXT REFERENCES prescriptions (id),
    total_minor INTEGER NOT NULL,
    discount_minor INTEGER NOT NULL,
    payment_method TEXT NOT NULL,
    payment_reference TEXT,
    amount_tendered_minor INTEGER,
    change_minor INTEGER,
    voided_at TEXT,
    voided_by TEXT REFERENCES users (id),
    void_reason TEXT,
    copies INTEGER NOT NULL DEFAULT 0
  );
  CREATE INDEX sales_sold_at ON sales (sold_at);
  CREATE INDEX sales_till ON sales (till_id);

  -- As printed: one per medicine sold, by the pack or the unit.
  CREATE TABLE sale_items (
    id TEXT PRIMARY KEY,
    sale_id TEXT NOT NULL REFERENCES sales (id),
    position INTEGER NOT NULL,
    medicine_id TEXT NOT NULL REFERENCES medicines (id),
    name TEXT NOT NULL,
    pack TEXT,
    quantity INTEGER NOT NULL,
    unit_price_minor INTEGER NOT NULL,
    discount_minor INTEGER NOT NULL,
    line_total_minor INTEGER NOT NULL
  );
  CREATE INDEX sale_items_sale ON sale_items (sale_id);

  -- Batch by batch, in units: what stock and returns count in.
  CREATE TABLE sale_lines (
    id TEXT PRIMARY KEY,
    sale_id TEXT NOT NULL REFERENCES sales (id),
    medicine_id TEXT NOT NULL REFERENCES medicines (id),
    batch_id TEXT NOT NULL REFERENCES batches (id),
    name TEXT NOT NULL,
    batch_number TEXT NOT NULL,
    expires_on TEXT,
    quantity INTEGER NOT NULL,
    returned INTEGER NOT NULL DEFAULT 0,
    unit_cost_minor INTEGER NOT NULL,
    line_total_minor INTEGER NOT NULL,
    position INTEGER NOT NULL
  );
  CREATE INDEX sale_lines_sale ON sale_lines (sale_id);

  CREATE TABLE sale_returns (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    sale_id TEXT NOT NULL REFERENCES sales (id),
    returned_at TEXT NOT NULL,
    reason TEXT NOT NULL,
    total_minor INTEGER NOT NULL,
    user_id TEXT NOT NULL REFERENCES users (id)
  );
  CREATE INDEX sale_returns_sale ON sale_returns (sale_id);

  CREATE TABLE sale_return_lines (
    id TEXT PRIMARY KEY,
    return_id TEXT NOT NULL REFERENCES sale_returns (id),
    sale_line_id TEXT NOT NULL REFERENCES sale_lines (id),
    quantity INTEGER NOT NULL,
    restock INTEGER NOT NULL,
    amount_minor INTEGER NOT NULL
  );
  `,
  // Pricing by the batch: what it was bought for (in the pack and currency of
  // the supplier's invoice) and what it sells for. A batch without its own
  // selling price sells at the medicine's.
  `
  ALTER TABLE batches ADD COLUMN sell_price_minor INTEGER;
  ALTER TABLE batches ADD COLUMN buy_unit TEXT;
  ALTER TABLE batches ADD COLUMN buy_unit_size INTEGER NOT NULL DEFAULT 1;
  ALTER TABLE batches ADD COLUMN buy_price_minor INTEGER;
  ALTER TABLE batches ADD COLUMN buy_currency TEXT;
  ALTER TABLE batches ADD COLUMN exchange_rate REAL;

  ALTER TABLE receipt_lines ADD COLUMN buy_unit TEXT;
  ALTER TABLE receipt_lines ADD COLUMN buy_unit_size INTEGER NOT NULL DEFAULT 1;
  ALTER TABLE receipt_lines ADD COLUMN buy_quantity INTEGER;
  ALTER TABLE receipt_lines ADD COLUMN buy_price_minor INTEGER;
  ALTER TABLE receipt_lines ADD COLUMN buy_currency TEXT;
  ALTER TABLE receipt_lines ADD COLUMN exchange_rate REAL;
  ALTER TABLE receipt_lines ADD COLUMN cost_minor INTEGER;
  ALTER TABLE receipt_lines ADD COLUMN sell_price_minor INTEGER;
  UPDATE receipt_lines SET buy_quantity = quantity, buy_price_minor = unit_cost_minor, cost_minor = quantity * unit_cost_minor;
  UPDATE batches SET buy_price_minor = unit_cost_minor;
  `,
  // Expenses, by category. Operating costs, recurring bills (due again after
  // their frequency) and one-off spending; a category paid from the till is
  // paid out of a shift's cash drawer, and counts against it.
  `
  CREATE TABLE expense_categories (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    type TEXT NOT NULL CHECK (type IN ('operating', 'recurring', 'one_off')),
    frequency TEXT CHECK (frequency IN ('weekly', 'monthly', 'quarterly', 'yearly')),
    usual_amount_minor INTEGER,
    from_till INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  );

  CREATE TABLE expenses (
    id TEXT PRIMARY KEY,
    number TEXT NOT NULL UNIQUE,
    category_id TEXT NOT NULL REFERENCES expense_categories (id),
    amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
    spent_on TEXT NOT NULL,
    paid_from TEXT NOT NULL CHECK (paid_from IN ('till', 'cash', 'bank', 'mobile_money')),
    till_id TEXT REFERENCES tills (id),
    cash_movement_id TEXT REFERENCES cash_movements (id),
    payee TEXT,
    reference TEXT,
    note TEXT,
    user_id TEXT NOT NULL REFERENCES users (id),
    created_at TEXT NOT NULL,
    voided_at TEXT,
    voided_by TEXT REFERENCES users (id),
    void_reason TEXT
  );
  CREATE INDEX expenses_spent_on ON expenses (spent_on);
  CREATE INDEX expenses_category ON expenses (category_id, spent_on);

  INSERT INTO expense_categories (id, name, type, frequency, from_till, created_at) VALUES
    (lower(hex(randomblob(16))), 'Transport', 'operating', NULL, 1, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Cleaning and supplies', 'operating', NULL, 1, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Generator fuel', 'operating', NULL, 1, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Rent', 'recurring', 'monthly', 0, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Salaries', 'recurring', 'monthly', 0, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Electricity and water', 'recurring', 'monthly', 0, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Licences and permits', 'recurring', 'yearly', 0, strftime('%Y-%m-%dT%H:%M:%fZ')),
    (lower(hex(randomblob(16))), 'Repairs and equipment', 'one_off', NULL, 0, strftime('%Y-%m-%dT%H:%M:%fZ'));
  `,
];

export const SCHEMA_VERSION = MIGRATIONS.length;

export function migrate(db: SQLiteDatabase) {
  const { user_version: version } = db.getFirstSync<{ user_version: number }>('PRAGMA user_version')!;
  if (version > SCHEMA_VERSION) {
    throw new Error('This database was made by a newer version of Miles Pharmacy. Update the app to open it.');
  }
  for (let next = version; next < SCHEMA_VERSION; next++) {
    db.execSync('BEGIN IMMEDIATE');
    try {
      db.execSync(MIGRATIONS[next]);
      db.execSync(`PRAGMA user_version = ${next + 1}`);
      db.execSync('COMMIT');
    } catch (error) {
      db.execSync('ROLLBACK');
      throw error;
    }
  }
}
