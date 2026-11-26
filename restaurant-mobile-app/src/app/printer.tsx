import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { Linking, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { BluetoothDevice } from '../../modules/sunmi-printer';
import { Chips, Detail, Panel, Toggle } from '@/components/pages/kit';
import { ScreenHeader } from '@/components/screen-header';
import { Button, IconButton } from '@/components/ui/button';
import { Badge, Banner } from '@/components/ui/misc';
import { useBusiness } from '@/hooks/use-business';
import { haptic } from '@/lib/feedback';
import { time } from '@/lib/format';
import { getPrintChoices, subscribePrintChoices, updatePrintChoices, type ReceiptAfterPay } from '@/lib/printing';
import { printBill, printKitchenTicket } from '@/lib/receipt';
import { pairedBluetoothDevices } from '@/lib/terminal-printer/bluetooth-driver';
import { PrinterError } from '@/lib/terminal-printer/errors';
import { bluetoothPrinterService, printerService, printsOnTerminal } from '@/lib/terminal-printer/service';
import { getPrinterSettings, MAX_CHARS_PER_LINE, MIN_CHARS_PER_LINE, subscribePrinterSettings, updatePrinterSettings } from '@/lib/terminal-printer/settings';
import type { PrinterResult, PrinterState } from '@/lib/terminal-printer/types';
import { colors } from '@/lib/theme';
import { useUser } from '@/store/auth';

type Tone = 'neutral' | 'green' | 'amber' | 'red';
type Message = { tone: 'success' | 'error' | 'warning'; text: string } | null;
type Where = 'builtin' | 'bluetooth' | 'dialog';

const STATE: Record<PrinterState, { label: string; tone: Tone }> = {
  ready: { label: 'Ready', tone: 'green' },
  busy: { label: 'Printing', tone: 'amber' },
  paper_out: { label: 'Out of paper', tone: 'red' },
  overheated: { label: 'Too hot', tone: 'red' },
  error: { label: 'Fault', tone: 'red' },
  disconnected: { label: 'Not connected', tone: 'red' },
  unsupported: { label: 'Not supported', tone: 'neutral' },
  unknown: { label: 'Unknown', tone: 'neutral' },
};

const AFTER_PAY: { value: ReceiptAfterPay; label: string; hint: string }[] = [
  { value: 'ask', label: 'Ask', hint: 'After each payment, choose to print the receipt or not.' },
  { value: 'print', label: 'Always print', hint: 'The receipt prints as soon as the bill is paid.' },
  { value: 'none', label: 'Never', hint: 'No receipt after payment. It can still be printed from the bill.' },
];

const WHERE_HINT: Record<Where, string> = {
  builtin: 'Bills and kitchen tickets print straight on this terminal, through its printer service.',
  bluetooth: 'Bills and kitchen tickets print on a paired Bluetooth receipt printer. On many POS terminals the built-in printer is one too ("InnerPrinter").',
  dialog: 'Each print opens the phone’s print dialog: a printer it knows, or a PDF.',
};

/**
 * This phone's printing: where paper goes (the terminal's printer service,
 * a paired Bluetooth printer, or the print dialog), what prints by itself,
 * the paper's layout, and test pages. Kept on the device, not the account.
 */
export default function PrinterScreen() {
  const insets = useSafeAreaInsets();
  const business = useBusiness();
  const user = useUser();
  const choices = useSyncExternalStore(subscribePrintChoices, getPrintChoices);
  const settings = useSyncExternalStore(subscribePrinterSettings, getPrinterSettings);
  const builtin = useSyncExternalStore(printerService.subscribe, printerService.getSnapshot);
  const bluetooth = useSyncExternalStore(bluetoothPrinterService.subscribe, bluetoothPrinterService.getSnapshot);
  const [checking, setChecking] = useState(false);
  const [sampling, setSampling] = useState<'receipt' | 'ticket' | null>(null);
  const [message, setMessage] = useState<Message>(null);
  const [showDetails, setShowDetails] = useState(false);
  const [devices, setDevices] = useState<BluetoothDevice[] | null>(null);
  const [finding, setFinding] = useState(false);
  const [testing, setTesting] = useState<string | null>(null);

  const builtinAvailable = printerService.isDriverAvailable() && (printerService.isTerminal() || (settings.use === true && settings.connection === 'builtin'));
  const bluetoothAvailable = bluetoothPrinterService.isDriverAvailable();
  const where: Where =
    settings.use === false
      ? 'dialog'
      : settings.connection === 'bluetooth' && bluetoothAvailable
        ? 'bluetooth'
        : printerService.isDriverAvailable() && printsOnTerminal()
          ? 'builtin'
          : 'dialog';
  const service = where === 'bluetooth' ? bluetoothPrinterService : printerService;
  const snapshot = where === 'bluetooth' ? bluetooth : builtin;
  const direct = where !== 'dialog' && printsOnTerminal();
  const status = snapshot.lastStatus;
  const state = status ? STATE[status.state] : null;

  const refresh = useCallback(async () => {
    setChecking(true);
    try {
      await service.getStatus();
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof PrinterError ? e.message : String(e) });
    } finally {
      setChecking(false);
    }
  }, [service]);

  useEffect(() => {
    // The state arrives through the service's snapshot.
    if (where !== 'dialog') service.getStatus().catch(() => {});
  }, [where, service, settings.bluetoothAddress]);

  const findPrinters = async () => {
    setMessage(null);
    setFinding(true);
    try {
      setDevices(await pairedBluetoothDevices());
    } catch (e) {
      setDevices([]);
      setMessage({ tone: 'error', text: e instanceof Error ? e.message : String(e) });
    } finally {
      setFinding(false);
    }
  };

  const choose = (next: Where) => {
    setMessage(null);
    if (next === 'dialog') updatePrinterSettings({ use: false });
    else updatePrinterSettings({ use: true, connection: next });
    if (next === 'bluetooth' && devices === null) void findPrinters();
  };

  const pickDevice = (device: BluetoothDevice) => {
    setMessage(null);
    updatePrinterSettings({ use: true, connection: 'bluetooth', bluetoothAddress: device.address, bluetoothName: device.name });
  };

  /** A job on the chosen printer, with what it reported. */
  const runDirect = async (job: () => Promise<PrinterResult>, done: string) => {
    setMessage(null);
    const result = await job();
    if (result.success) haptic.success();
    else haptic.error();
    setMessage(result.success ? { tone: 'success', text: done } : { tone: result.outcome === 'unknown' ? 'warning' : 'error', text: result.message });
    await refresh();
  };

  const testDevice = async (device: BluetoothDevice) => {
    pickDevice(device);
    setTesting(device.address);
    try {
      await runDirect(() => bluetoothPrinterService.printTestPage(business.name || 'Bewe Miles Resto'), `Test page sent to ${device.name}. Check the paper.`);
    } finally {
      setTesting(null);
    }
  };

  /** A made-up receipt or ticket, the way real ones go. */
  const sample = async (kind: 'receipt' | 'ticket') => {
    setMessage(null);
    setSampling(kind);
    try {
      if (kind === 'receipt') {
        await printBill({
          business: business.name || 'Your restaurant',
          branch: business.branch,
          title: 'Sample receipt',
          details: ['Table 4 · ORD-0001', time(new Date().toISOString())],
          lines: [
            { name: 'Grilled tilapia', quantity: 1, unitPriceMinor: 1500000, totalMinor: 1500000 },
            { name: 'Soda', quantity: 2, unitPriceMinor: 200000, totalMinor: 400000 },
          ],
          totalMinor: 1900000,
          footer: ['Paid by Cash', `Served by ${user?.name ?? 'the cashier'}.`, 'Not a real bill: a printer test.'],
        });
      } else {
        await printKitchenTicket({
          number: 'T-SAMPLE',
          where: 'Table 4',
          orderNumber: 'ORD-0001',
          sentAt: new Date().toISOString(),
          sentBy: user?.name ?? '',
          lines: [
            { name: 'Grilled tilapia', quantity: 1, note: 'No chilli', voided: false },
            { name: 'Chips', quantity: 2, note: null, voided: false },
          ],
        });
      }
      if (direct) setMessage({ tone: 'success', text: 'Sent to the printer. Check the paper.' });
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof Error ? e.message : String(e) });
    } finally {
      setSampling(null);
    }
  };

  const whereOptions = [
    ...(builtinAvailable ? [{ value: 'builtin' as const, label: 'Built-in' }] : []),
    ...(bluetoothAvailable ? [{ value: 'bluetooth' as const, label: 'Bluetooth' }] : []),
    { value: 'dialog' as const, label: 'Print dialog' },
  ];
  const chosenName = where === 'bluetooth' ? (settings.bluetoothName ?? settings.bluetoothAddress) : where === 'builtin' ? (printerService.deviceLabel() ?? 'Built-in printer') : 'Print dialog';

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Printer" subtitle="Settings for this phone" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ paddingBottom: insets.bottom + 24 }} contentContainerClassName="w-full max-w-xl gap-4 self-center p-4">
        <Panel title="Where paper goes">
          <View className="gap-3 p-4">
            {whereOptions.length > 1 ? <Chips options={whereOptions} value={where} onChange={choose} /> : null}
            <View className="flex-row items-start gap-3">
              <View className="flex-1">
                <Text className="text-lg font-bold text-slate-900" numberOfLines={1}>
                  {chosenName ?? 'No printer chosen'}
                </Text>
                <Text className="text-sm text-slate-500">{WHERE_HINT[where]}</Text>
              </View>
              {where !== 'dialog' && state ? <Badge label={checking ? 'Checking…' : state.label} tone={checking ? 'neutral' : state.tone} /> : null}
            </View>
            {!printerService.isDriverAvailable() ? (
              <Text className="text-sm text-slate-500">This build has no printer driver (Expo Go, iOS or the web): it uses the print dialog.</Text>
            ) : null}
            {where !== 'dialog' && status && status.state !== 'ready' && status.state !== 'unknown' && !checking ? <Banner tone="warning">{status.message}</Banner> : null}
            {where !== 'dialog' ? <Button title="Check the printer" icon="refresh" variant="ghost" loading={checking} onPress={() => void refresh()} /> : null}
          </View>
        </Panel>

        {where === 'bluetooth' ? (
          <Panel
            title="Bluetooth printers"
            right={<Button title={finding ? 'Looking…' : 'Paired printers'} icon="bluetooth" variant="ghost" loading={finding} onPress={() => void findPrinters()} />}>
            {devices === null ? (
              <Text className="px-4 py-4 text-sm text-slate-500">Tap Paired printers to list the printers paired with this phone.</Text>
            ) : devices.length === 0 ? (
              <Text className="px-4 py-4 text-sm text-slate-500">Nothing paired yet. Pair the printer in Bluetooth settings first (steps below).</Text>
            ) : (
              devices.map((device) => {
                const chosen = device.address.toUpperCase() === settings.bluetoothAddress?.toUpperCase();
                return (
                  <Pressable
                    key={device.address}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: chosen }}
                    onPress={() => pickDevice(device)}
                    className={`flex-row items-center gap-3 border-b border-slate-100 px-4 py-3 ${chosen ? 'bg-brand-50' : 'active:bg-slate-50'}`}>
                    <Ionicons name={chosen ? 'radio-button-on' : 'radio-button-off'} size={22} color={chosen ? colors.brand[700] : colors.muted} />
                    <View className="flex-1">
                      <View className="flex-row items-center gap-2">
                        <Text className="shrink text-base font-semibold text-slate-900" numberOfLines={1}>
                          {device.name}
                        </Text>
                        {device.isPrinter ? <Badge label="Printer" tone="blue" /> : null}
                      </View>
                      <Text className="text-xs text-slate-500">{device.address}</Text>
                    </View>
                    <Button
                      title="Test"
                      icon="print-outline"
                      variant="outline"
                      loading={testing === device.address}
                      disabled={testing !== null}
                      onPress={() => void testDevice(device)}
                    />
                  </Pressable>
                );
              })
            )}
            <View className="gap-1.5 border-t border-slate-100 px-4 py-3">
              <Text className="text-sm font-semibold text-slate-700">Pairing a printer</Text>
              <Text className="text-sm text-slate-600">1. Turn the printer on (hold its pair button if it has one).</Text>
              <Text className="text-sm text-slate-600">2. In Android’s Bluetooth settings, pair with the printer’s name.</Text>
              <Text className="text-sm text-slate-600">3. Come back here, tap Paired printers, pick it and print a test.</Text>
              <Text className="text-sm text-slate-500">On a POS terminal the built-in printer is usually paired already, as “InnerPrinter”.</Text>
              <Button title="Open Bluetooth settings" icon="settings-outline" variant="ghost" onPress={() => void Linking.sendIntent('android.settings.BLUETOOTH_SETTINGS').catch(() => Linking.openSettings())} />
            </View>
          </Panel>
        ) : null}

        <Panel title="What prints by itself">
          <View className="gap-4 p-4">
            <Toggle
              label="Kitchen ticket on send"
              hint="Each time lines go to the kitchen, this phone prints their ticket. Tickets can always be printed again from the order."
              value={choices.kitchenTickets}
              onChange={(kitchenTickets) => updatePrintChoices({ kitchenTickets })}
            />
            <View className="gap-2 border-t border-slate-100 pt-3">
              <Text className="text-base font-medium text-slate-800">Receipt after payment</Text>
              <Chips options={AFTER_PAY} value={choices.receiptAfterPay} onChange={(receiptAfterPay) => updatePrintChoices({ receiptAfterPay })} />
              <Text className="text-sm text-slate-500">{AFTER_PAY.find((o) => o.value === choices.receiptAfterPay)?.hint}</Text>
            </View>
          </View>
        </Panel>

        {direct ? (
          <Panel title="Paper">
            <View className="gap-1 px-4 py-2">
              <Stepper
                label="Characters per line"
                hint="Print the width check and pick the longest ruler on one line. 32 on most 58 mm rolls."
                value={settings.charsPerLine}
                min={MIN_CHARS_PER_LINE}
                max={MAX_CHARS_PER_LINE}
                onChange={(charsPerLine) => updatePrinterSettings({ charsPerLine })}
              />
              <Stepper
                label="Blank lines after"
                hint="So the paper clears the tear bar."
                value={settings.feedLinesAfter}
                min={0}
                max={10}
                onChange={(feedLinesAfter) => updatePrinterSettings({ feedLinesAfter })}
              />
            </View>
            <View className="flex-row gap-2 px-4 pb-4">
              <Button
                title="Test page"
                variant="outline"
                icon="print-outline"
                loading={snapshot.busy}
                className="flex-1"
                onPress={() => void runDirect(() => service.printTestPage(business.name || 'Bewe Miles Resto'), 'Test page sent. Check the paper.')}
              />
              <Button
                title="Width check"
                variant="outline"
                icon="resize-outline"
                disabled={snapshot.busy}
                className="flex-1"
                onPress={() => void runDirect(() => service.printCalibrationPage(), 'Set the characters per line to the longest ruler that printed on one line.')}
              />
            </View>
          </Panel>
        ) : null}

        <Panel title="Try it">
          <View className="gap-2 p-4">
            <Text className="text-sm text-slate-500">A sample with made-up dishes, printed the way real ones are.</Text>
            <View className="flex-row gap-2">
              <Button
                title="Sample receipt"
                icon="receipt-outline"
                variant="outline"
                className="flex-1"
                loading={sampling === 'receipt'}
                disabled={sampling !== null}
                onPress={() => void sample('receipt')}
              />
              <Button
                title="Sample ticket"
                icon="flame-outline"
                variant="outline"
                className="flex-1"
                loading={sampling === 'ticket'}
                disabled={sampling !== null}
                onPress={() => void sample('ticket')}
              />
            </View>
            <Text className="text-sm text-slate-500">The receipt’s header, phone number and footer come from the branch settings on the web.</Text>
          </View>
        </Panel>

        {message ? <Banner tone={message.tone}>{message.text}</Banner> : null}

        {where !== 'dialog' && status ? (
          <Panel
            title="Printer details"
            right={<IconButton icon={showDetails ? 'chevron-up' : 'chevron-down'} label={showDetails ? 'Hide details' : 'Show details'} onPress={() => setShowDetails(!showDetails)} />}>
            {showDetails ? (
              <View className="px-4 py-2">
                <Detail label="Driver" value={status.details.driver} />
                {status.details.device ? <Detail label="Device" value={status.details.device} /> : null}
                {status.details.printerModel ? <Detail label="Model" value={status.details.printerModel} /> : null}
                {status.details.printerSerial ? <Detail label="Serial" value={status.details.printerSerial} /> : null}
                {status.details.printerVersion ? <Detail label="Printer version" value={status.details.printerVersion} /> : null}
                {status.details.serviceVersion ? <Detail label="Service version" value={status.details.serviceVersion} /> : null}
                {status.details.stateCode !== null ? <Detail label="State code" value={String(status.details.stateCode)} /> : null}
                {snapshot.diagnostics.length > 0 ? (
                  <View className="mt-2 border-t border-slate-100 pt-2">
                    <Text className="pb-1 text-sm font-semibold text-slate-700">Recent</Text>
                    {snapshot.diagnostics.slice(0, 8).map((entry, i) => (
                      <Text key={`${entry.at}-${i}`} className={`py-0.5 text-xs ${entry.kind === 'error' ? 'text-red-700' : 'text-slate-600'}`}>
                        {time(entry.at)} · {entry.summary}
                      </Text>
                    ))}
                  </View>
                ) : null}
              </View>
            ) : null}
          </Panel>
        ) : null}
      </ScrollView>
    </View>
  );
}

/** A number set with − and +. */
function Stepper({ label, hint, value, min, max, onChange }: { label: string; hint?: string; value: number; min: number; max: number; onChange: (value: number) => void }) {
  return (
    <View className="flex-row items-center gap-3 py-2">
      <View className="flex-1">
        <Text className="text-base font-medium text-slate-800">{label}</Text>
        {hint ? <Text className="text-sm text-slate-500">{hint}</Text> : null}
      </View>
      <IconButton icon="remove-circle-outline" label={`Less: ${label}`} disabled={value <= min} onPress={() => onChange(value - 1)} />
      <Text className="w-9 text-center text-lg font-bold text-slate-900">{value}</Text>
      <IconButton icon="add-circle-outline" label={`More: ${label}`} disabled={value >= max} onPress={() => onChange(value + 1)} />
    </View>
  );
}
