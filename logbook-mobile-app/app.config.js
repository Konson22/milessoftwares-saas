/**
 * app.json, plus native Google sign-in on iOS: its config plugin needs the
 * iOS client's URL scheme (the client ID reversed), so it is added only when
 * EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID is set. Android needs no plugin options:
 * Google finds its client by package name and signing key.
 */
const fs = require('fs');
const path = require('path');
const { withAndroidManifest } = require('expo/config-plugins');

/**
 * Phone notifications on Android go through Firebase Cloud Messaging: the
 * build needs the Firebase project's google-services.json (not committed).
 * Set GOOGLE_SERVICES_JSON (an EAS file variable) or put the file here.
 * Without it the app builds and runs, and simply registers no push token.
 */
function withGoogleServices(config) {
  const file = process.env.GOOGLE_SERVICES_JSON ?? path.join(__dirname, 'google-services.json');
  if (!fs.existsSync(file)) return config;
  return { ...config, android: { ...config.android, googleServicesFile: file } };
}

/**
 * expo-network asks for ACCESS_WIFI_STATE, which makes Google Play treat
 * android.hardware.wifi as required and hide the app from devices without
 * Wi-Fi. The app works on any connection, so the feature is optional.
 */
function withOptionalWifi(config) {
  return withAndroidManifest(config, (mod) => {
    const manifest = mod.modResults.manifest;
    const features = (manifest['uses-feature'] ??= []);
    const wifi = features.find((f) => f.$['android:name'] === 'android.hardware.wifi');
    if (wifi) wifi.$['android:required'] = 'false';
    else features.push({ $: { 'android:name': 'android.hardware.wifi', 'android:required': 'false' } });
    return mod;
  });
}

module.exports = ({ config: base }) => {
  const config = withGoogleServices(withOptionalWifi(base));
  const iosClientId = process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID;
  if (!iosClientId) return config;

  const iosUrlScheme = `com.googleusercontent.apps.${iosClientId.replace(/\.apps\.googleusercontent\.com$/, '')}`;
  return {
    ...config,
    plugins: [...(config.plugins ?? []), ['@react-native-google-signin/google-signin', { iosUrlScheme }]],
  };
};
