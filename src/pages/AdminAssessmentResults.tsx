import { useState } from "react";
import { Layout } from "@/components/Layout";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost } from "@/lib/api";
import { downloadCertificatePdf } from "@/lib/certificateGenerator";
import { useToast } from "@/components/ui/use-toast";
import {
  ClipboardCheck,
  Search,
  RefreshCw,
  CheckCircle2,
  XCircle,
  Lock,
  Unlock,
  Mail,
  AlertTriangle,
  Award,
  Users,
  ChevronLeft,
  ChevronRight,
  Info,
  Settings,
  Eye,
  Edit3,
  Check
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle
} from "@/components/ui/dialog";

interface AssessmentAttempt {
  id: number;
  user_id: number;
  emp_code: string;
  emp_name: string;
  ofc_mail: string;
  department_id: number;
  department_name: string;
  course_id: number;
  attempt: number;
  quiz_score: number;
  passing_score: number;
  result: "Passed" | "Failed";
  is_locked: boolean;
  has_admin_unlocked: boolean;
  email_sent: boolean;
  email_to: string | null;
  email_cc: string | null;
  attempted_at: string;
  attempted_at_fmt: string;
  photo?: string;
}

interface ApiResponse {
  success: boolean;
  metrics: {
    total_attempts: number;
    total_candidates: number;
    total_passed: number;
    total_failed: number;
    total_locked: number;
    total_emails_sent: number;
  };
  attempts: AssessmentAttempt[];
  pagination: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
    hasPrev: boolean;
    hasNext: boolean;
  };
}

// Helper to convert any timestamp to Indian Standard Time (Asia/Kolkata)
const formatDateTimeIST = (dateVal?: string | null, fallbackFmt?: string) => {
  if (!dateVal && !fallbackFmt) return "—";
  try {
    const raw = dateVal || fallbackFmt || "";
    if (!raw) return "—";
    // If it's already an ISO or datetime string without timezone, assume UTC from server
    const isoStr = (raw.endsWith("Z") || raw.includes("+") || raw.includes("T"))
      ? raw
      : raw.replace(" ", "T") + "Z";
    const d = new Date(isoStr);
    if (isNaN(d.getTime())) return fallbackFmt || raw;
    return d.toLocaleString("en-IN", {
      timeZone: "Asia/Kolkata",
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });
  } catch {
    return fallbackFmt || dateVal || "—";
  }
};

export default function AdminAssessmentResults() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [page, setPage] = useState(1);
  const [unlockTarget, setUnlockTarget] = useState<AssessmentAttempt | null>(null);
  const [unlocking, setUnlocking] = useState(false);
  const [downloadingCertId, setDownloadingCertId] = useState<number | null>(null);

  const handleDownloadAttemptCert = async (att: AssessmentAttempt) => {
    setDownloadingCertId(att.id);
    try {
      await downloadCertificatePdf({
        name: att.emp_name || 'Employee',
        empCode: att.emp_code && att.emp_code !== '—' ? att.emp_code : `HL${att.user_id}`,
        dateStr: (att as any).passed_date_fmt || att.attempted_at,
        userId: att.user_id,
      });
      toast({
        title: "Certificate Generated",
        description: `Official certificate for ${att.emp_name} downloaded successfully.`,
      });
      queryClient.invalidateQueries({ queryKey: ["admin_assessment_results"] });
    } catch (err: any) {
      toast({
        title: "Certificate Download Failed",
        description: err?.message || "Could not generate certificate.",
        variant: "destructive",
      });
    } finally {
      setDownloadingCertId(null);
    }
  };

  // Email Settings Modal State
  const [emailModalOpen, setEmailModalOpen] = useState(false);
  const [emailLoading, setEmailLoading] = useState(false);
  const [emailSaving, setEmailSaving] = useState(false);
  const [emailActiveTab, setEmailActiveTab] = useState<"edit" | "preview" | "heads">("edit");
  const [emailSubject, setEmailSubject] = useState("");
  const [emailBody, setEmailBody] = useState("");
  const [emailCc, setEmailCc] = useState("");
  const [deptHeads, setDeptHeads] = useState<Array<{ id: number; dept: string; email: string }>>([]);
  const [variables, setVariables] = useState<Array<{ tag: string; desc: string }>>([]);

  const handleOpenEmailSettings = async () => {
    setEmailModalOpen(true);
    setEmailLoading(true);
    try {
      const res: any = await apiGet("/admin/assessment_results.php?action=get_template");
      if (res?.success && res.template) {
        setEmailSubject(res.template.subject || "");
        setEmailBody(res.template.body_html || "");
        setEmailCc(res.template.cc_recipients || "");
        setDeptHeads(res.dept_heads || []);
        setVariables(res.variables || []);
      }
    } catch (e: any) {
      toast({
        title: "Error loading email settings",
        description: e?.message || "Could not fetch assessment email template.",
        variant: "destructive"
      });
    } finally {
      setEmailLoading(false);
    }
  };

  const handleSaveEmailTemplate = async () => {
    if (!emailSubject.trim() || !emailBody.trim()) {
      toast({
        title: "Validation Error",
        description: "Subject line and email body cannot be empty.",
        variant: "destructive"
      });
      return;
    }
    setEmailSaving(true);
    try {
      const res: any = await apiPost("/admin/assessment_results.php", {
        action: "save_email_template",
        subject: emailSubject,
        body_html: emailBody,
        cc_recipients: emailCc,
        dept_heads: deptHeads
      });
      if (res?.success) {
        toast({
          title: "Template Saved Successfully",
          description: "Assessment 2-attempt lockout email template updated."
        });
        setEmailModalOpen(false);
      } else {
        toast({
          title: "Save Failed",
          description: res?.message || "Failed to save email template.",
          variant: "destructive"
        });
      }
    } catch (e: any) {
      toast({
        title: "Error",
        description: e?.message || "An error occurred while saving.",
        variant: "destructive"
      });
    } finally {
      setEmailSaving(false);
    }
  };

  const handleResetEmailTemplate = async () => {
    setEmailSaving(true);
    try {
      const res: any = await apiPost("/admin/assessment_results.php", {
        action: "reset_email_template"
      });
      if (res?.success) {
        toast({
          title: "Template Reset",
          description: "Assessment email template reset to clean default paragraphs."
        });
        await handleOpenEmailSettings();
      }
    } catch (e: any) {
      toast({
        title: "Error",
        description: "Failed to reset template.",
        variant: "destructive"
      });
    } finally {
      setEmailSaving(false);
    }
  };

  // Fetch assessment results from backend
  const { data, isLoading, refetch, isFetching } = useQuery<ApiResponse>({
    queryKey: ["admin_assessment_results", page, statusFilter, search],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: String(page),
        limit: "15",
        status: statusFilter,
        q: search
      });
      return await apiGet(`/admin/assessment_results.php?${params.toString()}`);
    },
    staleTime: 30 * 1000
  });

  const metrics = data?.metrics || {
    total_attempts: 0,
    total_candidates: 0,
    total_passed: 0,
    total_failed: 0,
    total_locked: 0,
    total_emails_sent: 0
  };

  const attempts = data?.attempts || [];
  const pagination = data?.pagination || { page: 1, totalPages: 1, total: 0 };

  // Handle Admin Unlock
  const handleConfirmUnlock = async () => {
    if (!unlockTarget) return;
    setUnlocking(true);
    try {
      const res: any = await apiPost("/admin/assessment_results.php", {
        action: "unlock",
        user_id: unlockTarget.user_id,
        course_id: unlockTarget.course_id
      });
      if (res?.success) {
        toast({
          title: "Assessment Unlocked",
          description: `${unlockTarget.emp_name} has been unlocked and can now relaunch the assessment.`
        });
        setUnlockTarget(null);
        queryClient.invalidateQueries({ queryKey: ["admin_assessment_results"] });
      } else {
        toast({
          title: "Unlock Failed",
          description: res?.message || "Could not unlock assessment.",
          variant: "destructive"
        });
      }
    } catch (err: any) {
      toast({
        title: "Error",
        description: err?.message || "An unexpected error occurred.",
        variant: "destructive"
      });
    } finally {
      setUnlocking(false);
    }
  };

  return (
    <Layout isAdmin>
      <div className="w-full space-y-6">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2.5">
              <div className="p-2 bg-emerald-100 dark:bg-emerald-950/60 rounded-xl text-emerald-600 dark:text-emerald-400">
                <ClipboardCheck className="w-6 h-6" />
              </div>
              <div>
                <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                  Assessment Results
                </h1>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Real-time Storyline SCORM induction quiz scores, pass/fail status, and 2-attempt lockout tracking
                </p>
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={handleOpenEmailSettings}
              className="flex items-center gap-2 shadow-2xs border-slate-200 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 font-semibold cursor-pointer"
            >
              <Mail className="w-4 h-4 text-emerald-600" />
              <span>Assessment Email Settings</span>
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => refetch()}
              disabled={isFetching}
              className="flex items-center gap-2 shadow-2xs cursor-pointer"
            >
              <RefreshCw className={`w-4 h-4 ${isFetching ? "animate-spin" : ""}`} />
              <span>Refresh</span>
            </Button>
          </div>
        </div>

        {/* Top Metric Cards (Interactive Click-to-Filter) */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5">
          <Card 
            onClick={() => {
              setStatusFilter("all");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-md hover:-translate-y-0.5 select-none ${
              statusFilter === "all" ? "ring-2 ring-slate-900 dark:ring-slate-100 border-transparent shadow-sm" : "hover:border-slate-300"
            }`}
            title="Click to view all candidates & attempts"
          >
            <CardContent className="p-4 flex flex-col justify-between">
              <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">Total Candidates</span>
              <div className="flex items-baseline gap-2 mt-2">
                <span className="text-2xl font-bold text-slate-800 dark:text-slate-100">
                  {metrics.total_candidates}
                </span>
                <Users className="w-4 h-4 text-slate-400 ml-auto" />
              </div>
            </CardContent>
          </Card>

          <Card 
            onClick={() => {
              setStatusFilter("all");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-md hover:-translate-y-0.5 select-none ${
              statusFilter === "all" ? "ring-2 ring-blue-500/50 border-transparent shadow-sm" : "hover:border-blue-300"
            }`}
            title="Click to view all attempts"
          >
            <CardContent className="p-4 flex flex-col justify-between">
              <span className="text-xs font-semibold text-muted-foreground">Total Attempts</span>
              <div className="flex items-baseline gap-2 mt-2">
                <span className="text-2xl font-bold text-slate-800 dark:text-slate-100">
                  {metrics.total_attempts}
                </span>
                <Award className="w-4 h-4 text-blue-500 ml-auto" />
              </div>
            </CardContent>
          </Card>

          <Card 
            onClick={() => {
              setStatusFilter("passed");
              setPage(1);
            }}
            className={`border shadow-2xs bg-emerald-50/40 dark:bg-emerald-950/20 border-emerald-200/60 dark:border-emerald-800/40 cursor-pointer transition-all hover:shadow-md hover:-translate-y-0.5 select-none ${
              statusFilter === "passed" ? "ring-2 ring-emerald-600 dark:ring-emerald-400 border-transparent shadow-sm" : "hover:border-emerald-400"
            }`}
            title="Click to filter passed candidates (Score ≥ 80%)"
          >
            <CardContent className="p-4 flex flex-col justify-between">
              <span className="text-xs font-semibold text-emerald-800 dark:text-emerald-300">Passed (≥ 80%)</span>
              <div className="flex items-baseline gap-2 mt-2">
                <span className="text-2xl font-bold text-emerald-700 dark:text-emerald-400">
                  {metrics.total_passed}
                </span>
                <CheckCircle2 className="w-4 h-4 text-emerald-600 ml-auto" />
              </div>
            </CardContent>
          </Card>

          <Card 
            onClick={() => {
              setStatusFilter("failed");
              setPage(1);
            }}
            className={`border shadow-2xs bg-amber-50/40 dark:bg-amber-950/20 border-amber-200/60 dark:border-amber-800/40 cursor-pointer transition-all hover:shadow-md hover:-translate-y-0.5 select-none ${
              statusFilter === "failed" ? "ring-2 ring-amber-600 dark:ring-amber-400 border-transparent shadow-sm" : "hover:border-amber-400"
            }`}
            title="Click to filter failed attempt 1 candidates"
          >
            <CardContent className="p-4 flex flex-col justify-between">
              <span className="text-xs font-semibold text-amber-800 dark:text-amber-300">Failed (Att 1)</span>
              <div className="flex items-baseline gap-2 mt-2">
                <span className="text-2xl font-bold text-amber-700 dark:text-amber-400">
                  {metrics.total_failed}
                </span>
                <AlertTriangle className="w-4 h-4 text-amber-600 ml-auto" />
              </div>
            </CardContent>
          </Card>

          <Card 
            onClick={() => {
              setStatusFilter("locked");
              setPage(1);
            }}
            className={`border shadow-2xs bg-rose-50/40 dark:bg-rose-950/20 border-rose-200/60 dark:border-rose-800/40 cursor-pointer transition-all hover:shadow-md hover:-translate-y-0.5 select-none ${
              statusFilter === "locked" ? "ring-2 ring-rose-600 dark:ring-rose-400 border-transparent shadow-sm" : "hover:border-rose-400"
            }`}
            title="Click to filter locked out candidates (2 Failed Attempts)"
          >
            <CardContent className="p-4 flex flex-col justify-between">
              <span className="text-xs font-semibold text-rose-800 dark:text-rose-300">Locked (Fail 2/2)</span>
              <div className="flex items-baseline gap-2 mt-2">
                <span className="text-2xl font-bold text-rose-700 dark:text-rose-400">
                  {metrics.total_locked}
                </span>
                <Lock className="w-4 h-4 text-rose-600 ml-auto" />
              </div>
            </CardContent>
          </Card>

          <Card 
            onClick={() => {
              setStatusFilter("emails_sent");
              setPage(1);
            }}
            className={`border shadow-2xs cursor-pointer transition-all hover:shadow-md hover:-translate-y-0.5 select-none ${
              statusFilter === "emails_sent" ? "ring-2 ring-purple-600 dark:ring-purple-400 border-transparent shadow-sm" : "hover:border-purple-300"
            }`}
            title="Click to filter records where alert email was sent"
          >
            <CardContent className="p-4 flex flex-col justify-between">
              <span className="text-xs font-semibold text-muted-foreground">Alert Emails Sent</span>
              <div className="flex items-baseline gap-2 mt-2">
                <span className="text-2xl font-bold text-purple-700 dark:text-purple-400">
                  {metrics.total_emails_sent}
                </span>
                <Mail className="w-4 h-4 text-purple-500 ml-auto" />
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Filter Bar */}
        <Card className="border shadow-2xs">
          <CardContent className="p-4">
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3">
              {/* Status Filter Buttons */}
              <div className="flex items-center gap-1.5 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
                {[
                  { id: "all", label: "All Results" },
                  { id: "passed", label: "Passed (≥ 80%)" },
                  { id: "failed", label: "Failed (Attempt 1)" },
                  { id: "locked", label: "Locked Out" },
                  { id: "emails_sent", label: "Alert Emails Sent" }
                ].map((tab) => (
                  <button
                    key={tab.id}
                    onClick={() => {
                      setStatusFilter(tab.id);
                      setPage(1);
                    }}
                    className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer whitespace-nowrap ${
                      statusFilter === tab.id
                        ? "bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 shadow-2xs"
                        : "text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                    }`}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>

              {/* Search input */}
              <div className="relative w-full sm:w-72">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search candidate, code, email..."
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                  className="pl-9 h-9 text-xs"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Main Table */}
        <Card className="border shadow-2xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 dark:bg-slate-900/50 border-b text-slate-600 dark:text-slate-400 font-semibold uppercase tracking-wider">
                <tr>
                  <th className="px-4 py-3">Employee</th>
                  <th className="px-4 py-3">Department</th>
                  <th className="px-3 py-3 text-center">Attempt</th>
                  <th className="px-3 py-3 text-center">Score</th>
                  <th className="px-3 py-3 text-center">Result</th>
                  <th className="px-3 py-3 text-center">Status</th>
                  <th className="px-4 py-3">Alert Email</th>
                  <th className="px-4 py-3">Submitted At</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {isLoading ? (
                  <tr>
                    <td colSpan={9} className="text-center py-12 text-slate-500">
                      <RefreshCw className="w-5 h-5 animate-spin mx-auto mb-2 text-slate-400" />
                      Loading assessment results...
                    </td>
                  </tr>
                ) : attempts.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="text-center py-12 text-slate-500">
                      No assessment attempts found matching your filter.
                    </td>
                  </tr>
                ) : (
                  attempts.map((att) => {
                    const isLockedOut = att.is_locked && !att.has_admin_unlocked;
                    return (
                      <tr key={att.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-850/50 transition-colors">
                        {/* Employee */}
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2.5">
                            <div className="w-8 h-8 rounded-full bg-slate-200 dark:bg-slate-800 flex items-center justify-center font-bold text-xs text-slate-700 dark:text-slate-300 overflow-hidden shrink-0">
                              {att.emp_name.charAt(0)}
                            </div>
                            <div>
                              <div className="font-semibold text-slate-900 dark:text-slate-100 flex items-center gap-1.5">
                                {att.emp_name}
                                {att.emp_code && att.emp_code !== '—' && (
                                  <span className="text-[10px] font-mono text-muted-foreground bg-slate-100 dark:bg-slate-800 px-1 py-0.5 rounded">
                                    {att.emp_code}
                                  </span>
                                )}
                              </div>
                              <div className="text-[11px] text-muted-foreground">{att.ofc_mail}</div>
                            </div>
                          </div>
                        </td>

                        {/* Department */}
                        <td className="px-4 py-3 text-slate-700 dark:text-slate-300 font-medium">
                          {att.department_name}
                        </td>

                        {/* Attempt */}
                        <td className="px-3 py-3 text-center">
                          <Badge variant="outline" className="font-mono text-[11px]">
                            #{att.attempt}
                          </Badge>
                        </td>

                        {/* Score */}
                        <td className="px-3 py-3 text-center">
                          <span
                            className={`font-bold font-mono text-xs px-2 py-1 rounded ${
                              att.quiz_score >= 80
                                ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                                : "bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300"
                            }`}
                          >
                            {Number(att.quiz_score).toFixed(1)}%
                          </span>
                        </td>

                        {/* Result */}
                        <td className="px-3 py-3 text-center">
                          {att.result === "Passed" ? (
                            <Badge className="bg-emerald-600 hover:bg-emerald-700 text-white gap-1 text-[11px]">
                              <CheckCircle2 className="w-3 h-3" /> Passed
                            </Badge>
                          ) : (
                            <Badge variant="destructive" className="gap-1 text-[11px]">
                              <XCircle className="w-3 h-3" /> Failed
                            </Badge>
                          )}
                        </td>

                        {/* Status */}
                        <td className="px-3 py-3 text-center">
                          {att.result === "Passed" ? (
                            <span className="text-emerald-700 dark:text-emerald-400 font-medium text-[11px]">Completed</span>
                          ) : isLockedOut ? (
                            <span className="inline-flex items-center gap-1 text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/60 px-2 py-0.5 rounded-md font-bold text-[11px]">
                              <Lock className="w-3 h-3" /> Locked (2/2)
                            </span>
                          ) : att.has_admin_unlocked ? (
                            <span className="inline-flex items-center gap-1 text-blue-700 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/60 px-2 py-0.5 rounded-md font-semibold text-[11px]">
                              <Unlock className="w-3 h-3" /> Admin Unlocked
                            </span>
                          ) : (
                            <span className="text-amber-700 dark:text-amber-400 font-medium text-[11px]">Retake Open</span>
                          )}
                        </td>

                        {/* Alert Email */}
                        <td className="px-4 py-3">
                          {att.email_sent ? (
                            <div className="flex items-center gap-1.5" title={`TO: ${att.email_to}\nCC: ${att.email_cc}`}>
                              <span className="p-1 rounded bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300">
                                <Mail className="w-3 h-3" />
                              </span>
                              <div className="text-[11px]">
                                <span className="font-semibold text-purple-700 dark:text-purple-300">Sent Once</span>
                                <div className="text-[10px] text-muted-foreground truncate max-w-[140px]">
                                  {att.email_to}
                                </div>
                              </div>
                            </div>
                          ) : (
                            <span className="text-muted-foreground text-[11px]">—</span>
                          )}
                        </td>

                        {/* Submitted At (Indian Standard Time) */}
                        <td className="px-4 py-3 text-slate-500 whitespace-nowrap text-[11px]">
                          {formatDateTimeIST(att.attempted_at, att.attempted_at_fmt)}
                        </td>

                        {/* Action: Certificate & Unlock */}
                        <td className="px-4 py-3 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            {att.result === 'Passed' && (
                              <Button
                                size="sm"
                                variant="outline"
                                disabled={downloadingCertId === att.id}
                                onClick={() => handleDownloadAttemptCert(att)}
                                className="h-7 px-2 text-xs font-bold text-emerald-800 border-emerald-300 bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-400 gap-1 cursor-pointer shadow-2xs"
                                title={(att as any).certificate_info?.download_count ? `Downloaded ${(att as any).certificate_info.download_count} time(s). Last: ${(att as any).certificate_info.last_downloaded_at_fmt || 'Recently'}` : "Download Official Certificate PDF"}
                              >
                                {downloadingCertId === att.id ? (
                                  <RefreshCw className="w-3 h-3 animate-spin text-emerald-600" />
                                ) : (
                                  <Award className="w-3 h-3 text-emerald-600" />
                                )}
                                <span>Certificate</span>
                                {Boolean((att as any).certificate_info?.download_count) && (
                                  <span className="text-[10px] bg-emerald-200/90 text-emerald-900 font-black px-1.5 py-0.2 rounded-full">
                                    {(att as any).certificate_info.download_count}x
                                  </span>
                                )}
                              </Button>
                            )}
                            {isLockedOut && (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setUnlockTarget(att)}
                                className="h-7 text-xs font-semibold text-rose-700 border-rose-300 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950"
                              >
                                <Unlock className="w-3 h-3 mr-1" />
                                Unlock
                              </Button>
                            )}
                            {att.result !== 'Passed' && !isLockedOut && (
                              <span className="text-muted-foreground text-[11px]">—</span>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {pagination.totalPages > 1 && (
            <div className="p-4 border-t flex items-center justify-between text-xs text-muted-foreground">
              <span>
                Showing page {pagination.page} of {pagination.totalPages} ({pagination.total} attempts)
              </span>
              <div className="flex items-center gap-1.5">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={!pagination.hasPrev}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="h-7 px-2"
                >
                  <ChevronLeft className="w-4 h-4" />
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={!pagination.hasNext}
                  onClick={() => setPage((p) => Math.min(pagination.totalPages, p + 1))}
                  className="h-7 px-2"
                >
                  <ChevronRight className="w-4 h-4" />
                </Button>
              </div>
            </div>
          )}
        </Card>

        {/* Unlock Confirmation Dialog */}
        <Dialog open={Boolean(unlockTarget)} onOpenChange={(open) => !open && setUnlockTarget(null)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Unlock className="w-5 h-5 text-emerald-600" />
                Unlock Assessment for {unlockTarget?.emp_name}
              </DialogTitle>
              <DialogDescription className="pt-2 text-xs">
                This employee exhausted 2 attempts and was locked out. Unlocking will allow them to launch and take the Storyline Assessment again.
              </DialogDescription>
            </DialogHeader>

            {unlockTarget && (
              <div className="p-3 bg-slate-50 dark:bg-slate-900 rounded-lg text-xs space-y-1.5 border">
                <div><strong>Employee:</strong> {unlockTarget.emp_name} ({unlockTarget.emp_code})</div>
                <div><strong>Office Email:</strong> {unlockTarget.ofc_mail}</div>
                <div><strong>Department:</strong> {unlockTarget.department_name}</div>
                <div><strong>Latest Score:</strong> {unlockTarget.quiz_score}% (Attempt #{unlockTarget.attempt})</div>
              </div>
            )}

            <DialogFooter className="gap-2 sm:gap-0">
              <Button variant="ghost" onClick={() => setUnlockTarget(null)} disabled={unlocking}>
                Cancel
              </Button>
              <Button
                onClick={handleConfirmUnlock}
                disabled={unlocking}
                className="bg-emerald-600 hover:bg-emerald-700 text-white"
              >
                {unlocking ? "Unlocking..." : "Confirm & Unlock Assessment"}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Assessment Email Settings & Template Dialog */}
        <Dialog open={emailModalOpen} onOpenChange={setEmailModalOpen}>
          <DialogContent className="max-w-4xl max-h-[90vh] overflow-hidden flex flex-col p-0">
            <DialogHeader className="px-6 pt-5 pb-3 border-b">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                  <div className="p-2 rounded-xl bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300">
                    <Mail className="w-5 h-5" />
                  </div>
                  <div>
                    <DialogTitle className="text-lg font-bold">
                      Assessment 2-Attempt Lockout Email Settings
                    </DialogTitle>
                    <DialogDescription className="text-xs text-muted-foreground">
                      Dispatched strictly once when a trainee fails 2 attempts (&lt; 80%).
                    </DialogDescription>
                  </div>
                </div>
              </div>

              {/* Sub tabs */}
              <div className="flex items-center gap-2 pt-3">
                <button
                  type="button"
                  onClick={() => setEmailActiveTab("edit")}
                  className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer ${
                    emailActiveTab === "edit"
                      ? "bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900"
                      : "text-slate-600 hover:bg-slate-100 dark:text-slate-400"
                  }`}
                >
                  <Edit3 className="w-3.5 h-3.5" />
                  <span>Template Editor</span>
                </button>
                <button
                  type="button"
                  onClick={() => setEmailActiveTab("preview")}
                  className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer ${
                    emailActiveTab === "preview"
                      ? "bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900"
                      : "text-slate-600 hover:bg-slate-100 dark:text-slate-400"
                  }`}
                >
                  <Eye className="w-3.5 h-3.5" />
                  <span>Live Preview</span>
                </button>
                <button
                  type="button"
                  onClick={() => setEmailActiveTab("heads")}
                  className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors flex items-center gap-1.5 cursor-pointer ${
                    emailActiveTab === "heads"
                      ? "bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900"
                      : "text-slate-600 hover:bg-slate-100 dark:text-slate-400"
                  }`}
                >
                  <Users className="w-3.5 h-3.5" />
                  <span>Recipients &amp; Dept Heads</span>
                </button>
              </div>
            </DialogHeader>

            <div className="flex-1 overflow-y-auto p-6 space-y-4">
              {emailLoading ? (
                <div className="py-16 text-center text-slate-500">
                  <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-slate-400" />
                  <span>Loading template configuration...</span>
                </div>
              ) : emailActiveTab === "edit" ? (
                <div className="space-y-4 text-xs">
                  {/* Subject Line */}
                  <div className="space-y-1.5">
                    <label className="font-semibold text-slate-700 dark:text-slate-300">
                      Email Subject Line
                    </label>
                    <Input
                      value={emailSubject}
                      onChange={(e) => setEmailSubject(e.target.value)}
                      placeholder="Assessment Locked - 2 Failed Attempts: {{employee_name}} ({{employee_code}})"
                      className="text-xs"
                    />
                  </div>

                  {/* CC Recipients */}
                  <div className="space-y-1.5">
                    <label className="font-semibold text-slate-700 dark:text-slate-300">
                      CC Recipients (Comma-separated)
                    </label>
                    <Input
                      value={emailCc}
                      onChange={(e) => setEmailCc(e.target.value)}
                      placeholder="ashis.rout@hexalearn.co.in, ashiskrout1@gmail.com"
                      className="text-xs"
                    />
                    <p className="text-[11px] text-muted-foreground">
                      Fixed management CC recipients for all lockout alerts.
                    </p>
                  </div>

                  {/* Variables Helper */}
                  <div className="space-y-1.5 bg-slate-50 dark:bg-slate-900 p-3 rounded-lg border">
                    <span className="font-semibold text-slate-700 dark:text-slate-300 block mb-1">
                      Available Template Placeholders (Click to insert):
                    </span>
                    <div className="flex flex-wrap gap-1.5">
                      {variables.map((v) => (
                        <button
                          key={v.tag}
                          type="button"
                          onClick={() => setEmailBody((prev) => prev + ` ${v.tag}`)}
                          className="px-2 py-0.5 bg-white dark:bg-slate-800 border rounded font-mono text-[11px] text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-slate-700 transition-colors cursor-pointer"
                          title={v.desc}
                        >
                          {v.tag}
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Body HTML */}
                  <div className="space-y-1.5">
                    <label className="font-semibold text-slate-700 dark:text-slate-300">
                      HTML Email Body Template
                    </label>
                    <Textarea
                      rows={14}
                      value={emailBody}
                      onChange={(e) => setEmailBody(e.target.value)}
                      className="font-mono text-xs leading-relaxed"
                      placeholder="HTML template markup with variables..."
                    />
                  </div>
                </div>
              ) : emailActiveTab === "preview" ? (
                <div className="space-y-3">
                  <div className="p-3 bg-slate-50 dark:bg-slate-900 rounded-lg text-xs space-y-1 border">
                    <div><strong>Simulated Subject:</strong> {emailSubject.replace(/{{employee_name}}/g, "Suraj Kumar Bal").replace(/{{employee_code}}/g, "HL078")}</div>
                    <div><strong>Simulated TO:</strong> arup.panda@hexalearn.com (Software Dept Head)</div>
                    <div><strong>Simulated CC:</strong> {emailCc || "ashis.rout@hexalearn.co.in, ashiskrout1@gmail.com"}</div>
                    <div className="text-[11px] text-slate-500 italic pt-1">
                      * Employee is NOT emailed. Candidate reviews attempt history and score at <code>/onboard/attempt-history</code>.
                    </div>
                  </div>

                  <div className="border rounded-xl p-4 bg-white dark:bg-slate-950 shadow-inner overflow-hidden">
                    <div
                      dangerouslySetInnerHTML={{
                        __html: emailBody
                          .replace(/{{employee_name}}/g, "Suraj Kumar Bal")
                          .replace(/{{employee_code}}/g, "HL078")
                          .replace(/{{office_email}}/g, "suraj.bal@hexalearn.com")
                          .replace(/{{department}}/g, "Software")
                          .replace(/{{attempt_1_score}}/g, "65.0%")
                          .replace(/{{attempt_2_score}}/g, "72.0%")
                          .replace(/{{passing_score}}/g, "80%")
                          .replace(/{{status}}/g, "LOCKED")
                      }}
                    />
                  </div>
                </div>
              ) : (
                <div className="space-y-4 text-xs">
                  <div className="p-3 bg-emerald-50/70 border border-emerald-200/80 rounded-xl text-emerald-900 space-y-1">
                    <strong className="block text-emerald-800">Automated Dispatch Rules:</strong>
                    <ul className="list-disc list-inside space-y-0.5 text-[11px]">
                      <li>Dispatched strictly on <strong>2nd failed attempt (&lt; 80%)</strong>.</li>
                      <li>Guaranteed to be sent <strong>strictly once</strong> per employee.</li>
                      <li>Sent ONLY to Management / Department Head. <strong>Employee is NOT emailed</strong>; candidate views their status at <code>/onboard/attempt-history</code>.</li>
                      <li>If Department Head is defined (Depts 1, 2, 3, 4, 6, 7), they are automatically added as TO recipient.</li>
                      <li>Dept 5 ("Other") or unlisted depts send directly to primary Management CC.</li>
                      <li><code>satyabrata.das@hexalearn.com</code> and reporting managers are STRICTLY excluded.</li>
                    </ul>
                  </div>

                  <div className="border rounded-xl overflow-hidden">
                    <table className="w-full text-left">
                      <thead className="bg-slate-50 dark:bg-slate-900 border-b font-semibold text-slate-700 dark:text-slate-300">
                        <tr>
                          <th className="p-2.5">Dept ID</th>
                          <th className="p-2.5">Department Name</th>
                          <th className="p-2.5">Designated Head Email</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {deptHeads.map((dh) => (
                          <tr key={dh.id} className="hover:bg-slate-50 dark:hover:bg-slate-900/50">
                            <td className="p-2.5 font-mono text-muted-foreground w-20">#{dh.id}</td>
                            <td className="p-2.5 font-semibold text-slate-800 dark:text-slate-200 w-48">{dh.dept}</td>
                            <td className="p-2.5">
                              <Input
                                value={dh.email}
                                onChange={(e) => {
                                  const val = e.target.value;
                                  setDeptHeads((prev) =>
                                    prev.map((item) => (item.id === dh.id ? { ...item, email: val } : item))
                                  );
                                }}
                                className="h-7 text-xs font-mono max-w-sm"
                                placeholder="head@hexalearn.com"
                              />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </div>

            <DialogFooter className="px-6 py-3 border-t bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setEmailModalOpen(false)}
                  disabled={emailSaving}
                >
                  Close
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleResetEmailTemplate}
                  disabled={emailSaving || emailLoading}
                  className="text-xs text-slate-600 hover:text-slate-900"
                >
                  Reset to Default
                </Button>
              </div>
              <div className="flex items-center gap-2">
                <Button
                  size="sm"
                  onClick={handleSaveEmailTemplate}
                  disabled={emailSaving || emailLoading}
                  className="bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer"
                >
                  {emailSaving ? (
                    <>
                      <RefreshCw className="w-3.5 h-3.5 animate-spin mr-1.5" />
                      Saving...
                    </>
                  ) : (
                    <>
                      <Check className="w-3.5 h-3.5 mr-1.5" />
                      Save Email Template
                    </>
                  )}
                </Button>
              </div>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </Layout>
  );
}
