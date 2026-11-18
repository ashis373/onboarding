import { useCallback, useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Layout } from "@/components/Layout";
import { 
  BarChart3, 
  Users, 
  CheckCircle2, 
  Clock, 
  Award, 
  TrendingUp, 
  BookOpen, 
  FileText,
  AlertCircle
} from "lucide-react";
import { 
  ResponsiveContainer, 
  BarChart, 
  Bar, 
  XAxis, 
  YAxis, 
  Tooltip as RechartsTooltip, 
  Cell 
} from "recharts";
import { apiGet } from "@/lib/api";
import { useToast } from "@/components/ui/use-toast";

interface ReportsData {
  overall: {
    completion_rate: number;
    total_employees: number;
    completed_employees: number;
    average_training_time: string;
    avg_seconds: number;
  };
  assessments: {
    total_attempts: number;
    passed_attempts: number;
    failed_attempts: number;
    pass_rate: number;
    average_score: number;
  };
  courses: Array<{
    id: number;
    title: string;
    type: string;
    enrolled: number;
    completed: number;
    in_progress: number;
    completion_rate: number;
    average_score?: number | null;
    average_time: string;
  }>;
}

const AdminAnalytics = () => {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  // Tier 1: React Query cached reports overview (60 second fresh in-memory cache)
  const {
    data: reportsData,
    isLoading: loading,
    refetch: refetchReports,
  } = useQuery<ReportsData>({
    queryKey: ['admin_reports_overview'],
    queryFn: async () => {
      try {
        const res = await apiGet<{ success: boolean } & ReportsData>("/admin/reports_overview.php");
        if (res && res.success) {
          return res;
        }
        throw new Error("Failed to load reports");
      } catch (err) {
        toast({ title: "Failed to connect to reports service", variant: "destructive" });
        throw err;
      }
    },
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    placeholderData: (previousData) => previousData,
  });

  const isInitialLoading = Boolean(loading && !reportsData);
  const data = reportsData ?? null;

  const loadReports = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['admin_reports_overview'] });
    void refetchReports();
  }, [queryClient, refetchReports]);


  const courseChartData = useMemo(() => {
    if (!data?.courses) return [];
    return data.courses.map((c) => ({
      name: c.title.length > 20 ? c.title.slice(0, 20) + "..." : c.title,
      rate: c.completion_rate,
      completed: c.completed,
      enrolled: c.enrolled,
    }));
  }, [data]);

  return (
    <Layout isAdmin>
      <div className="space-y-6 pb-12 animate-in fade-in duration-300">
        {/* 1. Header Banner */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="h-11 w-11 rounded-2xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0 shadow-2xs">
              <BarChart3 className="h-5 w-5" />
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                  Reports &amp; Organization Analytics
                </h1>
              </div>
              <p className="text-xs text-slate-400 font-medium mt-0.5">
                Aggregated onboarding performance, course completion rates, and assessment pass/fail benchmarks
              </p>
            </div>
          </div>
        </div>

        {isInitialLoading ? (
          <div className="space-y-6 animate-pulse">
            {/* Metric Skeletons */}
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
                  <div className="h-4 w-12 bg-slate-200 rounded-full" />
                </div>
              ))}
            </div>

            {/* Charts Skeleton */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
              <div className="lg:col-span-7 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs h-72 flex flex-col justify-between">
                <div className="space-y-1.5">
                  <div className="h-4 w-40 bg-slate-200 rounded" />
                  <div className="h-3 w-56 bg-slate-200 rounded" />
                </div>
                <div className="h-52 w-full bg-slate-100 rounded-xl mt-3" />
              </div>
              <div className="lg:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs h-72 flex flex-col justify-between">
                <div className="space-y-1.5">
                  <div className="h-4 w-40 bg-slate-200 rounded" />
                  <div className="h-3 w-48 bg-slate-200 rounded" />
                </div>
                <div className="h-52 w-full bg-slate-100 rounded-xl mt-3" />
              </div>
            </div>

            {/* Table Skeleton */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
              <div className="h-4 w-48 bg-slate-200 rounded" />
              <div className="space-y-3">
                {[1, 2, 3, 4].map((i) => (
                  <div key={i} className="h-12 bg-slate-100 rounded-xl" />
                ))}
              </div>
            </div>
          </div>
        ) : (
          <>
            {/* 2. Top Summary Metrics Cards */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
          {/* Overall Completion Rate */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0">
                <TrendingUp className="h-5 w-5" />
              </div>
              <div>
                <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Overall Completion</div>
                <div className="text-xl sm:text-2xl font-black text-slate-800 leading-tight mt-0.5">
                  {data?.overall.completion_rate || 0}%
                </div>
              </div>
            </div>
            <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
              Avg Progress
            </span>
          </div>

          {/* Assessment Pass Rate */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                <Award className="h-5 w-5" />
              </div>
              <div>
                <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Assessment Pass Rate</div>
                <div className="text-xl sm:text-2xl font-black text-purple-700 leading-tight mt-0.5">
                  {data?.assessments.pass_rate || 0}%
                </div>
              </div>
            </div>
            <span className="text-[10px] font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded-full border border-purple-100">
              {data?.assessments.total_attempts || 0} Attempts
            </span>
          </div>

          {/* Average Training Time */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
                <Clock className="h-5 w-5" />
              </div>
              <div>
                <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Avg Training Time</div>
                <div className="text-xl sm:text-2xl font-black text-sky-700 leading-tight mt-0.5">
                  {data?.overall.average_training_time || "0m"}
                </div>
              </div>
            </div>
            <span className="text-[10px] font-bold text-sky-700 bg-sky-50 px-2 py-0.5 rounded-full border border-sky-100">
              Active time
            </span>
          </div>

          {/* Total Employees Completed */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <CheckCircle2 className="h-5 w-5" />
              </div>
              <div>
                <div className="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Onboarding Completed</div>
                <div className="text-xl sm:text-2xl font-black text-[#00A389] leading-tight mt-0.5">
                  {data?.overall.completed_employees || 0}
                </div>
              </div>
            </div>
            <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
              {data?.overall.completed_employees || 0} of {data?.overall.total_employees || 0}
            </span>
          </div>
        </div>

        {/* 3. Charts & Benchmarks Row */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
          {/* Course Completion Rate Bar Chart (col-span 7) */}
          <div className="lg:col-span-7 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col justify-between">
            <div className="pb-3 border-b border-slate-100">
              <h2 className="text-sm font-black text-slate-800 tracking-tight">
                Course Completion Rates
              </h2>
              <p className="text-[11px] text-slate-400 font-medium">
                Percentage of enrolled employees who completed each individual onboarding course
              </p>
            </div>

            <div className="h-60 w-full pt-4">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={courseChartData} margin={{ top: 10, right: 10, left: -15, bottom: 25 }}>
                  <XAxis 
                    dataKey="name" 
                    stroke="#94a3b8" 
                    fontSize={10} 
                    tickLine={false} 
                    interval={0}
                    angle={-10}
                    textAnchor="end"
                  />
                  <YAxis 
                    stroke="#94a3b8" 
                    fontSize={11} 
                    tickLine={false} 
                    axisLine={false} 
                    domain={[0, 100]} 
                    tickFormatter={(v) => `${v}%`}
                  />
                  <RechartsTooltip 
                    formatter={(val: any) => [`${val}%`, 'Completion Rate']}
                    contentStyle={{ 
                      backgroundColor: '#ffffff', 
                      borderRadius: '12px', 
                      border: '1px solid #e2e8f0', 
                      boxShadow: '0 4px 6px -1px rgba(0,0,0,0.05)', 
                      fontSize: '12px', 
                      fontWeight: 'bold' 
                    }} 
                  />
                  <Bar dataKey="rate" radius={[6, 6, 0, 0]} maxBarSize={45}>
                    {courseChartData.map((_entry, index) => (
                      <Cell key={`cell-${index}`} fill={index % 2 === 0 ? "#00A389" : "#0284c7"} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* Assessment Performance Breakdown (col-span 5) */}
          <div className="lg:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs flex flex-col justify-between">
            <div className="pb-3 border-b border-slate-100">
              <h2 className="text-sm font-black text-slate-800 tracking-tight">
                Assessment Pass vs. Fail Breakdown
              </h2>
              <p className="text-[11px] text-slate-400 font-medium">
                Historical attempt audit and minimum 80% passing threshold compliance
              </p>
            </div>

            <div className="space-y-4 py-3">
              <div className="grid grid-cols-2 gap-3 text-center">
                <div className="p-3.5 rounded-xl bg-emerald-50/60 border border-emerald-100">
                  <div className="text-[10px] text-emerald-700 font-bold uppercase">Passed Attempts</div>
                  <div className="text-2xl font-black text-[#00A389] mt-0.5">
                    {data?.assessments.passed_attempts || 0}
                  </div>
                  <span className="text-[10px] text-emerald-600 font-semibold mt-1 block">
                    Score ≥ 80%
                  </span>
                </div>

                <div className="p-3.5 rounded-xl bg-rose-50/60 border border-rose-100">
                  <div className="text-[10px] text-rose-700 font-bold uppercase">Failed Attempts</div>
                  <div className="text-2xl font-black text-rose-700 mt-0.5">
                    {data?.assessments.failed_attempts || 0}
                  </div>
                  <span className="text-[10px] text-rose-600 font-semibold mt-1 block">
                    Score &lt; 80%
                  </span>
                </div>
              </div>

              <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                <div>
                  <div className="text-xs font-bold text-slate-800">Average Assessment Score</div>
                  <p className="text-[10px] text-slate-400">Mean score across all submitted attempts</p>
                </div>
                <div className="text-lg font-black text-purple-700">
                  {data?.assessments.average_score || 0}%
                </div>
              </div>
            </div>

            <div className="pt-3 border-t border-slate-100 text-[10px] text-slate-400 font-medium flex justify-between">
              <span>Passing requirement: 80% minimum</span>
              <span>Total attempts: {data?.assessments.total_attempts || 0}</span>
            </div>
          </div>
        </div>



        {/* 5. Course Performance Breakdown Table */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
          <div className="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <h2 className="text-sm font-black text-slate-800 tracking-tight">
                Course Performance Breakdown
              </h2>
              <p className="text-[11px] text-slate-400 font-medium">
                Detailed metrics for each onboarding course, average duration, and completion rates
              </p>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-100 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  <th className="py-2 px-3">Course / Activity</th>
                  <th className="py-2 px-3">Type</th>
                  <th className="py-2 px-3">Enrolled</th>
                  <th className="py-2 px-3">Completed</th>
                  <th className="py-2 px-3">Completion Rate</th>
                  <th className="py-2 px-3">Average Score</th>
                  <th className="py-2 px-3">Average Time</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(data?.courses || []).map((course) => (
                  <tr key={course.id} className="hover:bg-slate-50/60 transition-colors">
                    <td className="py-3 px-3 font-bold text-slate-800">
                      {course.title}
                    </td>
                    <td className="py-3 px-3">
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-700">
                        {course.type}
                      </span>
                    </td>
                    <td className="py-3 px-3 text-slate-600 font-semibold">
                      {course.enrolled}
                    </td>
                    <td className="py-3 px-3 font-bold text-[#00A389]">
                      {course.completed}
                    </td>
                    <td className="py-3 px-3 min-w-[120px]">
                      <div className="flex items-center gap-2">
                        <div className="h-1.5 flex-1 bg-slate-100 rounded-full overflow-hidden">
                          <div 
                            className="h-full bg-[#00A389] rounded-full transition-all duration-500" 
                            style={{ width: `${Math.min(100, Math.max(0, course.completion_rate))}%` }} 
                          />
                        </div>
                        <span className="font-bold text-slate-700 w-8 text-right">
                          {course.completion_rate}%
                        </span>
                      </div>
                    </td>
                    <td className="py-3 px-3 font-semibold text-slate-700">
                      {course.average_score !== null ? `${course.average_score}%` : "—"}
                    </td>
                    <td className="py-3 px-3 font-medium text-slate-500">
                      {course.average_time}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
        </>
      )}
      </div>
    </Layout>
  );
};

export default AdminAnalytics;
