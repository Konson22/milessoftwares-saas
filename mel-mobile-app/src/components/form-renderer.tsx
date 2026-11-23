import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Image, Pressable, Text, TextInput, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { currentPlace, takePhoto } from '@/collect/capture';
import { choicesOf, flatten, type Answers, type Definition, type GeoPoint, type Item as Question } from '@/collect/definition';
import type { Evaluation } from '@/collect/rules';
import type { PhotoFile } from '@/collect/types';
import { colors, webNoOutline } from '@/lib/theme';

/*
 * Draws a form from its definition: only the questions the rules make
 * visible, in order, sections as headings. The rules engine (the same as
 * the server's) decides visibility, requirement and errors; this only
 * renders and collects.
 */

export const ERROR_TEXT: Record<string, string> = {
  required: 'An answer is needed.',
  type: 'This answer is not in the right form.',
  min: 'This is below the lowest allowed.',
  max: 'This is above the highest allowed.',
  min_length: 'This is too short.',
  max_length: 'This is too long.',
  pattern: 'Use digits only.',
  choice: 'Choose one of the options.',
  min_choices: 'Choose more options.',
  max_choices: 'Choose fewer options.',
  attachment: 'Take the photo again.',
  photo_locked: 'Photos cannot be changed in a correction.',
};

export const errorText = (code: string) => ERROR_TEXT[code] ?? code;

type Props = {
  definition: Definition;
  answers: Answers;
  evaluation: Evaluation;
  photos: Record<string, PhotoFile>;
  photoMaxKb: number;
  /** Errors shown: after a first attempt to finish. */
  showErrors: boolean;
  /** Reviewer notes by question (a correction). */
  notes?: Record<string, string>;
  /** Photos cannot change in a correction. */
  photosLocked?: boolean;
  /** Only these items (one page of a long form); all when omitted. */
  only?: string[];
  onChange: (name: string, value: unknown, photo?: PhotoFile | null) => void;
  onError: (message: string) => void;
};

export function FormRenderer({ definition, answers, evaluation, photos, photoMaxKb, showErrors, notes = {}, photosLocked, only, onChange, onError }: Props) {
  const visible = new Set(evaluation.visible);
  const required = new Set(evaluation.required);
  const page = only ? new Set(only) : null;

  return (
    <View className="gap-5">
      {flatten(definition.items)
        .filter((q) => visible.has(q.name) && (!page || page.has(q.name)))
        .map((q) =>
          q.type === 'group' ? (
            <Text key={q.name} accessibilityRole="header" className="mt-2 border-b border-slate-300 pb-1 text-lg font-bold text-slate-800">
              {q.label}
            </Text>
          ) : q.type === 'note' ? (
            <Text key={q.name} className="text-base text-slate-600">
              {q.label}
            </Text>
          ) : (
            <View key={q.name} className="gap-1.5">
              <Text className="text-base font-medium text-slate-800">
                {q.label}
                {required.has(q.name) ? <Text className="text-red-600"> *</Text> : null}
              </Text>
              {q.hint && <Text className="text-sm text-slate-500">{q.hint}</Text>}
              {notes[q.name] && (
                <View className="flex-row gap-1.5 border-l-4 border-amber-400 bg-amber-50 px-2 py-1.5">
                  <Ionicons name="chatbubble-ellipses-outline" size={16} color={colors.warning} />
                  <Text className="flex-1 text-sm text-amber-900">{notes[q.name]}</Text>
                </View>
              )}
              <Answer
                question={q}
                definition={definition}
                value={answers[q.name]}
                photo={photos[q.name]}
                photoMaxKb={photoMaxKb}
                photosLocked={photosLocked}
                onChange={(value, photo) => onChange(q.name, value, photo)}
                onError={onError}
              />
              {showErrors && evaluation.errors[q.name] && (
                <Text accessibilityRole="alert" className="text-sm text-red-700">
                  {errorText(evaluation.errors[q.name])}
                </Text>
              )}
            </View>
          ),
        )}
    </View>
  );
}

function Answer({
  question: q,
  definition,
  value,
  photo,
  photoMaxKb,
  photosLocked,
  onChange,
  onError,
}: {
  question: Question;
  definition: Definition;
  value: unknown;
  photo?: PhotoFile;
  photoMaxKb: number;
  photosLocked?: boolean;
  onChange: (value: unknown, photo?: PhotoFile | null) => void;
  onError: (message: string) => void;
}) {
  const [busy, setBusy] = useState(false);

  switch (q.type) {
    case 'text':
    case 'textarea':
      return <Input value={typeof value === 'string' ? value : ''} multiline={q.type === 'textarea'} onChange={(t) => onChange(t === '' ? undefined : t)} />;

    case 'integer':
    case 'decimal':
      return (
        <Input
          value={value === undefined || value === null ? '' : String(value as number | string)}
          keyboard={q.type === 'integer' ? 'number-pad' : 'decimal-pad'}
          onChange={(t) => {
            const trimmed = t.trim().replace(',', '.');
            if (trimmed === '') return onChange(undefined);
            const n = Number(trimmed);
            // Kept as typed until it is a number, so the rules can say why.
            onChange(Number.isFinite(n) && /^-?\d+(\.\d+)?$/.test(trimmed) ? n : t);
          }}
        />
      );

    case 'date':
    case 'time':
    case 'datetime': {
      const kind = q.type;
      const placeholder = { date: 'YYYY-MM-DD', time: 'HH:MM', datetime: 'YYYY-MM-DDTHH:MM' }[kind];
      return (
        <View className="flex-row gap-2">
          <View className="flex-1">
            <Input value={typeof value === 'string' ? value : ''} placeholder={placeholder} keyboard="numbers-and-punctuation" onChange={(t) => onChange(t === '' ? undefined : t)} />
          </View>
          <Button title={kind === 'date' ? 'Today' : 'Now'} variant="outline" size="lg" onPress={() => onChange(nowFor(kind))} />
        </View>
      );
    }

    case 'select_one':
    case 'yes_no':
      return (
        <View className="gap-2">
          {choicesOf(definition, q).map((c) => (
            <Choice key={c.name} label={c.label} selected={value === c.name} round onPress={() => onChange(value === c.name ? undefined : c.name)} />
          ))}
        </View>
      );

    case 'select_multiple': {
      const chosen = Array.isArray(value) ? (value as string[]) : [];
      return (
        <View className="gap-2">
          {choicesOf(definition, q).map((c) => (
            <Choice
              key={c.name}
              label={c.label}
              selected={chosen.includes(c.name)}
              onPress={() => {
                const next = chosen.includes(c.name) ? chosen.filter((x) => x !== c.name) : [...chosen, c.name];
                onChange(next.length ? next : undefined);
              }}
            />
          ))}
        </View>
      );
    }

    case 'geopoint': {
      const point = value as GeoPoint | undefined;
      return (
        <View className="gap-2">
          {point && (
            <Text className="text-sm text-slate-700">
              {point.lat.toFixed(5)}, {point.lng.toFixed(5)}
              {point.accuracy !== undefined ? ` (± ${point.accuracy} m)` : ''}
            </Text>
          )}
          <Button
            title={point ? 'Record the location again' : 'Record the location'}
            icon="locate-outline"
            variant="outline"
            loading={busy}
            onPress={() => {
              setBusy(true);
              currentPlace()
                .then((p) => onChange(p))
                .catch((e: unknown) => onError(e instanceof Error ? e.message : 'The location could not be found.'))
                .finally(() => setBusy(false));
            }}
          />
          {point?.accuracy !== undefined && point.accuracy > 50 && (
            <Text className="text-sm text-amber-800">The location is rough. Try again in the open if you can.</Text>
          )}
        </View>
      );
    }

    case 'photo':
      return (
        <View className="gap-2">
          {photo && typeof value === 'string' && <Image source={{ uri: photo.uri }} accessibilityLabel={q.label} style={{ width: '100%', aspectRatio: 4 / 3 }} resizeMode="cover" />}
          {photosLocked ? (
            <Text className="text-sm text-slate-500">Photos cannot be changed in a correction.</Text>
          ) : (
            <View className="flex-row gap-2">
              <Button
                title={value ? 'Take again' : 'Take a photo'}
                icon="camera-outline"
                variant="outline"
                loading={busy}
                className="flex-1"
                onPress={() => {
                  setBusy(true);
                  takePhoto(photoMaxKb)
                    .then((p) => {
                      if (p) onChange(p.sha256, p);
                    })
                    .catch((e: unknown) => onError(e instanceof Error ? e.message : 'The photo could not be taken.'))
                    .finally(() => setBusy(false));
                }}
              />
              {typeof value === 'string' && <Button title="Remove" variant="ghost" onPress={() => onChange(undefined, null)} />}
            </View>
          )}
        </View>
      );

    default:
      return <Text className="text-sm text-slate-500">This question needs a newer app.</Text>;
  }
}

/** The phone's current date or time, in the form the rules read. */
export function nowFor(type: 'date' | 'time' | 'datetime', now = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  const time = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  return type === 'date' ? date : type === 'time' ? time : `${date}T${time}`;
}

/** An answer as the collector reads it back on the review page. */
export function answerText(definition: Definition, q: Question, value: unknown): string {
  if (value === undefined || value === null || value === '') return '';
  if (q.type === 'select_one' || q.type === 'yes_no') return choicesOf(definition, q).find((c) => c.name === value)?.label ?? String(value);
  if (q.type === 'select_multiple' && Array.isArray(value)) {
    const choices = choicesOf(definition, q);
    return value.map((v) => choices.find((c) => c.name === v)?.label ?? String(v)).join(', ');
  }
  if (q.type === 'geopoint' && typeof value === 'object') {
    const p = value as GeoPoint;
    return `${p.lat.toFixed(5)}, ${p.lng.toFixed(5)}${p.accuracy !== undefined ? ` (± ${p.accuracy} m)` : ''}`;
  }
  if (q.type === 'photo') return 'Photo taken';
  return String(value as string | number);
}

function Input({
  value,
  onChange,
  multiline,
  placeholder,
  keyboard,
}: {
  value: string;
  onChange: (text: string) => void;
  multiline?: boolean;
  placeholder?: string;
  keyboard?: 'number-pad' | 'decimal-pad' | 'numbers-and-punctuation';
}) {
  return (
    <TextInput
      value={value}
      onChangeText={onChange}
      multiline={multiline}
      placeholder={placeholder}
      placeholderTextColor={colors.faint}
      keyboardType={keyboard ?? 'default'}
      className={`border border-slate-300 bg-white px-3 text-base text-slate-900 ${multiline ? 'min-h-24 py-2' : 'min-h-12'}`}
      style={[webNoOutline, multiline ? { textAlignVertical: 'top' } : null]}
    />
  );
}

function Choice({ label, selected, round, onPress }: { label: string; selected: boolean; round?: boolean; onPress: () => void }) {
  return (
    <Pressable
      accessibilityRole={round ? 'radio' : 'checkbox'}
      accessibilityState={{ checked: selected }}
      onPress={onPress}
      className={`min-h-12 flex-row items-center gap-3 border px-3 ${selected ? 'border-brand-500 bg-brand-50' : 'border-slate-300 bg-white'}`}>
      <Ionicons
        name={round ? (selected ? 'radio-button-on' : 'radio-button-off') : selected ? 'checkbox' : 'square-outline'}
        size={22}
        color={selected ? colors.brand[600] : colors.faint}
      />
      <Text className="flex-1 text-base text-slate-800">{label}</Text>
    </Pressable>
  );
}
