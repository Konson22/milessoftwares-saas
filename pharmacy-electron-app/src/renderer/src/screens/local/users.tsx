import { Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Badge, Button, Field, Input, Modal, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { dateTime } from '@/lib/format';

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
  pharmacist: 'Sells with discounts, verifies prescriptions, manages medicines, stock and deliveries.',
  cashier: 'Sells, records prescriptions, sees stock.',
};

/** Who may sign in on this PC, and as what. */
export function UsersScreen({ page, path, reload }: ScreenProps<Props>) {
  const { users, roles, meId } = page.props;
  const [editing, setEditing] = useState<User | 'new' | null>(null);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Users"
        description="Each person signs in with their own username, so every sale and change shows who made it."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus size={16} /> Add user
          </Button>
        }
      />
      <Panel>
        <table className="w-full text-sm">
          <thead className="bg-muted text-left text-xs text-muted-foreground">
            <tr>
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-4 py-2 font-medium">Username</th>
              <th className="px-4 py-2 font-medium">Role</th>
              <th className="px-4 py-2 font-medium">Added</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {users.map((u) => (
              <tr key={u.id} onClick={() => setEditing(u)} className={`cursor-pointer hover:bg-accent ${u.active ? '' : 'opacity-50'}`}>
                <td className="px-4 py-2.5 font-medium">
                  {u.name} {u.id === meId ? <Badge tone="blue">You</Badge> : null} {!u.active ? <Badge>Switched off</Badge> : null}
                </td>
                <td className="px-4 py-2.5">{u.username}</td>
                <td className="px-4 py-2.5">{u.roleLabel}</td>
                <td className="px-4 py-2.5 text-muted-foreground">{dateTime(u.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Panel>
      {editing ? <UserDialog user={editing === 'new' ? null : editing} roles={roles} self={editing !== 'new' && editing.id === meId} path={path} reload={reload} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function UserDialog({
  user,
  roles,
  self,
  path,
  reload,
  onClose,
}: {
  user: User | null;
  roles: Props['roles'];
  self: boolean;
  path: string;
  reload: () => Promise<void>;
  onClose: () => void;
}) {
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({
    name: user?.name ?? '',
    username: user?.username ?? '',
    role: user?.role ?? 'cashier',
    active: user?.active ?? true,
    password: '',
    password_confirmation: '',
  });

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (await run(user ? 'PUT' : 'POST', user ? `users/${user.id}` : 'users', data)) onClose();
  };

  return (
    <Modal
      title={user ? user.name : 'Add a user'}
      onClose={onClose}
      footer={
        <Button type="submit" form="user" disabled={busy}>
          Save
        </Button>
      }
    >
      <form id="user" onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Name" error={errors.name}>
            <Input autoFocus value={data.name} onChange={(e) => setData({ ...data, name: e.target.value })} required />
          </Field>
          <Field label="Username" error={errors.username}>
            <Input value={data.username} onChange={(e) => setData({ ...data, username: e.target.value })} required />
          </Field>
        </div>
        <Field label="Role" error={errors.role} hint={ROLE_NOTES[data.role]}>
          <Select value={data.role} onChange={(e) => setData({ ...data, role: e.target.value })}>
            {roles.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </Select>
        </Field>
        {user ? <Notice tone="blue">Leave the password empty to keep it. Fill it in to set a new one (a forgotten password).</Notice> : null}
        <div className="grid grid-cols-2 gap-3">
          <Field label={user ? 'New password' : 'Password'} error={errors.password} hint="At least 6 characters">
            <Input type="password" value={data.password} onChange={(e) => setData({ ...data, password: e.target.value })} required={!user} autoComplete="new-password" />
          </Field>
          <Field label="Password again" error={errors.password_confirmation}>
            <Input type="password" value={data.password_confirmation} onChange={(e) => setData({ ...data, password_confirmation: e.target.value })} required={!user || Boolean(data.password)} autoComplete="new-password" />
          </Field>
        </div>
        {user && !self ? (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.active} onChange={(e) => setData({ ...data, active: e.target.checked })} /> May sign in
            {errors.active ? <span className="text-destructive">{errors.active}</span> : null}
          </label>
        ) : null}
      </form>
    </Modal>
  );
}
