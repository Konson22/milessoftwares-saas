import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { Text, View } from 'react-native';

import { Panel, Toggle } from '@/components/pages/kit';
import { Button } from '@/components/ui/button';
import { Banner, Row } from '@/components/ui/misc';
import { haptic } from '@/lib/feedback';
import { PrinterError } from '@/lib/terminal-printer/errors';
import { printerService, printsOnTerminal } from '@/lib/terminal-printer/service';
import { getPrinterSettings, subscribePrinterSettings, updatePrinterSettings } from '@/lib/terminal-printer/settings';
import type { PrinterResult } from '@/lib/terminal-printer/types';

/**
 * The printer built into a POS terminal (SUNMI): whether receipts
 * tickets print on it, its state, a test page and the paper's width. Only in
 * an Android build with the module; on a terminal it is used unless turned
 * off here.
 */
export function TerminalPrinterSettings() {
  const printer = useSyncExternalStore(printerService.subscribe, printerService.getSnapshot);
  const settings = useSyncExternalStore(subscribePrinterSettings, getPrinterSettings);
  const [checking, setChecking] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'error' | 'warning'; text: string } | null>(null);
  const available = printerService.isDriverAvailable();
  const terminal = available && printerService.isTerminal();
  const on = available && printsOnTerminal();

  const refresh = useCallback(async () => {
    setChecking(true);
    try {
      await printerService.getStatus();
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof PrinterError ? e.message : String(e) });
    } finally {
      setChecking(false);
    }
  }, []);

  // The state arrives through the service's snapshot.
  useEffect(() => {
    if (on) printerService.getStatus().catch(() => {});
  }, [on]);

  // A phone without the printer service, and not set to use one: nothing to show.
  if (!available || (!terminal && settings.use !== true)) return null;

  async function run(job: () => Promise<PrinterResult>, done: string) {
    setMessage(null);
    const result = await job();
    if (result.success) {
      haptic.success();
      setMessage({ tone: 'success', text: done });
    } else {
      haptic.error();
      setMessage({ tone: result.outcome === 'unknown' ? 'warning' : 'error', text: result.message });
    }
    await refresh();
  }

  return (
    <Panel title="Terminal printer">
      <View className="gap-3 p-4">
        <Toggle
          label="Print on this terminal"
          hint={`Receipts print on ${printerService.deviceLabel() ?? 'the built-in printer'}, not through the print dialog.`}
          value={on}
          onChange={(use) => updatePrinterSettings({ use })}
        />

        {on && (
          <>
            <Row label="State" value={checking ? 'Checking…' : (printer.lastStatus?.message ?? '—')} />
            <View className="flex-row items-center justify-between">
              <Text className="text-base text-slate-500">Characters per line</Text>
              <View className="flex-row items-center gap-2">
                <Button title="−" variant="outline" accessibilityLabel="Fewer characters per line" onPress={() => updatePrinterSettings({ charsPerLine: settings.charsPerLine - 1 })} />
                <Text className="w-10 text-center text-base font-semibold text-slate-900">{settings.charsPerLine}</Text>
                <Button title="+" variant="outline" accessibilityLabel="More characters per line" onPress={() => updatePrinterSettings({ charsPerLine: settings.charsPerLine + 1 })} />
              </View>
            </View>
            <View className="flex-row gap-2">
              <Button
                title="Test print"
                variant="outline"
                icon="print-outline"
                loading={printer.busy}
                className="flex-1"
                onPress={() => run(() => printerService.printTestPage('Miles Petrol'), 'The printer reported the test page as printed.')}
              />
              <Button
                title="Width check"
                variant="outline"
                icon="resize-outline"
                disabled={printer.busy}
                className="flex-1"
                onPress={() => run(() => printerService.printCalibrationPage(), 'Set the characters per line to the longest ruler that printed on one line.')}
              />
            </View>
            <Button title="Refresh state" variant="ghost" icon="refresh" loading={checking} onPress={refresh} />
          </>
        )}

        {message && <Banner tone={message.tone}>{message.text}</Banner>}
      </View>
    </Panel>
  );
}
