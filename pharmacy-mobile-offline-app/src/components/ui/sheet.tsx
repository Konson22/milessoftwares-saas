import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { IconButton } from './button';

/** Bottom sheet built on Modal — used for quick, single-purpose forms. */
export function Sheet({
  visible,
  onClose,
  title,
  children,
}: {
  visible: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
}) {
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      {/* Android draws edge to edge, so the window no longer resizes for the keyboard: lift the sheet on both. */}
      <KeyboardAvoidingView behavior={Platform.OS === 'web' ? undefined : 'padding'} className="flex-1 justify-end">
        <Pressable className="absolute inset-0 bg-brand-950/60" onPress={onClose} accessibilityLabel="Close" />
        <View
          className="max-h-[85%] w-full max-w-xl self-center rounded-t-[32px] bg-white"
          style={{ paddingBottom: Math.max(insets.bottom, 16) }}>
          <View className="items-center pt-2.5">
            <View className="h-1 w-10 rounded-full bg-slate-300" />
          </View>
          <View className="flex-row items-center justify-between border-b border-slate-100 py-1.5 pl-5 pr-2">
            <Text accessibilityRole="header" className="text-lg font-bold text-slate-900">
              {title}
            </Text>
            <IconButton icon="close" label="Close" onPress={onClose} />
          </View>
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerClassName="gap-4 p-5">
            {children}
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}
