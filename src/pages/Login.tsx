import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/useAuth";
import corhisLogo from "@/assets/corhis-logo.png";

const Login = () => {
  const { user, loading } = useAuth();

  const isLocalCorHis = typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis');
  const corhisBase = isLocalCorHis ? '/internal/corhis' : '';
  useEffect(() => {
    if (loading) return;
    if (user) {
      window.location.replace(`${corhisBase}/onboard/dashboard`);
    } else {
      const returnUrl = encodeURIComponent(`${corhisBase}/onboard/dashboard`);
      window.location.replace(`${corhisBase}/login?return=${returnUrl}`);
    }
  }, [user, loading, corhisBase]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 p-4">
      <Card className="w-full max-w-md shadow-lg border-slate-200">
        <CardHeader className="text-center">
          <div className="mx-auto mb-4 flex items-center justify-center">
            <div className="inline-flex items-center rounded-xl p-2 dark:bg-white/95 dark:shadow-sm">
              <img src={corhisLogo} alt="CorHis" className="h-10 w-auto object-contain" />
            </div>
          </div>
          <CardTitle className="text-2xl font-bold text-slate-800">CorHis</CardTitle>
          <CardDescription>
            Employee Onboarding Platform
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="space-y-4 text-center">
            <div className="flex justify-center">
              <div className="w-8 h-8 rounded-full border-3 border-slate-200 border-t-emerald-600 animate-spin" />
            </div>
            <p className="text-sm text-slate-600">Redirecting to Timesheet Login...</p>
            <Button asChild className="w-full">
              <a href={`${corhisBase}/login?return=${encodeURIComponent(`${corhisBase}/onboard/dashboard`)}`}>Click here if not redirected automatically</a>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default Login;