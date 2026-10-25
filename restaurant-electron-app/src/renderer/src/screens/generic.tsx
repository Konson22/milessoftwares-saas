import { ExternalLink } from 'lucide-react';

import { Button, Notice, PageHeader, Panel } from '@/components/ui';
import { webUrl } from '@/lib/config';
import { useAuth } from '@/store/auth';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

const SHARED = new Set(['access', 'branch', 'branchPermissions', 'branches', 'canSwitchBranch', 'currency', 'errors', 'flash', 'product', 'user', 'workspace']);

const title = (key: string) => key.replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase());

/**
 * A page that has no screen of its own in the app yet: its data, read-only,
 * as tables, and a link to do things on the web meanwhile.
 */
export function GenericScreen({ page, path }: ScreenProps) {
  const workspace = useAuth((s) => s.workspace);
  const open = useUi((s) => s.open);
  const entries = Object.entries(page.props).filter(([key]) => !SHARED.has(key));

  return (
    <div className="space-y-4">
      <PageHeader
        title={title(page.component.split('/').pop() === 'index' ? page.component.split('/')[0] : page.component.replace('/', ' · '))}
        actions={
          workspace ? (
            <Button variant="secondary" onClick={() => void window.desktop.openExternal(webUrl(workspace, path))}>
              <ExternalLink size={15} /> Open on the web
            </Button>
          ) : null
        }
      />
      <Notice tone="blue">This page is shown as data for now; its own screen in the app is on the way. To change things, open it on the web.</Notice>
      {entries.map(([key, value]) => (
        <Panel key={key} title={title(key)}>
          <Value value={value} onOpen={(id) => open(`${path.split('?')[0]}/${id}`)} />
        </Panel>
      ))}
    </div>
  );
}

function Value({ value, onOpen }: { value: unknown; onOpen: (id: string) => void }) {
  if (Array.isArray(value)) {
    if (value.length === 0) return <p className="px-4 py-3 text-sm text-muted-foreground">None.</p>;
    if (typeof value[0] !== 'object' || value[0] === null) return <p className="px-4 py-3 text-sm">{value.join(', ')}</p>;
    const columns = Object.keys(value[0]).filter((k) => k !== 'id' && typeof (value[0] as any)[k] !== 'object');
    return (
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-muted text-left text-xs text-muted-foreground">
            <tr>
              {columns.map((c) => (
                <th key={c} className="px-4 py-2 font-medium whitespace-nowrap">
                  {title(c)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {value.map((row: any, i) => (
              <tr key={row.id ?? i} onClick={row.id ? () => onOpen(row.id) : undefined} className={row.id ? 'cursor-pointer hover:bg-accent' : ''}>
                {columns.map((c) => (
                  <td key={c} className="px-4 py-2 whitespace-nowrap">
                    {format(row[c])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }
  if (value && typeof value === 'object') {
    return (
      <dl className="grid grid-cols-3 gap-3 p-4 text-sm">
        {Object.entries(value).map(([k, v]) => (
          <div key={k}>
            <dt className="text-xs text-muted-foreground">{title(k)}</dt>
            <dd>{typeof v === 'object' && v !== null ? JSON.stringify(v) : format(v)}</dd>
          </div>
        ))}
      </dl>
    );
  }
  return <p className="px-4 py-3 text-sm">{format(value)}</p>;
}

function format(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  return String(v);
}
