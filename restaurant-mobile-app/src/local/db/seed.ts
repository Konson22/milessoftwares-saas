import { run } from './database';
import { settings } from './settings';
import { now, uuid } from './util';

/** A menu, tables and drinks in stock to try the restaurant with ("Add a sample menu" on set-up). */

const DISHES: [string, string, number][] = [
  ['Starters', 'Vegetable soup', 6],
  ['Starters', 'Samosas (3)', 4],
  ['Mains', 'Grilled chicken and chips', 14],
  ['Mains', 'Beef stew and rice', 12],
  ['Mains', 'Fish fillet and ugali', 15],
  ['Mains', 'Vegetable curry', 10],
  ['Desserts', 'Fruit salad', 5],
];

const DRINKS: [string, string, number, number, number][] = [
  // section, name, price, cost, in stock
  ['Soft drinks', 'Soda 300 ml', 2, 1, 48],
  ['Soft drinks', 'Water 500 ml', 1.5, 0.6, 60],
  ['Soft drinks', 'Fresh juice', 3, 1.2, 20],
  ['Hot drinks', 'Tea', 1.5, 0.4, 100],
  ['Hot drinks', 'Coffee', 2.5, 0.8, 80],
  ['Beer', 'Lager 500 ml', 4, 2.2, 36],
];

const TABLES: [string, string, number][] = [
  ['Inside', 'T1', 2],
  ['Inside', 'T2', 4],
  ['Inside', 'T3', 4],
  ['Inside', 'T4', 6],
  ['Terrace', 'P1', 4],
  ['Terrace', 'P2', 4],
];

export function seedSample(by: string) {
  const { decimals } = settings();
  const minor = (major: number) => Math.round(major * 10 ** decimals);
  const at = now();

  for (const [section, name, price] of DISHES) {
    run(
      "INSERT INTO menu_items (id, kind, name, section, unit, price_minor, reorder_level, active, created_at) VALUES (?, 'dish', ?, ?, 'plate', ?, 0, 1, ?)",
      uuid(),
      name,
      section,
      minor(price),
      at,
    );
  }

  for (const [section, name, price, cost, quantity] of DRINKS) {
    const id = uuid();
    const unit = section === 'Hot drinks' ? 'cup' : section === 'Soft drinks' && name.startsWith('Fresh') ? 'glass' : 'bottle';
    run(
      "INSERT INTO menu_items (id, kind, name, section, unit, price_minor, reorder_level, active, created_at) VALUES (?, 'drink', ?, ?, ?, ?, 10, 1, ?)",
      id,
      name,
      section,
      unit,
      minor(price),
      at,
    );
    run('INSERT INTO stock_levels (menu_item_id, quantity, average_cost_minor) VALUES (?, ?, ?)', id, quantity, minor(cost));
    run(
      "INSERT INTO stock_movements (id, menu_item_id, type, quantity, unit_cost_minor, note, created_by, created_at) VALUES (?, ?, 'opening', ?, ?, 'Sample stock', ?, ?)",
      uuid(),
      id,
      quantity,
      minor(cost),
      by,
      at,
    );
  }

  for (const [area, name, seats] of TABLES) {
    run('INSERT INTO dining_tables (id, name, area, seats, active) VALUES (?, ?, ?, ?, 1)', uuid(), name, area, seats);
  }
}
