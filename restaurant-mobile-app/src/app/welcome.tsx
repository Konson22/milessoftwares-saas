import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground, BrandMark } from '@/components/art-background';
import type { IconName } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api/client';
import type { Mode } from '@/lib/types';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

const CHOICES: { mode: Mode; icon: IconName; title: string; text: string; points: string[] }[] = [
  {
    mode: 'online',
    icon: 'cloud-outline',
    title: 'Online',
    text: 'With your Miles Softwares account and your restaurant’s workspace.',
    points: ['Every phone, tablet and PC shares the same floor and kitchen', 'Managed on the web; Finance gets the bills', 'Needs the internet'],
  },
  {
    mode: 'offline',
    icon: 'phone-portrait-outline',
    title: 'Offline',
    text: 'The whole restaurant in a database on this phone. No account, no internet.',
    points: ['Staff sign in here with a username and password', 'Daily backups you can share or restore', 'This phone only: nothing is shared with others'],
  },
];

/**
 * First opening: how the restaurant works on this phone. Each choice shows
 * its details on "Learn more". Either can be switched to later from the
 * account screen; the other's data stays.
 */
export default function WelcomeScreen() {
  const chooseMode = useAuth((s) => s.chooseMode);
  const insets = useSafeAreaInsets();
  const [busy, setBusy] = useState<Mode | null>(null);
  const [open, setOpen] = useState<Mode | null>(null);
  const [error, setError] = useState<string | null>(null);

  const choose = async (mode: Mode) => {
    setBusy(mode);
    setError(null);
    try {
      await chooseMode(mode);
    } catch (e) {
      setError(errorMessage(e));
      setBusy(null);
    }
  };

  return (
    <ArtBackground>
      <ScrollView contentContainerStyle={{ paddingBottom: insets.bottom + 24 }} contentContainerClassName="w-full max-w-xl self-center px-5">
        <BrandMark />
        <Text accessibilityRole="header" className="mb-4 text-2xl font-bold text-white">
          How will you use it?
        </Text>
        {error ? (
          <Banner tone="error" className="mb-3">
            {error}
          </Banner>
        ) : null}
        <View className="gap-3">
          {CHOICES.map((choice) => {
            const expanded = open === choice.mode;
            return (
              <View key={choice.mode} className="bg-white">
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={choice.title}
                  disabled={busy !== null}
                  onPress={() => choose(choice.mode)}
                  className="flex-row items-center gap-3 p-5 active:bg-brand-50">
                  <View className="h-12 w-12 items-center justify-center bg-brand-600">
                    <Ionicons name={choice.icon} size={24} color={colors.white} />
                  </View>
                  <Text className="flex-1 text-lg font-bold text-slate-900">{choice.title}</Text>
                  <Ionicons name={busy === choice.mode ? 'hourglass-outline' : 'chevron-forward'} size={22} color={colors.brand[600]} />
                </Pressable>
                <Pressable
                  accessibilityRole="link"
                  accessibilityState={{ expanded }}
                  accessibilityLabel={`Learn more about ${choice.title}`}
                  hitSlop={8}
                  onPress={() => setOpen(expanded ? null : choice.mode)}
                  className="flex-row items-center gap-1 self-start px-5 pb-4">
                  <Text className="text-sm font-semibold text-brand-700 underline">{expanded ? 'Show less' : 'Learn more'}</Text>
                  <Ionicons name={expanded ? 'chevron-up' : 'chevron-down'} size={14} color={colors.brand[700]} />
                </Pressable>
                {expanded ? (
                  <View className="gap-1.5 border-t border-slate-100 px-5 pb-5 pt-3">
                    <Text className="mb-1 text-sm text-slate-500">{choice.text}</Text>
                    {choice.points.map((point) => (
                      <View key={point} className="flex-row items-start gap-2">
                        <Ionicons name="checkmark" size={16} color={colors.accentDark} style={{ marginTop: 2 }} />
                        <Text className="flex-1 text-sm text-slate-700">{point}</Text>
                      </View>
                    ))}
                    <Text className="mt-1 text-xs text-slate-500">You can change this later under Account.</Text>
                  </View>
                ) : null}
              </View>
            );
          })}
        </View>
      </ScrollView>
    </ArtBackground>
  );
}
