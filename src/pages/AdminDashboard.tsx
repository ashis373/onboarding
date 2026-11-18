import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Layout } from "@/components/Layout";
import {
  Users,
  BookOpen,
  FileText,
  BarChart3,
  Plus,
  Upload,
  Pencil,
  Trash2,
  List,
  CheckCircle2,
  RefreshCw,
  Trophy,
  ArrowRight,
  Clock,
  ShieldCheck,
  Eye,
  TrendingUp,
  Sparkles,
  Activity,
  Award,
  Layers,
  ChevronRight,
  UserCheck
} from "lucide-react";
import {
  ResponsiveContainer,
  AreaChart,
  Area,
  XAxis,
  YAxis,
  Tooltip as RechartsTooltip,
  PieChart,
  Pie,
  Cell
} from "recharts";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { apiGet, apiPost } from "@/lib/api";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { useToast } from "@/components/ui/use-toast";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";

type AdminStats = {
  totalEmployees: number;
  activeCourses?: number;
  activeOnboarding: number;
  completedOnboarding: number;
  averageCompletion: number;
  completionRate?: number;
};

type ModuleSummary = {
  id: number;
  title: string;
  type: string;
  status: "active" | "draft";
  views: number;
  video_url?: string;
  file_url?: string;
};

type AssessmentSummary = {
  id: number;
  title: string;
  questions: number;
  attempts: number;
  avgScore: number;
};



type ItemOption = {
  id: number;
  name: string;
};

type DashboardSummaryResponse = {
  success: boolean;
  summary: {
    modules: Array<{
      id: number;
      title: string;
      type: string;
      completed?: boolean;
      is_active?: boolean;
      video_url?: string;
      file_url?: string;
    }>;
    stats: {
      admin: AdminStats;
    };
  };
  message?: string;
};

type AssessmentListResponse = {
  success: boolean;
  assessments: Array<{
    id: number;
    title: string;
    questions?: number;
    attempts?: number;
    score?: number;
  }>;
};

type EmployeeListResponse = {
  success: boolean;
  employees: Array<{
    uid: string;
    name: string;
    progress?: { percent?: number };
    joiningdate?: string;
    photo?: string;
  }>;
};

const AdminDashboard = () => {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  const [openCreateQuiz, setOpenCreateQuiz] = useState(false);
  const [syncingLms, setSyncingLms] = useState(false);
  const [quizForm, setQuizForm] = useState({ title: "", passing_score: 75, time_limit_minutes: 15, questions: 10 });
  const [moduleDialogOpen, setModuleDialogOpen] = useState(false);
  const [moduleDialogMode, setModuleDialogMode] = useState<"create" | "edit">("create");
  const [moduleForm, setModuleForm] = useState({
    title: "",
    type: "video",
    video_url: "",
    file_url: "",
    is_active: true,
  });
  const [moduleSaving, setModuleSaving] = useState(false);
  const [activeModuleId, setActiveModuleId] = useState<number | null>(null);
  const [editQuizDialog, setEditQuizDialog] = useState(false);
  const [quizEditForm, setQuizEditForm] = useState({ title: "", passing_score: 75, time_limit_minutes: 15, questions: 10 });
  const [activeQuizId, setActiveQuizId] = useState<number | null>(null);
  const [quizSaving, setQuizSaving] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState<{ type: "module" | "assessment"; id: number } | null>(null);

  // Tier 1: React Query cached dashboard data (60 second fresh in-memory cache)
  const {
    data: dashboardData,
    isLoading: loading,
    refetch: refetchDashboard,
  } = useQuery({
    queryKey: ['admin_dashboard_data'],
    queryFn: async () => {
      const [summaryRes, assessmentRes, employeesRes] = await Promise.allSettled([
        apiGet<DashboardSummaryResponse>(`/dashboard/summary.php`),
        apiGet<AssessmentListResponse>(`/assessments/list.php`),
        apiGet<EmployeeListResponse>(`/admin/employees.php`),
      ]);
      return { summaryRes, assessmentRes, employeesRes };
    },
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    placeholderData: (previousData) => previousData,
  });

  const isInitialLoading = Boolean(loading && !dashboardData);

  const { stats, inductionContent, quizzes, pageError } = useMemo(() => {
    let newStats: AdminStats = {
      totalEmployees: 0,
      activeCourses: 0,
      activeOnboarding: 0,
      completedOnboarding: 0,
      averageCompletion: 0,
    };
    let newContent: ModuleSummary[] = [];
    let newQuizzes: AssessmentSummary[] = [];
    let err: string | null = null;

    if (!dashboardData) {
      return { stats: newStats, inductionContent: newContent, quizzes: newQuizzes, pageError: null };
    }

    const { summaryRes, assessmentRes, employeesRes } = dashboardData;

    if (summaryRes.status === "fulfilled" && summaryRes.value.success) {
      const adminStats = summaryRes.value.summary.stats?.admin as any;
      const modules = Array.isArray(summaryRes.value.summary.modules) ? summaryRes.value.summary.modules : [];
      const activeModules = modules.filter((m) => m.is_active !== false);

      if (adminStats) {
        newStats = {
          ...newStats,
          totalEmployees: Number(adminStats.totalEmployees ?? 0),
          activeCourses: Number(adminStats.activeCourses ?? activeModules.length),
          activeOnboarding: Number(adminStats.enrolledCount ?? adminStats.activeOnboarding ?? 0),
          completedOnboarding: Number(adminStats.completedOnboarding ?? 0),
          averageCompletion: Number(adminStats.averageCompletion ?? 0),
          completionRate: Number(adminStats.completionRate ?? 0),
        };
      }
      newContent = modules.map((m) => ({
        id: m.id,
        title: m.title,
        type: m.type,
        status: m.is_active ? "active" : m.completed ? "active" : "draft",
        views: 0,
        video_url: m.video_url,
        file_url: m.file_url,
      }));
    } else if (summaryRes.status === "fulfilled") {
      err = summaryRes.value.message ?? "Unable to load dashboard summary.";
    } else {
      err = summaryRes.reason instanceof Error ? summaryRes.reason.message : "Unable to load dashboard summary.";
    }

    if (assessmentRes.status === "fulfilled" && assessmentRes.value.success) {
      newQuizzes = (assessmentRes.value.assessments || []).map((quiz) => ({
        id: Number(quiz.id),
        title: quiz.title,
        questions: quiz.questions ?? 0,
        attempts: quiz.attempts ?? 0,
        avgScore: quiz.score ?? 0,
      }));
    }

    if (employeesRes.status === "fulfilled" && employeesRes.value.success) {
      const empData = employeesRes.value as any;
      const empList = empData.employees || [];
      const enrolledEmp = empList.filter((e: any) => Boolean(e.is_moodle_enrolled));
      const totalEmp = empData.summary?.totalStaff ?? empData.totalEmployees ?? 0;
      const enrolledCount = empData.summary?.moodleEnrolled ?? empData.totalMoodleEnrolled ?? enrolledEmp.length;
      
      // Authoritative completion count across all company employees from server summary:
      const authoritativeCompleted = empData.summary?.completed !== undefined && empData.summary?.completed !== null
        ? Number(empData.summary.completed)
        : (newStats.completedOnboarding || 0);

      const activeInProg = Math.max(0, enrolledCount - authoritativeCompleted);
      const avgComp = Number(newStats.averageCompletion > 0 ? newStats.averageCompletion : (enrolledCount > 0
        ? Math.round(enrolledEmp.reduce((acc: number, e: any) => acc + (e.progress?.percent || 0), 0) / enrolledCount)
        : 0));

      newStats = {
        ...newStats,
        totalEmployees: totalEmp > 0 ? totalEmp : newStats.totalEmployees,
        activeOnboarding: enrolledCount > 0 ? enrolledCount : newStats.activeOnboarding,
        completedOnboarding: authoritativeCompleted,
        averageCompletion: avgComp,
        completionRate: totalEmp > 0 ? Math.round((authoritativeCompleted / totalEmp) * 100) : 0,
      };
    }

    return { stats: newStats, inductionContent: newContent, quizzes: newQuizzes, pageError: err };
  }, [dashboardData]);

  const loadDashboard = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['admin_dashboard_data'] });
    void refetchDashboard();
  }, [queryClient, refetchDashboard]);



  const handleSyncLms = async () => {
    setSyncingLms(true);
    try {
      await apiGet('/moodle/courses.php?sync_progress=1');
      toast({ title: "Courses Synced", description: "Course catalog, completions, and user records refreshed." });
      void loadDashboard();
    } catch {
      toast({ title: "Sync Failed", description: "Failed to connect to course service.", variant: "destructive" });
    } finally {
      setSyncingLms(false);
    }
  };

  const totalCohort = stats.totalEmployees || 0;
  const completedCohort = stats.completedOnboarding || 0;
  const enrolledTotal = stats.activeOnboarding || 0;
  // Currently in-progress enrolled employees (enrolled minus finished)
  const inProgressCohort = Math.max(0, enrolledTotal - completedCohort);
  // Unlinked / not yet enrolled employees
  const notStartedCohort = Math.max(0, totalCohort - enrolledTotal);

  // Exact cohort completion rate (percentage of enrolled who completed)
  const cohortCompletionRate = enrolledTotal > 0
    ? Math.round((completedCohort / enrolledTotal) * 100)
    : (totalCohort > 0 ? Math.round((completedCohort / totalCohort) * 100) : 0);

  const formatCohortPercent = (count: number) => {
    if (totalCohort === 0 || count === 0) return "0%";
    const pct = (count / totalCohort) * 100;
    return Number.isInteger(pct) ? `${pct}%` : `${pct.toFixed(2)}%`;
  };

  const statusDonutData = useMemo(() => [
    { name: 'Completed', value: completedCohort, color: '#00A389' },
    { name: 'In Progress', value: inProgressCohort, color: '#0284c7' },
    { name: 'Not Started', value: notStartedCohort, color: '#cbd5e1' }
  ], [completedCohort, inProgressCohort, notStartedCohort]);

  const velocityTrendData = useMemo(() => {
    const act = enrolledTotal || 3;
    const comp = completedCohort;
    return [
      { name: 'Enrollment', completed: 0, active: act },
      { name: 'Course 1', completed: 0, active: act },
      { name: 'Course 2', completed: 0, active: act },
      { name: 'Course 3', completed: comp, active: act },
      { name: 'Current', completed: comp, active: act },
    ];
  }, [enrolledTotal, completedCohort]);

  return (
    <>
      <Layout isAdmin>
        <div className="space-y-6 pb-12 animate-in fade-in duration-300">
          {!isInitialLoading && pageError && (
            <div className="rounded-2xl border border-destructive/40 bg-destructive/10 p-4 text-xs text-destructive font-semibold">
              {pageError}
            </div>
          )}

          {/* 1. TOP HEADER BANNER */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div className="flex items-center gap-3.5">
              <div className="h-11 w-11 rounded-2xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0 shadow-2xs">
                <BarChart3 className="h-5 w-5" />
              </div>
              <div>
                <div className="flex items-center gap-2 flex-wrap">
                  <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                    Onboarding Administration &amp; Analytics
                  </h1>
                </div>
                <p className="text-xs text-slate-400 font-medium mt-0.5">
                  Monitor employee onboarding and track learning progress in real time.
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2 flex-wrap">
              <button
                onClick={() => {
                  window.scrollTo({ top: 0, left: 0, behavior: "instant" });
                  navigate('/admin/employees');
                }}
                className="px-3.5 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer shadow-2xs bg-[#00A389] text-white hover:bg-[#008f77]"
              >
                <Users className="h-3.5 w-3.5" />
                <span>Employee Tracking →</span>
              </button>
            </div>
          </div>

          {isInitialLoading ? (
            <div className="space-y-6 animate-pulse">
              {/* 2. TOP 4 KPI SKELETONS */}
              <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                {[1, 2, 3, 4].map((i) => (
                  <div key={i} className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-10 rounded-xl bg-slate-200 shrink-0" />
                      <div className="space-y-1.5">
                        <div className="h-3 w-20 bg-slate-200 rounded" />
                        <div className="h-6 w-12 bg-slate-200 rounded" />
                      </div>
                    </div>
                    <div className="h-4 w-14 bg-slate-200 rounded-full" />
                  </div>
                ))}
              </div>

              {/* 3. CHARTS ROW SKELETON */}
              <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
                <div className="lg:col-span-7 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs h-72 flex flex-col justify-between">
                  <div className="flex justify-between items-center pb-3 border-b border-slate-100">
                    <div className="space-y-1.5">
                      <div className="h-4 w-40 bg-slate-200 rounded" />
                      <div className="h-3 w-56 bg-slate-200 rounded" />
                    </div>
                    <div className="h-4 w-20 bg-slate-200 rounded" />
                  </div>
                  <div className="h-48 w-full bg-slate-100 rounded-xl mt-3" />
                </div>
                <div className="lg:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs h-72 flex flex-col justify-between">
                  <div className="flex justify-between items-center pb-3 border-b border-slate-100">
                    <div className="space-y-1.5">
                      <div className="h-4 w-32 bg-slate-200 rounded" />
                      <div className="h-3 w-40 bg-slate-200 rounded" />
                    </div>
                  </div>
                  <div className="h-48 w-full bg-slate-100 rounded-xl mt-3" />
                </div>
              </div>

              {/* 4. CONTENT & ASSESSMENTS SKELETON */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
                  <div className="h-5 w-36 bg-slate-200 rounded" />
                  <div className="space-y-3">
                    {[1, 2, 3].map((i) => (
                      <div key={i} className="h-14 bg-slate-100 rounded-xl" />
                    ))}
                  </div>
                </div>
                <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
                  <div className="h-5 w-36 bg-slate-200 rounded" />
                  <div className="space-y-3">
                    {[1, 2, 3].map((i) => (
                      <div key={i} className="h-14 bg-slate-100 rounded-xl" />
                    ))}
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <>
              {/* 2. TOP 4 KPI METRIC CARDS */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
            {/* Total Employees */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0">
                  <Users className="h-5 w-5" />
                </div>
                <div>
                  <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Total Employees</div>
                  <div className="text-xl sm:text-2xl font-black text-slate-800 leading-tight mt-0.5">{stats.totalEmployees}</div>
                </div>
              </div>
              <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
                Active Staff
              </span>
            </div>

            {/* Active Courses */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                  <BookOpen className="h-5 w-5" />
                </div>
                <div>
                  <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Active Courses</div>
                  <div className="text-xl sm:text-2xl font-black text-slate-800 leading-tight mt-0.5">{stats.activeCourses || inductionContent.length}</div>
                </div>
              </div>
              <span className="text-[10px] font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-full border border-indigo-100">
                Category 1
              </span>
            </div>

            {/* Onboarding Enrolled */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
                  <UserCheck className="h-5 w-5" />
                </div>
                <div>
                  <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Onboarding Enrolled</div>
                  <div className="text-xl sm:text-2xl font-black text-slate-800 leading-tight mt-0.5">{stats.activeOnboarding}</div>
                </div>
              </div>
              <span className="text-[10px] font-bold text-sky-700 bg-sky-50 px-2 py-0.5 rounded-full border border-sky-100">
                Active Cohort
              </span>
            </div>

            {/* Completed */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                  <CheckCircle2 className="h-5 w-5" />
                </div>
                <div>
                  <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Onboarding Completed</div>
                  <div className="text-xl sm:text-2xl font-black text-slate-800 leading-tight mt-0.5">{completedCohort}</div>
                </div>
              </div>
              <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
                {cohortCompletionRate}% Rate
              </span>
            </div>
          </div>

          {/* 3. GRAPHICAL VISUAL CHARTS ROW (Velocity Area Chart & Status Donut Chart) */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
            {/* Velocity Trend Area Chart (col-span 7) */}
            <div className="lg:col-span-7 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col justify-between">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                <div className="flex items-center gap-2.5">
                  <div className="h-8 w-8 rounded-xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0">
                    <TrendingUp className="h-4 w-4" />
                  </div>
                  <div>
                    <h3 className="text-sm font-black text-slate-800 tracking-tight">Onboarding Velocity &amp; Completion Velocity</h3>
                    <p className="text-[11px] text-slate-400 font-medium">Trajectory of employee module and assessment finishes</p>
                  </div>
                </div>

                <div className="flex items-center gap-3 text-[11px] font-bold">
                  <span className="flex items-center gap-1.5 text-slate-600">
                    <span className="h-2 w-2 rounded-full bg-[#00A389]" /> Completed
                  </span>
                  <span className="flex items-center gap-1.5 text-slate-600">
                    <span className="h-2 w-2 rounded-full bg-[#0284c7]" /> Active
                  </span>
                </div>
              </div>

              <div className="h-56 w-full pt-4">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={velocityTrendData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                    <defs>
                      <linearGradient id="colorCompleted" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#00A389" stopOpacity={0.4} />
                        <stop offset="95%" stopColor="#00A389" stopOpacity={0.0} />
                      </linearGradient>
                      <linearGradient id="colorActive" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#0284c7" stopOpacity={0.3} />
                        <stop offset="95%" stopColor="#0284c7" stopOpacity={0.0} />
                      </linearGradient>
                    </defs>
                    <XAxis dataKey="name" stroke="#94a3b8" fontSize={11} tickLine={false} axisLine={false} />
                    <YAxis stroke="#94a3b8" fontSize={11} tickLine={false} axisLine={false} allowDecimals={false} domain={[0, 4]} />
                    <RechartsTooltip
                      contentStyle={{ backgroundColor: '#ffffff', borderRadius: '12px', border: '1px solid #e2e8f0', boxShadow: '0 4px 6px -1px rgba(0,0,0,0.05)', fontSize: '12px', fontWeight: 'bold' }}
                    />
                    <Area type="monotone" dataKey="completed" stroke="#00A389" strokeWidth={2.5} fillOpacity={1} fill="url(#colorCompleted)" />
                    <Area type="monotone" dataKey="active" stroke="#0284c7" strokeWidth={2} strokeDasharray="4 4" fillOpacity={1} fill="url(#colorActive)" />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            </div>

            {/* Status Donut Chart & Completion Gauge (col-span 5) */}
            <div className="lg:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col justify-between">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                <div className="flex items-center gap-2.5">
                  <div className="h-8 w-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                    <Activity className="h-4 w-4" />
                  </div>
                  <div>
                    <h3 className="text-sm font-black text-slate-800 tracking-tight">Status Distribution</h3>
                    <p className="text-[11px] text-slate-400 font-medium">Cohort progress ratio across stages</p>
                  </div>
                </div>
                <span className="text-xs font-black text-[#00A389] bg-[#00A389]/10 px-2.5 py-0.5 rounded-full">
                  {cohortCompletionRate}% Rate
                </span>
              </div>

              <div className="flex flex-col sm:flex-row items-center justify-around gap-4 py-2">
                {/* Donut Chart with Centered Metric */}
                <div className="relative h-44 w-44 shrink-0 flex items-center justify-center">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={statusDonutData}
                        cx="50%"
                        cy="50%"
                        innerRadius={55}
                        outerRadius={75}
                        paddingAngle={statusDonutData.filter((d) => d.value > 0).length > 1 ? 4 : 0}
                        dataKey="value"
                      >
                        {statusDonutData.map((entry, index) => (
                          <Cell key={`cell-${index}`} fill={entry.color} />
                        ))}
                      </Pie>
                      <RechartsTooltip
                        formatter={(val: any, name: any) => {
                          const num = Number(val) || 0;
                          return [`${num} (${formatCohortPercent(num)})`, name];
                        }}
                        contentStyle={{ backgroundColor: '#ffffff', borderRadius: '10px', border: '1px solid #e2e8f0', fontSize: '11px', fontWeight: 'bold' }}
                      />
                    </PieChart>
                  </ResponsiveContainer>
                  {/* Center Label */}
                  <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-center">
                    <span className="text-2xl font-black text-slate-800 leading-none">{cohortCompletionRate}%</span>
                    <span className="text-[10px] font-bold text-slate-400 uppercase mt-0.5">Completion</span>
                  </div>
                </div>

                {/* Graphical Pill Legend */}
                <div className="space-y-2.5 w-full sm:w-auto min-w-[210px]">
                  <div className="flex items-center justify-between gap-4 p-2.5 rounded-xl bg-emerald-50/60 border border-emerald-100 text-xs">
                    <div className="flex items-center gap-2">
                      <span className="h-2 w-2 rounded-full bg-[#00A389]" />
                      <span className="font-bold text-emerald-900">Completed</span>
                    </div>
                    <div className="flex items-baseline gap-1.5">
                      <strong className="font-black text-[#00A389] text-sm">{completedCohort}</strong>
                      <span className="text-[11px] font-bold text-emerald-700/80">({formatCohortPercent(completedCohort)})</span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between gap-4 p-2.5 rounded-xl bg-sky-50/60 border border-sky-100 text-xs">
                    <div className="flex items-center gap-2">
                      <span className="h-2 w-2 rounded-full bg-[#0284c7]" />
                      <span className="font-bold text-sky-900">In Progress</span>
                    </div>
                    <div className="flex items-baseline gap-1.5">
                      <strong className="font-black text-[#0284c7] text-sm">{inProgressCohort}</strong>
                      <span className="text-[11px] font-bold text-sky-700/80">({formatCohortPercent(inProgressCohort)})</span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between gap-4 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-xs">
                    <div className="flex items-center gap-2">
                      <span className="h-2 w-2 rounded-full bg-slate-400" />
                      <span className="font-bold text-slate-600">Not Started</span>
                    </div>
                    <div className="flex items-baseline gap-1.5">
                      <strong className="font-black text-slate-700 text-sm">{notStartedCohort}</strong>
                      <span className="text-[11px] font-bold text-slate-500">({formatCohortPercent(notStartedCohort)})</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>          {/* 3. MAIN DASHBOARD CONTENT GRID */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            {/* Left Column (col-span 7): Course Curriculum */}
            <div className="lg:col-span-7 space-y-5">
              {/* Onboarding Curriculum Table Card */}
              <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
                <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                  <div className="flex items-center gap-2.5">
                    <div className="h-8 w-8 rounded-xl bg-emerald-50 text-[#00A389] flex items-center justify-center shrink-0">
                      <BookOpen className="h-4 w-4" />
                    </div>
                    <div>
                      <h3 className="text-sm font-black text-slate-800 tracking-tight">Onboarding Courses &amp; Modules</h3>
                      <p className="text-[11px] text-slate-400 font-medium">Active onboarding curriculum</p>
                    </div>
                  </div>
                  <button
                    onClick={() => {
                      window.scrollTo({ top: 0, left: 0, behavior: "instant" });
                      navigate('/admin/courses');
                    }}
                    className="text-xs font-bold text-[#00A389] hover:underline flex items-center gap-1 cursor-pointer"
                  >
                    Manage Courses →
                  </button>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse text-xs">
                    <thead>
                      <tr className="border-b border-slate-100 text-[10px] font-black uppercase tracking-wider text-slate-400">
                        <th className="py-2.5 px-3">#</th>
                        <th className="py-2.5 px-3">Module Name</th>
                        <th className="py-2.5 px-3">Type</th>
                        <th className="py-2.5 px-3 text-right">Status</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {inductionContent.map((content, idx) => (
                        <tr key={content.id} className="hover:bg-slate-50/60 transition-colors">
                          <td className="py-2.5 px-3 font-bold text-slate-400">{idx + 1}</td>
                          <td className="py-2.5 px-3">
                            <span className="font-bold text-slate-800">{content.title}</span>
                          </td>
                          <td className="py-2.5 px-3">
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 uppercase">
                              {content.type}
                            </span>
                          </td>
                          <td className="py-2.5 px-3 text-right">
                            <span className="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                              Active
                            </span>
                          </td>
                        </tr>
                      ))}
                      {inductionContent.length === 0 && (
                        <tr>
                          <td colSpan={4} className="py-6 text-center text-slate-400">No modules found.</td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            {/* Right Column (col-span 5): Onboarding Funnel & Recent Activity */}
            <div className="lg:col-span-5 space-y-5">
              {/* Onboarding Funnel Card */}
              <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-3.5">
                <h3 className="text-sm font-black text-slate-800 tracking-tight">Onboarding Completion Rate</h3>

                <div className="space-y-2">
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-semibold text-slate-600">Cohort Completion Rate</span>
                    <span className="font-black text-[#00A389]">{cohortCompletionRate}%</span>
                  </div>
                  <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-[#00A389] rounded-full transition-all duration-700"
                      style={{ width: `${Math.max(cohortCompletionRate, 0)}%` }}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-2.5 pt-2 border-t border-slate-100 text-xs">
                  <div className="p-2.5 bg-slate-50 rounded-xl">
                    <div className="text-[10px] text-slate-400 font-semibold">In Progress</div>
                    <div className="text-base font-black text-slate-800">{inProgressCohort}</div>
                  </div>
                  <div className="p-2.5 bg-emerald-50/60 rounded-xl">
                    <div className="text-[10px] text-emerald-700 font-semibold">Completed</div>
                    <div className="text-base font-black text-emerald-800">{completedCohort}</div>
                  </div>
                </div>

                <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                  <span className="text-[11px] text-slate-500 font-medium">{stats.activeOnboarding} Enrolled · {Math.max(0, stats.totalEmployees - stats.activeOnboarding)} Staff</span>
                  <button
                    onClick={() => {
                      window.scrollTo({ top: 0, left: 0, behavior: "instant" });
                      navigate('/admin/employees');
                    }}
                    className="text-xs font-bold text-[#00A389] hover:underline cursor-pointer"
                  >
                    Employee Tracking →
                  </button>
                </div>
              </div>
            </div>
          </div>
        </>
      )}
        </div>
      </Layout>
      <Dialog open={moduleDialogOpen} onOpenChange={(open) => { setModuleDialogOpen(open); if (!open) setActiveModuleId(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{moduleDialogMode === "create" ? "Create Module" : "Edit Module"}</DialogTitle>
          </DialogHeader>
          <div className="grid gap-3">
            <div>
              <Label htmlFor="module_title">Title</Label>
              <Input
                id="module_title"
                value={moduleForm.title}
                onChange={(e) => setModuleForm((prev) => ({ ...prev, title: e.target.value }))}
                placeholder="e.g. Welcome & Company Overview"
              />
            </div>
            <div>
              <Label>Type</Label>
              <Select value={moduleForm.type} onValueChange={(value) => setModuleForm((prev) => ({ ...prev, type: value }))}>
                <SelectTrigger>
                  <SelectValue placeholder="Select type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="video">Video</SelectItem>
                  <SelectItem value="pdf">PDF</SelectItem>
                  <SelectItem value="document">Document</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label htmlFor="module_video">Video URL</Label>
              <Input
                id="module_video"
                value={moduleForm.video_url}
                onChange={(e) => setModuleForm((prev) => ({ ...prev, video_url: e.target.value }))}
                placeholder="https://..."
              />
            </div>
            <div>
              <Label htmlFor="module_file">File URL</Label>
              <Input
                id="module_file"
                value={moduleForm.file_url}
                onChange={(e) => setModuleForm((prev) => ({ ...prev, file_url: e.target.value }))}
                placeholder="https://..."
              />
            </div>
            <div className="flex items-center justify-between">
              <div>
                <Label className="text-sm">Publish</Label>
                <p className="text-xs text-muted-foreground">Visible to employees</p>
              </div>
              <Switch
                checked={moduleForm.is_active}
                onCheckedChange={(checked) => setModuleForm((prev) => ({ ...prev, is_active: checked }))}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setModuleDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={moduleSaving}
              onClick={async () => {
                if (!moduleForm.title.trim()) {
                  toast({ title: "Title is required", variant: "destructive" });
                  return;
                }
                setModuleSaving(true);
                try {
                  if (moduleDialogMode === "create") {
                    const res = await apiPost<{ success: boolean }>(`/modules/create.php`, {
                      title: moduleForm.title.trim(),
                      type: moduleForm.type,
                      video_url: moduleForm.video_url || null,
                      file_url: moduleForm.file_url || null,
                      is_active: moduleForm.is_active ? 1 : 0,
                    });
                    if (res.success) {
                      toast({ title: "Module created" });
                      setModuleDialogOpen(false);
                      void loadDashboard();
                    }
                  } else if (activeModuleId) {
                    const res = await apiPost<{ success: boolean }>(`/modules/update.php`, {
                      id: activeModuleId,
                      title: moduleForm.title.trim(),
                      type: moduleForm.type,
                      video_url: moduleForm.video_url || null,
                      file_url: moduleForm.file_url || null,
                      is_active: moduleForm.is_active ? 1 : 0,
                    });
                    if (res.success) {
                      toast({ title: "Module updated" });
                      setModuleDialogOpen(false);
                      void loadDashboard();
                    }
                  }
                } catch (error) {
                  const message = error instanceof Error ? error.message : "Unable to save module";
                  toast({ title: "Module save failed", description: message, variant: "destructive" });
                } finally {
                  setModuleSaving(false);
                }
              }}
            >
              {moduleDialogMode === "create" ? "Create" : "Save"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Create Quiz Modal */}
      <Dialog open={openCreateQuiz} onOpenChange={setOpenCreateQuiz}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create Assessment</DialogTitle>
          </DialogHeader>
          <div className="grid md:grid-cols-2 gap-3">
            <div className="md:col-span-2">
              <Label htmlFor="q_title">Title</Label>
              <Input id="q_title" value={quizForm.title} onChange={(e) => setQuizForm({ ...quizForm, title: e.target.value })} placeholder="e.g. Security Basics" />
            </div>
            <div>
              <Label htmlFor="q_pass">Passing Score (%)</Label>
              <Input id="q_pass" type="number" value={quizForm.passing_score} onChange={(e) => setQuizForm({ ...quizForm, passing_score: parseInt(e.target.value || '0', 10) })} />
            </div>
            <div>
              <Label htmlFor="q_time">Time Limit (minutes)</Label>
              <Input id="q_time" type="number" value={quizForm.time_limit_minutes} onChange={(e) => setQuizForm({ ...quizForm, time_limit_minutes: parseInt(e.target.value || '0', 10) })} />
            </div>
            <div className="md:col-span-2">
              <Label htmlFor="q_questions">Number of Questions</Label>
              <Input id="q_questions" type="number" value={quizForm.questions} onChange={(e) => setQuizForm({ ...quizForm, questions: parseInt(e.target.value || '0', 10) })} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setOpenCreateQuiz(false)}>Cancel</Button>
            <Button
              onClick={async () => {
                if (!quizForm.title.trim()) {
                  toast({ title: "Title required", variant: "destructive" });
                  return;
                }
                try {
                  const res = await apiPost<{ success: boolean; id?: number }>(`/assessments/create.php`, {
                    title: quizForm.title.trim(),
                    passing_score: quizForm.passing_score,
                    time_limit_minutes: quizForm.time_limit_minutes,
                    questions: quizForm.questions,
                  });
                  if (res.success) {
                    toast({ title: "Assessment created" });
                    setOpenCreateQuiz(false);
                    void loadDashboard();
                  }
                } catch (error) {
                  const message = error instanceof Error ? error.message : "Unable to create assessment";
                  toast({ title: "Failed to create assessment", description: message, variant: "destructive" });
                }
              }}
            >
              Create
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={editQuizDialog} onOpenChange={(open) => { setEditQuizDialog(open); if (!open) setActiveQuizId(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Assessment</DialogTitle>
          </DialogHeader>
          <div className="grid md:grid-cols-2 gap-3">
            <div className="md:col-span-2">
              <Label htmlFor="edit_q_title">Title</Label>
              <Input
                id="edit_q_title"
                value={quizEditForm.title}
                onChange={(e) => setQuizEditForm((prev) => ({ ...prev, title: e.target.value }))}
              />
            </div>
            <div>
              <Label htmlFor="edit_q_questions">Questions</Label>
              <Input
                id="edit_q_questions"
                type="number"
                value={quizEditForm.questions}
                onChange={(e) => setQuizEditForm((prev) => ({ ...prev, questions: parseInt(e.target.value || "0", 10) }))}
              />
            </div>
            <div>
              <Label htmlFor="edit_q_pass">Passing Score (%)</Label>
              <Input
                id="edit_q_pass"
                type="number"
                value={quizEditForm.passing_score}
                onChange={(e) => setQuizEditForm((prev) => ({ ...prev, passing_score: parseInt(e.target.value || "0", 10) }))}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setEditQuizDialog(false)}>
              Cancel
            </Button>
            <Button
              disabled={quizSaving}
              onClick={async () => {
                if (!activeQuizId) return;
                if (!quizEditForm.title.trim()) {
                  toast({ title: "Title required", variant: "destructive" });
                  return;
                }
                setQuizSaving(true);
                try {
                  const res = await apiPost<{ success: boolean }>(`/assessments/update.php`, {
                    id: activeQuizId,
                    title: quizEditForm.title.trim(),
                    questions: quizEditForm.questions,
                    passing_score: quizEditForm.passing_score,
                  });
                  if (res.success) {
                    toast({ title: "Assessment updated" });
                    setEditQuizDialog(false);
                    void loadDashboard();
                  }
                } catch (error) {
                  const message = error instanceof Error ? error.message : "Unable to update assessment";
                  toast({ title: "Update failed", description: message, variant: "destructive" });
                } finally {
                  setQuizSaving(false);
                }
              }}
            >
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={Boolean(deleteConfirm)} onOpenChange={(open) => !open && setDeleteConfirm(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {deleteConfirm?.type === "module" ? "module" : "assessment"}?</AlertDialogTitle>
            <AlertDialogDescription>This action cannot be undone.</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={async () => {
                if (!deleteConfirm) return;
                try {
                  const endpoint =
                    deleteConfirm.type === "module"
                      ? `/modules/delete.php?id=${deleteConfirm.id}`
                      : `/assessments/delete.php?id=${deleteConfirm.id}`;
                  const res = await apiGet<{ success: boolean }>(endpoint);
                  if (res.success) {
                    toast({ title: deleteConfirm.type === "module" ? "Module removed" : "Assessment removed" });
                    setDeleteConfirm(null);
                    void loadDashboard();
                  }
                } catch (error) {
                  const message = error instanceof Error ? error.message : "Delete failed";
                  toast({ title: "Delete failed", description: message, variant: "destructive" });
                }
              }}
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
};

export default AdminDashboard;