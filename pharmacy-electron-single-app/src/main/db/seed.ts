import { run } from './database';
import { now, today, uuid } from './util';

/**
 * Sample medicines with stock, offered at first run to try the app. Prices
 * are in major units (SSP 35.00). Remove them later by switching the
 * medicines off, or start again from a fresh install.
 */

interface Sample {
  name: string;
  generic: string;
  strength: string;
  form: string;
  unit: string;
  barcode: string;
  price: number;
  category?: string;
  storage?: string;
  rx?: boolean;
  controlled?: boolean;
  reorder?: number;
  packs?: { name: string; units: number; price: number }[];
  batches: { number: string; quantity: number; expiresIn: number; cost: number }[];
}

const SAMPLES: Sample[] = [
  {
    name: 'Panadol Extra', generic: 'Paracetamol + Caffeine', strength: '500mg/65mg', form: 'tablet', unit: 'tablet', barcode: '6001234500011', price: 35, reorder: 50,
    packs: [{ name: 'Strip', units: 10, price: 320 }],
    batches: [{ number: 'PX-2207', quantity: 12, expiresIn: 45, cost: 20 }, { number: 'PX-2311', quantity: 140, expiresIn: 400, cost: 22 }],
  },
  { name: 'Amoxil', generic: 'Amoxicillin', strength: '500mg', form: 'capsule', unit: 'capsule', barcode: '6001234500028', price: 82, rx: true, reorder: 30, batches: [{ number: 'AM-118', quantity: 60, expiresIn: 300, cost: 50 }] },
  {
    name: 'Brufen', generic: 'Ibuprofen', strength: '400mg', form: 'tablet', unit: 'tablet', barcode: '6001234500042', price: 28, reorder: 30,
    packs: [{ name: 'Box', units: 30, price: 780 }],
    batches: [{ number: 'BR-77', quantity: 90, expiresIn: 500, cost: 15 }],
  },
  { name: 'Tramal', generic: 'Tramadol', strength: '50mg', form: 'capsule', unit: 'capsule', barcode: '6001234500059', price: 95, rx: true, controlled: true, batches: [{ number: 'TR-12', quantity: 30, expiresIn: 250, cost: 60 }] },
  { name: 'Piriton', generic: 'Chlorphenamine', strength: '4mg', form: 'tablet', unit: 'tablet', barcode: '6001234500066', price: 12, batches: [{ number: 'PI-40', quantity: 100, expiresIn: 700, cost: 6 }] },
  { name: 'Ventolin Inhaler', generic: 'Salbutamol', strength: '100mcg', form: 'inhaler', unit: 'inhaler', barcode: '6001234500073', price: 1200, rx: true, storage: 'below_30', reorder: 5, batches: [{ number: 'VE-9', quantity: 6, expiresIn: 20, cost: 800 }] },
  {
    name: 'ORS Sachets', generic: 'Oral Rehydration Salts', strength: '20.5g', form: 'powder', unit: 'sachet', barcode: '6001234500080', price: 6,
    packs: [{ name: 'Box', units: 20, price: 110 }],
    batches: [{ number: 'OR-301', quantity: 200, expiresIn: 900, cost: 3 }],
  },
  { name: 'Cetirizine', generic: 'Cetirizine', strength: '10mg', form: 'tablet', unit: 'tablet', barcode: '6001234500097', price: 15, batches: [{ number: 'CE-5', quantity: 10, expiresIn: -10, cost: 8 }] },
  { name: 'Vitamin C', generic: 'Ascorbic Acid', strength: '1000mg', form: 'effervescent', unit: 'tube', barcode: '6001234500103', price: 540, category: 'Supplements', batches: [{ number: 'VC-88', quantity: 30, expiresIn: 365, cost: 350 }] },
  { name: 'Flagyl', generic: 'Metronidazole', strength: '400mg', form: 'tablet', unit: 'tablet', barcode: '6001234500110', price: 30, rx: true, reorder: 20, batches: [] },
  { name: 'Actrapid', generic: 'Insulin (human)', strength: '100IU/ml', form: 'injection', unit: 'vial', barcode: '6001234500127', price: 4500, rx: true, storage: 'fridge', batches: [{ number: 'AC-3', quantity: 8, expiresIn: 120, cost: 3200 }] },
];

export function seedDemo(userId: string, decimals: number) {
  const minor = (major: number) => Math.round(major * 10 ** decimals);
  const at = now();

  for (const s of SAMPLES) {
    const id = uuid();
    run(
      `INSERT INTO medicines (id, name, generic_name, strength, form, unit, barcode, category, storage, prescription_only, controlled,
        price_minor, last_unit_cost_minor, reorder_level, tracks_expiry, active, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)`,
      id, s.name, s.generic, s.strength, s.form, s.unit, s.barcode, s.category ?? 'Medicines', s.storage ?? 'room',
      s.rx || s.controlled ? 1 : 0, s.controlled ? 1 : 0, minor(s.price), s.batches[0] ? minor(s.batches[0].cost) : null, s.reorder ?? 10, at, at,
    );
    (s.packs ?? []).forEach((p, i) => run('INSERT INTO packs (id, medicine_id, name, units, price_minor, position) VALUES (?, ?, ?, ?, ?, ?)', uuid(), id, p.name, p.units, minor(p.price), i));
    for (const b of s.batches) {
      const batchId = uuid();
      run(
        'INSERT INTO batches (id, medicine_id, batch_number, expires_on, quantity, unit_cost_minor, status, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        batchId, id, b.number, today(b.expiresIn), b.quantity, minor(b.cost), 'available', at,
      );
      run(
        'INSERT INTO stock_movements (id, medicine_id, batch_id, type, quantity, reason, note, reference, user_id, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        uuid(), id, batchId, 'opening', b.quantity, 'Sample stock', null, null, userId, at,
      );
    }
  }

  run('INSERT INTO suppliers (id, name, phone, email, address, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', uuid(), 'Juba Medical Supplies', '+211 920 000 001', null, 'Konyo Konyo, Juba', at);
}
