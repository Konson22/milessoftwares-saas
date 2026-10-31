import { router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { CustomerPicker } from '@/components/customer-picker';
import { PaymentForm, paymentPayload, usePayment } from '@/components/payment-form';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, Card, Divider, Row, SectionTitle } from '@/components/ui/misc';
import { api, ApiError, errorMessage } from '@/lib/api';
import { haptic } from '@/lib/feedback';
import { money } from '@/lib/format';
import type { CustomerOption, SalePayload } from '@/lib/types';
import { useAuth } from '@/store/auth';
import { cartTotals, linePrice, packOf, useCart } from '@/store/cart';

/** The customer (when the branch asks for one), the payment, and the sale. */
export default function CheckoutScreen() {
  const cart = useCart();
  const counter = useAuth((s) => s.counter);
  const refreshTill = useAuth((s) => s.refreshTill);
  const [payment, setPayment] = usePayment();
  const [customer, setCustomer] = useState<CustomerOption | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const totals = cartTotals(cart.lines);

  if (cart.lines.length === 0 || !counter) {
    return (
      <SafeAreaView className="flex-1 items-center justify-center gap-4 bg-canvas p-8">
        <Text className="text-lg font-semibold text-slate-900">Cart is empty</Text>
        <Button title="Back to the counter" onPress={() => router.back()} />
      </SafeAreaView>
    );
  }

  const rules = counter.counter;
  const onAccount = payment.method === 'account';
  const customerRequired =
    rules.require_customer_always ||
    (rules.require_customer_above_minor !== null && totals.total > rules.require_customer_above_minor);

  async function complete() {
    const paid = paymentPayload(payment, totals.total);
    if (!paid.ok) return fail(paid.error);
    if (onAccount) {
      if (!customer) return fail('Choose the customer whose account it goes on.');
      if (!customer.on_account) return fail(`${customer.name} does not buy on account. A supervisor can allow it under Customers.`);
      if (customer.available_minor !== null && totals.total > customer.available_minor) {
        return fail(`Over ${customer.name}’s credit limit: ${money(customer.available_minor)} is left.`);
      }
    } else if (customerRequired && !cart.customerName.trim()) {
      return fail(
        rules.require_customer_always
          ? 'This branch records the customer on every sale. Enter their name.'
          : `This branch records the customer on sales over ${money(rules.require_customer_above_minor)}. Enter their name.`,
      );
    }

    const payload: SalePayload = {
      items: cart.lines.map((l) => ({
        item_id: l.item.id,
        quantity: l.quantity,
        discount_percent: l.discount_percent || undefined,
        pack_id: l.pack_id,
      })),
      submission_id: cart.submissionId,
      payment_method: paid.payment_method,
      payment_reference: paid.payment_reference,
      amount_tendered_minor: paid.amount_tendered_minor,
      customer_name: onAccount ? null : cart.customerName.trim() || null,
      customer_phone: onAccount ? null : cart.customerPhone.trim() || null,
      customer_id: onAccount ? customer!.id : null,
    };

    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const { sale, duplicate } = await api.sell(payload);
      haptic.success();
      refreshTill().catch(() => {});
      router.replace({ pathname: '/receipt/[id]', params: { id: sale.id, done: '1', duplicate: duplicate ? '1' : '' } });
      cart.clear();
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        setFieldErrors({ customer_name: e.field('customer_name') ?? '', customer_id: e.field('customer_id') ?? '', amount_tendered: e.field('amount_tendered') ?? '' });
        // A line's stock or discount changed since it was added: say which.
        const lineError = Object.entries(e.errors).find(([k]) => k.startsWith('items.'));
        if (lineError) {
          const line = cart.lines[Number(lineError[0].split('.')[1])];
          return fail(line ? `${line.item.name}: ${lineError[1][0]}` : lineError[1][0]);
        }
      }
      fail(errorMessage(e));
    }
  }

  function fail(message: string) {
    haptic.error();
    setError(message);
  }

  return (
    <SafeAreaView className="flex-1 bg-canvas" edges={['bottom']}>
      <ScreenHeader title="Checkout" subtitle={`${cart.lines.length} item${cart.lines.length === 1 ? '' : 's'} · ${money(totals.total)}`} icon="close" iconLabel="Close checkout" onIcon={() => router.back()} />

      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <ScrollView contentContainerClassName="w-full max-w-2xl self-center gap-4 p-4" keyboardShouldPersistTaps="handled">
          <Card className="gap-1">
            {cart.lines.map((l) => {
              const pack = packOf(l);
              return (
                <View key={l.item.id} className="flex-row justify-between gap-3 py-1">
                  <Text className="flex-1 text-base text-slate-700">
                    {l.quantity} × {l.item.name}
                    {pack ? ` (${pack.name.toLowerCase()} of ${pack.units})` : ''}
                    {l.discount_percent ? `, ${l.discount_percent}% off` : ''}
                  </Text>
                  <Text className="text-base font-medium text-slate-900">{money(linePrice(l).total)}</Text>
                </View>
              );
            })}
            <Divider />
            {totals.discount > 0 && <Row label="Discount" value={`−${money(totals.discount)}`} tone="text-emerald-700" />}
            <Row label="Total" value={money(totals.total)} strong />
          </Card>

          {!onAccount && (
            <>
              <SectionTitle>{customerRequired ? 'Customer (required)' : 'Customer (optional)'}</SectionTitle>
              <View className="flex-row gap-3">
                <Field
                  label="Name"
                  value={cart.customerName}
                  onChangeText={(name) => cart.setCustomer({ name })}
                  autoCapitalize="words"
                  placeholder="Walk-in"
                  className="flex-[3]"
                  error={fieldErrors.customer_name || null}
                />
                <Field
                  label="Phone"
                  value={cart.customerPhone}
                  onChangeText={(phone) => cart.setCustomer({ phone })}
                  keyboardType="phone-pad"
                  className="flex-[2]"
                />
              </View>
            </>
          )}

          <PaymentForm payment={payment} onChange={setPayment} totalMinor={totals.total} />

          {onAccount && <CustomerPicker value={customer} onChange={setCustomer} error={fieldErrors.customer_id || null} />}

          {fieldErrors.amount_tendered ? <Banner tone="error">{fieldErrors.amount_tendered}</Banner> : null}
          {error && <Banner tone="error">{error}</Banner>}
        </ScrollView>
      </KeyboardAvoidingView>

      <View className="w-full max-w-2xl self-center border-t border-slate-200 bg-white p-4 shadow-lg shadow-brand-900/10">
        <Button title={`${onAccount ? 'Invoice on account' : 'Complete sale'} · ${money(totals.total)}`} variant="accent" size="lg" icon="checkmark-circle" loading={busy} onPress={complete} />
      </View>
    </SafeAreaView>
  );
}
