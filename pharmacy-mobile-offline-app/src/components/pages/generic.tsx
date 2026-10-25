import { Text, View } from 'react-native';

import { Card, EmptyState, SectionTitle } from '@/components/ui/misc';
import { openPage } from '@/hooks/use-page';

import { Details, formatValue, humanize, ListCard, PageScroll, StatusChip } from './kit';
import { recordPath, type ScreenProps } from './types';

/** What every page carries, and props that are for the web's forms only. */
const SKIP = new Set(['access', 'branch', 'branchPermissions', 'branches', 'canSwitchBranch', 'currency', 'errors', 'flash', 'product', 'user', 'workspace']);

const TITLE_KEYS = ['label', 'name', 'item', 'title', 'patientName', 'medicine', 'supplier', 'number'];
const HIDDEN_KEYS = new Set(['id', 'status', 'logo']);

type Row = Record<string, unknown>;

function titleOf(row: Row): [string, string | null] {
  for (const key of TITLE_KEYS) {
    if (typeof row[key] === 'string' && row[key]) return [row[key] as string, key];
  }
  return ['—', null];
}

/** A row's other plain values, the first few, as "Label: value". */
function summary(row: Row, skip: (string | null)[]): string {
  return Object.entries(row)
    .filter(([k, v]) => !HIDDEN_KEYS.has(k) && !skip.includes(k) && (v === null || typeof v !== 'object') && !/^can[A-Z]/.test(k))
    .filter(([, v]) => v !== null && v !== '' && v !== false)
    .slice(0, 4)
    .map(([k, v]) => `${humanize(k)}: ${formatValue(k, v)}`)
    .join(' · ');
}

/**
 * A page that has no screen of its own in the app: its data as cards and
 * details, records opening as pages where the web has them, and the page on
 * the web for making changes.
 */
export function GenericScreen({ page, path, reload, loading }: ScreenProps) {
  const entries = Object.entries(page.props).filter(([key, value]) => !SKIP.has(key) && !/^can[A-Z]/.test(key) && typeof value === 'object' && value !== null);

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      {entries.length === 0 && <EmptyState icon="document-outline" title="Nothing to show here" />}
      {entries.map(([key, value]) => (
        <View key={key}>
          <SectionTitle>{humanize(key)}</SectionTitle>
          <Value value={value} path={path} />
        </View>
      ))}
      <View className="gap-2">
        <Text className="text-center text-sm text-slate-500">To add or change things here, use the pharmacy on the web.</Text>
      </View>
    </PageScroll>
  );
}

function Value({ value, path }: { value: unknown; path: string }) {
  if (Array.isArray(value)) {
    if (value.length === 0) return <Text className="px-1 text-base text-slate-500">None.</Text>;
    if (typeof value[0] !== 'object' || value[0] === null) return <Text className="px-1 text-base text-slate-700">{value.join(', ')}</Text>;
    return (
      <View className="gap-2">
        {(value as Row[]).map((row, i) => {
          const [title, titleKey] = titleOf(row);
          const id = typeof row.id === 'string' ? row.id : null;
          const target = id ? recordPath(path, id) : null;
          const number = titleKey !== 'number' && typeof row.number === 'string' ? row.number : null;
          return (
            <ListCard
              key={id ?? i}
              title={title}
              subtitle={summary(row, [titleKey, 'number'])}
              meta={number}
              right={typeof row.status === 'string' ? <StatusChip status={row.status} /> : undefined}
              onPress={target ? () => openPage(target) : undefined}
            />
          );
        })}
      </View>
    );
  }

  const rows = Object.entries(value as Row)
    .filter(([k, v]) => !HIDDEN_KEYS.has(k) && (v === null || typeof v !== 'object'))
    .map(([k, v]) => [humanize(k), formatValue(k, v)] as [string, string]);
  return (
    <Card>
      <Details rows={rows} />
    </Card>
  );
}
