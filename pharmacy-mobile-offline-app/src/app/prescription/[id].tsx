import { randomUUID } from 'expo-crypto';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { PaymentForm, paymentPayload, usePayment } from '@/components/payment-form';
import { RX_BADGE } from '@/components/sale-status';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge, Banner, Card, Divider, Row, RxBadges, SectionTitle } from '@/components/ui/misc';
import { QtyStepper } from '@/components/ui/qty-stepper';
import { Sheet } from '@/components/ui/sheet';
import { useFocusedQuery } from '@/hooks/use-async';
import { api, ApiError, errorMessage } from '@/lib/api';
import { confirm, haptic, notify } from '@/lib/feedback';
import { dateTime, money, prettyDate } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { Prescription } from '@/lib/types';
import { useAuth } from '@/store/auth';

export default function PrescriptionScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { data: rx, setData, error, loading } = useFocusedQuery(() => api.prescription(id), id);
  const [rejecting, setRejecting] = useState(false);
  const [verifying, setVerifying] = useState(false);

  if (!rx) {
    return (
      <SafeAreaView className="flex-1 items-center justify-center gap-4 bg-canvas p-8">
        {loading ? <ActivityIndicator size="large" color={colors.brand[600]} /> : <Banner tone="error">{error ?? 'Prescription not found.'}</Banner>}
        {!loading && <Button title="Back" onPress={() => router.back()} />}
      </SafeAreaView>
    );
  }

  async function verify() {
    if (!(await confirm('Verify this prescription?', 'You checked the prescription and the medicines suit the patient.', 'Verify'))) return;
    setVerifying(true);
    try {
      setData(await api.verifyPrescription(rx!.id));
      haptic.success();
    } catch (e) {
      notify('Could not verify', errorMessage(e));
    } finally {
      setVerifying(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-canvas" edges={['bottom']}>
      <ScreenHeader
        title={rx.patient_name}
        subtitle={rx.number}
        icon="arrow-back"
        onIcon={() => router.back()}
        right={
          <View className="pr-3">
            <Badge label={rx.status_label} tone={RX_BADGE[rx.status]} />
          </View>
        }
      />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <ScrollView contentContainerClassName="w-full max-w-2xl self-center gap-4 p-4" keyboardShouldPersistTaps="handled">
          {rx.expired && <Banner tone="error">This prescription expired on {prettyDate(rx.expires_on)}; it can no longer be dispensed.</Banner>}
          {rx.status === 'rejected' && rx.rejection_reason && <Banner tone="error">Rejected: {rx.rejection_reason}</Banner>}

          <Card className="gap-1">
            <Row label="Patient" value={`${rx.patient_name}${rx.patient_age !== null ? `, ${rx.patient_age}` : ''}`} />
            <Row label="Prescriber" value={rx.prescriber_name} />
            {rx.prescriber_registration && <Row label="Registration" value={rx.prescriber_registration} />}
            <Row label="Issued" value={prettyDate(rx.issued_on)} />
            <Row label="Valid until" value={prettyDate(rx.expires_on)} />
            <Divider />
            <Row label="Recorded by" value={rx.recorded_by} />
            {rx.verified_by && <Row label="Verified by" value={rx.verified_by} />}
            {rx.notes && <Text className="mt-1 text-sm italic text-slate-500">{rx.notes}</Text>}
          </Card>

          <Card className="gap-2">
            <SectionTitle>Medicines</SectionTitle>
            {rx.items.map((item) => (
              <View key={item.id} className="gap-0.5 border-t border-slate-100 pt-2">
                <View className="flex-row flex-wrap items-center gap-1.5">
                  <Text className="text-base font-semibold text-slate-900">{item.name}</Text>
                  <RxBadges cd={item.controlled} />
                </View>
                <Text className="text-sm text-slate-600">{item.directions}</Text>
                <Text className="text-sm text-slate-500">
                  {item.quantity} {item.unit} prescribed · {item.dispensed} given · {item.available} in stock
                </Text>
              </View>
            ))}
          </Card>

          {rx.sales.length > 0 && (
            <Card className="gap-1">
              <SectionTitle>Dispensed</SectionTitle>
              {rx.sales.map((s) => (
                <Pressable
                  key={s.id}
                  accessibilityRole="link"
                  onPress={() => router.push({ pathname: '/receipt/[id]', params: { id: s.id } })}
                  className="min-h-11 flex-row items-center justify-between">
                  <Text className={`text-base ${s.voided ? 'text-slate-400 line-through' : 'text-brand-700'}`}>
                    {s.number} · {dateTime(s.sold_at)}
                  </Text>
                  <Text className="text-base font-medium text-slate-900">{money(s.total_minor)}</Text>
                </Pressable>
              ))}
            </Card>
          )}

          {(rx.can.verify || rx.can.reject) && (
            <View className="flex-row gap-2">
              {rx.can.reject && (
                <Button title="Reject" variant="outline" icon="close-circle-outline" onPress={() => setRejecting(true)} className="flex-1" />
              )}
              {rx.can.verify && <Button title="Verify" icon="shield-checkmark" loading={verifying} onPress={verify} className="flex-[2]" />}
            </View>
          )}
          {rx.status === 'recorded' && !rx.can.verify && <Banner tone="info">Waiting for a pharmacist to verify it.</Banner>}

          {rx.can.dispense && <Dispense rx={rx} onDone={setData} />}
        </ScrollView>
      </KeyboardAvoidingView>

      <RejectSheet
        visible={rejecting}
        rx={rx}
        onClose={() => setRejecting(false)}
        onDone={(updated) => {
          setData(updated);
          setRejecting(false);
        }}
      />
    </SafeAreaView>
  );
}

/** Gives what is left (or some of it) and takes payment: a sale against the prescription. */
function Dispense({ rx, onDone }: { rx: Prescription; onDone: (rx: Prescription) => void }) {
  const till = useAuth((s) => s.till);
  const shiftsRequired = useAuth((s) => s.counter?.counter.shifts_required ?? true);
  const refreshTill = useAuth((s) => s.refreshTill);
  const open = rx.items.filter((i) => i.remaining > 0);
  const [quantities, setQuantities] = useState<Record<string, number>>(() =>
    Object.fromEntries(open.map((i) => [i.id, Math.min(i.remaining, i.available)])),
  );
  const [payment, setPayment] = usePayment();
  // Kept across retries: a dropped connection never dispenses twice.
  const [submissionId, setSubmissionId] = useState(() => randomUUID());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const total = open.reduce((sum, i) => sum + (quantities[i.id] ?? 0) * i.unit_price_minor, 0);
  const giving = open.some((i) => (quantities[i.id] ?? 0) > 0);

  if (shiftsRequired && !till) {
    return (
      <Card className="gap-3">
        <SectionTitle>Dispense</SectionTitle>
        <Banner tone="warning">Start your shift before taking payment.</Banner>
        <Button title="Go to Shift" variant="outline" icon="cash-outline" onPress={() => router.navigate('/shift')} />
      </Card>
    );
  }

  async function dispense() {
    const paid = paymentPayload(payment, total);
    if (!paid.ok) return setError(paid.error);
    if (!giving) return setError('Choose what to give now.');
    setBusy(true);
    setError(null);
    try {
      const result = await api.dispense(rx.id, {
        submission_id: submissionId,
        payment_method: paid.payment_method,
        payment_reference: paid.payment_reference,
        amount_tendered_minor: paid.amount_tendered_minor,
        quantities,
      });
      haptic.success();
      refreshTill().catch(() => {});
      onDone(result.prescription);
      setSubmissionId(randomUUID());
      router.push({ pathname: '/receipt/[id]', params: { id: result.sale.id, done: '1', duplicate: result.duplicate ? '1' : '' } });
    } catch (e) {
      haptic.error();
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Card className="gap-3">
        <SectionTitle>Give now</SectionTitle>
        {open.map((item) => {
          const max = Math.min(item.remaining, item.available);
          const qty = quantities[item.id] ?? 0;
          return (
            <View key={item.id} className="flex-row items-center gap-3 border-t border-slate-100 pt-3">
              <View className="flex-1">
                <Text className="text-base font-semibold text-slate-900">{item.name}</Text>
                <Text className="text-sm text-slate-500">
                  {item.remaining} left · {money(item.unit_price_minor)} per {item.unit}
                  {item.available < item.remaining ? ` · only ${item.available} in stock` : ''}
                </Text>
              </View>
              {max === 0 ? (
                <Text className="text-sm text-red-600">None in stock</Text>
              ) : qty > 0 ? (
                <QtyStepper value={qty} max={max} name={item.name} onChange={(q) => setQuantities((s) => ({ ...s, [item.id]: Math.max(0, q) }))} />
              ) : (
                <Button title="Give" variant="outline" onPress={() => setQuantities((s) => ({ ...s, [item.id]: max }))} />
              )}
            </View>
          );
        })}
        <Divider />
        <Row label="Total" value={money(total)} strong />
      </Card>

      {giving && <PaymentForm payment={payment} onChange={setPayment} totalMinor={total} />}
      {error && <Banner tone="error">{error}</Banner>}
      <Button title={`Dispense · ${money(total)}`} size="lg" icon="medkit" loading={busy} disabled={!giving} onPress={dispense} />
    </>
  );
}

function RejectSheet({ visible, rx, onClose, onDone }: { visible: boolean; rx: Prescription; onClose: () => void; onDone: (rx: Prescription) => void }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (!reason.trim()) return setError('Say why it is rejected.');
    setBusy(true);
    setError(null);
    try {
      onDone(await api.rejectPrescription(rx.id, reason.trim()));
      haptic.success();
      setReason('');
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Sheet visible={visible} onClose={onClose} title="Reject prescription">
      <Text className="text-base text-slate-500">Nothing is dispensed from a rejected prescription. The patient sees the prescriber again.</Text>
      <Field label="Reason" value={reason} onChangeText={setReason} placeholder="e.g. Dose too high for the patient's age" multiline maxLength={255} />
      {error && <Banner tone="error">{error}</Banner>}
      <Button title="Reject" variant="danger" size="lg" loading={busy} onPress={submit} />
    </Sheet>
  );
}
