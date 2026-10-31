import { run } from './database';
import { now, today, uuid } from './util';

/**
 * Sample products with stock, offered at first run to try the app. Prices
 * are in major units (SSP 35.00). Remove them later by switching the
 * products off, or start again from a fresh install. Food and drink track
 * their expiry; the rest do not.
 */

interface Sample {
  name: string;
  unit: string;
  barcode: string;
  price: number;
  category: string;
  expiry?: boolean;
  reorder?: number;
  packs?: { name: string; units: number; price: number }[];
  /** expiresIn: days from today; ignored without `expiry`. */
  lots: { number: string; quantity: number; expiresIn?: number; cost: number }[];
}

const SAMPLES: Sample[] = [
  {
    name: 'Mineral Water 500ml', unit: 'bottle', barcode: '6009876500011', price: 500, category: 'Drinks', expiry: true, reorder: 48,
    packs: [{ name: 'Crate', units: 24, price: 10800 }],
    lots: [{ number: 'L-0811', quantity: 30, expiresIn: 20, cost: 300 }, { number: 'L-0902', quantity: 96, expiresIn: 300, cost: 320 }],
  },
  {
    name: 'Cola 330ml can', unit: 'can', barcode: '6009876500028', price: 1000, category: 'Drinks', expiry: true, reorder: 24,
    packs: [{ name: 'Tray', units: 24, price: 22000 }],
    lots: [{ number: 'C-77', quantity: 72, expiresIn: 200, cost: 700 }],
  },
  { name: 'Rice 5kg', unit: 'bag', barcode: '6009876500035', price: 9500, category: 'Groceries', expiry: true, reorder: 10, lots: [{ number: 'R-12', quantity: 25, expiresIn: 400, cost: 7800 }] },
  { name: 'Sugar 2kg', unit: 'bag', barcode: '6009876500042', price: 4200, category: 'Groceries', expiry: true, reorder: 10, lots: [{ number: 'S-3', quantity: 8, expiresIn: 500, cost: 3500 }] },
  { name: 'Cooking Oil 1L', unit: 'bottle', barcode: '6009876500059', price: 3800, category: 'Groceries', expiry: true, reorder: 12, lots: [{ number: 'O-41', quantity: 40, expiresIn: 250, cost: 3000 }] },
  { name: 'Milk Powder 400g', unit: 'tin', barcode: '6009876500066', price: 6500, category: 'Groceries', expiry: true, lots: [{ number: 'M-5', quantity: 6, expiresIn: -5, cost: 5000 }] },
  {
    name: 'Bath Soap', unit: 'bar', barcode: '6009876500073', price: 800, category: 'Household', reorder: 20,
    packs: [{ name: 'Box', units: 12, price: 9000 }],
    lots: [{ number: 'B-1', quantity: 60, cost: 500 }],
  },
  { name: 'Washing Powder 1kg', unit: 'packet', barcode: '6009876500080', price: 3500, category: 'Household', reorder: 10, lots: [{ number: 'W-9', quantity: 24, cost: 2600 }] },
  { name: 'AA Batteries (2)', unit: 'pack', barcode: '6009876500097', price: 1500, category: 'Electrical', lots: [{ number: 'E-20', quantity: 30, cost: 900 }] },
  { name: 'Exercise Book 96pg', unit: 'book', barcode: '6009876500103', price: 400, category: 'Stationery', reorder: 50, lots: [{ number: 'X-2', quantity: 200, cost: 220 }] },
  { name: 'Phone Charger', unit: 'piece', barcode: '6009876500110', price: 5000, category: 'Electrical', reorder: 5, lots: [] },
];

export function seedDemo(userId: string, decimals: number) {
  const minor = (major: number) => Math.round(major * 10 ** decimals);
  const at = now();

  for (const s of SAMPLES) {
    const id = uuid();
    run(
      `INSERT INTO products (id, name, unit, barcode, category, price_minor, last_unit_cost_minor, reorder_level, tracks_expiry, active, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)`,
      id, s.name, s.unit, s.barcode, s.category, minor(s.price), s.lots[0] ? minor(s.lots[0].cost) : null, s.reorder ?? 10, s.expiry ? 1 : 0, at, at,
    );
    (s.packs ?? []).forEach((p, i) => run('INSERT INTO packs (id, product_id, name, units, price_minor, position) VALUES (?, ?, ?, ?, ?, ?)', uuid(), id, p.name, p.units, minor(p.price), i));
    for (const lot of s.lots) {
      const batchId = uuid();
      run(
        'INSERT INTO batches (id, product_id, batch_number, expires_on, quantity, unit_cost_minor, status, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        batchId, id, lot.number, s.expiry && lot.expiresIn !== undefined ? today(lot.expiresIn) : null, lot.quantity, minor(lot.cost), 'available', at,
      );
      run(
        'INSERT INTO stock_movements (id, product_id, batch_id, type, quantity, reason, note, reference, user_id, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        uuid(), id, batchId, 'opening', lot.quantity, 'Sample stock', null, null, userId, at,
      );
    }
  }

  run('INSERT INTO suppliers (id, name, phone, email, address, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', uuid(), 'Juba Wholesale Traders', '+211 920 000 001', null, 'Konyo Konyo, Juba', at);

  // Customers: two buy on account (one up to a limit), one only gets quotations.
  const customers: [string, string, boolean, number | null][] = [
    ['Sunrise Hotel', '+211 920 000 101', true, 500000],
    ['St. Mary’s School', '+211 920 000 102', true, null],
    ['Daniel Ladu', '+211 920 000 103', false, null],
  ];
  customers.forEach(([name, phone, onAccount, limit], i) =>
    run(
      'INSERT INTO customers (id, number, name, phone, on_account, credit_limit_minor, active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
      uuid(),
      `C-${String(i + 1).padStart(5, '0')}`,
      name,
      phone,
      onAccount ? 1 : 0,
      limit === null ? null : minor(limit),
      at,
    ),
  );
  run("INSERT INTO sequences (name, value) VALUES ('customer', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", customers.length);
}
