import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useWindowDimensions } from 'react-native';

import { errorMessage } from '@/lib/api';

/**
 * Loads data and reloads whenever the screen regains focus, so lists stay
 * current after actions taken on other screens.
 */
export function useFocusedQuery<T>(load: () => Promise<T>, key: string | number = '') {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const loadRef = useRef(load);
  useEffect(() => {
    loadRef.current = load;
  });

  const reload = useCallback(async () => {
    setLoading(true);
    try {
      setData(await loadRef.current());
      setError(null);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void reload();
      // `key` (e.g. the selected date) is a deliberate refetch trigger.
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [reload, key]),
  );

  return { data, setData, error, loading, reload };
}

/** Debounced value — used for type-ahead search. */
export function useDebounced<T>(value: T, ms = 200) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), ms);
    return () => clearTimeout(id);
  }, [value, ms]);
  return debounced;
}

/** Tablet layout: catalog and cart side by side. */
export function useIsWide() {
  const { width } = useWindowDimensions();
  return width >= 768;
}
