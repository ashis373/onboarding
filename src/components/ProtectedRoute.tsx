import { ReactNode } from 'react';
import { useAuth } from '@/hooks/useAuth';

interface ProtectedRouteProps {
  children: ReactNode;
}

export default function ProtectedRoute({ children }: ProtectedRouteProps) {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <div className="flex flex-col items-center gap-3">
          <div className="w-10 h-10 rounded-full border-4 border-slate-200 border-t-emerald-600 animate-spin" />
          <p className="text-sm font-medium text-slate-500">Checking authentication...</p>
        </div>
      </div>
    );
  }

  if (!user) {
    const isLocalCorHis = typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis');
    const corhisBase = isLocalCorHis ? '/internal/corhis' : '';
    const currentPath = window.location.pathname + window.location.search + window.location.hash;
    const returnUrl = encodeURIComponent(currentPath || `${corhisBase}/onboard/`);
    window.location.href = `${corhisBase}/login?return=${returnUrl}`;
    return null;
  }

  return <>{children}</>;
}


