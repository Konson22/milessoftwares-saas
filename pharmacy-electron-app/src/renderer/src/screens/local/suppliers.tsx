import { Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Badge, Button, Empty, Field, Input, Modal, PageHeader, Panel } from '@/components/ui';
import { useAction } from '@/hooks/use-page';

import type { ScreenProps } from '../types';

interface Supplier {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  active: boolean;
}

/** The pharmacy's suppliers. */
export function SuppliersScreen({ page, path, reload }: ScreenProps<{ suppliers: Supplier[]; canManage: boolean }>) {
  const { suppliers, canManage } = page.props;
  const [editing, setEditing] = useState<Supplier | 'new' | null>(null);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Suppliers"
        actions={
          canManage ? (
            <Button onClick={() => setEditing('new')}>
              <Plus size={16} /> Add supplier
            </Button>
          ) : null
        }
      />
      <Panel>
        {suppliers.length === 0 ? (
          <Empty title="No suppliers yet" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Name</th>
                <th className="px-4 py-2 font-medium">Phone</th>
                <th className="px-4 py-2 font-medium">Email</th>
                <th className="px-4 py-2 font-medium">Address</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {suppliers.map((s) => (
                <tr key={s.id} onClick={canManage ? () => setEditing(s) : undefined} className={canManage ? 'cursor-pointer hover:bg-accent' : ''}>
                  <td className="px-4 py-2.5 font-medium">
                    {s.name} {!s.active ? <Badge>Inactive</Badge> : null}
                  </td>
                  <td className="px-4 py-2.5">{s.phone ?? '—'}</td>
                  <td className="px-4 py-2.5">{s.email ?? '—'}</td>
                  <td className="px-4 py-2.5">{s.address ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
      {editing ? <SupplierDialog supplier={editing === 'new' ? null : editing} path={path} reload={reload} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function SupplierDialog({ supplier, path, reload, onClose }: { supplier: Supplier | null; path: string; reload: () => Promise<void>; onClose: () => void }) {
  const { run, busy, errors } = useAction(path, reload);
  const [data, setData] = useState({
    name: supplier?.name ?? '',
    phone: supplier?.phone ?? '',
    email: supplier?.email ?? '',
    address: supplier?.address ?? '',
    active: supplier?.active ?? true,
  });

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (await run(supplier ? 'PUT' : 'POST', supplier ? `suppliers/${supplier.id}` : 'suppliers', data)) onClose();
  };

  return (
    <Modal
      title={supplier ? supplier.name : 'Add a supplier'}
      onClose={onClose}
      footer={
        <Button type="submit" form="supplier" disabled={busy}>
          Save
        </Button>
      }
    >
      <form id="supplier" onSubmit={submit} className="space-y-3">
        {(['name', 'phone', 'email', 'address'] as const).map((key) => (
          <Field key={key} label={key[0].toUpperCase() + key.slice(1)} error={errors[key]}>
            <Input value={data[key]} onChange={(e) => setData({ ...data, [key]: e.target.value })} required={key === 'name'} type={key === 'email' ? 'email' : 'text'} />
          </Field>
        ))}
        {supplier ? (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.active} onChange={(e) => setData({ ...data, active: e.target.checked })} /> Active
          </label>
        ) : null}
      </form>
    </Modal>
  );
}
