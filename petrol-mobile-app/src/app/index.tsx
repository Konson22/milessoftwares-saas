import { Redirect } from 'expo-router';

import { useAuth } from '@/store/auth';

export default function Index() {
  const status = useAuth((s) => s.status);
  if (status === 'signedOut') return <Redirect href="/login" />;
  if (status === 'noBusiness') return <Redirect href="/create-business" />;
  if (status === 'chooseBusiness') return <Redirect href="/choose-business" />;
  if (status === 'needsBranch') return <Redirect href="/select-branch" />;
  return <Redirect href="/home" />;
}
