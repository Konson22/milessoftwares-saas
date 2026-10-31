import { FolderOpen } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button, Panel } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { toast } from '@/store/ui';

interface SystemInfo {
  version: string;
  mode: string | null;
  os: string;
  electron: string;
  dataFolder: string;
  databasePath: string;
  databaseSize: number | null;
  schemaVersion: number;
}

const size = (bytes: number) => (bytes > 1_048_576 ? `${(bytes / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

/** The app and this PC, as support asks for them over the phone. */
export function System() {
  const [info, setInfo] = useState<SystemInfo | null>(null);

  useEffect(() => {
    call<SystemInfo>('system.info')
      .then(setInfo)
      .catch((e) => toast(errorMessage(e), 'red'));
  }, []);

  if (!info) return null;
  const rows: [string, string][] = [
    ['App', `Miles Pharmacy ${info.version}`],
    ['Runs', info.mode === 'offline' ? 'On this PC only (offline)' : 'With a Miles workspace (hybrid)'],
    ['Computer', info.os],
    ['Engine', `Electron ${info.electron}`],
    ['Database', `${info.databasePath}${info.databaseSize !== null ? ` · ${size(info.databaseSize)}` : ''} · version ${info.schemaVersion}`],
    ['Data folder', info.dataFolder],
  ];

  return (
    <div className="space-y-4">
      <Panel title="About this app">
        <dl className="grid grid-cols-[10rem_1fr] gap-y-2 p-4 text-sm">
          {rows.map(([label, value]) => (
            <div key={label} className="contents">
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="break-all">{value}</dd>
            </div>
          ))}
        </dl>
        <div className="flex gap-2 border-t border-border p-4">
          <Button variant="secondary" onClick={() => void call('system.openDataFolder').catch((e) => toast(errorMessage(e), 'red'))}>
            <FolderOpen size={16} /> Open the data folder
          </Button>
        </div>
      </Panel>
      <p className="text-xs text-muted-foreground">
        To run this PC with a Miles workspace instead, sign out and choose Change on the sign-in screen. This pharmacy’s data stays on the PC as it is.
      </p>
    </div>
  );
}
