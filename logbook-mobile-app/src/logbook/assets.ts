import type { Occurrence } from '@/logbook/work';
import type { FieldDef, Kit, KitResource, Values } from '@/logbook/types';

/*
 * ADR-0031 on the phone. A sheet names its main asset in one resource
 * field (`subject`). Once a manager links assets to the sheet, only those
 * are offered there; with none linked, any active asset of the field's
 * category is. The kit holds active assets only, so an asset retired or
 * unlinked since a draft was written is simply missing from it: the draft
 * keeps its choice, says so, and is never moved to another asset.
 */

/** The resource field naming the entry's asset, when the kit says. */
export function subjectOf(kit: Kit | null, sheetId: string): string | null {
  return kit?.sheets.find((s) => s.id === sheetId)?.subject ?? null;
}

/** The assets a resource field offers on this sheet. */
export function offered(kit: Kit | null, sheetId: string, field: FieldDef): KitResource[] {
  const sheet = kit?.sheets.find((s) => s.id === sheetId);
  const linked = field.name === sheet?.subject ? (sheet?.assets ?? []) : [];
  return (kit?.resources ?? []).filter(
    (r) => (!field.resource_type || r.type.toLowerCase() === field.resource_type.toLowerCase()) && (linked.length === 0 || linked.includes(r.id)),
  );
}

/**
 * Why the draft's asset can no longer be sent from this phone, or null.
 * Only checked once the kit has loaded and the sheet is in it.
 */
export function assetProblem(kit: Kit | null, sheetId: string, fields: FieldDef[], values: Values): string | null {
  const subject = subjectOf(kit, sheetId);
  if (kit === null || subject === null) return null;
  const chosen = values[subject];
  if (typeof chosen !== 'string' || chosen === '') return null;
  const field = fields.find((f) => f.name === subject);
  if (!field) return null;
  if (!(kit.resources ?? []).some((r) => r.id === chosen)) {
    return 'The asset this entry names is no longer active (retired or out of service). Choose another, or ask a manager.';
  }
  if (!offered(kit, sheetId, field).some((r) => r.id === chosen)) {
    return 'The asset this entry names is no longer linked to this log sheet. Choose one of its assets, or ask a manager.';
  }
  return null;
}

/**
 * The expected readings this entry can answer: those of the chosen asset
 * and those for the whole sheet. With no asset chosen yet, all of them.
 */
export function readingsForAsset(choices: Occurrence[], assetId: string | null, keep: string | null = null): Occurrence[] {
  if (!assetId) return choices;
  return choices.filter((o) => o.id === keep || !o.resourceId || o.resourceId === assetId);
}
