import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, SectionList, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { RecordRow, SearchBox, Segments } from '@/components/records';
import { ScreenHeader } from '@/components/screen-header';
import { Badge, EmptyState } from '@/components/ui/misc';
import { buckets, purposeLabel } from '@/collect/overview';
import type { Assignment, Item } from '@/collect/types';
import { useStartRecord } from '@/hooks/use-start';
import { colors } from '@/lib/theme';
import { useCollect } from '@/store/collect';

type Show = 'forms' | 'drafts' | 'submitted' | 'attention';

/**
 * The forms this person may fill in (from their assignments), by programme,
 * with what is on the phone: drafts, uploaded records, and records that
 * need them.
 */
export default function FormsScreen() {
  const insets = useSafeAreaInsets();
  const params = useLocalSearchParams<{ show?: Show; form?: string }>();
  const kit = useCollect((s) => s.kit);
  const items = useCollect((s) => s.items);
  const downloaded = useCollect((s) => s.downloaded);
  const syncing = useCollect((s) => s.syncing);
  const refresh = useCollect((s) => s.refresh);
  const [show, setShow] = useState<Show>('forms');
  const [formId, setFormId] = useState<string | null>(null);
  const [query, setQuery] = useState('');

  // Opened from Home or a task: start on the matching list (once per link).
  const link = `${params.show ?? ''}|${params.form ?? ''}`;
  const [seenLink, setSeenLink] = useState('|');
  if (link !== seenLink) {
    setSeenLink(link);
    if (params.form) {
      setFormId(params.form);
      setShow('drafts');
    } else if (params.show) {
      setShow(params.show);
    }
  }

  const b = buckets(items);
  const q = query.trim().toLowerCase();
  const matches = (name: string, extra?: (string | null | undefined)[]) => !q || [name, ...(extra ?? [])].some((s) => s?.toLowerCase().includes(q));

  const sections = (() => {
    if (show === 'forms') {
      const byProgramme = new Map<string, Assignment[]>();
      for (const a of kit?.assignments ?? []) {
        if (!matches(a.form.name, [a.form.description, a.form.programme, a.unit?.label])) continue;
        const key = a.form.programme ?? 'Other forms';
        byProgramme.set(key, [...(byProgramme.get(key) ?? []), a]);
      }
      return [...byProgramme.entries()].map(([title, data]) => ({ title, data: data as (Assignment | Item)[] }));
    }
    const list = show === 'drafts' ? b.drafts : show === 'attention' ? [...b.corrections, ...b.refused] : [...b.waiting, ...b.uploaded];
    const data = list
      .filter((i) => !formId || i.formId === formId)
      .filter((i) => matches(i.formName))
      .sort((x, y) => y.updatedAt.localeCompare(x.updatedAt));
    return data.length ? [{ title: '', data: data as (Assignment | Item)[] }] : [];
  })();

  const formName = formId ? (kit?.assignments.find((a) => a.form.id === formId)?.form.name ?? items.find((i) => i.formId === formId)?.formName) : null;

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Forms" subtitle={`${kit?.assignments.length ?? 0} available · ${downloaded.length} on this phone`} />
      <SectionList
        sections={sections}
        keyExtractor={(x) => x.id}
        stickySectionHeadersEnabled={false}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={syncing} onRefresh={() => void refresh()} />}
        contentContainerStyle={{ padding: 16, gap: 8, paddingBottom: insets.bottom + 24, flexGrow: 1 }}
        ListHeaderComponent={
          <View className="mb-2 gap-3">
            <SearchBox value={query} onChange={setQuery} placeholder="Search forms" />
            <Segments
              value={show}
              onChange={(v) => {
                setShow(v);
                if (v === 'forms') setFormId(null);
              }}
              options={[
                { value: 'forms', label: 'Forms' },
                { value: 'drafts', label: `Drafts${b.drafts.length ? ` (${b.drafts.length})` : ''}` },
                { value: 'submitted', label: 'Submitted' },
                { value: 'attention', label: `Needs me${b.corrections.length + b.refused.length ? ` (${b.corrections.length + b.refused.length})` : ''}` },
              ]}
            />
            {formName && show !== 'forms' && (
              <Pressable accessibilityRole="button" onPress={() => setFormId(null)} className="flex-row items-center gap-1 self-start bg-brand-50 px-2 py-1.5">
                <Text className="text-sm text-brand-800">Only “{formName}”</Text>
                <Ionicons name="close" size={16} color={colors.brand[700]} />
              </Pressable>
            )}
          </View>
        }
        renderSectionHeader={({ section }) => (section.title ? <Text className="mt-3 text-xs font-bold uppercase tracking-wider text-slate-600">{section.title}</Text> : null)}
        ListEmptyComponent={
          <EmptyState
            icon={show === 'forms' ? 'document-text-outline' : 'file-tray-outline'}
            title={show === 'forms' ? (kit ? 'No forms available' : 'No forms on this phone yet') : show === 'drafts' ? 'No drafts' : show === 'attention' ? 'Nothing needs you' : 'Nothing submitted yet'}
            message={show === 'forms' ? (kit ? 'Forms appear here once your coordinator assigns them.' : 'Connect to the internet once to download your forms.') : undefined}
          />
        }
        renderItem={({ item }) => ('form' in item ? <FormCard assignment={item} downloaded={downloaded.includes(item.version?.id ?? '')} drafts={b.drafts.filter((d) => d.formId === item.form.id).length} /> : <RecordRow item={item} />)}
      />
    </View>
  );
}

function FormCard({ assignment: a, downloaded, drafts }: { assignment: Assignment; downloaded: boolean; drafts: number }) {
  const { begin, busy } = useStartRecord();
  const open = a.canStart && a.version !== null && downloaded;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityHint={open ? 'Starts a new record' : undefined}
      accessibilityState={{ disabled: !open, busy: busy === a.id }}
      disabled={!open || busy !== null}
      onPress={() => void begin(a)}
      className={`min-h-16 gap-1.5 border border-slate-200 bg-white p-3 ${open ? 'active:bg-brand-50' : ''}`}>
      <View className="flex-row items-center gap-2">
        <Text className="flex-1 text-base font-semibold text-slate-800">{a.form.name}</Text>
        {open && <Ionicons name="add-circle" size={26} color={colors.brand[600]} />}
      </View>
      <View className="flex-row flex-wrap gap-1.5">
        <Badge label={purposeLabel(a.form.purpose)} />
        {a.version && <Badge label={`v${a.version.number}`} tone="blue" a11y={`Version ${a.version.number}`} />}
        <Badge label={downloaded ? 'Available offline' : 'Not downloaded'} tone={downloaded ? 'green' : 'red'} />
        {drafts > 0 && <Badge label={`${drafts} ${drafts === 1 ? 'draft' : 'drafts'}`} tone="amber" />}
        {!a.canStart && <Badge label="Not open for new records" tone="neutral" />}
      </View>
      {a.unit && <Text className="text-sm text-slate-600">{a.unit.label}</Text>}
      {!downloaded && a.version && (
        <Pressable accessibilityRole="button" onPress={() => router.push('/sync')} hitSlop={8}>
          <Text className="text-sm font-semibold text-brand-700">Download it from Sync</Text>
        </Pressable>
      )}
    </Pressable>
  );
}
