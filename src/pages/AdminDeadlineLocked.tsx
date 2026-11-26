import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Layout } from "@/components/Layout";
import { apiGet, apiPost } from "@/lib/api";
import { useToast } from "@/components/ui/use-toast";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Lock,
  Unlock,
  Clock,
  Search,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  Calendar,
  Users,
  Award,
  CalendarPlus,
  Loader2,
  ShieldAlert,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";

interface LockedEmployee {
  id: number;
  user_id: number;
  emp_code: string;
  name: string;
  email: string;
  department: string;
  profile_img?: string;
  is_ex_employee?: boolean;
  batch_id: number;
  batch_label: string;
  base_deadline: string;
  base_deadline_fmt: string;
  extended_deadline: string | null;
  extended_deadline_fmt: string | null;
  effective_deadline: string;
  effective_deadline_fmt: string;
  is_extended: boolean;
  extension_reason: string | null;
  extended_by_admin_name: string;
  is_locked: boolean;
  is_past_deadline: boolean;
  time_diff_text: string;
  has_passed: boolean;
  passed_score: number | null;
  latest_score: number | null;
  total_attempts: number;
  first_opened_at: string | null;
  updated_at: string | null;
}

interface ApiResponse {
  success: boolean;
  metrics: {
    total_records: number;
    total_batch1: number;
    total_passed: number;
    total_locked: number;
    total_pending: number;
    total_extended: number;
    cnt_current?: number;
    cnt_ex?: number;
    emp_type?: string;
  };
  employees: LockedEmployee[];
  pagination: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
    hasPrev: boolean;
    hasNext: boolean;
  };
}

export default function AdminDeadlineLocked() {
  const { toast } = useToast();
  const [searchTerm, setSearchTerm] = useState("");
  const [statusFilter, setStatusFilter] = useState<string>("locked");
  const [empType, setEmpType] = useState<"current" | "ex">("current");
  const [page, setPage] = useState(1);

  // Extension Modal State
  const [extendTarget, setExtendTarget] = useState<LockedEmployee | null>(null);
  const [newDeadlineDate, setNewDeadlineDate] = useState<string>("");
  const [extensionReason, setExtensionReason] = useState<string>("");
  const [submittingExtension, setSubmittingExtension] = useState(false);

  // Query API
  const { data, isLoading, isFetching, refetch } = useQuery<ApiResponse>({
    queryKey: ["admin_deadline_locked", statusFilter, searchTerm, empType, page],
    queryFn: async () => {
      const params = new URLSearchParams({
        status: statusFilter,
        search: searchTerm,
        emp_type: empType,
        page: String(page),
        limit: "15",
      });
      const res = await apiGet<ApiResponse>(`/admin/deadline_locked_employees.php?${params.toString()}`);
      return res;
    },
    staleTime: 5 * 60 * 1000,
    gcTime: 15 * 60 * 1000,
    refetchOnWindowFocus: false,
  });

  const metrics = data?.metrics || {
    total_records: 50,
    total_batch1: 50,
    total_passed: 0,
    total_locked: 0,
    total_pending: 0,
    total_extended: 0,
    cnt_current: 50,
    cnt_ex: 0,
  };

  const employees = data?.employees || [];
  const pagination = data?.pagination || {
    page: 1,
    limit: 15,
    total: 0,
    totalPages: 1,
    hasPrev: false,
    hasNext: false,
  };

  const handleOpenExtendDialog = (emp: LockedEmployee) => {
    setExtendTarget(emp);
    // Default to +7 days from now
    const nextWeek = new Date();
    nextWeek.setDate(nextWeek.getDate() + 7);
    const yyyy = nextWeek.getFullYear();
    const mm = String(nextWeek.getMonth() + 1).padStart(2, "0");
    const dd = String(nextWeek.getDate()).padStart(2, "0");
    setNewDeadlineDate(`${yyyy}-${mm}-${dd}`);
    setExtensionReason(emp.extension_reason || "Manager approval - Approved extension");
  };

  const handleApplyPreset = (daysToAdd: number) => {
    const target = new Date();
    target.setDate(target.getDate() + daysToAdd);
    const yyyy = target.getFullYear();
    const mm = String(target.getMonth() + 1).padStart(2, "0");
    const dd = String(target.getDate()).padStart(2, "0");
    setNewDeadlineDate(`${yyyy}-${mm}-${dd}`);
  };

  const handleSubmitExtension = async () => {
    if (!extendTarget || !newDeadlineDate) return;

    setSubmittingExtension(true);
    try {
      const res = await apiPost<{ success: boolean; message?: string }>("/admin/extend_deadline.php", {
        user_id: extendTarget.user_id,
        new_deadline: newDeadlineDate,
        reason: extensionReason.trim() || "Manager approval",
      });

      if (res && res.success !== false) {
        toast({
          title: "Deadline Extended",
          description: `Access deadline for ${extendTarget.name} has been updated to ${newDeadlineDate} 11:59 PM.`,
        });
        setExtendTarget(null);
        refetch();
      } else {
        toast({
          title: "Extension Failed",
          description: res?.message || "Could not extend deadline.",
          variant: "destructive",
        });
      }
    } catch (err: any) {
      toast({
        title: "Extension Error",
        description: err?.message || "An unexpected error occurred.",
        variant: "destructive",
      });
    } finally {
      setSubmittingExtension(false);
    }
  };

  return (
    <Layout isAdmin>
      <div className="w-full space-y-6 pb-12">
        {/* 1. Header Banner */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b pb-5">
          <div>
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-xl bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400">
                <ShieldAlert className="w-5 h-5" />
              </div>
              <div>
                <h1 className="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                  Deadline Lockouts (Batch 1)
                </h1>
                <p className="text-xs sm:text-sm text-muted-foreground">
                  Real-time tracking of employees from <code className="bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded text-[11px] font-mono">onboard_employee_deadlines</code> and individual deadline extensions.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => refetch()}
              disabled={isFetching}
              className="gap-1.5 text-xs font-semibold shadow-2xs"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${isFetching ? "animate-spin text-rose-600" : ""}`} />
              Refresh
            </Button>
          </div>
        </div>

        {/* 2. Top Summary KPI Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3.5">
          <Card
            onClick={() => {
              setStatusFilter("locked");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-xs ${
              statusFilter === "locked"
                ? "ring-2 ring-rose-500 border-rose-400"
                : metrics.total_locked > 0
                ? "border-rose-300 bg-rose-50/50 dark:bg-rose-950/20"
                : "border-slate-200 hover:border-rose-300"
            }`}
          >
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-[11px] uppercase tracking-wider font-bold text-rose-700 dark:text-rose-400">
                    Currently Locked
                  </p>
                  <p className="text-2xl font-black text-rose-900 dark:text-rose-200 mt-1">
                    {metrics.total_locked}
                  </p>
                </div>
                <div className="p-2.5 rounded-xl bg-rose-100 dark:bg-rose-900/60 text-rose-700 dark:text-rose-300">
                  <Lock className="w-5 h-5" />
                </div>
              </div>
              <p className="text-[11px] text-muted-foreground mt-2">
                {metrics.total_locked === 0 ? "No employees locked yet" : "Deadline expired without passing"}
              </p>
            </CardContent>
          </Card>

          <Card
            onClick={() => {
              setStatusFilter("pending");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-xs ${
              statusFilter === "pending"
                ? "ring-2 ring-amber-500 border-amber-400 bg-amber-50/30 dark:bg-amber-950/20"
                : "border-slate-200 hover:border-amber-300"
            }`}
          >
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-[11px] uppercase tracking-wider font-bold text-amber-700 dark:text-amber-400">
                    Pending Cutoff
                  </p>
                  <p className="text-2xl font-black text-amber-900 dark:text-amber-200 mt-1">
                    {metrics.total_pending}
                  </p>
                </div>
                <div className="p-2.5 rounded-xl bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300">
                  <Clock className="w-5 h-5" />
                </div>
              </div>
              <p className="text-[11px] text-muted-foreground mt-2">
                Cutoff: 24-Nov-2026 11:59 PM
              </p>
            </CardContent>
          </Card>

          <Card
            onClick={() => {
              setStatusFilter("passed");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-xs ${
              statusFilter === "passed"
                ? "ring-2 ring-emerald-500 border-emerald-400"
                : "border-emerald-200 bg-emerald-50/40 dark:bg-emerald-950/20 hover:border-emerald-400"
            }`}
          >
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-[11px] uppercase tracking-wider font-bold text-emerald-700 dark:text-emerald-400">
                    Certified & Exempt
                  </p>
                  <p className="text-2xl font-black text-emerald-900 dark:text-emerald-200 mt-1">
                    {metrics.total_passed}
                  </p>
                </div>
                <div className="p-2.5 rounded-xl bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300">
                  <Award className="w-5 h-5" />
                </div>
              </div>
              <p className="text-[11px] text-muted-foreground mt-2">
                Passed assessment (No extension needed)
              </p>
            </CardContent>
          </Card>

          <Card
            onClick={() => {
              setStatusFilter("extended");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-xs ${
              statusFilter === "extended"
                ? "ring-2 ring-blue-500 border-blue-400"
                : "border-blue-200 bg-blue-50/40 dark:bg-blue-950/20 hover:border-blue-400"
            }`}
          >
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-[11px] uppercase tracking-wider font-bold text-blue-700 dark:text-blue-400">
                    Extensions Granted
                  </p>
                  <p className="text-2xl font-black text-blue-900 dark:text-blue-200 mt-1">
                    {metrics.total_extended}
                  </p>
                </div>
                <div className="p-2.5 rounded-xl bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300">
                  <CalendarPlus className="w-5 h-5" />
                </div>
              </div>
              <p className="text-[11px] text-muted-foreground mt-2">
                Approved Manager / HR extensions
              </p>
            </CardContent>
          </Card>
        </div>

        {/* 3. Filter Tabs, Staff Toggle & Search Controls */}
        <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0">
            {[
              { id: "locked", label: `Currently Locked (${metrics.total_locked})` },
              { id: "pending", label: `Pending Cutoff (${metrics.total_pending})` },
              { id: "extended", label: `Extended (${metrics.total_extended})` },
              { id: "passed", label: `Certified (${metrics.total_passed})` },
              { id: "all", label: `All Batch 1 (${metrics.total_records})` },
            ].map((tab) => (
              <button
                key={tab.id}
                onClick={() => {
                  setStatusFilter(tab.id);
                  setPage(1);
                }}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-all whitespace-nowrap cursor-pointer ${statusFilter === tab.id
                    ? "bg-slate-900 text-white shadow-2xs dark:bg-white dark:text-slate-900"
                    : "bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"
                  }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <div className="flex items-center gap-2.5 flex-wrap sm:flex-nowrap">
            {/* Current / Ex-Staff Toggle */}
            <div className="flex items-center gap-1 bg-slate-100 dark:bg-slate-800 p-1 rounded-xl border border-slate-200/80 dark:border-slate-700/80 shrink-0">
              <button
                type="button"
                onClick={() => {
                  setEmpType("current");
                  setPage(1);
                }}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${empType === "current"
                    ? "bg-white text-emerald-800 shadow-2xs dark:bg-slate-900 dark:text-emerald-400"
                    : "text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                  }`}
              >
                <span className={`h-2 w-2 rounded-full ${empType === "current" ? "bg-emerald-500" : "bg-slate-300"}`} />
                <span>Current</span>
              </button>
              <button
                type="button"
                onClick={() => {
                  setEmpType("ex");
                  setPage(1);
                }}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${empType === "ex"
                    ? "bg-white text-amber-800 shadow-2xs dark:bg-slate-900 dark:text-amber-400"
                    : "text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                  }`}
              >
                <span className={`h-2 w-2 rounded-full ${empType === "ex" ? "bg-amber-500" : "bg-slate-300"}`} />
                <span>Ex-Staff</span>
              </button>
            </div>

            {/* Search Input */}
            <div className="relative w-full sm:w-64">
              <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={searchTerm}
                onChange={(e) => {
                  setSearchTerm(e.target.value);
                  setPage(1);
                }}
                placeholder="Search employee, ID, department..."
                className="pl-8 text-xs h-9 bg-card"
              />
            </div>
          </div>
        </div>

        {/* 4. Table of Employees in onboard_employee_deadlines */}
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-card overflow-hidden shadow-2xs">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Employee</th>
                  <th className="px-3 py-3">Department</th>
                  <th className="px-3 py-3">Batch</th>
                  <th className="px-3 py-3">Scheduled Deadline</th>
                  <th className="px-3 py-3 text-center">Remaining / Overdue</th>
                  <th className="px-3 py-3 text-center">Induction Result</th>
                  <th className="px-3 py-3 text-center">Access Status</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {isLoading ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-muted-foreground">
                      <Loader2 className="w-5 h-5 animate-spin mx-auto mb-2 text-rose-600" />
                      Loading deadline records...
                    </td>
                  </tr>
                ) : employees.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-muted-foreground">
                      No employees matching the selected criteria.
                    </td>
                  </tr>
                ) : (
                  employees.map((emp) => {
                    return (
                      <tr
                        key={emp.id}
                        className="hover:bg-slate-50/70 dark:hover:bg-slate-850/50 transition-colors"
                      >
                        {/* Employee */}
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 border flex items-center justify-center overflow-hidden shrink-0 font-bold text-slate-700 text-xs">
                              {emp.profile_img ? (
                                <img
                                  src={emp.profile_img}
                                  alt={emp.name}
                                  className="w-full h-full object-cover"
                                  onError={(e) => {
                                    (e.target as HTMLElement).style.display = "none";
                                  }}
                                />
                              ) : (
                                emp.name.charAt(0)
                              )}
                            </div>
                            <div className="min-w-0">
                              <div className="font-bold text-slate-900 dark:text-white truncate">
                                {emp.name}
                              </div>
                              <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground flex-wrap">
                                <span className="font-mono font-semibold">{emp.emp_code}</span>
                                {emp.is_ex_employee && (
                                  <span className="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                    Ex-Staff
                                  </span>
                                )}
                                <span>•</span>
                                <span className="truncate max-w-[160px]">{emp.email}</span>
                              </div>
                            </div>
                          </div>
                        </td>

                        {/* Department */}
                        <td className="px-3 py-3 text-slate-600 dark:text-slate-300 font-medium whitespace-nowrap">
                          {emp.department}
                        </td>

                        {/* Batch */}
                        <td className="px-3 py-3 whitespace-nowrap">
                          <span className="font-semibold text-[11px] px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                            {emp.batch_label}
                          </span>
                        </td>

                        {/* Scheduled Deadline */}
                        <td className="px-3 py-3 whitespace-nowrap">
                          <div className="font-semibold text-slate-900 dark:text-white text-[11px]">
                            {emp.effective_deadline_fmt}
                          </div>
                          {emp.is_extended && (
                            <div className="text-[10px] text-blue-600 dark:text-blue-400 flex items-center gap-1 mt-0.5" title={`Reason: ${emp.extension_reason || 'Manager approval'}\nBy: ${emp.extended_by_admin_name || 'Admin'}`}>
                              <CalendarPlus className="w-2.5 h-2.5" />
                              <span>Extended from {emp.base_deadline_fmt}</span>
                            </div>
                          )}
                        </td>

                        {/* Remaining / Overdue */}
                        <td className="px-3 py-3 text-center whitespace-nowrap">
                          {emp.has_passed ? (
                            <span className="text-[11px] text-emerald-700 dark:text-emerald-400 font-medium">
                              Completed
                            </span>
                          ) : emp.is_past_deadline ? (
                            <span className="text-[11px] font-bold text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/60 px-2 py-0.5 rounded-full border border-rose-200">
                              {emp.time_diff_text}
                            </span>
                          ) : (
                            <span className="text-[11px] font-medium text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/60 px-2 py-0.5 rounded-full border border-amber-200">
                              {emp.time_diff_text}
                            </span>
                          )}
                        </td>

                        {/* Induction Result */}
                        <td className="px-3 py-3 text-center whitespace-nowrap">
                          {emp.has_passed ? (
                            <Badge className="bg-emerald-600 hover:bg-emerald-700 text-white gap-1 text-[11px]">
                              <CheckCircle2 className="w-3 h-3" /> {emp.passed_score ? `${emp.passed_score}% Passed` : "Passed"}
                            </Badge>
                          ) : emp.total_attempts > 0 ? (
                            <Badge variant="outline" className="border-rose-300 text-rose-700 text-[11px] font-semibold">
                              {emp.latest_score !== null ? `${emp.latest_score}% (Att #${emp.total_attempts})` : `Failed (Att #${emp.total_attempts})`}
                            </Badge>
                          ) : (
                            <span className="text-muted-foreground text-[11px]">Not Attempted</span>
                          )}
                        </td>

                        {/* Access Status */}
                        <td className="px-3 py-3 text-center whitespace-nowrap">
                          {emp.has_passed ? (
                            <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/60 px-2 py-0.5 rounded-md font-bold text-[11px] border border-emerald-200">
                              <CheckCircle2 className="w-3 h-3" /> Certified
                            </span>
                          ) : emp.is_locked ? (
                            <span className="inline-flex items-center gap-1 text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/60 px-2.5 py-0.5 rounded-full font-bold text-[11px] border border-rose-200">
                              <Lock className="w-3 h-3 text-rose-600" /> Deadline Locked
                            </span>
                          ) : emp.is_extended ? (
                            <span className="inline-flex items-center gap-1 text-blue-700 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/60 px-2.5 py-0.5 rounded-full font-bold text-[11px] border border-blue-200">
                              <CalendarPlus className="w-3 h-3 text-blue-600" /> Extended
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/60 px-2 py-0.5 rounded-md font-medium text-[11px] border border-amber-200">
                              <Clock className="w-3 h-3" /> In Progress
                            </span>
                          )}
                        </td>

                        {/* Actions */}
                        <td className="px-4 py-3 text-right whitespace-nowrap">
                          {emp.has_passed ? (
                            <span className="text-muted-foreground text-[11px]">—</span>
                          ) : (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleOpenExtendDialog(emp)}
                              className="h-7 text-xs font-semibold gap-1 text-blue-700 hover:text-blue-800 border-blue-300 hover:bg-blue-50 dark:border-blue-800 dark:hover:bg-blue-950 shadow-2xs cursor-pointer"
                            >
                              <CalendarPlus className="w-3 h-3 text-blue-600" />
                              <span>Extend Deadline</span>
                            </Button>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination Controls (15 employees per page) */}
          {pagination.totalPages > 1 && (
            <div className="p-3.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-muted-foreground">
              <span>
                Showing {Math.min((pagination.page - 1) * pagination.limit + 1, pagination.total)} to{" "}
                {Math.min(pagination.page * pagination.limit, pagination.total)} of {pagination.total} employees
              </span>
              <div className="flex items-center gap-1.5">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={!pagination.hasPrev}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="h-7 px-2 cursor-pointer"
                  title="Previous Page"
                >
                  <ChevronLeft className="w-3.5 h-3.5" />
                </Button>
                {Array.from({ length: pagination.totalPages }, (_, i) => i + 1).map((p) => (
                  <Button
                    key={p}
                    size="sm"
                    variant={p === pagination.page ? "default" : "outline"}
                    onClick={() => setPage(p)}
                    className="h-7 w-7 p-0 text-xs cursor-pointer font-bold"
                  >
                    {p}
                  </Button>
                ))}
                <Button
                  size="sm"
                  variant="outline"
                  disabled={!pagination.hasNext}
                  onClick={() => setPage((p) => Math.min(pagination.totalPages, p + 1))}
                  className="h-7 px-2 cursor-pointer"
                  title="Next Page"
                >
                  <ChevronRight className="w-3.5 h-3.5" />
                </Button>
              </div>
            </div>
          )}
        </div>

        {/* 5. Extend Deadline Modal */}
        <Dialog open={!!extendTarget} onOpenChange={(open) => !open && setExtendTarget(null)}>
          <DialogContent className="sm:max-w-[480px]">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <CalendarPlus className="w-5 h-5 text-blue-600" />
                <span>Extend Onboarding Deadline</span>
              </DialogTitle>
              <DialogDescription className="text-xs">
                Grant an individual deadline extension to unlock course access for this candidate.
              </DialogDescription>
            </DialogHeader>

            {extendTarget && (
              <div className="space-y-4 py-2">
                <div className="bg-slate-50 dark:bg-slate-900 p-3 rounded-xl border border-slate-200/80 space-y-1.5 text-xs">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Candidate:</span>
                    <span className="font-bold text-slate-900 dark:text-white">
                      {extendTarget.name} ({extendTarget.emp_code})
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Department:</span>
                    <span className="font-medium">{extendTarget.department}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Current Deadline:</span>
                    <span className="font-semibold text-rose-700 dark:text-rose-400">
                      {extendTarget.effective_deadline_fmt}
                    </span>
                  </div>
                </div>

                {/* Quick Presets */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Quick Presets:
                  </label>
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => handleApplyPreset(3)}
                      className="text-xs h-7 px-2.5"
                    >
                      +3 Days
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => handleApplyPreset(5)}
                      className="text-xs h-7 px-2.5"
                    >
                      +5 Days
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => handleApplyPreset(7)}
                      className="text-xs h-7 px-2.5 font-bold border-blue-300 text-blue-700 bg-blue-50 hover:bg-blue-100"
                    >
                      +7 Days
                    </Button>
                  </div>
                </div>

                {/* New Date Picker */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    New Deadline Date (Cutoff: 11:59:59 PM IST):
                  </label>
                  <Input
                    type="date"
                    value={newDeadlineDate}
                    onChange={(e) => setNewDeadlineDate(e.target.value)}
                    className="h-9 text-xs"
                  />
                </div>

                {/* Reason */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Extension Reason (Manager Approval / HR Note):
                  </label>
                  <Input
                    type="text"
                    placeholder="e.g. Approved leave - Manager granted 5-day extension"
                    value={extensionReason}
                    onChange={(e) => setExtensionReason(e.target.value)}
                    className="h-9 text-xs"
                  />
                </div>
              </div>
            )}

            <DialogFooter>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setExtendTarget(null)}
                disabled={submittingExtension}
                className="text-xs"
              >
                Cancel
              </Button>
              <Button
                size="sm"
                onClick={handleSubmitExtension}
                disabled={submittingExtension || !newDeadlineDate}
                className="text-xs gap-1.5 bg-blue-600 hover:bg-blue-700 text-white font-bold"
              >
                {submittingExtension ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <CalendarPlus className="w-3.5 h-3.5" />
                )}
                <span>Save & Unlock</span>
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </Layout>
  );
}
