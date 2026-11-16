/**
 * app.json, plus native Google sign-in on iOS: its config plugin needs the
 * iOS client's URL scheme (the client ID reversed), so it is added only when
 * EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID is set. Android needs no plugin options:
 * Google finds its client by package name and signing key.
 */
module.exports = ({ config }) => {
  const iosClientId = process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID;
  if (!iosClientId) return config;

  const iosUrlScheme = `com.googleusercontent.apps.${iosClientId.replace(/\.apps\.googleusercontent\.com$/, '')}`;
  return {
    ...config,
    plugins: [...(config.plugins ?? []), ['@react-native-google-signin/google-signin', { iosUrlScheme }]],
  };
};
