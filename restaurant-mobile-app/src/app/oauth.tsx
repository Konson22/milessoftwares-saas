import { Redirect } from 'expo-router';

/**
 * The platform's sign-in redirect (…://oauth). The browser session hands the
 * code to the sign-in flow; on Android the link also opens the app here.
 */
export default function OAuthRedirect() {
  return <Redirect href="/" />;
}
