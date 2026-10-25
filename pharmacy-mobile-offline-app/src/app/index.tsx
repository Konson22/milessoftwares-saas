import { Redirect } from 'expo-router';

import { useAuth } from '@/store/auth';

export default function Index() {
  const status = useAuth((s) => s.status);
  if (status === 'setUp') return <Redirect href="/set-up" />;
  if (status === 'signedOut') return <Redirect href="/login" />;
  return <Redirect href="/home" />;
}
