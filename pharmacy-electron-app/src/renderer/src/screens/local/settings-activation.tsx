import { CheckCircle2, Copy, FileKey2, KeyRound, Loader2, Smartphone } from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';

import { Badge, Button, Field, Input, Notice, Panel } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { useLicence, type LicenceState } from '@/store/licence';
import { toast } from '@/store/ui';

/**
 * Offline: the trial, or the licence this PC runs on. A licence key
 * (MILES-XXXX-XXXX-XXXX) activates it: online in one step, or, with no
 * internet here, from a phone (the QR code opens the activation page with
 * this PC's codes) and back as a code or a licence file. Either way the
 * code is checked on this PC.
 */
export function Activation() {
  const state = useLicence((s) => s.state);
  const load = useLicence((s) => s.load);
  const [more, setMore] = useState(false);

  useEffect(() => {
    void load();
  }, [load]);

  if (!state) return <Panel title="Activation">{<div className="p-4 text-sm text-muted-foreground">Loading…</div>}</Panel>;
  const licensed = state.status === 'licensed';

  return (
    <div className="space-y-4">
      <Panel title="This PC’s licence" actions={<StatusBadge state={state} />}>
        <div className="space-y-3 p-4 text-sm">
          <Status state={state} />
          {licensed && !more ? (
            <Button size="sm" variant="ghost" onClick={() => setMore(true)}>
              Use another licence key
            </Button>
          ) : null}
        </div>
      </Panel>

      {!licensed || more ? (
        <>
          <ByKey />
          <ByPhone state={state} />
        </>
      ) : null}
    </div>
  );
}

/** The usual way: the key, sent from here for this PC's code. */
function ByKey() {
  const activateKey = useLicence((s) => s.activateKey);
  const [key, setKey] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await activateKey(key);
      setKey('');
      toast('This PC is activated.');
    } catch (err) {
      setError(err instanceof ApiError ? (err.field('key') ?? err.message) : errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Panel title="Activate with your licence key">
      <form onSubmit={submit} className="space-y-3 p-4">
        <p className="text-sm text-muted-foreground">
          The key is on your card or in the email you got when you bought it. This needs the internet once, for a few seconds (a phone’s hotspot is
          enough); after that the app never needs it.
        </p>
        <div className="flex items-start gap-2">
          <div className="max-w-sm flex-1">
            <Field label="Licence key" error={error}>
              <Input
                value={key}
                onChange={(e) => setKey(e.target.value.toUpperCase())}
                placeholder="MILES-XXXX-XXXX-XXXX"
                spellCheck={false}
                autoComplete="off"
                className="font-mono tracking-wider"
              />
            </Field>
          </div>
          <Button type="submit" className="mt-6" disabled={busy || !key.trim()}>
            {busy ? <Loader2 size={16} className="animate-spin" /> : <KeyRound size={16} />} Activate
          </Button>
        </div>
      </form>
    </Panel>
  );
}

/** No internet here: a phone does the online part, and the code comes back typed, pasted or as a file. */
function ByPhone({ state }: { state: LicenceState }) {
  const activate = useLicence((s) => s.activate);
  const openFile = useLicence((s) => s.openFile);
  const [qr, setQr] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState<'code' | 'file' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [paste, setPaste] = useState(false);

  useEffect(() => {
    QRCode.toDataURL(state.activateUrl, { margin: 1, width: 200, errorCorrectionLevel: 'M' })
      .then(setQr)
      .catch(() => setQr(null));
  }, [state.activateUrl]);

  const copy = (text: string, what: string) =>
    void navigator.clipboard.writeText(text).then(
      () => toast(`${what} copied.`),
      () => toast('Could not copy. Select it and copy it by hand.', 'red'),
    );

  const run = async (kind: 'code' | 'file', work: () => Promise<LicenceState | null>) => {
    setBusy(kind);
    setError(null);
    try {
      if (await work()) {
        setCode('');
        toast('This PC is activated.');
      }
    } catch (err) {
      setError(err instanceof ApiError ? (err.field('code') ?? err.message) : errorMessage(err));
    } finally {
      setBusy(null);
    }
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    void run('code', () => activate(code));
  };

  return (
    <Panel title="No internet on this PC?">
      <div className="grid gap-6 p-4 md:grid-cols-[200px_1fr]">
        <div className="space-y-2 text-center">
          {qr ? <img src={qr} alt="QR code for the activation page, with this PC’s codes" className="mx-auto size-[200px] border border-border" /> : null}
          <p className="text-xs text-muted-foreground">
            <Smartphone size={12} className="mr-1 inline" />
            Scan with your phone’s camera
          </p>
        </div>

        <div className="space-y-4 text-sm">
          <ol className="list-decimal space-y-1 pl-5 text-muted-foreground">
            <li>Scan the code with a phone. It opens the activation page with this PC’s codes filled in.</li>
            <li>Enter your licence key there. You get the activation code on screen, and by email if you ask.</li>
            <li>Bring it here: open the licence file (on a USB stick, or from the email), or paste the code.</li>
          </ol>

          <div className="grid grid-cols-2 gap-3">
            <CodeBox label="Device code" value={state.deviceCode} onCopy={() => copy(state.deviceCode, 'Device code')} />
            <CodeBox label="Pharmacy ID" value={state.pharmacyId} onCopy={() => copy(state.pharmacyId, 'Pharmacy ID')} />
          </div>
          <p className="text-xs text-muted-foreground">
            No phone either? Call or WhatsApp Miles Softwares with your licence key and these two codes; they read you back the code.
          </p>

          {error ? <Notice tone="red">{error}</Notice> : null}
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => void run('file', openFile)} disabled={busy !== null}>
              {busy === 'file' ? <Loader2 size={16} className="animate-spin" /> : <FileKey2 size={16} />} Open a licence file…
            </Button>
            <Button variant="ghost" onClick={() => setPaste((p) => !p)}>
              Paste an activation code
            </Button>
            <Button variant="ghost" onClick={() => copy(`Device code: ${state.deviceCode}\nPharmacy ID: ${state.pharmacyId}`, 'Both codes')}>
              <Copy size={16} /> Copy both codes
            </Button>
          </div>

          {paste ? (
            <form onSubmit={submit} className="space-y-2">
              <textarea
                value={code}
                onChange={(e) => setCode(e.target.value)}
                rows={4}
                spellCheck={false}
                placeholder="MP1.…"
                aria-label="Activation code"
                className="w-full border border-input bg-background px-3 py-2 font-mono text-xs break-all focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              />
              <Button type="submit" disabled={busy !== null || !code.trim()}>
                {busy === 'code' ? <Loader2 size={16} className="animate-spin" /> : <KeyRound size={16} />} Activate with this code
              </Button>
            </form>
          ) : null}
        </div>
      </div>
    </Panel>
  );
}

function StatusBadge({ state }: { state: LicenceState }) {
  switch (state.status) {
    case 'licensed':
      return <Badge tone="green">Activated</Badge>;
    case 'trial':
      return <Badge tone={state.daysLeft <= 14 ? 'amber' : 'blue'}>Free trial · {state.daysLeft} days left</Badge>;
    case 'expired':
      return <Badge tone="red">{state.what === 'trial' ? 'Trial ended' : 'Licence ended'}</Badge>;
    case 'invalid':
      return <Badge tone="red">Not for this PC</Badge>;
    case 'clock':
      return <Badge tone="red">Check the clock</Badge>;
  }
}

function Status({ state }: { state: LicenceState }) {
  switch (state.status) {
    case 'licensed':
      return (
        <>
          <p className="flex items-center gap-2 font-medium">
            <CheckCircle2 size={16} className="text-emerald-600" /> Activated for {state.licence.name || 'this pharmacy'}.
          </p>
          <dl className="grid grid-cols-[10rem_1fr] gap-y-1 text-muted-foreground">
            <dt>Licence</dt>
            <dd className="font-mono text-xs leading-5">{state.licence.id}</dd>
            <dt>Activated</dt>
            <dd>{dateTime(state.licence.issued)}</dd>
            <dt>Runs until</dt>
            <dd>{state.licence.expires ? dateTime(state.licence.expires) : 'No end date'}</dd>
          </dl>
        </>
      );
    case 'trial':
      return (
        <>
          <p>
            The free trial runs until <b>{dateTime(state.endsAt)}</b>. Everything works until then. Activate any time before it ends; nothing is lost.
          </p>
          {state.problem ? <Notice tone="amber">The activation code saved here is not for this PC or pharmacy. Activate again below.</Notice> : null}
        </>
      );
    case 'expired':
      return (
        <Notice tone="red">
          The {state.what === 'trial' ? 'free trial' : 'licence'} ended {dateTime(state.endedAt)}. Selling and changes are paused; sales, stock, reports and
          backups can still be read and saved. Activate below to carry on where you left off.
        </Notice>
      );
    case 'invalid':
      return (
        <Notice tone="red">
          The activation code saved here is for {state.problem === 'branch' ? 'another pharmacy' : 'another PC'} (a backup restored here, or a new PC, say).
          Ask Miles Softwares to free your licence key, then activate it again below.
        </Notice>
      );
    case 'clock':
      return (
        <Notice tone="red">
          This PC’s clock is behind the last time the app was used ({dateTime(state.lastSeenAt)}). Correct the date and time in Windows, then open
          this page again.
        </Notice>
      );
  }
}

function CodeBox({ label, value, onCopy }: { label: string; value: string; onCopy: () => void }): ReactNode {
  return (
    <div className="border border-border p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-1 flex items-center justify-between gap-2">
        <span className="font-mono text-lg font-semibold tracking-wider select-all">{value}</span>
        <button type="button" onClick={onCopy} className="p-1 text-muted-foreground hover:text-foreground" aria-label={`Copy the ${label.toLowerCase()}`}>
          <Copy size={15} />
        </button>
      </div>
    </div>
  );
}
