import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ScreenHeader } from '@/components/screen-header';
import { Button, IconButton } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, Card, RxBadges, SectionTitle } from '@/components/ui/misc';
import { QtyStepper } from '@/components/ui/qty-stepper';
import { useDebounced } from '@/hooks/use-async';
import { api, ApiError, errorMessage } from '@/lib/api';
import { haptic } from '@/lib/feedback';
import { isoDate } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { Item } from '@/lib/types';

interface Line {
  item: Item;
  quantity: number;
  directions: string;
}

/** A prescription as it is handed in: recorded here, verified by a pharmacist before it is dispensed. */
export default function NewPrescriptionScreen() {
  const [patient, setPatient] = useState('');
  const [age, setAge] = useState('');
  const [prescriber, setPrescriber] = useState('');
  const [registration, setRegistration] = useState('');
  const [issued, setIssued] = useState(isoDate());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<Line[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const update = (index: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, i) => (i === index ? { ...l, ...patch } : l)));

  async function save() {
    const problems: Record<string, string> = {};
    if (!patient.trim()) problems.patient_name = 'Enter the patient’s name.';
    if (!prescriber.trim()) problems.prescriber_name = 'Enter the prescriber’s name.';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(issued) || issued > isoDate()) problems.issued_on = 'A date like 2026-10-22, not in the future.';
    if (age && !/^\d{1,3}$/.test(age)) problems.patient_age = 'Age in years.';
    setErrors(problems);
    if (Object.keys(problems).length) return;
    if (lines.length === 0) return setError('Add the medicines prescribed.');
    if (lines.some((l) => !l.directions.trim())) return setError('Enter the directions for every medicine.');

    setBusy(true);
    setError(null);
    try {
      const rx = await api.recordPrescription({
        patient_name: patient.trim(),
        patient_age: age ? Number(age) : null,
        prescriber_name: prescriber.trim(),
        prescriber_registration: registration.trim() || null,
        issued_on: issued,
        notes: notes.trim() || null,
        items: lines.map((l) => ({ medicine_id: l.item.id, quantity: l.quantity, directions: l.directions.trim() })),
      });
      haptic.success();
      router.replace({ pathname: '/prescription/[id]', params: { id: rx.id } });
    } catch (e) {
      haptic.error();
      if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      setError(errorMessage(e));
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-canvas" edges={['bottom']}>
      <ScreenHeader title="Record prescription" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <ScrollView contentContainerClassName="w-full max-w-2xl self-center gap-4 p-4" keyboardShouldPersistTaps="handled">
          <SectionTitle>Patient</SectionTitle>
          <View className="flex-row gap-3">
            <Field label="Name" value={patient} onChangeText={setPatient} autoCapitalize="words" className="flex-[3]" error={errors.patient_name} />
            <Field label="Age" value={age} onChangeText={setAge} keyboardType="number-pad" maxLength={3} className="flex-1" error={errors.patient_age} />
          </View>

          <SectionTitle>Prescriber</SectionTitle>
          <Field label="Name" value={prescriber} onChangeText={setPrescriber} autoCapitalize="words" placeholder="e.g. Dr. Peter Lado" error={errors.prescriber_name} />
          <View className="flex-row gap-3">
            <Field label="Registration (optional)" value={registration} onChangeText={setRegistration} autoCapitalize="characters" className="flex-1" />
            <Field
              label="Issued on"
              value={issued}
              onChangeText={setIssued}
              placeholder="YYYY-MM-DD"
              keyboardType="numbers-and-punctuation"
              className="flex-1"
              error={errors.issued_on}
            />
          </View>

          <SectionTitle>Medicines</SectionTitle>
          {lines.map((line, index) => (
            <Card key={line.item.id} className="gap-3">
              <View className="flex-row items-center gap-2">
                <View className="flex-1">
                  <View className="flex-row flex-wrap items-center gap-1.5">
                    <Text className="text-base font-semibold text-slate-900">{line.item.name}</Text>
                    <RxBadges rx={line.item.restricted} cd={line.item.controlled} />
                  </View>
                  <Text className="text-sm text-slate-500">{[line.item.strength, line.item.form].filter(Boolean).join(' · ')}</Text>
                </View>
                <IconButton icon="trash-outline" label={`Remove ${line.item.name}`} color={colors.danger} onPress={() => setLines((ls) => ls.filter((_, i) => i !== index))} />
              </View>
              <View className="flex-row items-center gap-3">
                <Text className="text-sm text-slate-600">Quantity ({line.item.unit})</Text>
                <QtyStepper value={line.quantity} max={100000} name={line.item.name} onChange={(q) => update(index, { quantity: Math.max(1, q) })} />
              </View>
              <Field
                label="Directions"
                value={line.directions}
                onChangeText={(directions) => update(index, { directions })}
                placeholder="e.g. 1 tablet three times a day for 5 days"
                maxLength={255}
                error={errors[`items.${index}.directions`] ?? errors[`items.${index}.medicine_id`]}
              />
            </Card>
          ))}
          <MedicinePicker
            exclude={lines.map((l) => l.item.id)}
            onPick={(item) => {
              setLines((ls) => [...ls, { item, quantity: 1, directions: '' }]);
              setError(null);
            }}
          />

          <Field label="Notes (optional)" value={notes} onChangeText={setNotes} multiline maxLength={1000} />
          {error && <Banner tone="error">{error}</Banner>}
        </ScrollView>
      </KeyboardAvoidingView>
      <View className="w-full max-w-2xl self-center border-t border-slate-200 bg-white p-4 shadow-lg shadow-brand-900/10">
        <Button title="Record for a pharmacist" size="lg" icon="document-text" loading={busy} onPress={save} />
      </View>
    </SafeAreaView>
  );
}

function MedicinePicker({ exclude, onPick }: { exclude: string[]; onPick: (item: Item) => void }) {
  const [query, setQuery] = useState('');
  const debounced = useDebounced(query.trim(), 250);
  const [results, setResults] = useState<{ query: string; items: Item[] }>({ query: '', items: [] });

  useEffect(() => {
    let active = true;
    if (!debounced) return;
    api
      .items(debounced)
      .then((items) => active && setResults({ query: debounced, items }))
      .catch(() => active && setResults({ query: debounced, items: [] }));
    return () => {
      active = false;
    };
  }, [debounced]);

  const shown = debounced && results.query === debounced ? results.items.filter((i) => !exclude.includes(i.id)).slice(0, 8) : [];

  return (
    <View className="gap-2">
      <Field label="Add a medicine" icon="search" value={query} onChangeText={setQuery} placeholder="Name or generic name" autoCorrect={false} />
      {shown.map((item) => (
        <Pressable
          key={item.id}
          accessibilityRole="button"
          onPress={() => {
            onPick(item);
            setQuery('');
          }}
          className="min-h-12 flex-row items-center gap-2 border border-slate-200 bg-white px-4 py-2 active:bg-brand-50">
          <View className="flex-1">
            <Text className="text-base text-slate-900">{item.name}</Text>
            <Text className="text-sm text-slate-500">{[item.generic_name, item.strength].filter(Boolean).join(' · ')}</Text>
          </View>
          <RxBadges rx={item.restricted} cd={item.controlled} />
        </Pressable>
      ))}
    </View>
  );
}
