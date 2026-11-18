import { useCallback, useEffect, useState } from "react";
import { Layout } from "@/components/Layout";
import { apiGet } from "@/lib/api";
import {
  Activity,
  RefreshCw,
  Zap,
  Database,
  CheckCircle2,
  AlertCircle,
  Users,
  Layers,
} from "lucide-react";

interface ApiUsageSummary {
  requests_24h: number;
  live_calls_24h: number;
  cached_calls_24h: number;
  user_cache_hits_24h: number;
  failed_calls_24h: number;
  cache_hit_rate: number;
  avg_live_per_sync: number;
}

interface ScenarioRow {
  endpoint: string;
  scenario: string;
  requests: number;
  total_live: number;
  total_cached: number;
  total_failed: number;
  avg_live: number;
  avg_ms: number;
}

interface UserUsageRow {
  user_id: number | null;
  employee_name: string;
  requests: number;
  total_live: number;
  total_cached: number;
  cache_hits: number;
}

interface ApiUsageLog {
  id: number;
  user_id: number | null;
  employee_name: string;
  endpoint: string;
  scenario: string;
  live_calls: number;
  cached_calls: number;
  failed_calls: number;
  duration_ms: number;
  ws_functions: string;
  created_at: string;
}

const AdminApiUsage = () => {
  const [loading, setLoading] = useState(true);
  const [days, setDays] = useState<number>(1);
  const [endpointFilter, setEndpointFilter] = useState<string>("all");
  const [summary, setSummary] = useState<ApiUsageSummary | null>(null);
  const [byScenario, setByScenario] = useState<ScenarioRow[]>([]);
  const [byUser, setByUser] = useState<UserUsageRow[]>([]);
  const [logs, setLogs] = useState<ApiUsageLog[]>([]);

  const loadUsage = useCallback(async (d = days, ep = endpointFilter) => {
    setLoading(true);
    try {
      const res = await apiGet<{
        success: boolean;
        summary: ApiUsageSummary;
        by_scenario: ScenarioRow[];
        by_user: UserUsageRow[];
        logs: ApiUsageLog[];
      }>(`/admin/moodle_api_usage.php?days=${d}&endpoint=${encodeURIComponent(ep)}&limit=60`);
      if (res && res.success) {
        setSummary(res.summary);
        setByScenario(res.by_scenario || []);
        setByUser(res.by_user || []);
        setLogs(res.logs || []);
      }
    } catch {
      // Ignore transient load error
    } finally {
      setLoading(false);
    }
  }, [days, endpointFilter]);

  useEffect(() => {
    void loadUsage(days, endpointFilter);
  }, [loadUsage, days, endpointFilter]);

  return (
    <Layout isAdmin>
      <div className="space-y-6 pb-12 animate-in fade-in duration-300">
        {/* Header */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="h-11 w-11 rounded-2xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0">
              <Activity className="h-5 w-5" />
            </div>
            <div>
              <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                Moodle REST API Usage
              </h1>
              <p className="text-xs text-slate-500 font-medium mt-0.5">
                Live vs cached Moodle WebService call counts per user, page, and scenario (IST)
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <select
              value={days}
              onChange={(e) => setDays(Number(e.target.value))}
              className="text-xs font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 outline-none focus:border-[#00A389]"
            >
              <option value={1}>Last 24 Hours</option>
              <option value={7}>Last 7 Days</option>
              <option value={14}>Last 14 Days</option>
            </select>
            <select
              value={endpointFilter}
              onChange={(e) => setEndpointFilter(e.target.value)}
              className="text-xs font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 outline-none focus:border-[#00A389]"
            >
              <option value="all">All Endpoints</option>
              <option value="courses.php">courses.php</option>
              <option value="launch.php">launch.php</option>
              <option value="employee_progress.php">employee_progress.php</option>
              <option value="course_access.php">course_access.php</option>
            </select>
            <button
              onClick={() => void loadUsage(days, endpointFilter)}
              disabled={loading}
              className="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 shadow-2xs cursor-pointer disabled:opacity-50"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />
              Refresh
            </button>
          </div>
        </div>

        {/* Summary KPIs (Last 24h) */}
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
          <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
            <div className="flex items-center gap-2 text-[11px] font-bold uppercase text-slate-400">
              <Zap className="h-3.5 w-3.5 text-[#00A389]" />
              Live Calls (24h)
            </div>
            <div className="text-2xl font-black text-slate-800 mt-1">{summary?.live_calls_24h ?? 0}</div>
          </div>
          <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
            <div className="flex items-center gap-2 text-[11px] font-bold uppercase text-slate-400">
              <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
              0-Call Cache Hit %
            </div>
            <div className="text-2xl font-black text-emerald-700 mt-1">{summary?.cache_hit_rate ?? 0}%</div>
            <div className="text-[10px] text-slate-400 mt-0.5">{summary?.user_cache_hits_24h ?? 0} / {summary?.requests_24h ?? 0} requests</div>
          </div>
          <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
            <div className="flex items-center gap-2 text-[11px] font-bold uppercase text-slate-400">
              <Database className="h-3.5 w-3.5 text-blue-600" />
              Metadata Hits (24h)
            </div>
            <div className="text-2xl font-black text-slate-800 mt-1">{summary?.cached_calls_24h ?? 0}</div>
          </div>
          <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
            <div className="flex items-center gap-2 text-[11px] font-bold uppercase text-slate-400">
              <Layers className="h-3.5 w-3.5 text-indigo-600" />
              Avg Live / Sync
            </div>
            <div className="text-2xl font-black text-slate-800 mt-1">{summary?.avg_live_per_sync ?? 0}</div>
          </div>
          <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
            <div className="flex items-center gap-2 text-[11px] font-bold uppercase text-slate-400">
              <AlertCircle className="h-3.5 w-3.5 text-rose-500" />
              Failed Calls (24h)
            </div>
            <div className="text-2xl font-black text-rose-600 mt-1">{summary?.failed_calls_24h ?? 0}</div>
          </div>
        </div>

        {/* Breakdown by Scenario & Top Users */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-3">
            <h2 className="text-sm font-black text-slate-800 flex items-center gap-2">
              <Layers className="h-4 w-4 text-[#00A389]" />
              Calls by Endpoint &amp; Scenario
            </h2>
            <div className="overflow-x-auto border border-slate-200/80 rounded-xl">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-[10px] font-bold uppercase text-slate-500">
                    <th className="py-2 px-3">Endpoint</th>
                    <th className="py-2 px-3">Scenario</th>
                    <th className="py-2 px-3 text-right">Reqs</th>
                    <th className="py-2 px-3 text-right">Live Calls</th>
                    <th className="py-2 px-3 text-right">Avg / Req</th>
                    <th className="py-2 px-3 text-right">Avg ms</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {byScenario.length > 0 ? byScenario.map((s, idx) => (
                    <tr key={idx} className="hover:bg-slate-50/70">
                      <td className="py-2 px-3 font-mono text-[11px] text-slate-700">{s.endpoint}</td>
                      <td className="py-2 px-3 font-semibold text-slate-800">{s.scenario}</td>
                      <td className="py-2 px-3 text-right">{s.requests}</td>
                      <td className="py-2 px-3 text-right font-bold text-[#00A389]">{s.total_live}</td>
                      <td className="py-2 px-3 text-right">{s.avg_live}</td>
                      <td className="py-2 px-3 text-right text-slate-500">{s.avg_ms}</td>
                    </tr>
                  )) : (
                    <tr><td colSpan={6} className="py-5 text-center text-slate-400">No data yet</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-3">
            <h2 className="text-sm font-black text-slate-800 flex items-center gap-2">
              <Users className="h-4 w-4 text-indigo-600" />
              Top Users by Live Moodle REST Calls
            </h2>
            <div className="overflow-x-auto border border-slate-200/80 rounded-xl">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-[10px] font-bold uppercase text-slate-500">
                    <th className="py-2 px-3">Employee</th>
                    <th className="py-2 px-3 text-right">Requests</th>
                    <th className="py-2 px-3 text-right">Cache Hits</th>
                    <th className="py-2 px-3 text-right">Live Calls</th>
                    <th className="py-2 px-3 text-right">Meta Cached</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {byUser.length > 0 ? byUser.map((u, idx) => (
                    <tr key={idx} className="hover:bg-slate-50/70">
                      <td className="py-2 px-3 font-semibold text-slate-800">{u.employee_name}</td>
                      <td className="py-2 px-3 text-right">{u.requests}</td>
                      <td className="py-2 px-3 text-right text-emerald-700 font-semibold">{u.cache_hits}</td>
                      <td className="py-2 px-3 text-right font-bold text-[#00A389]">{u.total_live}</td>
                      <td className="py-2 px-3 text-right text-slate-500">{u.total_cached}</td>
                    </tr>
                  )) : (
                    <tr><td colSpan={5} className="py-5 text-center text-slate-400">No data yet</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Detailed Request Log */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-3">
          <h2 className="text-sm font-black text-slate-800">Recent Requests (IST)</h2>
          <div className="overflow-x-auto border border-slate-200/80 rounded-xl">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-[10px] font-bold uppercase text-slate-500">
                  <th className="py-2.5 px-3">Time</th>
                  <th className="py-2.5 px-3">Employee</th>
                  <th className="py-2.5 px-3">Endpoint</th>
                  <th className="py-2.5 px-3">Scenario</th>
                  <th className="py-2.5 px-3 text-right">Live</th>
                  <th className="py-2.5 px-3 text-right">Cached</th>
                  <th className="py-2.5 px-3 text-right">Failed</th>
                  <th className="py-2.5 px-3 text-right">ms</th>
                  <th className="py-2.5 px-3">Moodle Functions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {logs.length > 0 ? logs.map((l) => (
                  <tr key={l.id} className="hover:bg-slate-50/70">
                    <td className="py-2 px-3 whitespace-nowrap font-mono text-[11px] text-slate-500">{l.created_at}</td>
                    <td className="py-2 px-3 font-semibold text-slate-800 whitespace-nowrap">{l.employee_name}</td>
                    <td className="py-2 px-3 font-mono text-[11px] text-slate-600">{l.endpoint}</td>
                    <td className="py-2 px-3 whitespace-nowrap">
                      <span className={`inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold border ${
                        l.scenario === "user_cache_hit"
                          ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                          : l.scenario === "sync_single_course"
                            ? "bg-blue-50 text-blue-700 border-blue-200"
                            : "bg-slate-100 text-slate-700 border-slate-200"
                      }`}>
                        {l.scenario}
                      </span>
                    </td>
                    <td className="py-2 px-3 text-right font-bold text-[#00A389]">{l.live_calls}</td>
                    <td className="py-2 px-3 text-right text-slate-500">{l.cached_calls}</td>
                    <td className={`py-2 px-3 text-right font-semibold ${l.failed_calls > 0 ? "text-rose-600" : "text-slate-400"}`}>{l.failed_calls}</td>
                    <td className="py-2 px-3 text-right text-slate-500">{l.duration_ms}</td>
                    <td className="py-2 px-3 text-slate-600 max-w-[300px] truncate font-mono text-[10px]" title={l.ws_functions}>{l.ws_functions}</td>
                  </tr>
                )) : (
                  <tr><td colSpan={9} className="py-6 text-center text-slate-400">{loading ? "Loading API usage logs..." : "No API requests logged in this period yet."}</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Layout>
  );
};

export default AdminApiUsage;

