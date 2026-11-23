import { ReactNode, useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { useNavigate, useLocation } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { apiGet } from "@/lib/api";
import {
  Home,
  BookOpen,
  ClipboardCheck,
  Users,
  Settings,
  LogOut,
  BarChart3,
  FileText,
  Moon,
  Sun,
  Clock,
  LayoutGrid,
  Mail,
  Activity,
  Award,
  ArrowLeft,
  Lock
} from "lucide-react";
import { useTheme } from "next-themes";
import corhisLogo from "@/assets/corhis-logo.png";
import { EcosystemAppSwitcher } from "./EcosystemAppSwitcher";

interface LayoutProps {
  children: ReactNode;
  isAdmin?: boolean;
  sidebarContent?: ReactNode;
  activeEmployeeTab?: 'all' | 'roadmap' | 'scorm_report' | 'attempt_history';
  onSelectEmployeeTab?: (tab: 'all' | 'roadmap' | 'scorm_report' | 'attempt_history') => void;
}

export const Layout = ({ 
  children, 
  isAdmin = false, 
  sidebarContent,
  activeEmployeeTab,
  onSelectEmployeeTab
}: LayoutProps) => {
  const navigate = useNavigate();
  const location = useLocation();
  const { user } = useAuth();
  const { theme, setTheme } = useTheme();
  const [displayName, setDisplayName] = useState<string>("");

  const isLocalCorHis = typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis');
  const corhisBase = isLocalCorHis ? '/internal/corhis' : '';

  const [avatarSrc, setAvatarSrc] = useState<string>("");
  const [avatarFailed, setAvatarFailed] = useState(false);

  useEffect(() => {
    if (user?.firstname) setDisplayName(`${user.firstname} ${user.lastname || ''}`.trim());
    const rawUrl = user?.profile_img_url || (user?.profile_img ? `${corhisBase}/dist/img/user/${user.profile_img}` : '');
    if (rawUrl) {
      let url = rawUrl;
      try { url = encodeURI(decodeURI(url)); } catch {}
      setAvatarSrc(url);
      setAvatarFailed(false);
    } else {
      setAvatarSrc("");
      setAvatarFailed(false);
    }
  }, [user, corhisBase]);

  useEffect(() => {
    window.scrollTo({ top: 0, left: 0, behavior: "instant" });
    if (document.documentElement) document.documentElement.scrollTop = 0;
    if (document.body) document.body.scrollTop = 0;
  }, [location.pathname, location.search]);

  const handleAvatarError = () => {
    if (avatarSrc && avatarSrc.startsWith('/internal/') && !avatarSrc.startsWith('http')) {
      setAvatarSrc(`https://hexalearn.com${avatarSrc}`);
    } else {
      setAvatarFailed(true);
    }
  };

  const employeeNavItems = [
    { id: "all", icon: Home, label: "Dashboard", path: "/dashboard" },
    { id: "roadmap", icon: LayoutGrid, label: "My Learning Journey", path: "/modules" },
    { id: "attempt_history", icon: Clock, label: "Attempt History", path: "/attempt-history" },
  ];

  const isUserAdmin = Boolean(user && (['26', '64', '205'].includes(String(user.uid)) || String(user.user_role) === '1'));
  const effectiveIsAdmin = Boolean(isAdmin || location.pathname.startsWith('/admin'));

  const { data: lockCountData } = useQuery<{ success: boolean; total_locked: number }>({
    queryKey: ['admin_deadline_lock_count'],
    queryFn: async () => {
      try {
        const res = await apiGet<any>('/admin/deadline_locked_employees.php?count_only=1');
        return res;
      } catch {
        return { success: false, total_locked: 0 };
      }
    },
    enabled: effectiveIsAdmin,
    staleTime: 30 * 1000,
  });
  const lockedCount = lockCountData?.total_locked ?? 0;

  const adminNavItems = [
    { id: "admin_dash", icon: BarChart3, label: "Dashboard", path: "/admin" },
    { id: "admin_courses", icon: BookOpen, label: "Courses", path: "/admin/courses" },
    { id: "admin_tracking", icon: Users, label: "Employee Tracking", path: "/admin/employees" },
    { id: "admin_assessments", icon: ClipboardCheck, label: "Assessment Results", path: "/admin/assessments" },
    { id: "admin_deadline_lockouts", icon: Lock, label: "Deadline Lockouts", path: "/admin/deadline-locked-employees", badge: lockedCount },
    { id: "admin_reports", icon: FileText, label: "Reports", path: "/admin/reports" },
    { id: "admin_certificates", icon: Award, label: "Certificate Downloads", path: "/admin/certificates" },
    { id: "admin_emails", icon: Mail, label: "Email Settings", path: "/admin/emails" },
    { id: "admin_settings", icon: Settings, label: "Settings", path: "/admin/settings" },
    { id: "admin_api_usage", icon: Activity, label: "API Usage", path: "/admin/api-usage" },
  ];

  const navItems = effectiveIsAdmin ? adminNavItems : employeeNavItems;

  const handleLogout = async () => {
    try {
      await apiGet(`/auth/logout.php`);
    } catch { }
    const returnUrl = encodeURIComponent(`${corhisBase}/onboard/`);
    window.location.href = `${corhisBase}/login?return=${returnUrl}`;
  };

  return (
    <div className="min-h-screen bg-background flex flex-col justify-between">
      <div className="flex-1 flex flex-col">
        {/* Default Header - Exactly as requested */}
        <header className="sticky top-0 z-30 border-b bg-card">
          <div className="flex h-16 items-center justify-between px-6">
            <div
              className="flex items-center gap-3 cursor-pointer hover:opacity-95 transition-opacity"
              onClick={() => navigate(effectiveIsAdmin ? '/admin' : '/dashboard')}
              title={effectiveIsAdmin ? "CorHis Onboarding Admin" : "CorHis Onboarding"}
            >
              <div className="flex items-center h-8 max-h-8 shrink-0 overflow-hidden dark:bg-white/95 dark:px-2.5 dark:py-1 dark:rounded-lg transition-colors">
                <img
                  src={corhisLogo}
                  alt="CorHis"
                  className="w-[160px] max-w-[160px] h-8 max-h-8 object-contain select-none block"
                  style={{ width: "160px", maxWidth: "160px", height: "32px", maxHeight: "32px" }}
                />
              </div>
              <div className="w-px h-7 bg-border" />
              <div className="flex items-center gap-2">
                <BookOpen className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                  {effectiveIsAdmin ? "ONBOARD ADMIN" : "ONBOARDING"}
                </span>
              </div>
            </div>
            <div className="flex items-center gap-2.5">
              {/* Back to Employee Portal Quick Pill */}
              <a
                href={`${corhisBase}/employee/`}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-emerald-50 dark:bg-slate-800 dark:hover:bg-slate-750 text-slate-700 hover:text-emerald-700 dark:text-slate-200 dark:hover:text-emerald-300 border border-slate-200/90 dark:border-border text-xs font-bold transition-all shadow-2xs"
                title="Return to Employee Workspace"
              >
                <ArrowLeft className="w-3.5 h-3.5" />
                <span className="hidden sm:inline">Employee Portal</span>
              </a>

              {/* App Switcher */}
              <EcosystemAppSwitcher currentApp="onboard" isAdmin={isUserAdmin} />

              {/* Appearance Mode Toggle */}
              <button
                type="button"
                onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
                className="p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-all border border-slate-200/90 dark:border-border/70 text-slate-600 dark:text-slate-300 bg-white dark:bg-card shadow-2xs cursor-pointer flex items-center justify-center"
                title={theme === "dark" ? "Switch to Light Mode" : "Switch to Dark Mode"}
                aria-label="Toggle dark mode"
              >
                {theme === "dark" ? (
                  <Sun className="w-4 h-4 text-amber-400" />
                ) : (
                  <Moon className="w-4 h-4 text-slate-700 dark:text-slate-200" />
                )}
              </button>

              <div className="h-6 w-px bg-border/80 mx-1 hidden sm:block" />

              <div className="flex items-center gap-3">
                <div className="h-9 w-9 rounded-full overflow-hidden bg-muted flex items-center justify-center text-sm font-semibold border border-slate-200 dark:border-border shrink-0">
                  {avatarSrc && !avatarFailed ? (
                    <img
                      src={avatarSrc}
                      alt={displayName || 'User'}
                      className="h-full w-full object-cover"
                      onError={handleAvatarError}
                    />
                  ) : (
                    <span>{displayName ? displayName.charAt(0).toUpperCase() : 'U'}</span>
                  )}
                </div>
                <span className="text-sm font-medium whitespace-nowrap max-w-[200px] truncate text-foreground">
                  {displayName || 'User'}
                </span>
              </div>
              <Button variant="ghost" size="sm" onClick={handleLogout} className="gap-2 text-muted-foreground hover:text-foreground">
                <LogOut className="h-4 w-4" />
                Logout
              </Button>
            </div>
          </div>
        </header>

        <div className="flex flex-1 items-start">
          {/* Sidebar */}
          <aside className="w-64 shrink-0 border-r bg-card sticky top-16 h-[calc(100vh-4rem)] flex flex-col justify-between overflow-y-auto z-20">
            <nav className="p-4 space-y-2">
              {navItems.map((item: any) => {
                const Icon = item.icon;
                let active = false;
                if (effectiveIsAdmin) {
                  active = item.path === '/admin'
                    ? (location.pathname === '/admin' || location.pathname === '/admin/')
                    : location.pathname.startsWith(item.path);
                } else {
                  if (activeEmployeeTab) {
                    active = activeEmployeeTab === item.id;
                  } else {
                    const currentTab = new URLSearchParams(location.search).get('tab');
                    if (currentTab) {
                      active = currentTab === item.id;
                    } else if (item.id === 'all') {
                      active = location.pathname === '/dashboard' || location.pathname === '/' || location.pathname === '';
                    } else {
                      active = location.pathname === item.path ||
                        location.pathname.startsWith(item.path) ||
                        (item.id === 'roadmap' && (location.pathname.includes('roadmap') || location.pathname.includes('modules'))) ||
                        (item.id === 'scorm_report' && (location.pathname.includes('report') || location.pathname.includes('scorm'))) ||
                        (item.id === 'attempt_history' && location.pathname.includes('attempt'));
                    }
                  }
                }

                return (
                  <Button
                    key={item.id || item.path}
                    variant={active ? "secondary" : "ghost"}
                    className={`w-full justify-start gap-3 h-auto py-2 px-3 relative cursor-pointer ${
                      active 
                        ? 'font-bold bg-[#00A389]/10 text-[#00A389] dark:bg-[#00A389]/20 dark:text-[#00E5BF]' 
                        : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white dark:hover:bg-slate-800/60'
                    }`}
                    onClick={() => {
                      if (!effectiveIsAdmin && onSelectEmployeeTab && item.id) {
                        onSelectEmployeeTab(item.id);
                      }
                      window.scrollTo({ top: 0, left: 0, behavior: "instant" });
                      if (document.documentElement) document.documentElement.scrollTop = 0;
                      if (document.body) document.body.scrollTop = 0;
                      navigate(item.path);
                    }}
                    aria-current={active ? 'page' : undefined}
                  >
                    {active && (
                      <span className="absolute left-0 top-0 h-full w-[3.5px] bg-[#00A389] rounded-r" />
                    )}
                    <Icon className={`h-4 w-4 shrink-0 ${active ? 'text-[#00A389]' : 'text-slate-400'}`} />
                    <div className="flex items-center justify-between flex-1 min-w-0">
                      <div className="flex flex-col text-left min-w-0">
                        <span className="text-xs font-bold leading-tight">{item.label}</span>
                        {item.subtitle && (
                          <span className="text-[10px] text-slate-400 font-medium leading-tight truncate">
                            {item.subtitle}
                          </span>
                        )}
                      </div>
                      {Boolean(item.badge && item.badge > 0) && (
                        <span className="ml-2 text-[10px] font-black bg-rose-500 text-white px-1.5 py-0.5 rounded-full shrink-0 animate-pulse">
                          {item.badge}
                        </span>
                      )}
                    </div>
                  </Button>
                );
              })}
            </nav>

            {sidebarContent && (
              <div className="p-3">
                {sidebarContent}
              </div>
            )}
          </aside>

          {/* Main Content */}
          <main className="flex-1 min-w-0 p-6">
            {children}
          </main>
        </div>
      </div>

      {/* Global Page Footer */}
      <footer className="border-t border-border bg-card py-3.5 px-6 flex items-center justify-between text-xs text-muted-foreground">
        <div className="flex items-center gap-2 font-medium">
          <span className="font-bold text-foreground">CorHis</span>
          <span>© 2026 CorHis. All rights reserved.</span>
        </div>
      </footer>
    </div>
  );
};

export default Layout;