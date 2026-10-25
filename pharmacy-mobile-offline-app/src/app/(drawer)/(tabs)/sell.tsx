import { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, View, type TextInputInstance } from 'react-native';

import { CartPanel } from '@/components/sell/cart-panel';
import { ProductSearch } from '@/components/sell/product-search';
import { ShiftGate } from '@/components/shift-gate';
import { EmptyState } from '@/components/ui/misc';
import { useIsWide } from '@/hooks/use-async';
import { useAuth } from '@/store/auth';

export default function SellScreen() {
  const counter = useAuth((s) => s.counter);

  if (counter && !counter.can.sell) {
    return <EmptyState icon="lock-closed-outline" title="You do not sell here" message="Your role can see sales and prescriptions." />;
  }

  return (
    <ShiftGate>
      <Terminal />
    </ShiftGate>
  );
}

function Terminal() {
  const wide = useIsWide();
  const [query, setQuery] = useState('');
  const searchRef = useRef<TextInputInstance>(null);

  if (wide) {
    // Tablet landscape: catalog | cart, like the web counter.
    return (
      <View className="flex-1 flex-row">
        <View className="flex-[3]">
          <ProductSearch ref={searchRef} query={query} onQueryChange={setQuery} alwaysShowResults />
        </View>
        <View className="flex-[2] border-l border-slate-200 bg-white">
          <CartPanel />
        </View>
      </View>
    );
  }

  // Phone portrait: Search → Cart → Pay. Results take over while typing.
  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1" keyboardVerticalOffset={90}>
      <ProductSearch ref={searchRef} query={query} onQueryChange={setQuery} />
      {!query.trim() && <CartPanel sheet />}
    </KeyboardAvoidingView>
  );
}
