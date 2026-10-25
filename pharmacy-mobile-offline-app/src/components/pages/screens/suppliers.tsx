import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { EmptyState } from '@/components/ui/misc';
import { useAction } from '@/hooks/use-page';

import { FormSheet, Panel, PanelRow, Toggle } from '../forms';
import { PageScroll } from '../kit';
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
export function SuppliersScreen({ page, path, reload, loading }: ScreenProps<{ suppliers: Supplier[]; canManage: boolean }>) {
  const { suppliers, canManage } = page.props;
  const action = useAction(path, reload);
  const [editing, setEditing] = useState<Supplier | 'new' | null>(null);
  const row = editing === 'new' ? null : editing;

  return (
    <PageScroll loading={loading} onRefresh={reload}>
      {canManage && <Button title="Add supplier" icon="add" square onPress={() => setEditing('new')} />}
      {suppliers.length === 0 ? (
        <EmptyState icon="business-outline" title="No suppliers yet" />
      ) : (
        <Panel>
          {suppliers.map((s) => (
            <PanelRow
              key={s.id}
              dim={!s.active}
              title={s.name}
              subtitle={[s.phone, s.email, s.address].filter(Boolean).join(' · ') || null}
              rightSub={s.active ? null : 'Inactive'}
              onPress={canManage ? () => setEditing(s) : undefined}
            />
          ))}
        </Panel>
      )}

      {editing && (
        <FormSheet
          title={row ? row.name : 'Add a supplier'}
          submit="Save"
          method={row ? 'PUT' : 'POST'}
          to={row ? `suppliers/${row.id}` : 'suppliers'}
          action={action}
          onClose={() => setEditing(null)}
          initial={{ name: row?.name ?? '', phone: row?.phone ?? '', email: row?.email ?? '', address: row?.address ?? '', active: row && !row.active ? '' : '1' }}
          clean={(d) => ({ ...d, active: d.active === '1' })}>
          {(data, set, errors) => (
            <>
              <Field label="Name" value={data.name} onChangeText={(t) => set('name', t)} error={errors.name} autoCapitalize="words" />
              <Field label="Phone" value={data.phone} onChangeText={(t) => set('phone', t)} error={errors.phone} keyboardType="phone-pad" />
              <Field label="Email" value={data.email} onChangeText={(t) => set('email', t)} error={errors.email} keyboardType="email-address" autoCapitalize="none" />
              <Field label="Address" value={data.address} onChangeText={(t) => set('address', t)} error={errors.address} />
              {row && <Toggle label="Active" value={data.active === '1'} onChange={(on) => set('active', on ? '1' : '')} />}
            </>
          )}
        </FormSheet>
      )}
    </PageScroll>
  );
}
