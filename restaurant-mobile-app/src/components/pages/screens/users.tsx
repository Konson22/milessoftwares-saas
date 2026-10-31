import { useState } from 'react';
import { Text } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Badge } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { useAction } from '@/hooks/use-page';

import { Chips, ListRow, Panel, Scroll, Toggle } from '../kit';
import type { ScreenProps } from '../types';

interface StaffMember {
  id: string;
  name: string;
  username: string;
  role: string;
  active: boolean;
  isYou: boolean;
}

interface Props {
  users: StaffMember[];
  roles: { value: string; label: string; text: string }[];
}

/**
 * Offline: the people who work the restaurant on this phone, each with a
 * username, a password and a role. A forgotten password is set again here.
 */
export function UsersScreen({ page, path, reload }: ScreenProps<Props>) {
  const { users, roles } = page.props;
  const { run, busy, errors, setErrors } = useAction(path, reload);
  const [editing, setEditing] = useState<StaffMember | 'new' | null>(null);
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [role, setRole] = useState('waiter');
  const [password, setPassword] = useState('');
  const [active, setActive] = useState(true);
  const roleLabel = (value: string) => roles.find((r) => r.value === value)?.label ?? value;

  const open = (user: StaffMember | 'new') => {
    setErrors({});
    setEditing(user);
    setName(user === 'new' ? '' : user.name);
    setUsername(user === 'new' ? '' : user.username);
    setRole(user === 'new' ? 'waiter' : user.role);
    setPassword('');
    setActive(user === 'new' ? true : user.active);
  };

  const save = async () => {
    const data = { name: name.trim(), username: username.trim(), role, password, active };
    const ok = editing === 'new' ? await run('POST', 'users', data) : editing ? await run('PUT', `users/${editing.id}`, data) : false;
    if (ok) setEditing(null);
  };

  return (
    <>
      <Scroll onRefresh={reload}>
        <Button title="Add someone" icon="person-add-outline" size="lg" onPress={() => open('new')} />
        <Panel title="Who works here">
          {users.map((user) => (
            <ListRow
              key={user.id}
              icon="person-circle-outline"
              title={`${user.name}${user.isYou ? ' (you)' : ''}`}
              subtitle={`${user.username} · ${roleLabel(user.role)}`}
              muted={!user.active}
              right={!user.active ? <Badge label="off" tone="neutral" /> : null}
              onPress={() => open(user)}
            />
          ))}
        </Panel>
      </Scroll>

      <Sheet visible={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? 'Someone new' : (editing?.name ?? '')}>
        <Field label="Name" value={name} onChangeText={setName} error={errors.name} autoFocus={editing === 'new'} />
        <Field label="Username" value={username} onChangeText={setUsername} autoCapitalize="none" autoCorrect={false} error={errors.username} hint="What they sign in with." />
        <Text className="text-sm font-medium text-slate-700">Role</Text>
        <Chips options={roles} value={role} onChange={setRole} />
        <Text className="text-sm text-slate-500">{roles.find((r) => r.value === role)?.text}</Text>
        {errors.role ? <Text className="text-sm text-red-600">{errors.role}</Text> : null}
        <Field
          label={editing === 'new' ? 'Password' : 'New password'}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoCapitalize="none"
          error={errors.password}
          hint={editing === 'new' ? 'At least 6 characters.' : 'Leave empty to keep theirs.'}
        />
        {editing !== 'new' ? <Toggle label="Can sign in" hint="Off: they no longer can." value={active} onChange={setActive} /> : null}
        {errors.active ? <Text className="text-sm text-red-600">{errors.active}</Text> : null}
        <Button title="Save" icon="checkmark" size="lg" loading={busy} onPress={save} />
      </Sheet>
    </>
  );
}
