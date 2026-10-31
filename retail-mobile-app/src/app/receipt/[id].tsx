import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { STATUS_BADGE } from '@/components/sale-status';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, Divider, Hero, Row } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useFocusedQuery } from '@/hooks/use-async';
import { api, errorMessage } from '@/lib/api';
import { confirm, haptic, notify } from '@/lib/feedback';
import { dateTime, fullDate, money } from '@/lib/format';
import { documentHtml, invoiceDocument, shareHtml } from '@/lib/documents';
import { openCashDrawer, printReceipt, shareReceiptPdf, shareReceiptText } from '@/lib/receipt';
import { colors } from '@/lib/theme';
import type { Sale } from '@/lib/types';
import { useAuth } from '@/store/auth';

export default function ReceiptScreen() {
  const { id, done, duplicate } = useLocalSearchParams<{ id: string; done?: string; duplicate?: string }>();
  const settings = useAuth((s) => s.counter?.receipt);
  const refreshTill = useAuth((s) => s.refreshTill);
  const { data: sale, setData, error, loading } = useFocusedQuery(() => api.sale(id), id);
  const [voiding, setVoiding] = useState(false);
  const justSold = done === '1';
  const kicked = useRef(false);

  // A new cash sale: the drawer opens once (on this device's printer, when set to).
  const cashSale = justSold && duplicate !== '1' && sale?.payment_method === 'cash' && sale.status === 'completed';
  useEffect(() => {
    if (!cashSale || kicked.current) return;
    kicked.current = true;
    void openCashDrawer();
  }, [cashSale]);

  /** Every print or share is logged with the shop, which numbers the copies. */
  async function output(kind: 'print' | 'pdf' | 'text' | 'a4') {
    if (!sale || !settings) return;
    try {
      const { copy } = await api.printed(sale.id).catch(() => ({ copy: null }));
      if (kind === 'a4') await shareHtml(documentHtml(settings, invoiceDocument(sale)), `Invoice ${sale.number}`);
      else if (kind === 'print') await printReceipt(sale, settings, copy);
      else if (kind === 'pdf') await shareReceiptPdf(sale, settings, copy);
      else await shareReceiptText(sale, settings);
    } catch (e) {
      notify('Could not complete', errorMessage(e));
    }
  }

  if (!sale || !settings) {
    return (
      <SafeAreaView className="flex-1 items-center justify-center gap-4 bg-canvas p-8">
        {loading ? <ActivityIndicator size="large" color={colors.brand[600]} /> : <Banner tone="error">{error ?? 'Sale not found.'}</Banner>}
        {!loading && <Button title="Back" onPress={() => router.back()} />}
      </SafeAreaView>
    );
  }

  const status = STATUS_BADGE[sale.status];
  const returned = sale.returns.reduce((sum, r) => sum + r.total_minor, 0);

  return (
    <SafeAreaView className="flex-1 bg-canvas" edges={['bottom']}>
      <ScreenHeader
        title={sale.number}
        icon={justSold ? undefined : 'arrow-back'}
        onIcon={() => router.back()}
        right={
          <View className="pr-3">
            <Badge label={sale.returns.length && sale.status === 'completed' ? 'Part returned' : status.label} tone={status.tone} />
          </View>
        }
      />

      <ScrollView contentContainerClassName="w-full max-w-xl self-center gap-4 p-4">
        {justSold && (
          <Hero className="items-center gap-2 py-7">
            <View className="h-16 w-16 items-center justify-center rounded-full bg-emerald-500">
              <Ionicons name="checkmark" size={38} color={colors.white} />
            </View>
            <Text className="text-base font-medium text-brand-100">
              {duplicate === '1' ? 'This sale had already gone through: here is its receipt' : sale.invoice ? `On ${sale.customer?.name ?? 'the customer'}’s account` : 'Sale completed'}
            </Text>
            <Text className="text-4xl font-bold text-white">{money(sale.total_minor)}</Text>
            {sale.change_minor != null && sale.change_minor > 0 && (
              <View className="mt-1 rounded-2xl bg-white px-5 py-2">
                <Text className="text-center text-sm font-medium text-brand-800">Change due</Text>
                <Text className="text-center text-2xl font-bold text-brand-900">{money(sale.change_minor)}</Text>
              </View>
            )}
          </Hero>
        )}

        {sale.status === 'voided' && (
          <Banner tone="error">
            Voided {sale.voided_at ? dateTime(sale.voided_at) : ''}
            {sale.voided_by ? ` by ${sale.voided_by}` : ''}: {sale.void_reason}
          </Banner>
        )}

        {/* The receipt on screen, to show the customer. */}
        <View className="gap-1 border border-slate-200 bg-white p-5 shadow-sm shadow-brand-900/10">
          {settings.business_name && <Text className="text-center text-lg font-bold text-slate-900">{settings.business_name}</Text>}
          {settings.address && <Text className="text-center text-sm text-slate-500">{settings.address}</Text>}
          <Divider />
          <Row label={sale.invoice ? 'Invoice' : 'Receipt'} value={sale.number} />
          <Row label="Date" value={dateTime(sale.sold_at)} />
          <Row label="Served by" value={sale.sold_by} />
          {sale.customer_name && <Row label="Customer" value={sale.customer_name} />}
          <Divider />
          {sale.receipt_lines.map((l, i) => (
            <View key={`${l.name}-${i}`} className="py-1">
              <View className="flex-row justify-between gap-2">
                <Text className="flex-1 text-base text-slate-800">{l.name}</Text>
                <Text className="text-base font-medium text-slate-900">{money(l.line_total_minor)}</Text>
              </View>
              <Text className="text-sm text-slate-500">
                {l.quantity} × {money(l.unit_price_minor)}
                {l.pack ? ` (${l.pack})` : ''}
                {l.discount_minor ? ` · −${money(l.discount_minor)}` : ''}
              </Text>
            </View>
          ))}
          <Divider />
          {sale.discount_minor > 0 && <Row label="Discount" value={`−${money(sale.discount_minor)}`} />}
          <Row label="Total" value={money(sale.total_minor)} strong />
          <Divider />
          <Row label="Paid by" value={sale.payment_method_label} />
          {sale.amount_tendered_minor != null && <Row label="Cash given" value={money(sale.amount_tendered_minor)} />}
          {sale.change_minor != null && <Row label="Change" value={money(sale.change_minor)} />}
          {sale.payment_reference && <Row label="Reference" value={sale.payment_reference} />}
          {sale.due_on && <Row label="Due" value={fullDate(sale.due_on)} />}
          {sale.invoice && sale.customer && <Row label={`${sale.customer.name} owes now`} value={money(sale.customer.balance_minor)} />}
          {returned > 0 && <Row label="Refunded" value={`−${money(returned)}`} tone="text-red-700" />}
          {settings.footer && <Text className="mt-3 text-center text-sm text-slate-500">{settings.footer}</Text>}
        </View>

        {sale.returns.length > 0 && (
          <View className="gap-1 border border-slate-200 bg-white p-4">
            {sale.returns.map((r) => (
              <Row key={r.id} label={`${r.number} · ${r.reason}`} value={`−${money(r.total_minor)}`} />
            ))}
          </View>
        )}

        <View className="flex-row gap-2">
          <Button title="Print" variant="outline" icon="print-outline" onPress={() => output('print')} className="flex-1" />
          <Button title="PDF" variant="outline" icon="document-outline" onPress={() => output('pdf')} className="flex-1" />
          <Button title="Text" variant="outline" icon="share-social-outline" onPress={() => output('text')} className="flex-1" />
        </View>
        {sale.invoice && <Button title="Share A4 invoice" variant="outline" icon="document-text-outline" onPress={() => output('a4')} />}

        {!justSold && (sale.can_void || sale.can_return) && (
          <View className="gap-2">
            {sale.can_return && (
              <Button
                title="Take a return"
                variant="outline"
                icon="return-down-back-outline"
                onPress={() => router.push({ pathname: '/return/[id]', params: { id: sale.id } })}
              />
            )}
            {sale.can_void && <Button title="Void sale" variant="outline" icon="close-circle-outline" onPress={() => setVoiding(true)} />}
          </View>
        )}
      </ScrollView>

      {justSold && (
        <View className="w-full max-w-xl self-center border-t border-slate-200 bg-white p-4 shadow-lg shadow-brand-900/10">
          <Button title="New sale" size="lg" icon="add-circle" onPress={() => router.dismissTo('/sell')} />
        </View>
      )}

      <VoidSheet
        visible={voiding}
        onClose={() => setVoiding(false)}
        sale={sale}
        onDone={(updated) => {
          setData(updated);
          setVoiding(false);
          refreshTill().catch(() => {});
        }}
      />
    </SafeAreaView>
  );
}

function VoidSheet({ visible, onClose, sale, onDone }: { visible: boolean; onClose: () => void; sale: Sale; onDone: (s: Sale) => void }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (!reason.trim()) return setError('Say why the sale is voided.');
    if (!(await confirm('Void this sale?', sale.invoice ? `${money(sale.total_minor)} comes off the customer’s account and the stock goes back.` : `${money(sale.total_minor)} is refunded and the stock goes back.`, 'Void', true))) return;
    setBusy(true);
    setError(null);
    try {
      onDone(await api.voidSale(sale.id, reason.trim()));
      haptic.success();
      setReason('');
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Sheet visible={visible} onClose={onClose} title="Void sale">
      <Text className="text-base text-slate-500">
        {sale.invoice
          ? `The invoice no longer counts on ${sale.customer?.name ?? 'the customer'}’s account, and every item goes back on the shelf.`
          : `The payment is refunded from your shift (${sale.payment_method_label.toLowerCase()}) and every item goes back on the shelf.`}
      </Text>
      <Field label="Reason" value={reason} onChangeText={setReason} placeholder="e.g. Wrong item scanned" multiline maxLength={255} />
      {error && <Banner tone="error">{error}</Banner>}
      <Button title="Void sale" variant="danger" size="lg" loading={busy} onPress={submit} />
    </Sheet>
  );
}
