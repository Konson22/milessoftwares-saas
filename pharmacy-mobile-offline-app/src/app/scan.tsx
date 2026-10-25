import { Ionicons } from '@expo/vector-icons';
import { CameraView, useCameraPermissions, type BarcodeScanningResult } from 'expo-camera';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button, IconButton } from '@/components/ui/button';
import { useAddProduct } from '@/hooks/use-add-product';
import { api, errorMessage } from '@/lib/api';
import { haptic } from '@/lib/feedback';
import { money } from '@/lib/format';
import { colors } from '@/lib/theme';
import { cartTotals, useCart } from '@/store/cart';

/** Continuous camera scanning: each recognised code is added to the cart. */
export default function ScanScreen() {
  const [permission, requestPermission] = useCameraPermissions();
  const addProduct = useAddProduct();
  const lines = useCart((s) => s.lines);
  const totals = cartTotals(lines);
  const [last, setLast] = useState<{ ok: boolean; message: string } | null>(null);
  const lastCode = useRef<{ code: string; at: number }>({ code: '', at: 0 });
  const busy = useRef(false);

  async function onScanned({ data }: BarcodeScanningResult) {
    const now = Date.now();
    // Ignore repeat reads of the same code held under the camera.
    if (busy.current || (data === lastCode.current.code && now - lastCode.current.at < 2000)) return;
    lastCode.current = { code: data, at: now };
    busy.current = true;
    try {
      const item = await api.itemByCode(data);
      if (!item) {
        haptic.error();
        setLast({ ok: false, message: `No product for barcode ${data}` });
      } else {
        setLast(addProduct(item));
      }
    } catch (e) {
      haptic.error();
      setLast({ ok: false, message: errorMessage(e) });
    } finally {
      busy.current = false;
    }
  }

  if (!permission) return <View className="flex-1 bg-black" />;

  if (!permission.granted) {
    return (
      <SafeAreaView className="flex-1 items-center justify-center gap-4 bg-canvas p-8">
        <View className="h-20 w-20 items-center justify-center rounded-full bg-brand-100">
          <Ionicons name="camera-outline" size={40} color={colors.brand[600]} />
        </View>
        <Text className="text-center text-lg font-semibold text-slate-900">Camera access needed</Text>
        <Text className="text-center text-base text-slate-500">
          Allow camera access to scan barcodes. A Bluetooth or USB scanner also works from the search field.
        </Text>
        <Button title="Allow camera" onPress={requestPermission} className="w-full" />
        <Button title="Back to the counter" variant="ghost" onPress={() => router.back()} />
      </SafeAreaView>
    );
  }

  return (
    <View className="flex-1 bg-black">
      <CameraView
        style={{ flex: 1 }}
        facing="back"
        barcodeScannerSettings={{ barcodeTypes: ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'qr', 'datamatrix'] }}
        onBarcodeScanned={onScanned}
      />
      <SafeAreaView className="absolute inset-0" pointerEvents="box-none">
        <View className="flex-row items-center justify-between px-3">
          <IconButton icon="close" onDark label="Close scanner" onPress={() => router.back()} className="bg-black/40" />
          <Text className="text-base font-semibold text-white">Scan products</Text>
          <View className="w-11" />
        </View>

        <View className="flex-1 items-center justify-center" pointerEvents="none">
          <View className="h-48 w-72 rounded-3xl border-4 border-accent" />
        </View>

        <View className="gap-3 p-4">
          {last && (
            <View className={`rounded-2xl px-4 py-3 ${last.ok ? 'bg-emerald-600' : 'bg-red-600'}`} accessibilityLiveRegion="polite">
              <Text className="text-base font-semibold text-white">{last.message}</Text>
            </View>
          )}
          <Button
            title={`Done · ${lines.length} item${lines.length === 1 ? '' : 's'} · ${money(totals.total)}`}
            size="lg"
            onPress={() => router.back()}
          />
        </View>
      </SafeAreaView>
    </View>
  );
}
