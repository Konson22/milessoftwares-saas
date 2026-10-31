import * as WebBrowser from 'expo-web-browser';
import { Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { openPage } from '@/hooks/use-page';
import { webUrl } from '@/lib/config';
import { useAuth } from '@/store/auth';

import { ListRow, Panel, Scroll } from './kit';
import type { ScreenProps } from './types';

const SHARED = new Set(['access', 'branch', 'branchPermissions', 'branches', 'canSwitchBranch', 'currency', 'errors', 'flash', 'product', 'user', 'workspace']);

const title = (key: string) => key.replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase());

function format(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  return String(v);
}

/**
 * A page the app has no screen of its own for yet (one the web added
 * since): its data, read-only, and a way to it on the web.
 */
export function GenericScreen({ page, path }: ScreenProps) {
  const workspace = useAuth((s) => s.workspace);
  const entries = Object.entries(page.props).filter(([key]) => !SHARED.has(key));

  return (
    <Scroll>
      <Banner tone="info">This page is shown as data for now. To change things here, open it on the web.</Banner>
      {workspace ? <Button title="Open on the web" icon="open-outline" variant="outline" onPress={() => void WebBrowser.openBrowserAsync(webUrl(workspace, path))} /> : null}
      {entries.map(([key, value]) => (
        <Panel key={key} title={title(key)}>
          {Array.isArray(value) ? (
            value.length === 0 ? (
              <Text className="px-4 py-3 text-base text-slate-500">None.</Text>
            ) : (
              value.map((row: any, i) =>
                row && typeof row === 'object' ? (
                  <ListRow
                    key={row.id ?? i}
                    title={format(row.name ?? row.number ?? row.title ?? row.id)}
                    subtitle={Object.entries(row)
                      .filter(([k, v]) => !['id', 'name', 'number', 'title'].includes(k) && (v === null || typeof v !== 'object'))
                      .map(([k, v]) => `${title(k)}: ${format(v)}`)
                      .join(' · ')}
                    onPress={row.id ? () => openPage(`${path.split('?')[0]}/${row.id}`) : undefined}
                  />
                ) : (
                  <Text key={i} className="px-4 py-2 text-base text-slate-800">
                    {format(row)}
                  </Text>
                ),
              )
            )
          ) : value && typeof value === 'object' ? (
            <View className="px-4 py-2">
              {Object.entries(value).map(([k, v]) => (
                <View key={k} className="flex-row justify-between gap-3 py-1">
                  <Text className="text-base text-slate-600">{title(k)}</Text>
                  <Text className="flex-1 text-right text-base text-slate-900">{typeof v === 'object' && v !== null ? JSON.stringify(v) : format(v)}</Text>
                </View>
              ))}
            </View>
          ) : (
            <Text className="px-4 py-3 text-base text-slate-800">{format(value)}</Text>
          )}
        </Panel>
      ))}
    </Scroll>
  );
}
