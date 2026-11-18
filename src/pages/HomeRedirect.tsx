import { useEffect } from 'react';
import { useAuth } from '@/hooks/useAuth';

export default function HomeRedirect() {
  const { user, loading } = useAuth();

  const isLocalCorHis = typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis');
  const corhisBase = isLocalCorHis ? '/internal/corhis' : '';
  useEffect(() => {
    if (loading) return;
    if (user) {
      window.location.replace(`${corhisBase}/onboard/dashboard`);
    } else {
      const returnUrl = encodeURIComponent(window.location.href);
      window.location.replace(`${corhisBase}/login?return=${returnUrl}`);
    }
  }, [user, loading, corhisBase]);

  return null;
}


