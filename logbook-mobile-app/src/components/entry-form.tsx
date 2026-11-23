import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Image, Pressable, Text, TextInput, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Sheet } from '@/components/ui/sheet';
import { chooseDocument, choosePhoto, takePhoto } from '@/logbook/capture';
import { nowFor, visible } from '@/logbook/fields';
import type { FieldDef, KitResource, LocalFile, ServerFile, Values } from '@/logbook/types';
import { colors, webNoOutline } from '@/lib/theme';

/*
 * Draws a template version's fields, in order, and collects the answers.
 * Checking is done on finishing (logbook/fields.ts, the server's rules);
 * this only renders, and shows the errors it is given.
 */

type Props = {
  fields: FieldDef[];
  values: Values;
  errors: Record<string, string>;
  members: { id: string; name: string }[];
  /** Active resources, for resource fields. */
  resources?: KitResource[];
  files: LocalFile[];
  serverFiles: ServerFile[];
  removedFiles: string[];
  fileMaxKb: number;
  filesPerField: number;
  onChange: (name: string, value: unknown) => void;
  onAddFile: (file: LocalFile) => void;
  onRemoveFile: (file: { uri: string } | { id: string }) => void;
  onError: (message: string) => void;
};

export function EntryForm(props: Props) {
  const { fields, values, errors } = props;
  return (
    <View className="gap-5">
      {fields.filter((field) => visible(field, values, fields)).map((field) => (
        <View key={field.name} className="gap-1.5">
          <Text className="text-base font-medium text-slate-800">
            {field.label}
            {field.required ? <Text className="text-red-600"> *</Text> : null}
          </Text>
          {field.help ? <Text className="text-sm text-slate-500">{field.help}</Text> : null}
          <Answer field={field} value={values[field.name]} {...props} />
          {errors[field.name] ? (
            <Text accessibilityRole="alert" className="text-sm text-red-700">
              {errors[field.name]}
            </Text>
          ) : null}
        </View>
      ))}
    </View>
  );
}

function Answer({ field, value, members, resources = [], files, serverFiles, removedFiles, fileMaxKb, filesPerField, onChange, onAddFile, onRemoveFile, onError }: Props & { field: FieldDef; value: unknown }) {
  const set = (v: unknown) => onChange(field.name, v === '' ? undefined : v);

  switch (field.type) {
    case 'text':
    case 'textarea':
      return <Input value={typeof value === 'string' ? value : ''} multiline={field.type === 'textarea'} placeholder={field.placeholder} onChange={set} />;

    case 'integer':
    case 'decimal': {
      const text = value === undefined || value === null ? '' : String(value as string | number);
      const range = normalRange(field);
      const out = range !== null && outside(field, text);
      return (
        <View className="gap-1">
          <View className="flex-row items-center gap-2">
            <View className="flex-1">
              <Input
                value={text}
                placeholder={field.placeholder}
                keyboard={field.type === 'integer' ? 'number-pad' : 'decimal-pad'}
                onChange={(t) => set(t.replace(',', '.'))}
              />
            </View>
            {field.unit ? <Text className="text-base text-slate-500">{field.unit}</Text> : null}
          </View>
          {range !== null && (
            <Text className={out ? 'text-sm font-medium text-amber-800' : 'text-sm text-slate-500'}>
              {out ? `Outside the normal range (${range}). It will be saved and flagged for the reviewer.` : `Normal range: ${range}.`}
            </Text>
          )}
        </View>
      );
    }

    case 'date':
    case 'time':
    case 'datetime': {
      const kind = field.type;
      const placeholder = { date: 'YYYY-MM-DD', time: 'HH:MM', datetime: 'YYYY-MM-DD HH:MM' }[kind];
      return (
        <View className="flex-row gap-2">
          <View className="flex-1">
            <Input value={typeof value === 'string' ? value.replace('T', ' ') : ''} placeholder={placeholder} keyboard="numbers-and-punctuation" onChange={(t) => set(kind === 'datetime' ? t.replace(' ', 'T') : t)} />
          </View>
          <Button title={kind === 'date' ? 'Today' : 'Now'} variant="outline" size="lg" onPress={() => set(nowFor(kind))} />
        </View>
      );
    }

    case 'select_one':
      return (
        <View className="gap-2">
          {(field.choices ?? []).map((c) => (
            <Choice key={c.value} label={c.label} selected={value === c.value} round onPress={() => set(value === c.value ? undefined : c.value)} />
          ))}
        </View>
      );

    case 'yes_no':
      return (
        <View className="flex-row gap-2">
          {[
            { label: 'Yes', v: true },
            { label: 'No', v: false },
          ].map((c) => (
            <View key={c.label} className="flex-1">
              <Choice label={c.label} selected={value === c.v} round onPress={() => set(value === c.v ? undefined : c.v)} />
            </View>
          ))}
        </View>
      );

    case 'select_multiple': {
      const chosen = Array.isArray(value) ? (value as string[]) : [];
      return (
        <View className="gap-2">
          {(field.choices ?? []).map((c) => (
            <Choice
              key={c.value}
              label={c.label}
              selected={chosen.includes(c.value)}
              onPress={() => {
                const next = chosen.includes(c.value) ? chosen.filter((x) => x !== c.value) : [...chosen, c.value];
                set(next.length ? next : undefined);
              }}
            />
          ))}
        </View>
      );
    }

    case 'user':
      return <PersonPicker value={typeof value === 'string' ? value : null} members={members} label={field.label} onChange={set} />;
    case 'resource':
      return (
        <PersonPicker
          value={typeof value === 'string' ? value : null}
          members={resources
            .filter((r) => !field.resource_type || r.type === field.resource_type)
            .map((r) => ({ id: r.id, name: `${r.code} · ${r.name}${r.location ? ` (${r.location})` : ''}` }))}
          label={field.label}
          onChange={set}
          noun="resource"
        />
      );

    case 'file': {
      const local = files.filter((f) => f.field === field.name);
      const kept = serverFiles.filter((f) => f.field === field.name && !removedFiles.includes(f.id));
      return (
        <FilesControl
          field={field.name}
          local={local}
          server={kept}
          full={local.length + kept.length >= filesPerField}
          maxKb={fileMaxKb}
          onAdd={onAddFile}
          onRemove={onRemoveFile}
          onError={onError}
        />
      );
    }
  }
  return <Text className="text-sm text-slate-500">This field needs a newer version of the app.</Text>;
}

function FilesControl({
  field,
  local,
  server,
  full,
  maxKb,
  onAdd,
  onRemove,
  onError,
}: {
  field: string;
  local: LocalFile[];
  server: ServerFile[];
  full: boolean;
  maxKb: number;
  onAdd: (file: LocalFile) => void;
  onRemove: (file: { uri: string } | { id: string }) => void;
  onError: (message: string) => void;
}) {
  const [busy, setBusy] = useState(false);
  const run = (work: () => Promise<LocalFile | null>) => {
    setBusy(true);
    work()
      .then((file) => file && onAdd(file))
      .catch((e: unknown) => onError(e instanceof Error ? e.message : 'The file could not be added.'))
      .finally(() => setBusy(false));
  };

  return (
    <View className="gap-2">
      {server.map((f) => (
        <FileRow key={f.id} name={f.name} note="Already sent" onRemove={() => onRemove({ id: f.id })} />
      ))}
      {local.map((f) =>
        f.mime.startsWith('image/') ? (
          <View key={f.uri} className="gap-1 rounded-xl border border-slate-200 bg-white p-2">
            <Image source={{ uri: f.uri }} accessibilityLabel={f.name} style={{ width: '100%', aspectRatio: 4 / 3 }} resizeMode="cover" />
            <FileRow name={f.name} note={size(f.size)} onRemove={() => onRemove({ uri: f.uri })} bare />
          </View>
        ) : (
          <FileRow key={f.uri} name={f.name} note={size(f.size)} onRemove={() => onRemove({ uri: f.uri })} />
        ),
      )}
      {full ? (
        <Text className="text-sm text-slate-500">This field has as many files as it takes.</Text>
      ) : (
        <View className="flex-row flex-wrap gap-2">
          <Button title="Take a photo" icon="camera-outline" variant="outline" loading={busy} onPress={() => run(() => takePhoto(field, maxKb))} />
          <Button title="Gallery" icon="image-outline" variant="outline" disabled={busy} onPress={() => run(() => choosePhoto(field, maxKb))} />
          <Button title="Document" icon="document-attach-outline" variant="outline" disabled={busy} onPress={() => run(() => chooseDocument(field, maxKb))} />
        </View>
      )}
    </View>
  );
}

const size = (bytes: number) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

function FileRow({ name, note, onRemove, bare }: { name: string; note: string; onRemove: () => void; bare?: boolean }) {
  return (
    <View className={`flex-row items-center gap-2 ${bare ? '' : 'rounded-xl border border-slate-200 bg-white px-3 py-2'}`}>
      <Ionicons name="attach-outline" size={18} color={colors.muted} />
      <View className="flex-1">
        <Text className="text-sm text-slate-800" numberOfLines={1}>
          {name}
        </Text>
        <Text className="text-xs text-slate-500">{note}</Text>
      </View>
      <Pressable accessibilityRole="button" accessibilityLabel={`Remove ${name}`} hitSlop={8} onPress={onRemove} className="p-1">
        <Ionicons name="trash-outline" size={20} color={colors.dangerDark} />
      </Pressable>
    </View>
  );
}

function PersonPicker({
  value,
  members,
  label,
  onChange,
  noun = 'person',
}: {
  value: string | null;
  members: { id: string; name: string }[];
  label: string;
  onChange: (v: unknown) => void;
  noun?: 'person' | 'resource';
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const chosen = members.find((m) => m.id === value);
  const shown = members.filter((m) => m.name.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 50);

  return (
    <>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${chosen?.name ?? `choose a ${noun}`}`}
        onPress={() => setOpen(true)}
        className="min-h-12 flex-row items-center gap-3 rounded-xl border border-slate-300 bg-white px-3">
        <Ionicons name={noun === 'person' ? 'person-outline' : 'cube-outline'} size={20} color={colors.muted} />
        <Text className={`flex-1 text-base ${chosen ? 'text-slate-900' : 'text-slate-400'}`}>
          {chosen?.name ?? (value ? (noun === 'person' ? 'Someone not on this phone' : 'A resource no longer active') : `Choose a ${noun}`)}
        </Text>
        {value && (
          <Pressable accessibilityRole="button" accessibilityLabel="Clear" hitSlop={8} onPress={() => onChange(undefined)}>
            <Ionicons name="close-circle" size={20} color={colors.faint} />
          </Pressable>
        )}
      </Pressable>
      <Sheet visible={open} onClose={() => setOpen(false)} title={label}>
        <View className="gap-2">
          <Input value={q} placeholder={noun === 'person' ? 'Search by name' : 'Search by code or name'} onChange={setQ} />
          {shown.map((m) => (
            <Choice
              key={m.id}
              label={m.name}
              selected={m.id === value}
              round
              onPress={() => {
                onChange(m.id);
                setOpen(false);
              }}
            />
          ))}
          {shown.length === 0 && <Text className="py-4 text-center text-sm text-slate-500">{noun === 'person' ? 'Nobody matches.' : 'No resource matches.'}</Text>}
        </View>
      </Sheet>
    </>
  );
}

export function Input({
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
      className={`rounded-md border border-slate-300 bg-white px-3 text-base text-slate-900 ${multiline ? 'min-h-24 py-2' : 'min-h-12'}`}
      style={[webNoOutline, multiline ? { textAlignVertical: 'top' } : null]}
    />
  );
}

/** "2 to 8 °C": a number field's normal range, or null when it has none. */
export function normalRange(field: FieldDef): string | null {
  const unit = field.unit ? ` ${field.unit}` : '';
  const low = bound(field.expected_min);
  const high = bound(field.expected_max);
  if (low !== null && high !== null) return `${low} to ${high}${unit}`;
  if (low !== null) return `at least ${low}${unit}`;
  if (high !== null) return `at most ${high}${unit}`;
  return null;
}

/** Whether a typed reading is outside the field's normal range. */
export function outside(field: FieldDef, text: string): boolean {
  if (text.trim() === '' || Number.isNaN(Number(text))) return false;
  const n = Number(text);
  const low = bound(field.expected_min);
  const high = bound(field.expected_max);
  return (low !== null && n < low) || (high !== null && n > high);
}

function bound(v: string | number | undefined): number | null {
  return v === undefined || v === '' || Number.isNaN(Number(v)) ? null : Number(v);
}

export function Choice({ label, selected, round, onPress }: { label: string; selected: boolean; round?: boolean; onPress: () => void }) {
  return (
    <Pressable
      accessibilityRole={round ? 'radio' : 'checkbox'}
      accessibilityState={{ checked: selected }}
      onPress={onPress}
      className={`min-h-12 flex-row items-center gap-3 rounded-md border px-3 ${selected ? 'border-brand-500 bg-brand-50' : 'border-slate-300 bg-white'}`}>
      <Ionicons name={round ? (selected ? 'radio-button-on' : 'radio-button-off') : selected ? 'checkbox' : 'square-outline'} size={22} color={selected ? colors.brand[600] : colors.faint} />
      <Text className="flex-1 text-base text-slate-800">{label}</Text>
    </Pressable>
  );
}
