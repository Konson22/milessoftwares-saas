import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { forwardRef, useEffect, useState, type RefObject } from 'react';
import { ActivityIndicator, FlatList, Pressable, Text, TextInput, View, type TextInputInstance } from 'react-native';

import { Banner, EmptyState } from '@/components/ui/misc';
import { useAddProduct } from '@/hooks/use-add-product';
import { useDebounced } from '@/hooks/use-async';
import { api, errorMessage } from '@/lib/api';
import { colors, webNoOutline } from '@/lib/theme';
import type { Item } from '@/lib/types';

import { ProductRow } from './product-row';

interface Props {
  query: string;
  onQueryChange: (q: string) => void;
  /** Phone: results only render while typing. Tablet: the column always shows. */
  alwaysShowResults?: boolean;
  onAdded?: () => void;
}

export const SearchBar = forwardRef<TextInputInstance, { query: string; onChange: (q: string) => void; onSubmit: () => void; busy: boolean }>(
  function SearchBar({ query, onChange, onSubmit, busy }, ref) {
    const [focused, setFocused] = useState(false);

    return (
      <View className="flex-row items-center gap-2">
        <View
          className={`min-h-12 flex-1 flex-row items-center rounded-2xl border bg-white px-3 shadow-sm shadow-brand-900/10 ${
            focused ? 'border-brand-500' : 'border-slate-200'
          }`}>
          <Ionicons name="search" size={20} color={focused ? colors.brand[600] : colors.muted} />
          <TextInput
            ref={ref}
            value={query}
            onChangeText={onChange}
            onSubmitEditing={onSubmit}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
            placeholder="Scan a barcode or search by name"
            placeholderTextColor={colors.faint}
            selectionColor={colors.brand[500]}
            autoFocus
            autoCorrect={false}
            autoCapitalize="none"
            returnKeyType="search"
            blurOnSubmit={false}
            accessibilityLabel="Search products"
            style={webNoOutline}
            className="flex-1 px-2 py-3 text-base text-slate-900"
          />
          {busy ? (
            <ActivityIndicator color={colors.brand[600]} />
          ) : query ? (
            <Pressable accessibilityLabel="Clear search" onPress={() => onChange('')} hitSlop={8}>
              <Ionicons name="close-circle" size={20} color={colors.faint} />
            </Pressable>
          ) : null}
        </View>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Scan with camera"
          onPress={() => router.push('/scan')}
          className="h-12 w-12 items-center justify-center bg-brand-600 active:bg-brand-700">
          <Ionicons name="barcode-outline" size={24} color={colors.white} />
        </Pressable>
      </View>
    );
  },
);

export const ProductSearch = forwardRef<TextInputInstance, Props>(function ProductSearch(
  { query, onQueryChange, alwaysShowResults, onAdded },
  ref,
) {
  const addProduct = useAddProduct();
  const debounced = useDebounced(query, 200);
  const [results, setResults] = useState<Item[]>([]);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<{ ok: boolean; message: string } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    if (!debounced.trim()) {
      setResults([]);
      return;
    }
    setBusy(true);
    api
      .items(debounced)
      .then((r) => {
        if (!active) return;
        setResults(r);
        setError(null);
      })
      .catch((e) => {
        if (active) setError(errorMessage(e));
      })
      .finally(() => {
        if (active) setBusy(false);
      });
    return () => {
      active = false;
    };
  }, [debounced]);

  useEffect(() => {
    if (!feedback) return;
    const id = setTimeout(() => setFeedback(null), 2500);
    return () => clearTimeout(id);
  }, [feedback]);

  function addAndReset(item: Item) {
    const result = addProduct(item);
    setFeedback(result);
    if (result.ok) {
      onQueryChange('');
      onAdded?.();
    }
    // Scan-first: keep the cursor in the search field for the next item.
    (ref as RefObject<TextInputInstance | null>)?.current?.focus();
  }

  // Enter from the keyboard or a HID scanner: an exact barcode wins, else a single match.
  async function submit() {
    const code = query.trim();
    if (!code) return;
    setBusy(true);
    try {
      const exact = await api.itemByCode(code);
      if (exact) return addAndReset(exact);
      if (results.length === 1) return addAndReset(results[0]);
      setFeedback({ ok: false, message: `No exact match for “${code}”` });
    } catch (e) {
      setFeedback({ ok: false, message: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  const showResults = alwaysShowResults || query.trim().length > 0;

  return (
    <View className={showResults ? 'flex-1' : ''}>
      <View className="gap-2 p-4 pb-3">
        <SearchBar ref={ref} query={query} onChange={onQueryChange} onSubmit={submit} busy={busy} />
        {feedback && <Banner tone={feedback.ok ? 'success' : 'error'}>{feedback.message}</Banner>}
        {error && <Banner tone="error">{error}</Banner>}
      </View>
      {showResults && (
        <FlatList
          data={results}
          keyExtractor={(i) => i.id}
          keyboardShouldPersistTaps="handled"
          contentContainerClassName="pb-4"
          renderItem={({ item }) => <ProductRow item={item} onPress={() => addAndReset(item)} />}
          ListEmptyComponent={
            busy ? undefined : query.trim() ? (
              <EmptyState icon="search" title="No products found" message="Try part of the name or the category, or scan the barcode." />
            ) : (
              <EmptyState icon="barcode-outline" title="Scan a product or type to search" />
            )
          }
          ListHeaderComponent={
            results.length > 0 ? (
              <Text className="px-5 pb-2 text-xs font-bold uppercase tracking-wider text-slate-600">
                {results.length} result{results.length === 1 ? '' : 's'}
              </Text>
            ) : undefined
          }
        />
      )}
    </View>
  );
});
