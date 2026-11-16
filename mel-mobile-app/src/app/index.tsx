import { Redirect } from 'expo-router';

import { useAuth } from '@/store/auth';

export default function Index() {
  const status = useAuth((s) => s.status);
  if (status === 'signedOut' || status === 'noBusiness') return <Redirect href="/login" />;
  if (status === 'chooseBusiness') return <Redirect href="/choose-business" />;
  return <Redirect href="/home" />;
}
