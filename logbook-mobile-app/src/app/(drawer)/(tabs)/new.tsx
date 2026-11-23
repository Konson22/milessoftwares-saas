import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, RefreshControl, SectionList, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { SearchBox } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { Banner, EmptyState, groupedRow, IconTile } from '@/components/ui/misc';
import { colors } from '@/lib/theme';
import type { KitSheet } from '@/logbook/types';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';

/** The log sheets this person writes on, by logbook. Templates are made and changed on the web. */
export default function NewEntryScreen() {
  const insets = useSafeAreaInsets();
  const kit = useLogbook((s) => s.kit);
  const downloaded = useLogbook((s) => s.downloaded);
  const downloading = useLogbook((s) => s.downloading);
  const kitError = useLogbook((s) => s.kitError);
  const downloadSheets = useLogbook((s) => s.downloadSheets);
  const start = useLogbook((s) => s.start);
  const [q, setQ] = useState('');
  const [opening, setOpening] = useState<string | null>(null);

  const sections = useMemo(() => {
    const term = q.trim().toLowerCase();
    const groups = new Map<string, KitSheet[]>();
    for (const sheet of kit?.sheets ?? []) {
      if (term && !`${sheet.name} ${sheet.code} ${sheet.logbook.name}`.toLowerCase().includes(term)) continue;
      groups.set(sheet.logbook.name, [...(groups.get(sheet.logbook.name) ?? []), sheet]);
    }
    return [...groups.entries()].map(([title, data]) => ({ title, data }));
  }, [kit, q]);

  const open = (sheet: KitSheet) => {
    setOpening(sheet.id);
    start(sheet)
      .then((item) => router.push({ pathname: '/entry/[id]', params: { id: item.id } }))
      .catch((e: unknown) => toast(e instanceof Error ? e.message : 'Could not start the entry.', 'error'))
      .finally(() => setOpening(null));
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader menu title="New entry" subtitle="Choose a log sheet" />
      <SectionList
        sections={sections}
        keyExtractor={(s) => s.id}
        stickySectionHeadersEnabled={false}
        contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={downloading} onRefresh={() => void downloadSheets()} />}
        ListHeaderComponent={
          <View className="gap-3 pb-2">
            {kitError && !kit && <Banner tone="warning">The log sheets could not be downloaded. Connect, then pull down to try again.</Banner>}
            <SearchBox value={q} onChange={setQ} placeholder="Search log sheets" />
          </View>
        }
        renderSectionHeader={({ section }) => (
          <View className="flex-row items-center gap-2 px-1 pb-2 pt-4">
            <Ionicons name="book" size={16} color={colors.brand[600]} />
            <Text className="flex-1 text-sm font-bold uppercase tracking-wide text-slate-600">{section.title}</Text>
            <Text className="text-xs font-semibold text-slate-400">{section.data.length}</Text>
          </View>
        )}
        renderItem={({ item: sheet, index, section }) => {
          const ready = !!sheet.version.id && downloaded.includes(sheet.version.id);
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{ disabled: !ready || opening !== null }}
              disabled={!ready || opening !== null}
              onPress={() => open(sheet)}
              className={`min-h-16 flex-row items-center gap-3 px-4 py-3 bg-white active:bg-slate-50 ${groupedRow(index, section.data.length)}`}>
              <IconTile icon="document-text" color={ready ? 'blue' : 'slate'} size={40} />
              <View className="flex-1 gap-0.5">
                <Text className="text-[15px] font-semibold text-slate-900">{sheet.name}</Text>
                <Text className="text-xs text-slate-500">
                  {sheet.code} · version {sheet.version.version ?? '?'}
                  {sheet.reviewRequired ? ' · reviewed' : ''}
                  {ready ? '' : ' · not on this phone yet'}
                </Text>
                {sheet.description ? (
                  <Text className="text-sm text-slate-600" numberOfLines={2}>
                    {sheet.description}
                  </Text>
                ) : null}
              </View>
              <View className={`h-9 w-9 items-center justify-center rounded-full ${ready ? 'bg-brand-600' : 'bg-slate-100'}`}>
                <Ionicons name={opening === sheet.id ? 'hourglass-outline' : ready ? 'add' : 'cloud-download-outline'} size={20} color={ready ? colors.white : colors.faint} />
              </View>
            </Pressable>
          );
        }}
        ListEmptyComponent={
          <EmptyState
            icon="document-text-outline"
            title={kit ? 'No log sheets for you' : 'No log sheets on this phone'}
            message={kit ? 'An administrator gives you sheets to write on, on the web.' : 'Connect to download the log sheets you write on.'}
          />
        }
      />
    </View>
  );
}
