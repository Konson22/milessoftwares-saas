import { useEffect, useMemo, useState } from 'react';
import { FlatList, RefreshControl, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { EntryRow, SearchBox, Segments } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { EmptyState } from '@/components/ui/misc';
import { buckets } from '@/logbook/sync';
import type { Item, StoredVersion } from '@/logbook/types';
import { store, useLogbook } from '@/store/logbook';

type Filter = 'attention' | 'drafts' | 'sending' | 'review' | 'done' | 'all';

/** The person's own entries on this phone, by what they need. */
export default function EntriesScreen() {
  const insets = useSafeAreaInsets();
  const items = useLogbook((s) => s.items);
  const scope = useLogbook((s) => s.scope);
  const syncing = useLogbook((s) => s.syncing);
  const refresh = useLogbook((s) => s.refresh);
  const [filter, setFilter] = useState<Filter>('attention');
  const [q, setQ] = useState('');
  const [versions, setVersions] = useState<Record<string, StoredVersion>>({});

  useEffect(() => {
    if (!scope) return;
    let live = true;
    const ids = [...new Set(items.map((i) => i.versionId))].filter((id) => !versions[id]);
    void Promise.all(ids.map((id) => store.version(scope, id))).then((found) => {
      if (!live) return;
      const add: Record<string, StoredVersion> = {};
      for (const v of found) if (v) add[v.id] = v;
      if (Object.keys(add).length) setVersions((old) => ({ ...old, ...add }));
    });
    return () => {
      live = false;
    };
  }, [items, scope, versions]);

  const b = buckets(items);
  const shown = useMemo(() => {
    const pick: Record<Filter, Item[]> = {
      attention: [...b.returned, ...b.refused],
      drafts: b.drafts,
      sending: b.waiting,
      review: b.inReview,
      done: b.done,
      all: items,
    };
    const term = q.trim().toLowerCase();
    return pick[filter]
      .filter((i) => !term || `${i.reference ?? ''} ${i.sheet.name} ${i.sheet.code} ${i.logbook}`.toLowerCase().includes(term))
      .sort((x, y) => y.occurredAt.localeCompare(x.occurredAt));
  }, [b.done, b.drafts, b.inReview, b.refused, b.returned, b.waiting, filter, items, q]);

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader menu title="My entries" subtitle={`${items.length} on this phone`} />
      <FlatList
        data={shown}
        keyExtractor={(i) => i.id}
        renderItem={({ item }) => <EntryRow item={item} version={versions[item.versionId]} />}
        contentContainerStyle={{ padding: 16, gap: 8, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}
        ListHeaderComponent={
          <View className="gap-3 pb-2">
            <SearchBox value={q} onChange={setQ} placeholder="Search reference or sheet" />
            <Segments
              value={filter}
              onChange={setFilter}
              options={[
                { value: 'attention', label: `Needs you (${b.returned.length + b.refused.length})` },
                { value: 'drafts', label: `Drafts (${b.drafts.length})` },
                { value: 'sending', label: `Sending (${b.waiting.length})` },
                { value: 'review', label: `In review (${b.inReview.length})` },
                { value: 'done', label: `Done (${b.done.length})` },
                { value: 'all', label: 'All' },
              ]}
            />
          </View>
        }
        ListEmptyComponent={<EmptyState icon="file-tray-outline" title="Nothing here" message={filter === 'attention' ? 'No entry is waiting on you.' : 'No entries in this list.'} />}
      />
    </View>
  );
}
