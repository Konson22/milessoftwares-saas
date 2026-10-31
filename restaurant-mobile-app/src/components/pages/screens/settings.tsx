import { useState } from 'react';
import { View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { useAction } from '@/hooks/use-page';
import { useAuth } from '@/store/auth';

import { Detail, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface Props {
  settings: { name: string; address: string | null; phone: string | null; currency: string; billFooter: string | null };
}

/** Offline: the restaurant's name and details, on its bills. The currency stays as set up. */
export function SettingsScreen({ page, path }: ScreenProps<Props>) {
  const { settings } = page.props;
  const refreshMe = useAuth((s) => s.refreshMe);
  // Saved: the restaurant's name shows everywhere, so who is signed in is read again.
  const { run, busy, errors } = useAction(path, refreshMe);
  const [name, setName] = useState(settings.name);
  const [address, setAddress] = useState(settings.address ?? '');
  const [phone, setPhone] = useState(settings.phone ?? '');
  const [footer, setFooter] = useState(settings.billFooter ?? '');

  return (
    <Scroll>
      <Panel title="The restaurant">
        <View className="gap-4 p-4">
          <Field label="Name" value={name} onChangeText={setName} error={errors.name} />
          <Field label="Address" value={address} onChangeText={setAddress} hint="Optional." error={errors.address} />
          <Field label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" hint="Optional." error={errors.phone} />
          <Field label="At the bottom of bills" value={footer} onChangeText={setFooter} maxLength={255} placeholder="Thank you!" error={errors.bill_footer} />
          <Detail label="Currency" value={settings.currency} />
          <Button
            title="Save"
            icon="checkmark"
            size="lg"
            loading={busy}
            onPress={() => run('PUT', 'settings', { name: name.trim(), address: address.trim() || null, phone: phone.trim() || null, bill_footer: footer.trim() || null })}
          />
        </View>
      </Panel>
    </Scroll>
  );
}
