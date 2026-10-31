import { Redirect } from 'expo-router';

import { useAuth } from '@/store/auth';

export default function Index() {
  const status = useAuth((s) => s.status);
  const mode = useAuth((s) => s.mode);
  if (status === 'chooseMode') return <Redirect href="/welcome" />;
  if (status === 'setUp') return <Redirect href="/set-up" />;
  if (status === 'signedOut') return <Redirect href={mode === 'offline' ? '/sign-in' : '/login'} />;
  if (status === 'chooseRestaurant') return <Redirect href="/choose-restaurant" />;
  if (status === 'needsBranch') return <Redirect href="/select-branch" />;
  return <Redirect href="/home" />;
}
