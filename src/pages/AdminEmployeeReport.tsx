import { useEffect, useState, useCallback, useMemo } from "react";
import { useParams, useNavigate, useSearchParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { 
  ArrowLeft, 
  BarChart3, 
  RotateCcw, 
  CheckCircle2, 
  AlertTriangle, 
  TrendingUp, 
  Award, 
  Mail, 
  Calendar, 
  User, 
  RefreshCw,
  ExternalLink,
  BookOpen,
  Unlock,
  ShieldCheck
} from "lucide-react";
import { apiGet } from "@/lib/api";
import { useToast } from "@/components/ui/use-toast";
import { AdminCourseAccessModal } from "@/components/AdminCourseAccessModal";

interface ProgressSummary {
  completed_modules: number;
  total_modules: number;
  percent: number;
  total_attempts: number;
}

interface EmployeeInfo {
  uid: number;
  name: string;
  email: string;
  joiningdate?: string;
  joiningdate_fmt?: string;
  photo?: string;
  moodle_user_id?: number | null;
  moodle_status?: string;
}

interface ModuleProgressItem {
  id: number;
  title: string;
  type: string;
  is_assessment: boolean;
  status: 'completed' | 'failed' | 'in_progress' | 'ready' | 'not_started';
  is_completed: boolean;
  is_failed: boolean;
  is_in_progress: boolean;
  progress_percent: number;
  score: number | null;
  passing_score: number;
  time_spent: string;
  attempts: number;
  course_id?: number;
  course_name?: string;
  current_slide?: number;
  slides_viewed?: number;
  total_slides?: number;
  first_started_at?: string | null;
  last_played_at?: string | null;
}

// Format duration helper
const formatDuration = (timeStr?: string) => {
  if (!timeStr || timeStr === '00:00:00' || timeStr === '00:00:00.00' || timeStr === '0s') {
    return '0 min 0 sec';
  }
  const clean = timeStr.split('.')[0];
  const parts = clean.split(':');
  if (parts.length === 3) {
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const s = parseInt(parts[2], 10);
    if (h > 0) return s > 0 ? `${h}h ${m} min ${s} sec` : `${h}h ${m} min`;
    if (m > 0 && s > 0) return `${m} min ${s} sec`;
    if (m > 0) return `${m} min`;
    if (s > 0) return `0 min ${s} sec`;
  }
  if (timeStr.endsWith('s') && !isNaN(parseInt(timeStr))) {
    const s = parseInt(timeStr);
    if (s < 60) return `0 min ${s} sec`;
    const m = Math.floor(s / 60);
    const rem = s % 60;
    return rem > 0 ? `${m} min ${rem} sec` : `${m} min`;
  }
  return timeStr;
};

// Format date time helper
const formatDateTime = (dateVal?: string | number | null) => {
  if (!dateVal) return null;
  try {
    const d = typeof dateVal === 'number' ? new Date(dateVal * 1000) : new Date(dateVal);
    if (isNaN(d.getTime())) return String(dateVal);
    return d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true,
    });
  } catch {
    return String(dateVal);
  }
};

// Compute elapsed calendar span between First Started At and Last Activity
const computeElapsedSpan = (start?: any, end?: any) => {
  if (!start || !end) return null;
  try {
    const parseTime = (val: any) => {
      if (!val) return 0;
      if (typeof val === 'number') return val > 1e11 ? val : val * 1000;
      const d = new Date(val).getTime();
      return isNaN(d) ? 0 : d;
    };
    const t1 = parseTime(start);
    const t2 = parseTime(end);
    if (t1 <= 0 || t2 <= 0 || t2 < t1) return null;
    const diffSec = Math.round((t2 - t1) / 1000);
    if (diffSec <= 0) return 'Just started';
    if (diffSec < 60) return `${diffSec} sec`;
    const h = Math.floor(diffSec / 3600);
    const m = Math.floor((diffSec % 3600) / 60);
    const s = diffSec % 60;
    if (h > 0) return s > 0 ? `${h}h ${m} min ${s} sec` : `${h}h ${m} min`;
    if (m > 0 && s > 0) return `${m} min ${s} sec`;
    if (m > 0) return `${m} min`;
    return `${diffSec} sec`;
  } catch {
    return null;
  }
};

interface AssessmentAttemptItem {
  id: number;
  attempt: number;
  score: number | null;
  result: string;
  is_passed: boolean;
  is_failed: boolean;
  duration: string;
  date: string;
  attempted_at?: string;
}

interface SessionLogItem {
  id: number;
  module_id: number;
  module_title: string;
  session_time: string;
  progress_percent: number;
  score: number | null;
  status: string;
  date: string;
}

interface LmsReportData {
  success: boolean;
  employee: EmployeeInfo;
  progress_summary: ProgressSummary;
  modules: ModuleProgressItem[];
  attempts: AssessmentAttemptItem[];
  sessions: SessionLogItem[];
}

const AdminEmployeeReport = () => {
  const params = useParams<{ uid?: string; id?: string }>();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  const targetUid = params.uid || params.id || searchParams.get('uid') || searchParams.get('id');
  const [isAccessModalOpen, setIsAccessModalOpen] = useState(false);

  const queryClient = useQueryClient();

  // Tier 1: React Query cached employee progress report (60 second fresh in-memory cache)
  const {
    data: reportData,
    isLoading: loading,
    error: queryErr,
    refetch: refetchReport,
  } = useQuery<LmsReportData>({
    queryKey: ['admin_employee_report', targetUid],
    queryFn: async () => {
      if (!targetUid) throw new Error("No employee ID specified");
      const res = await apiGet<LmsReportData>(`/admin/employee_progress.php?uid=${targetUid}`);
      if (res && res.success) return res;
      const msg = (res as any)?.message || "Failed to load employee audit report";
      toast({ title: msg, variant: "destructive" });
      throw new Error(msg);
    },
    enabled: Boolean(targetUid),
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    placeholderData: (previousData) => previousData,
  });

  const data = reportData ?? null;
  const error = !targetUid ? "No employee ID specified" : (queryErr instanceof Error ? queryErr.message : null);

  const loadReport = useCallback(() => {
    if (targetUid) {
      void queryClient.invalidateQueries({ queryKey: ['admin_employee_report', targetUid] });
      void refetchReport();
    }
  }, [queryClient, refetchReport, targetUid]);

  const learningModulesOnly = (data?.modules || []).filter((m) =>
    !m.is_assessment &&
    !m.title.toLowerCase().includes('assess') &&
    !m.title.toLowerCase().includes('quiz') &&
    !m.title.toLowerCase().includes('employee_assessment') &&
    m.title.trim().toLowerCase() !== 'employee onboarding module'
  );

  const validAttempts = (data?.attempts || []).filter((att) => {
    const isGhost = att.score === null && (!att.duration || att.duration === '0s') && (!att.date || att.date === '—');
    return !isGhost;
  });

  return (
    <Layout isAdmin>
      <div className="space-y-6 pb-12 animate-in fade-in duration-300 w-full">
        {/* Navigation & Back Action */}
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              window.scrollTo({ top: 0, left: 0, behavior: "instant" });
              navigate('/admin/employees');
            }}
            className="gap-2 text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border-slate-200 shadow-2xs cursor-pointer"
          >
            <ArrowLeft className="h-4 w-4" />
            <span>Back to Employees</span>
          </Button>

          <div className="flex items-center gap-2 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsAccessModalOpen(true)}
              className="gap-2 text-xs font-bold bg-amber-50 text-amber-900 hover:bg-amber-100 border-amber-200 shadow-2xs cursor-pointer"
              title="Manage course unlock permissions and relaunch access"
            >
              <Unlock className="h-3.5 w-3.5 text-amber-700" />
              <span>Course Access &amp; Relaunch</span>
            </Button>

            <Button
              variant="outline"
              size="sm"
              onClick={loadReport}
              disabled={loading}
              className="gap-2 text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border-slate-200 shadow-2xs cursor-pointer"
            >
              <RefreshCw className={`h-3.5 w-3.5 text-slate-600 ${loading ? 'animate-spin text-[#00A389]' : ''}`} />
              <span>Refresh LMS Data</span>
            </Button>
          </div>
        </div>

        {/* Loading State */}
        {loading && (
          <div className="bg-white border border-slate-200/80 rounded-2xl p-16 flex flex-col items-center justify-center shadow-2xs">
            <div className="h-10 w-10 rounded-full border-4 border-[#00A389]/20 border-t-[#00A389] animate-spin" />
            <p className="text-sm font-bold text-slate-700 mt-4">Loading real-time LMS report...</p>
            <p className="text-xs text-slate-400 mt-1">Connecting to Moodle LMS and auditing SCORM tracks</p>
          </div>
        )}

        {/* Error State */}
        {!loading && error && (
          <div className="bg-white border border-red-200 rounded-2xl p-10 flex flex-col items-center justify-center shadow-2xs text-center">
            <div className="h-12 w-12 rounded-full bg-red-50 text-red-600 flex items-center justify-center mb-3">
              <AlertTriangle className="h-6 w-6" />
            </div>
            <h2 className="text-lg font-black text-slate-800">Unable to load report</h2>
            <p className="text-xs text-slate-500 mt-1 max-w-md">{error}</p>
            <Button
              onClick={loadReport}
              className="mt-4 bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold"
            >
              Retry
            </Button>
          </div>
        )}

        {/* Loaded Content */}
        {!loading && data && (
          <>
            {/* 1. Employee Profile Header Card */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs flex flex-col md:flex-row md:items-center justify-between gap-6">
              <div className="flex items-center gap-4">
                <div className="h-16 w-16 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center font-black text-xl text-slate-700 shrink-0 shadow-2xs">
                  {data.employee.photo ? (
                    <img
                      src={data.employee.photo}
                      alt={data.employee.name}
                      className="h-full w-full object-cover"
                      onError={(e) => { (e.target as HTMLElement).style.display = 'none'; }}
                    />
                  ) : (
                    <span>{String(data.employee.name || '').split(' ').map((n) => n[0]).join('').slice(0, 2)}</span>
                  )}
                </div>
                <div>
                  <div className="flex items-center gap-2.5 flex-wrap">
                    <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                      {data.employee.name}
                    </h1>
                    {data.employee.moodle_user_id ? (
                      <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                        Moodle Enrolled (#{data.employee.moodle_user_id})
                      </span>
                    ) : (
                      <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-500 border border-slate-200">
                        Staff
                      </span>
                    )}
                  </div>

                  <div className="flex items-center gap-4 text-xs text-slate-500 font-medium mt-1 flex-wrap">
                    {data.employee.email && (
                      <span className="flex items-center gap-1.5 text-slate-600">
                        <Mail className="h-3.5 w-3.5 text-slate-400" />
                        {data.employee.email}
                      </span>
                    )}
                    {data.employee.joiningdate_fmt && (
                      <span className="flex items-center gap-1.5 text-slate-500">
                        <Calendar className="h-3.5 w-3.5 text-slate-400" />
                        Joined {data.employee.joiningdate_fmt}
                      </span>
                    )}
                    <span className="flex items-center gap-1.5 text-slate-400">
                      <User className="h-3.5 w-3.5 text-slate-400" />
                      Hexa UID: {data.employee.uid}
                    </span>
                  </div>
                </div>
              </div>

              <div className="flex items-center gap-3 self-start md:self-auto shrink-0">
                <span className="text-xs font-bold text-slate-400">Report Status:</span>
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black bg-emerald-50 text-emerald-800 border border-emerald-200">
                  <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                  Live LMS Synchronized
                </span>
              </div>
            </div>

            {/* 2. Top Summary KPI Cards */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
              {/* Overall Progress */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-2xs flex flex-col justify-between gap-3">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Overall Progress</div>
                    <div className="text-2xl font-black text-slate-800 leading-tight mt-1">
                      {data.progress_summary?.percent ?? 0}%
                    </div>
                  </div>
                  <div className="h-10 w-10 rounded-xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0">
                    <TrendingUp className="h-5 w-5" />
                  </div>
                </div>
                <div>
                  <div className="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-[#00A389] rounded-full transition-all duration-500"
                      style={{ width: `${Math.max(data.progress_summary?.percent ?? 0, 3)}%` }}
                    />
                  </div>
                  <div className="text-[10px] text-slate-400 font-medium mt-1.5">
                    {data.progress_summary?.completed_modules ?? 0} of {data.progress_summary?.total_modules ?? 0} modules completed
                  </div>
                </div>
              </div>

              {/* Total Modules */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-2xs flex flex-col justify-between gap-3">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Total Modules</div>
                    <div className="text-2xl font-black text-indigo-600 leading-tight mt-1">
                      {data.progress_summary?.total_modules ?? data.modules?.length ?? 0}
                    </div>
                  </div>
                  <div className="h-10 w-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                    <BookOpen className="h-5 w-5" />
                  </div>
                </div>
                <div className="text-[10px] text-slate-400 font-medium">
                  Moodle Category 1 Onboarding
                </div>
              </div>

              {/* Assessment Attempts */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-2xs flex flex-col justify-between gap-3">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Assessment Attempts</div>
                    <div className="text-2xl font-black text-amber-600 leading-tight mt-1">
                      {validAttempts.length}
                    </div>
                  </div>
                  <div className="h-10 w-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                    <RotateCcw className="h-5 w-5" />
                  </div>
                </div>
                <div className="text-[10px] text-slate-400 font-medium">
                  Recorded permanently in LMS
                </div>
              </div>

              {/* LMS Link Status */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-2xs flex flex-col justify-between gap-3">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">LMS Account Status</div>
                    <div className="text-lg font-black text-emerald-700 leading-tight mt-1 truncate">
                      {data.employee?.moodle_status ?? 'Linked'}
                    </div>
                  </div>
                  <div className="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                    <Award className="h-5 w-5" />
                  </div>
                </div>
                <div className="text-[10px] text-slate-400 font-medium">
                  Moodle User ID: #{data.employee?.moodle_user_id ?? 'Auto'}
                </div>
              </div>
            </div>

            {/* 3. Table 1: SCORM Learning & Module Progress Report */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100 flex-wrap gap-2">
                <div className="flex items-center gap-2.5">
                  <div className="h-8 w-8 rounded-lg bg-[#E6F7F4] text-[#00A389] flex items-center justify-center border border-[#00A389]/30">
                    <BarChart3 className="h-4 w-4" />
                  </div>
                  <div>
                    <h3 className="text-sm font-black text-slate-800 tracking-tight">
                      SCORM Learning & Module Progress Report
                    </h3>
                    <p className="text-[11px] text-slate-400 font-medium">
                      Real-time slide tracking, active time spent, and activity completions recorded directly by Moodle LMS
                    </p>
                  </div>
                </div>
                <span className="text-[10px] font-bold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-2.5 py-1 rounded-full">
                  Monotonic slide & time tracking
                </span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-slate-100 text-[10px] font-black uppercase tracking-wider text-slate-400">
                      <th className="py-2.5 px-3 w-8">#</th>
                      <th className="py-2.5 px-3">Module Name</th>
                      <th className="py-2.5 px-3">Status</th>
                      <th className="py-2.5 px-3">Time Spent, Overall Session Span</th>
                      <th className="py-2.5 px-3">Last Activity & Started</th>
                      <th className="py-2.5 px-3 text-right">Progress</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {learningModulesOnly.length > 0 ? (
                      learningModulesOnly.map((m, idx) => {
                        const isDone = m.status === 'completed';
                        const isFailed = m.status === 'failed';
                        const isProgress = m.status === 'in_progress';
                        const totalSlides = Number(m.total_slides) || (m.course_id === 2 ? 18 : m.course_id === 4 ? 18 : m.course_id === 5 ? 17 : m.course_id === 6 ? 19 : 0);
                        const currentSlide = isDone ? totalSlides : (Number(m.current_slide || m.slides_viewed) || 0);
                        const hasTime = Boolean(m.time_spent && m.time_spent !== '00:00:00' && m.time_spent !== '00:00:00.00' && m.time_spent !== '0s');
                        const formattedTime = formatDuration(m.time_spent);
                        const sessionSpan = computeElapsedSpan(m.first_started_at, m.last_played_at);
                        const formattedStarted = formatDateTime(m.first_started_at);
                        const formattedLastPlayed = formatDateTime(m.last_played_at);

                        return (
                          <tr key={m.id || idx} className="hover:bg-slate-50/60 transition-colors">
                            <td className="py-3 px-3 font-bold text-slate-400">{idx + 1}</td>
                            <td className="py-3 px-3">
                              <div className="font-bold text-slate-800">{m.title}</div>
                              <div className="text-[10px] text-slate-400 flex items-center gap-1.5 mt-0.5 flex-wrap">
                                <span>Single activity · SCORM</span>
                                {isDone && totalSlides > 0 ? (
                                  <>
                                    <span>•</span>
                                    <span className="font-semibold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-1.5 py-0.5 rounded text-[10px]">
                                      Completed ({totalSlides}/{totalSlides} slides)
                                    </span>
                                  </>
                                ) : isProgress && currentSlide > 0 && totalSlides > 0 ? (
                                  <>
                                    <span>•</span>
                                    <span className="font-semibold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-1.5 py-0.5 rounded text-[10px]">
                                      Slide {currentSlide}/{totalSlides}
                                    </span>
                                  </>
                                ) : null}
                              </div>
                            </td>
                            <td className="py-3 px-3">
                              {isDone ? (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full">
                                  ✓ Completed
                                </span>
                              ) : isFailed ? (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-amber-800 bg-amber-50 border border-amber-300 px-2.5 py-0.5 rounded-full">
                                  Failed (Needs {m.passing_score ?? 80}%)
                                </span>
                              ) : isProgress ? (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-2.5 py-0.5 rounded-full">
                                  In Progress
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 text-[10px] font-medium text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full">
                                  Ready
                                </span>
                              )}
                            </td>
                            <td className="py-3 px-3">
                              {hasTime ? (
                                <div className="space-y-0.5">
                                  <div className="font-bold text-slate-700 text-xs">
                                    {formattedTime}
                                  </div>
                                  {sessionSpan && (
                                    <div className="text-[10px] text-[#00A389] font-medium flex items-center gap-1">
                                      <span className="font-bold">Span:</span>
                                      <span>{sessionSpan}</span>
                                    </div>
                                  )}
                                </div>
                              ) : isProgress ? (
                                <span className="text-xs font-semibold text-[#00A389]">Active</span>
                              ) : (
                                <span className="text-slate-400 font-medium">—</span>
                              )}
                            </td>
                            <td className="py-3 px-3 text-slate-600">
                              {formattedLastPlayed ? (
                                <div className="space-y-0.5">
                                  <div className="text-xs font-semibold text-slate-700">
                                    {formattedLastPlayed}
                                  </div>
                                  {formattedStarted && formattedStarted !== formattedLastPlayed && (
                                    <div className="text-[10px] text-slate-400">
                                      Started: {formattedStarted}
                                    </div>
                                  )}
                                </div>
                              ) : (
                                <span className="text-slate-400 text-[11px] font-medium">—</span>
                              )}
                            </td>
                            <td className="py-3 px-3 text-right">
                              <div className="inline-flex items-center justify-end gap-2">
                                <div className="w-16 h-2 rounded-full bg-slate-100 overflow-hidden">
                                  <div
                                    className="h-full bg-[#00A389] rounded-full transition-all duration-300"
                                    style={{ width: `${m.progress_percent}%` }}
                                  />
                                </div>
                                <span className={`text-[11px] font-bold w-9 text-right ${m.progress_percent > 0 ? 'text-[#00A389]' : 'text-slate-700'}`}>
                                  {m.progress_percent}%
                                </span>
                              </div>
                            </td>
                          </tr>
                        );
                      })
                    ) : (
                      <tr>
                        <td colSpan={6} className="py-8 text-center text-slate-400 font-medium">
                          No module tracking recorded yet for this employee.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* 4. Table 2: Assessment Attempt History */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100 flex-wrap gap-2">
                <div className="flex items-center gap-2.5">
                  <div className="h-8 w-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center border border-amber-100">
                    <RotateCcw className="h-4 w-4" />
                  </div>
                  <div>
                    <h3 className="text-sm font-black text-slate-800 tracking-tight">
                      Assessment Attempt History
                    </h3>
                    <p className="text-[11px] text-slate-400 font-medium">
                      Permanent audit of all employee quiz and assessment attempts recorded in Moodle LMS
                    </p>
                  </div>
                </div>
                <span className="text-[10px] font-bold text-amber-800 bg-amber-100/80 px-2.5 py-1 rounded-full">
                  {validAttempts.length} Attempt{validAttempts.length !== 1 ? 's' : ''} Stored
                </span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-slate-100 text-[10px] font-black uppercase tracking-wider text-slate-400">
                      <th className="py-2.5 px-3">Attempt</th>
                      <th className="py-2.5 px-3">Score</th>
                      <th className="py-2.5 px-3">Result</th>
                      <th className="py-2.5 px-3">Duration</th>
                      <th className="py-2.5 px-3">Date & Time</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {validAttempts.length > 0 ? (
                      validAttempts.map((att, idx) => {
                        const isPassed = att.is_passed || att.result === 'Passed';
                        const isFailed = att.is_failed || att.result === 'Failed';
                        const attemptNum = att.attempt ?? (idx + 1);

                        return (
                          <tr key={att.id || idx} className="hover:bg-slate-50/60 transition-colors">
                            <td className="py-3 px-3">
                              <div className="font-bold text-slate-800">Attempt {attemptNum}</div>
                              <div className="text-[10px] text-slate-400">{attemptNum === 1 ? 'Initial' : 'Retake'}</div>
                            </td>
                            <td className="py-3 px-3">
                              <span className={`font-black text-sm ${isPassed ? 'text-emerald-700' : 'text-red-600'}`}>
                                {att.score !== null ? `${att.score}%` : '—'}
                              </span>
                            </td>
                            <td className="py-3 px-3">
                              {isPassed ? (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full">
                                  ✓ Passed
                                </span>
                              ) : isFailed ? (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-0.5 rounded-full">
                                  Failed
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 text-[10px] font-medium text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full">
                                  {att.result || 'Incomplete'}
                                </span>
                              )}
                            </td>
                            <td className="py-3 px-3 font-semibold text-slate-600">
                              {formatDuration(att.duration)}
                            </td>
                            <td className="py-3 px-3 text-slate-600 font-medium">
                              {formatDateTime(att.date || att.attempted_at) || '—'}
                            </td>
                          </tr>
                        );
                      })
                    ) : (
                      <tr>
                        <td colSpan={5} className="py-8 text-center text-slate-400 font-medium">
                          No assessment attempts recorded yet for this employee.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>


          </>
        )}
      </div>

      <AdminCourseAccessModal
        isOpen={isAccessModalOpen}
        onClose={() => setIsAccessModalOpen(false)}
        employeeUid={targetUid}
        employeeName={data?.employee?.name}
        onUpdated={() => loadReport()}
      />
    </Layout>
  );
};

export default AdminEmployeeReport;
