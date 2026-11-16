import { createContext, forwardRef, useCallback, useContext, useEffect, useImperativeHandle, useRef } from 'react';
import { ScrollView, StyleSheet, TextInput, type ScrollViewProps } from 'react-native';

import { useKeyboardHeight } from '@/hooks/use-keyboard';

/** Space kept between the focused field and the keyboard or the top. */
const GAP = 24;

const Reveal = createContext<(() => void) | null>(null);

/** For Field: bring this field into view when it takes focus. */
export function useRevealOnFocus() {
  return useContext(Reveal);
}

/**
 * A ScrollView for forms. It makes room for the keyboard at the bottom and
 * scrolls the focused field above it, on Android (edge to edge) and iOS.
 */
export const KeyboardScroll = forwardRef<ScrollView, ScrollViewProps>(function KeyboardScroll(
  { contentContainerStyle, onScroll, children, ...props },
  ref,
) {
  const keyboard = useKeyboardHeight();
  const scroll = useRef<ScrollView>(null);
  const offset = useRef(0);
  const keyboardNow = useRef(keyboard);
  useEffect(() => {
    keyboardNow.current = keyboard;
  }, [keyboard]);
  useImperativeHandle(ref, () => scroll.current as ScrollView);

  const reveal = useCallback(() => {
    // After the padding for the keyboard is laid out.
    setTimeout(() => {
      const input = TextInput.State.currentlyFocusedInput();
      const view = scroll.current;
      const host = view?.getNativeScrollRef();
      if (!input || !view || !host || !keyboardNow.current) return;
      host.measureInWindow((_x, top, _w, height) => {
        input.measureInWindow((_ix, inputTop, _iw, inputHeight) => {
          const visibleBottom = top + height - keyboardNow.current - GAP;
          const below = inputTop + inputHeight - visibleBottom;
          const above = top + GAP - inputTop;
          if (below > 0) view.scrollTo({ y: offset.current + Math.min(below, -above), animated: true });
          else if (above > 0) view.scrollTo({ y: Math.max(0, offset.current - above), animated: true });
        });
      });
    }, 60);
  }, []);

  useEffect(() => {
    if (keyboard) reveal();
  }, [keyboard, reveal]);

  const paddingBottom = Number(StyleSheet.flatten(contentContainerStyle)?.paddingBottom ?? 0);

  return (
    <Reveal.Provider value={reveal}>
      <ScrollView
        ref={scroll}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="interactive"
        scrollEventThrottle={16}
        {...props}
        contentContainerStyle={[contentContainerStyle, keyboard ? { paddingBottom: paddingBottom + keyboard } : null]}
        onScroll={(e) => {
          offset.current = e.nativeEvent.contentOffset.y;
          onScroll?.(e);
        }}>
        {children}
      </ScrollView>
    </Reveal.Provider>
  );
});
