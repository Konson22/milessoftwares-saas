/** Currencies a restaurant here prices in: code, minor-unit digits, name. */
export const CURRENCIES = [
  ['SSP', 2, 'South Sudanese pound'],
  ['USD', 2, 'US dollar'],
  ['KES', 2, 'Kenyan shilling'],
  ['UGX', 0, 'Ugandan shilling'],
  ['TZS', 2, 'Tanzanian shilling'],
  ['RWF', 0, 'Rwandan franc'],
  ['ETB', 2, 'Ethiopian birr'],
  ['SDG', 2, 'Sudanese pound'],
  ['EUR', 2, 'Euro'],
] as const;

export const decimalsFor = (code: string): number => CURRENCIES.find(([c]) => c === code)?.[1] ?? 2;
