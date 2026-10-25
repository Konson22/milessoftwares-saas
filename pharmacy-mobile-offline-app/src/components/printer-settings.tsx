import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Pressable, Switch, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Banner, Card, SectionTitle } from '@/components/ui/misc';
import { drawerKick, testPage } from '@/lib/escpos';
import { haptic } from '@/lib/feedback';
import { bluetoothPrinting, pairedDevices, sendToPrinter, type PairedDevice } from '@/lib/printer';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { usePrinter } from '@/store/printer';

/**
 * This device's receipt printer: a Bluetooth printer paired in the phone's
 * settings, or the system print dialog. Only in an Android build with the
 * printer module; elsewhere it says how receipts print.
 */
export function PrinterSettings() {
  const paper = useAuth((s) => s.counter?.receipt.paper_size ?? '80mm');
  const printer = usePrinter((s) => s.printer);
  const openDrawer = usePrinter((s) => s.openDrawer);
  const choose = usePrinter((s) => s.choose);
  const setOpenDrawer = usePrinter((s) => s.setOpenDrawer);
  const [devices, setDevices] = useState<PairedDevice[] | null>(null);
  const [busy, setBusy] = useState<'list' | 'test' | 'drawer' | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);

  if (!bluetoothPrinting) {
    return (
      <Card className="gap-2">
        <SectionTitle>Receipt printer</SectionTitle>
        <Text className="text-base text-slate-500">
          Receipts print through the system print dialog. Printing straight to a Bluetooth receipt printer needs the Android app (not
          Expo Go).
        </Text>
      </Card>
    );
  }

  async function run(kind: 'list' | 'test' | 'drawer', action: () => Promise<string | null>) {
    setBusy(kind);
    setMessage(null);
    try {
      const done = await action();
      if (done) {
        haptic.success();
        setMessage({ tone: 'success', text: done });
      }
    } catch (e) {
      haptic.error();
      setMessage({ tone: 'error', text: e instanceof Error ? e.message : 'Could not reach the printer.' });
    } finally {
      setBusy(null);
    }
  }

  const list = () =>
    run('list', async () => {
      const found = await pairedDevices();
      setDevices(found);
      return found.length ? null : 'No paired devices. Pair the printer in the phone’s Bluetooth settings, then look again.';
    });

  return (
    <Card className="gap-3">
      <SectionTitle>Receipt printer</SectionTitle>
      <View className="flex-row items-center gap-3">
        <Ionicons name={printer ? 'print' : 'print-outline'} size={22} color={colors.brand[600]} />
        <View className="flex-1">
          <Text className="text-base font-semibold text-slate-900">{printer ? printer.name : 'System print dialog'}</Text>
          <Text className="text-sm text-slate-500">
            {printer ? `Bluetooth · ${paper} paper` : 'Choose a paired Bluetooth printer to print without the dialog.'}
          </Text>
        </View>
      </View>

      {devices && (
        <View accessibilityRole="radiogroup">
          {[{ address: '', name: 'System print dialog', printer: false }, ...devices].map((d) => {
            const selected = (printer?.address ?? '') === d.address;
            return (
              <Pressable
                key={d.address || 'system'}
                accessibilityRole="radio"
                accessibilityState={{ selected }}
                onPress={() => {
                  choose(d.address ? { address: d.address, name: d.name } : null);
                  setMessage(null);
                }}
                className="min-h-12 flex-row items-center gap-3 border-t border-slate-100 py-3">
                <Ionicons name={selected ? 'radio-button-on' : 'radio-button-off'} size={20} color={selected ? colors.brand[600] : colors.line} />
                <Text className="flex-1 text-base text-slate-800">{d.name}</Text>
                {d.printer && <Text className="text-xs font-semibold text-brand-700">Printer</Text>}
              </Pressable>
            );
          })}
        </View>
      )}

      <View className="flex-row gap-2">
        <Button title={devices ? 'Look again' : 'Choose printer'} variant="outline" icon="bluetooth" loading={busy === 'list'} onPress={list} className="flex-1" />
        {printer && (
          <Button
            title="Test print"
            variant="outline"
            icon="document-text-outline"
            loading={busy === 'test'}
            onPress={() =>
              run('test', async () => {
                await sendToPrinter(printer.address, testPage(paper, printer.name));
                return 'Test page sent. If the last digits are cut off, set the paper width on the web (Counter settings).';
              })
            }
            className="flex-1"
          />
        )}
      </View>

      {printer && (
        <View className="flex-row items-center gap-3 border-t border-slate-100 pt-3">
          <View className="flex-1">
            <Text className="text-base text-slate-800">Open the cash drawer on cash sales</Text>
            <Text className="text-sm text-slate-500">For a drawer plugged into the printer.</Text>
          </View>
          <Switch value={openDrawer} onValueChange={setOpenDrawer} accessibilityLabel="Open the cash drawer on cash sales" />
        </View>
      )}
      {printer && openDrawer && (
        <Button
          title="Open the drawer now"
          variant="ghost"
          icon="cash-outline"
          loading={busy === 'drawer'}
          onPress={() =>
            run('drawer', async () => {
              await sendToPrinter(printer.address, drawerKick());
              return null;
            })
          }
        />
      )}

      {message && <Banner tone={message.tone}>{message.text}</Banner>}
    </Card>
  );
}
