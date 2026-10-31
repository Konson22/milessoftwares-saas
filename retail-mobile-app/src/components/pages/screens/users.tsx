import { useState } from 'react';
import { Text } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner } from '@/components/ui/misc';
import { useAction } from '@/hooks/use-page';
import { dateTime } from '@/lib/format';

import { Choice, FormSheet, Panel, PanelRow, Toggle } from '../forms';
import { PageScroll } from '../kit';
import type { ScreenProps } from '../types';

interface User {
  id: string;
  name: string;
  username: string;
  role: string;
  roleLabel: string;
  active: boolean;
  createdAt: string;
}

interface Props {
  users: User[];
  roles: { value: string; label: string }[];
  meId: string;
}

const ROLE_NOTES: Record<string, string> = {
  manager: 'Everything: voids and returns, reports, users, settings and backups.',
  supervisor: 'Sells with discounts, manages products, stock and deliveries.',
  cashier: 'Sells and sees stock.',
};

/** Who may sign in on this phone, and as what. */
export function UsersScreen({ page, path, reload, loading }: ScreenProps<Props>) {
  const { users, roles, meId } = page.props;
  const action = useAction(path, reload);
  const [editing, setEditing] = useState<User | 'new' | null>(null);
  const user = editing === 'new' ? null : editing;
  const self = user?.id === meId;

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      <Text className="text-sm text-slate-500">Each person signs in with their own username, so every sale and change shows who made it.</Text>
      <Button title="Add user" icon="person-add-outline" square onPress={() => setEditing('new')} />
      <Panel>
        {users.map((u) => (
          <PanelRow
            key={u.id}
            dim={!u.active}
            title={u.id === meId ? `${u.name} (you)` : u.name}
            subtitle={`${u.username} · added ${dateTime(u.createdAt)}`}
            right={u.roleLabel}
            rightSub={u.active ? null : 'Switched off'}
            onPress={() => setEditing(u)}
          />
        ))}
      </Panel>

      {editing && (
        <FormSheet
          title={user ? user.name : 'Add a user'}
          submit="Save"
          method={user ? 'PUT' : 'POST'}
          to={user ? `users/${user.id}` : 'users'}
          action={action}
          onClose={() => setEditing(null)}
          initial={{
            name: user?.name ?? '',
            username: user?.username ?? '',
            role: user?.role ?? 'cashier',
            active: user && !user.active ? '' : '1',
            password: '',
            password_confirmation: '',
          }}
          clean={(d) => ({ ...d, active: d.active === '1' })}>
          {(data, set, errors) => (
            <>
              <Field label="Name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} autoCapitalize="words" />
              <Field label="Username" value={data.username} onChangeText={(t) => set('username', t)} error={errors.username} autoCapitalize="none" autoCorrect={false} />
              <Choice label="Role" options={roles} value={data.role} onChange={(v) => set('role', v)} error={errors.role} hint={ROLE_NOTES[data.role]} />
              {user && <Banner tone="info">Leave the password empty to keep it. Fill it in to set a new one (a forgotten password).</Banner>}
              <Field
                label={user ? 'New password' : 'Password'}
                value={data.password}
                onChangeText={(t) => set('password', t)}
                error={errors.password}
                hint="At least 6 characters"
                secureTextEntry
                autoCapitalize="none"
              />
              <Field
                label="Password again"
                value={data.password_confirmation}
                onChangeText={(t) => set('password_confirmation', t)}
                error={errors.password_confirmation}
                secureTextEntry
                autoCapitalize="none"
              />
              {user && !self && <Toggle label="May sign in" value={data.active === '1'} onChange={(on) => set('active', on ? '1' : '')} error={errors.active} />}
            </>
          )}
        </FormSheet>
      )}
    </PageScroll>
  );
}
