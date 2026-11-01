import { useEffect, useState } from 'react';
import { Keyboard, Platform } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/**
 * How much of the bottom of the screen the on-screen keyboard covers, 0
 * while it is hidden. The app draws edge to edge on Android, so the window
 * no longer shrinks for the keyboard (adjustResize does nothing) and
 * KeyboardAvoidingView has nothing to go on; screens pad themselves by this
 * instead. Android reports the keyboard without the navigation bar under
 * it, so that is added back.
 */
export function useKeyboardHeight() {
  const [height, setHeight] = useState(0);
  const { bottom } = useSafeAreaInsets();

  useEffect(() => {
    if (Platform.OS === 'web') return;
    // iOS announces the keyboard before it slides in, Android only after.
    const show = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow', (e) =>
      setHeight(e.endCoordinates.height),
    );
    const hide = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillHide' : 'keyboardDidHide', () => setHeight(0));
    return () => {
      show.remove();
      hide.remove();
    };
  }, []);

  if (!height) return 0;
  return Platform.OS === 'android' ? height + bottom : height;
}
