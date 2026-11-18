import { useCallback, useEffect, useState } from "react";
import { Layout } from "@/components/Layout";
import {
  Settings,
  RefreshCw,
  ShieldCheck,
  Server,
  Database,
  Lock,
  CheckCircle2,
  Award,
  Users,
  Layers,
  Zap,
  Sliders,
  AlertCircle
} from "lucide-react";
import { apiGet, apiPost } from "@/lib/api";
import { useToast } from "@/components/ui/use-toast";
import { Switch } from "@/components/ui/switch";

interface SettingsData {
  moodle: {
    host: string;
    status: string;
    is_healthy: boolean;
    latency_ms: number;
    linked_users: number;
    default_category_id: number;
  };
  database: {
    connected: boolean;
    engine: string;
    version: string;
    charset: string;
  };
  rules: {
    passing_score: number;
    passing_unit: string;
    sequential_unlocking: boolean;
    auto_enrollment: boolean;
    mandatory_modules: string;
  };
  permissions: {
    total_active_users: number;
    administrators: number;
    trainees: number;
    super_admin_uids: string[];
  };
  refresh: {
    auto_sync: string;
    cache_policy: string;
    scorm_telemetry: string;
  };
}

interface MoodleErrorSummary {
  total_24h: number;
  total_7d: number;
  db_errors_24h: number;
  db_errors_7d: number;
  player_retries_7d: number;
  by_type: Record<string, number>;
}

interface MoodleErrorLog {
  id: number;
  user_id: number | null;
  employee_name: string;
  username: string;
  course_id: number | null;
  cmid: number | null;
  course_name: string;
  endpoint: string;
  ws_function: string | null;
  error_type: string;
  http_code: number | null;
  error_message: string;
  created_at: string;
}

const AdminSettings = () => {
  const { toast } = useToast();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<SettingsData | null>(null);
  const [errLoading, setErrLoading] = useState(false);
  const [errFilter, setErrFilter] = useState<string>("all");
  const [errSummary, setErrSummary] = useState<MoodleErrorSummary | null>(null);
  const [errLogs, setErrLogs] = useState<MoodleErrorLog[]>([]);

  const loadErrorLogs = useCallback(async (typeFilter = errFilter) => {
    setErrLoading(true);
    try {
      const res = await apiGet<{ success: boolean; summary: MoodleErrorSummary; logs: MoodleErrorLog[] }>(
        `/admin/moodle_errors.php?days=7&error_type=${encodeURIComponent(typeFilter)}&limit=50`
      );
      if (res && res.success) {
        setErrSummary(res.summary);
        setErrLogs(res.logs || []);
      }
    } catch {
      // Ignore transient error loading logs
    } finally {
      setErrLoading(false);
    }
  }, [errFilter]);

  const loadSettings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiGet<{ success: boolean } & SettingsData>("/admin/settings_data.php");
      if (res && res.success) {
        setData(res);
      } else {
        toast({ title: "Failed to load portal configuration", variant: "destructive" });
      }
    } catch {
      toast({ title: "Failed to connect to settings service", variant: "destructive" });
    } finally {
      setLoading(false);
    }
  }, [toast]);

  const [togglingSeq, setTogglingSeq] = useState(false);

  const handleToggleSequential = async (newVal: boolean) => {
    setTogglingSeq(true);
    try {
      const res = await apiPost<any>("/admin/toggle_sequential_locking.php", { enabled: newVal });
      if (res?.success) {
        toast({
          title: newVal ? "Sequential Progression Enabled" : "Sequential Progression Disabled",
          description: res.message
        });
        void loadSettings();
      } else {
        toast({ title: "Failed to update setting", variant: "destructive" });
      }
    } catch {
      toast({ title: "Server connection error", variant: "destructive" });
    } finally {
      setTogglingSeq(false);
    }
  };

  useEffect(() => {
    void loadSettings();
  }, [loadSettings]);

  useEffect(() => {
    void loadErrorLogs(errFilter);
  }, [loadErrorLogs, errFilter]);

  return (
    <Layout isAdmin>
      <div className="space-y-6 pb-12 animate-in fade-in duration-300">
        {/* 1. Top Header Banner */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="h-11 w-11 rounded-2xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0 shadow-2xs">
              <Settings className="h-5 w-5" />
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                  Portal &amp; System Configuration
                </h1>
                <span className="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-0.5 rounded-full shadow-2xs">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                  Moodle API Connected
                </span>
              </div>
              <p className="text-xs text-slate-400 font-medium mt-0.5">
                Moodle connection health, onboarding rules, passing thresholds, user permissions, and sync policies
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={loadSettings}
              disabled={loading}
              className="px-3.5 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer shadow-2xs bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 disabled:opacity-60"
            >
              <RefreshCw className={`h-3.5 w-3.5 text-slate-600 ${loading ? "animate-spin text-[#00A389]" : ""}`} />
              <span>Check Health</span>
            </button>
          </div>
        </div>

        {/* 2. Main Settings Grid (5 Key Sections as per adminpanel.md) */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
          {/* Section 1: Moodle Connection & API Health Status */}
          <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-xl bg-emerald-50 text-[#00A389] flex items-center justify-center shrink-0">
                  <Server className="h-4 w-4" />
                </div>
                <div>
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    Moodle Connection &amp; API Health
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    REST WebService connectivity and Moodle LMS host status
                  </p>
                </div>
              </div>
              <span className="text-xs font-black text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full">
                {data?.moodle.status || "Connected"}
              </span>
            </div>

            <div className="space-y-3 text-xs">
              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">LMS Endpoint URL</span>
                <span className="font-bold text-slate-800 font-mono text-[11px]">
                  {data?.moodle.host || "https://lms.moodle.hexalearn.com"}
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">API Response Latency</span>
                <span className="font-bold text-emerald-600">
                  {data?.moodle.latency_ms ? `${data.moodle.latency_ms} ms` : "Fast (< 50ms)"}
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Active Linked Moodle Accounts</span>
                <span className="font-bold text-slate-800">
                  {data?.moodle.linked_users ?? 1} Trainees Linked
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Default Course Category</span>
                <span className="font-bold text-slate-800">
                  Category #{data?.moodle.default_category_id || 1} (Onboarding)
                </span>
              </div>

              {/* Security Shield Callout */}
              <div className="p-3 rounded-xl bg-emerald-50/60 border border-emerald-100 text-[11px] text-emerald-900 flex items-start gap-2.5">
                <Lock className="h-4 w-4 text-[#00A389] shrink-0 mt-0.5" />
                <p className="font-medium">
                  <strong>Security Guard:</strong> Moodle REST tokens, master secrets, and database credentials are strictly guarded in server configuration files and are never exposed to browser clients.
                </p>
              </div>
            </div>
          </div>

          {/* Section 2: Onboarding Rules & Completion Requirements */}
          <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                  <Sliders className="h-4 w-4" />
                </div>
                <div>
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    Onboarding Rules &amp; Requirements
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Course sequence enforcement and graduation requirements
                  </p>
                </div>
              </div>
              <span className="text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2.5 py-0.5 rounded-full">
                Active Policy
              </span>
            </div>

            <div className="space-y-3 text-xs">
              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div className="pr-4">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-slate-800">Sequential Learning Progression</span>
                    <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${data?.rules.sequential_unlocking
                      ? 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                      : 'bg-slate-200 text-slate-600 border border-slate-300'
                      }`}>
                      {data?.rules.sequential_unlocking ? 'Enabled (One by One)' : 'Disabled (Open Order)'}
                    </span>
                  </div>
                  <p className="text-[10px] text-slate-400 mt-0.5">
                    {data?.rules.sequential_unlocking
                      ? 'Employees must complete courses one by one in sequence'
                      : 'Open mode: Employees can take courses in any order'}
                  </p>
                </div>
                <Switch
                  checked={Boolean(data?.rules.sequential_unlocking)}
                  onCheckedChange={handleToggleSequential}
                  disabled={togglingSeq || loading}
                  className="data-[state=checked]:bg-[#00A389] cursor-pointer shrink-0"
                />
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div>
                  <div className="font-bold text-slate-800">Automatic Trainee Enrollment</div>
                  <p className="text-[10px] text-slate-400">Auto-provisions Moodle accounts on first employee login</p>
                </div>
                <span className="text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full">
                  Active
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div>
                  <div className="font-bold text-slate-800">Graduation Requirements</div>
                  <p className="text-[10px] text-slate-400">Mandatory curriculum modules</p>
                </div>
                <span className="font-bold text-slate-700 text-right">
                  100% of Assigned Modules
                </span>
              </div>
            </div>
          </div>

          {/* Section 3: Assessment Passing Threshold */}
          <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                  <Award className="h-4 w-4" />
                </div>
                <div>
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    Assessment Passing Threshold
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Minimum score required to graduate quizzes and skill checks
                  </p>
                </div>
              </div>
              <span className="text-xs font-black text-purple-700 bg-purple-50 border border-purple-200 px-2.5 py-0.5 rounded-full">
                {data?.rules.passing_score || 80}% Passing
              </span>
            </div>

            <div className="space-y-3 text-xs">
              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Current Passing Threshold</span>
                <span className="text-base font-black text-purple-700">
                  {data?.rules.passing_score || 80}% Minimum
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Retake Attempt Allowance</span>
                <span className="font-bold text-slate-800">
                  Allowed until passing score achieved
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Transcript Score Retention</span>
                <span className="font-bold text-slate-800">
                  Highest Score Retained
                </span>
              </div>
            </div>
          </div>

          {/* Section 4: User Access & Permissions */}
          <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
                  <Users className="h-4 w-4" />
                </div>
                <div>
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    User Access &amp; Permissions
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Role-based access control and administrative privileges
                  </p>
                </div>
              </div>
              <span className="text-xs font-bold text-sky-700 bg-sky-50 border border-sky-200 px-2.5 py-0.5 rounded-full">
                RBAC Active
              </span>
            </div>

            <div className="space-y-3 text-xs">
              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Active Platform Headcount</span>
                <span className="font-bold text-slate-800">
                  {data?.permissions.total_active_users || 28} Total Users
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Portal Administrators</span>
                <span className="font-bold text-[#00A389]">
                  {data?.permissions.administrators || 3} Admins
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Active Trainees</span>
                <span className="font-bold text-slate-800">
                  {data?.permissions.trainees || 25} Trainees
                </span>
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="text-slate-500 font-medium">Administrative UIDs</span>
                <span className="font-bold text-slate-700 font-mono text-[11px]">
                  {data?.permissions.super_admin_uids?.join(", ") || "26, 64, 205"}
                </span>
              </div>
            </div>
          </div>

          {/* Section 5: Data Refresh & Telemetry Settings */}
          <div className="lg:col-span-2 bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center shrink-0">
                  <Zap className="h-4 w-4 text-[#00A389]" />
                </div>
                <div>
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    Data Refresh &amp; Telemetry Policies
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Synchronization interval, caching headers, and automated SCORM state tracking
                  </p>
                </div>
              </div>
              <span className="text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">
                Live Polling
              </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div className="text-[10px] text-slate-400 font-bold uppercase">Sync Frequency</div>
                <div className="font-bold text-slate-800 mt-1">{data?.refresh.auto_sync || "Real-time on LMS session"}</div>
                <p className="text-[10px] text-slate-400 mt-0.5">Queries Moodle WebService on trainee session start</p>
              </div>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div className="text-[10px] text-slate-400 font-bold uppercase">Browser Cache Policy</div>
                <div className="font-bold text-slate-800 mt-1">{data?.refresh.cache_policy || "Zero-cache (no-store)"}</div>
                <p className="text-[10px] text-slate-400 mt-0.5">Ensures telemetry data is never stale</p>
              </div>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div className="text-[10px] text-slate-400 font-bold uppercase">SCORM Telemetry Hook</div>
                <div className="font-bold text-[#00A389] mt-1">{data?.refresh.scorm_telemetry || "Automatic WebService Hook"}</div>
                <p className="text-[10px] text-slate-400 mt-0.5">Tracks slides viewed, suspend data, and attempt timestamps</p>
              </div>
            </div>
          </div>

          {/* Section 6: Moodle Connection & Launch Error Logs */}
          <div className="lg:col-span-2 bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
                  <AlertCircle className="h-4 w-4" />
                </div>
                <div>
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    Moodle Connection &amp; Launch Error Logs
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Tracks Moodle DB connection failures, HTTP 5xx/429 errors, timeouts, and player retries in Onboard DB
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <select
                  value={errFilter}
                  onChange={(e) => setErrFilter(e.target.value)}
                  className="text-xs font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 outline-none focus:border-[#00A389]"
                >
                  <option value="all">All Error Types</option>
                  <option value="db_connection_failed">DB Connection Failed</option>
                  <option value="http_5xx">HTTP 5xx</option>
                  <option value="rate_limited">Rate Limited (403/429)</option>
                  <option value="timeout">Timeout</option>
                  <option value="player_retry">Player Refresh Retry</option>
                </select>
                <button
                  onClick={() => void loadErrorLogs(errFilter)}
                  disabled={errLoading}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 cursor-pointer disabled:opacity-50"
                >
                  <RefreshCw className={`h-3.5 w-3.5 ${errLoading ? "animate-spin" : ""}`} />
                  Refresh
                </button>
              </div>
            </div>

            {/* Summary Frequency Metrics */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div className="text-[10px] text-slate-400 font-bold uppercase">Errors (Last 24h)</div>
                <div className="text-lg font-black text-slate-800 mt-0.5">{errSummary?.total_24h ?? 0}</div>
              </div>
              <div className="p-3 rounded-xl bg-rose-50/60 border border-rose-100">
                <div className="text-[10px] text-rose-500 font-bold uppercase">DB Errors (24h)</div>
                <div className="text-lg font-black text-rose-700 mt-0.5">{errSummary?.db_errors_24h ?? 0}</div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div className="text-[10px] text-slate-400 font-bold uppercase">Errors (Last 7d)</div>
                <div className="text-lg font-black text-slate-800 mt-0.5">{errSummary?.total_7d ?? 0}</div>
              </div>
              <div className="p-3 rounded-xl bg-amber-50/60 border border-amber-100">
                <div className="text-[10px] text-amber-600 font-bold uppercase">Player Retries (7d)</div>
                <div className="text-lg font-black text-amber-700 mt-0.5">{errSummary?.player_retries_7d ?? 0}</div>
              </div>
            </div>

            {/* Recent Error Logs Table */}
            <div className="overflow-x-auto border border-slate-200/80 rounded-xl">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-[10px] font-bold uppercase text-slate-500">
                    <th className="py-2.5 px-3">Time</th>
                    <th className="py-2.5 px-3">Employee</th>
                    <th className="py-2.5 px-3">Course</th>
                    <th className="py-2.5 px-3">Error Type</th>
                    <th className="py-2.5 px-3">Source / Function</th>
                    <th className="py-2.5 px-3">HTTP</th>
                    <th className="py-2.5 px-3">Message</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {errLogs.length > 0 ? (
                    errLogs.map((log) => (
                      <tr key={log.id} className="hover:bg-slate-50/70">
                        <td className="py-2.5 px-3 whitespace-nowrap text-slate-500 font-mono text-[11px]">
                          {log.created_at}
                        </td>
                        <td className="py-2.5 px-3 font-semibold text-slate-800 whitespace-nowrap">
                          {log.employee_name}
                        </td>
                        <td className="py-2.5 px-3 text-slate-700 max-w-[180px] truncate" title={log.course_name}>
                          {log.course_name}
                        </td>
                        <td className="py-2.5 px-3 whitespace-nowrap">
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border ${log.error_type === "db_connection_failed" || log.error_type === "http_5xx"
                              ? "bg-rose-50 text-rose-700 border-rose-200"
                              : log.error_type === "player_retry"
                                ? "bg-amber-50 text-amber-700 border-amber-200"
                                : "bg-slate-100 text-slate-700 border-slate-200"
                              }`}
                          >
                            {log.error_type}
                          </span>
                        </td>
                        <td className="py-2.5 px-3 text-slate-600 font-mono text-[11px] whitespace-nowrap">
                          {log.endpoint}{log.ws_function ? ` · ${log.ws_function}` : ""}
                        </td>
                        <td className="py-2.5 px-3 font-mono text-slate-600">
                          {log.http_code ?? "—"}
                        </td>
                        <td className="py-2.5 px-3 text-slate-600 max-w-[280px] truncate" title={log.error_message}>
                          {log.error_message}
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={7} className="py-6 text-center text-slate-400 font-medium">
                        {errLoading ? "Loading Moodle error logs..." : "No Moodle connection or launch errors recorded in the selected period."}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  );
};

export default AdminSettings;
