import React from "react";
import {
  LayoutGrid,
  Briefcase,
  GraduationCap,
  BookOpen,
  Home,
  Shield,
  Check,
  ExternalLink,
  Wrench,
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

interface EcosystemAppSwitcherProps {
  currentApp?: "employee" | "onboard" | "knowledgehub" | "auth" | "admin-pro" | "utility";
  isAdmin?: boolean;
  className?: string;
}

export const EcosystemAppSwitcher: React.FC<EcosystemAppSwitcherProps> = ({
  currentApp = "onboard",
  isAdmin = false,
  className,
}) => {
  const isLocal = typeof window !== "undefined" && window.location.pathname.startsWith("/internal/corhis");
  const basePath = isLocal ? "/internal/corhis" : "";

  const apps = [
    {
      id: "employee",
      name: "Employee Workspace",
      description: "Attendance, timesheets, tasks & project board",
      url: `${basePath}/employee/`,
      icon: Briefcase,
      color: "text-emerald-600 dark:text-emerald-400 bg-emerald-500/10",
      activeBorder: "border-emerald-500/40 bg-emerald-50/50 dark:bg-emerald-950/30",
    },
    {
      id: "onboard",
      name: "Onboarding Portal",
      description: "Induction modules, journey milestones & compliance",
      url: `${basePath}/onboard/`,
      icon: GraduationCap,
      color: "text-amber-600 dark:text-amber-400 bg-amber-500/10",
      activeBorder: "border-amber-500/40 bg-amber-50/50 dark:bg-amber-950/30",
    },
    {
      id: "knowledgehub",
      name: "KnowledgeHub",
      description: "Company knowledge base, wiki, articles & policies",
      url: `${basePath}/knowledgehub/`,
      icon: BookOpen,
      color: "text-sky-600 dark:text-sky-400 bg-sky-500/10",
      activeBorder: "border-sky-500/40 bg-sky-50/50 dark:bg-sky-950/30",
    },
    {
      id: "utility",
      name: "Hexa Utility",
      description: "27 developer, media, text, QA & wellness tools",
      url: `${basePath}/utility/`,
      icon: Wrench,
      color: "text-violet-600 dark:text-violet-400 bg-violet-500/10",
      activeBorder: "border-violet-500/40 bg-violet-50/50 dark:bg-violet-950/30",
    },
    {
      id: "auth",
      name: "Workspace Hub",
      description: "Central SSO application launcher & account dashboard",
      url: `${basePath}/auth/`,
      icon: Home,
      color: "text-indigo-600 dark:text-indigo-400 bg-indigo-500/10",
      activeBorder: "border-indigo-500/40 bg-indigo-50/50 dark:bg-indigo-950/30",
    },
    ...(isAdmin
      ? [
          {
            id: "admin-pro",
            name: "Admin Pro",
            description: "Enterprise workforce administration & management",
            url: `${basePath}/admin-pro/`,
            icon: Shield,
            color: "text-purple-600 dark:text-purple-400 bg-purple-500/10",
            activeBorder: "border-purple-500/40 bg-purple-50/50 dark:bg-purple-950/30",
          },
        ]
      : []),
  ];

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className={cn(
            "p-2 px-2.5 rounded-xl transition-all border border-slate-200/90 dark:border-border/70 text-slate-700 dark:text-slate-300 bg-white dark:bg-card shadow-2xs flex items-center gap-1.5 group outline-hidden hover:bg-slate-50 hover:border-emerald-300/80 dark:hover:bg-slate-800 dark:hover:border-emerald-500/40 focus-visible:ring-2 focus-visible:ring-emerald-500/50 cursor-pointer",
            className
          )}
          title="Switch CorHis Application"
          aria-label="CorHis Applications"
        >
          <LayoutGrid className="w-4 h-4 text-emerald-600 dark:text-emerald-400 group-hover:scale-110 transition-transform" />
          <span className="hidden md:inline text-[11px] font-bold text-slate-700 dark:text-slate-200 group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">
            Apps
          </span>
        </button>
      </DropdownMenuTrigger>

      <DropdownMenuContent
        align="end"
        sideOffset={8}
        className="w-80 p-2 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xl z-50"
      >
        <DropdownMenuLabel className="px-2 py-1.5">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="p-1 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <LayoutGrid className="w-3.5 h-3.5" />
              </div>
              <span className="text-xs font-bold text-slate-800 dark:text-slate-100">
                CorHis Applications
              </span>
            </div>
            <span className="text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
              Unified SSO
            </span>
          </div>
          <p className="text-[11px] font-normal text-slate-500 dark:text-slate-400 mt-1">
            Navigate seamlessly between connected workspace portals.
          </p>
        </DropdownMenuLabel>

        <DropdownMenuSeparator className="my-1.5 bg-slate-100 dark:bg-slate-800" />

        <div className="space-y-1">
          {apps.map((app) => {
            const isCurrent = currentApp === app.id;
            const Icon = app.icon;

            return (
              <DropdownMenuItem
                key={app.id}
                asChild
                className={cn(
                  "flex items-start gap-3 p-2.5 rounded-xl cursor-pointer transition-colors outline-hidden select-none",
                  isCurrent
                    ? `${app.activeBorder} border`
                    : "border border-transparent hover:bg-slate-100/90 dark:hover:bg-slate-800/80 focus:bg-slate-100/90 dark:focus:bg-slate-800/80"
                )}
              >
                <a href={app.url} className="w-full flex items-start gap-3">
                  <div
                    className={cn(
                      "mt-0.5 p-2 rounded-xl shrink-0 transition-transform group-hover:scale-105",
                      app.color
                    )}
                  >
                    <Icon className="w-4 h-4" />
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="text-xs font-bold text-slate-900 dark:text-white leading-tight">
                        {app.name}
                      </span>
                      {isCurrent && (
                        <span className="text-[9px] font-extrabold px-1.5 py-0.2 rounded-full bg-emerald-500/20 text-emerald-800 dark:text-emerald-300 border border-emerald-500/30">
                          Current
                        </span>
                      )}
                    </div>
                    <p className="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">
                      {app.description}
                    </p>
                  </div>

                  {isCurrent ? (
                    <Check className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-1" />
                  ) : (
                    <ExternalLink className="w-3.5 h-3.5 text-slate-300 dark:text-slate-600 shrink-0 mt-1 opacity-0 group-hover:opacity-100 transition-opacity" />
                  )}
                </a>
              </DropdownMenuItem>
            );
          })}
        </div>

        <DropdownMenuSeparator className="my-1.5 bg-slate-100 dark:bg-slate-800" />
        <div className="px-2 py-1 text-[10px] text-slate-400 dark:text-slate-500 flex items-center justify-between">
          <span>Single sign-on active across all apps</span>
          <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
};
