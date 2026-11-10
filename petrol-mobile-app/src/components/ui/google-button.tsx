import { Image } from 'expo-image';
import { ActivityIndicator, Pressable, Text } from 'react-native';

/**
 * Google's own sign-in button, as its branding guidelines draw it (light theme,
 * rectangular): white, a grey outline, the four-colour G and dark text. Its
 * colours are Google's, so they stay here rather than in the app's theme.
 */
export function GoogleButton({ onPress, loading, disabled, title = 'Continue with Google' }: { onPress: () => void; loading?: boolean; disabled?: boolean; title?: string }) {
  const inactive = disabled || loading;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={title}
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      onPress={onPress}
      disabled={inactive}
      className={`min-h-11 flex-row items-center justify-center gap-3 rounded border border-[#747775] bg-white px-3 active:bg-[#f2f2f2] ${inactive ? 'opacity-60' : ''}`}>
      {loading ? (
        <ActivityIndicator size="small" color="#1f1f1f" style={{ width: 22, height: 22 }} />
      ) : (
        <Image source={require('../../../assets/images/google-g.png')} contentFit="contain" style={{ width: 22, height: 22 }} accessible={false} />
      )}
      <Text className="text-base font-semibold text-[#1f1f1f]">{title}</Text>
    </Pressable>
  );
}
