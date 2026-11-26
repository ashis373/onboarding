import { useState, useMemo, useCallback } from "react";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Award,
  Search,
  RefreshCw,
  Eye,
  Download,
  Calendar,
  CheckCircle2,
  Clock,
  Users,
  Building2,
  Filter,
  RotateCcw,
  ChevronLeft,
  ChevronRight,
  Loader2,
  FileCheck,
  AlertCircle,
  ShieldCheck,
  ShieldAlert,
  Copy,
  Check,
  ExternalLink,
  History
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle
} from "@/components/ui/dialog";
import { Switch } from "@/components/ui/switch";
import { apiGet, apiPost } from "@/lib/api";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useToast } from "@/components/ui/use-toast";
import { downloadCertificatePdf, generateCertificateDataUrl } from "@/lib/certificateGenerator";

export interface CertificateDownloadEvent {
  id: number;
  download_number: number;
  downloaded_at: string;
  downloaded_at_fmt: string;
  downloaded_by_id: number;
  downloaded_by_name: string;
  is_self: boolean;
  ip_address: string;
}

interface CertificateRecord {
  uid: number;
  emp_code: string;
  certificate_number: string;
  name: string;
  email: string;
  photo?: string;
  dept_id: number;
  dept_name: string;
  quiz_score: number;
  attempt_number: number;
  passed_at: string;
  passed_date_fmt: string;
  passed_datetime_ist: string;
  download_count: number;
  first_downloaded_at: string | null;
  first_downloaded_fmt: string | null;
  last_downloaded_at: string | null;
  last_downloaded_fmt: string | null;
  download_history?: CertificateDownloadEvent[];
}

interface CertificatesResponse {
  success: boolean;
  allow_employee_download?: boolean;
  metrics: {
    total_certified: number;
    total_downloads: number;
    downloaded_users: number;
    never_downloaded: number;
  };
  departments: Array<{ id: number; name: string }>;
  records: CertificateRecord[];
  pagination: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
    hasPrev: boolean;
    hasNext: boolean;
  };
}

export default function AdminCertificates() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  // Search & Filter State
  const [searchInput, setSearchInput] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [selectedDept, setSelectedDept] = useState("all");
  const [statusFilter, setStatusFilter] = useState<"all" | "downloaded" | "never">("all");
  const [page, setPage] = useState(1);
  const limit = 10;

  // Action States
  const [downloadingUid, setDownloadingUid] = useState<number | null>(null);
  const [previewRecord, setPreviewRecord] = useState<CertificateRecord | null>(null);
  const [historyRecord, setHistoryRecord] = useState<CertificateRecord | null>(null);
  const [previewImageUrl, setPreviewImageUrl] = useState<string | null>(null);
  const [generatingPreview, setGeneratingPreview] = useState(false);
  const [copiedCertNo, setCopiedCertNo] = useState<string | null>(null);

  // Master ON/OFF Switch State
  const [togglingDownloadAccess, setTogglingDownloadAccess] = useState(false);

  // Test Download Modal State
  const [testModalOpen, setTestModalOpen] = useState(false);
  const [allEmployeesList, setAllEmployeesList] = useState<any[]>([]);
  const [loadingEmployees, setLoadingEmployees] = useState(false);
  const [selectedTestUid, setSelectedTestUid] = useState<number | null>(null);
  const [testName, setTestName] = useState("");
  const [testCode, setTestCode] = useState("");
  const [testDateStr, setTestDateStr] = useState("November 23, 2026");
  const [isTestingDownload, setIsTestingDownload] = useState(false);

  // Verification Modal State
  const [verifyModalOpen, setVerifyModalOpen] = useState(false);
  const [verifyInput, setVerifyInput] = useState("");
  const [verifying, setVerifying] = useState(false);
  const [verifyResult, setVerifyResult] = useState<any | null>(null);

  // Debounce search
  const handleSearchChange = (val: string) => {
    setSearchInput(val);
    const handler = setTimeout(() => {
      setDebouncedSearch(val.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(handler);
  };

  // Build Query URL
  const queryUrl = useMemo(() => {
    const params = new URLSearchParams();
    params.set("page", String(page));
    params.set("limit", String(limit));
    if (debouncedSearch) params.set("q", debouncedSearch);
    if (selectedDept !== "all") params.set("dept", selectedDept);
    if (statusFilter !== "all") params.set("status", statusFilter);
    return `/admin/certificates.php?${params.toString()}`;
  }, [page, limit, debouncedSearch, selectedDept, statusFilter]);

  // Fetch Data
  const { data, isLoading, isFetching, refetch } = useQuery<CertificatesResponse>({
    queryKey: ["admin_certificates", queryUrl],
    queryFn: () => apiGet<CertificatesResponse>(queryUrl),
    staleTime: 5 * 60 * 1000,
    gcTime: 15 * 60 * 1000,
    refetchOnWindowFocus: false,
    placeholderData: (prev) => prev,
  });

  const records = useMemo(() => data?.records || [], [data]);
  const metrics = useMemo(() => data?.metrics || {
    total_certified: 0,
    total_downloads: 0,
    downloaded_users: 0,
    never_downloaded: 0
  }, [data]);
  const departments = useMemo(() => data?.departments || [], [data]);
  const pagination = useMemo(() => data?.pagination || {
    page: 1,
    limit: 10,
    total: 0,
    totalPages: 1,
    hasPrev: false,
    hasNext: false
  }, [data]);

  // Reset Filters
  const handleResetFilters = () => {
    setSearchInput("");
    setDebouncedSearch("");
    setSelectedDept("all");
    setStatusFilter("all");
    setPage(1);
  };

  // Copy helper
  const handleCopy = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedCertNo(text);
    setTimeout(() => setCopiedCertNo(null), 2000);
    toast({ title: "Copied", description: `${text} copied to clipboard.` });
  };

  // Trigger Download (Reissue Protection: backend reuses same certificate_number)
  const handleDownload = async (record: CertificateRecord) => {
    setDownloadingUid(record.uid);
    try {
      await downloadCertificatePdf({
        name: record.name,
        empCode: record.emp_code,
        dateStr: record.passed_date_fmt,
        userId: record.uid,
      });
      toast({
        title: "Certificate Generated",
        description: `Official certificate for ${record.name} (${record.certificate_number}) downloaded.`,
      });
      queryClient.invalidateQueries({ queryKey: ["admin_certificates"] });
    } catch (err: any) {
      toast({
        title: "Download Failed",
        description: err?.message || "Failed to generate certificate.",
        variant: "destructive",
      });
    } finally {
      setDownloadingUid(null);
    }
  };

  // Open Preview Dialog
  const handleOpenPreview = async (record: CertificateRecord) => {
    setPreviewRecord(record);
    setGeneratingPreview(true);
    setPreviewImageUrl(null);
    try {
      const dataUrl = await generateCertificateDataUrl({
        name: record.name,
        empCode: record.emp_code,
        dateStr: record.passed_date_fmt,
        userId: record.uid,
      });
      setPreviewImageUrl(dataUrl);
    } catch (err: any) {
      toast({
        title: "Preview Error",
        description: err?.message || "Could not generate certificate preview.",
        variant: "destructive",
      });
      setPreviewRecord(null);
    } finally {
      setGeneratingPreview(false);
    }
  };

  // Certificate Verification Handler
  const handleVerify = async (queryToVerify?: string) => {
    const target = (queryToVerify || verifyInput).trim();
    if (!target) {
      toast({ title: "Input Required", description: "Please enter a Certificate Number or Employee Code.", variant: "destructive" });
      return;
    }
    setVerifying(true);
    setVerifyResult(null);
    try {
      const res: any = await apiGet(`/admin/verify_certificate.php?cert_no=${encodeURIComponent(target)}`);
      setVerifyResult(res);
      setVerifyModalOpen(true);
    } catch (err: any) {
      setVerifyResult({
        success: false,
        is_valid: false,
        message: err?.message || "Failed to reach verification service."
      });
      setVerifyModalOpen(true);
    } finally {
      setVerifying(false);
    }
  };

  const [downloadAllowedLocal, setDownloadAllowedLocal] = useState<boolean | null>(null);

  const isEmployeeDownloadAllowed = downloadAllowedLocal !== null 
    ? downloadAllowedLocal 
    : Boolean(data?.allow_employee_download);

  // Master switch handler
  const handleToggleEmployeeDownload = async (newVal: boolean) => {
    setDownloadAllowedLocal(newVal);
    setTogglingDownloadAccess(true);
    try {
      const res: any = await apiPost('/admin/certificates.php', {
        action: 'toggle_employee_download',
        allowed: newVal
      });
      if (res?.success) {
        queryClient.setQueryData(["admin_certificates", queryUrl], (old: any) => {
          if (!old) return old;
          return { ...old, allow_employee_download: newVal };
        });
        queryClient.invalidateQueries({ queryKey: ["admin_certificates"] });
        toast({
          title: newVal ? "🟢 Live for Employees" : "🔒 Admin-Only Mode Active",
          description: res.message || (newVal 
            ? "Qualified employees can now see and download their certificates on the employee portal." 
            : "Certificate download is now hidden from all employee portals."),
        });
      } else {
        throw new Error(res?.message || "Failed to update setting");
      }
    } catch (err: any) {
      setDownloadAllowedLocal(!newVal);
      toast({
        title: "Update Failed",
        description: err?.message || "Could not toggle employee download status.",
        variant: "destructive"
      });
    } finally {
      setTogglingDownloadAccess(false);
    }
  };

  // Open Test Modal & load all active employees
  const handleOpenTestModal = async () => {
    setTestModalOpen(true);
    if (allEmployeesList.length === 0) {
      setLoadingEmployees(true);
      try {
        const res: any = await apiGet('/admin/certificates.php?action=all_employees');
        if (res?.success && Array.isArray(res?.employees)) {
          setAllEmployeesList(res.employees);
          if (res.employees.length > 0) {
            const first = res.employees[0];
            setSelectedTestUid(first.uid);
            setTestName(first.name);
            setTestCode(first.emp_code);
          }
        }
      } catch (err) {
        setTestName("Ashis Kumar Rout");
        setTestCode("HL198");
      } finally {
        setLoadingEmployees(false);
      }
    }
  };

  const handleSelectTestEmployee = (uid: number) => {
    setSelectedTestUid(uid);
    const emp = allEmployeesList.find(e => e.uid === uid);
    if (emp) {
      setTestName(emp.name);
      setTestCode(emp.emp_code);
    }
  };

  const handleDownloadTestCert = async () => {
    if (!testName.trim()) {
      toast({ title: "Name Required", description: "Please enter candidate name.", variant: "destructive" });
      return;
    }
    setIsTestingDownload(true);
    try {
      await downloadCertificatePdf({
        name: testName.trim(),
        empCode: testCode.trim() || "HL000",
        dateStr: testDateStr.trim() || "November 23, 2026",
        userId: selectedTestUid || 999999,
      });
      toast({
        title: "Test Certificate Downloaded",
        description: `Official test PDF for ${testName} downloaded successfully.`,
      });
    } catch (err: any) {
      toast({
        title: "Test Download Failed",
        description: err?.message || "Could not generate certificate PDF.",
        variant: "destructive"
      });
    } finally {
      setIsTestingDownload(false);
    }
  };

  const handlePreviewTestCert = async () => {
    if (!testName.trim()) {
      toast({ title: "Name Required", description: "Please enter candidate name.", variant: "destructive" });
      return;
    }
    setPreviewRecord({
      uid: selectedTestUid || 999999,
      emp_code: testCode.trim() || "HL000",
      certificate_number: "HL-2026-TEST",
      name: testName.trim(),
      email: "test@hexalearn.com",
      dept_id: 1,
      dept_name: "General",
      quiz_score: 100,
      attempt_number: 1,
      passed_at: new Date().toISOString(),
      passed_date_fmt: testDateStr.trim() || "November 23, 2026",
      passed_datetime_ist: "Today",
      download_count: 1,
      first_downloaded_at: null,
      first_downloaded_fmt: null,
      last_downloaded_at: null,
      last_downloaded_fmt: null,
    });
    setGeneratingPreview(true);
    setPreviewImageUrl(null);
    try {
      const dataUrl = await generateCertificateDataUrl({
        name: testName.trim(),
        empCode: testCode.trim() || "HL000",
        dateStr: testDateStr.trim() || "November 23, 2026",
        userId: selectedTestUid || 999999,
      });
      setPreviewImageUrl(dataUrl);
    } catch (err: any) {
      toast({
        title: "Preview Error",
        description: err?.message || "Could not render test preview.",
        variant: "destructive",
      });
      setPreviewRecord(null);
    } finally {
      setGeneratingPreview(false);
    }
  };

  return (
    <Layout>
      <div className="w-full space-y-6 pb-12">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="p-2 bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 rounded-xl">
                <Award className="h-6 w-6" />
              </span>
              <div>
                <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100 tracking-tight">
                  Certificate Downloads & Verification
                </h1>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Track unique certificate numbers (e.g. HL-2026-00001), verify authenticity, and audit candidate downloads.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={() => refetch()}
              disabled={isFetching}
              className="gap-1.5 h-9 text-xs font-semibold shadow-2xs"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
              <span>Refresh</span>
            </Button>
          </div>
        </div>

        {/* Master ON/OFF Switch Banner Card */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5 rounded-2xl border bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 shadow-2xs">
          <div className="flex items-center gap-3.5">
            <div className={`p-2.5 rounded-xl shrink-0 ${isEmployeeDownloadAllowed ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'}`}>
              {isEmployeeDownloadAllowed ? <CheckCircle2 className="h-5 w-5" /> : <ShieldAlert className="h-5 w-5" />}
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h2 className="text-sm font-bold text-slate-900 dark:text-slate-100">
                  Employee Certificate Download Access
                </h2>
                <Badge
                  variant="outline"
                  className={isEmployeeDownloadAllowed 
                    ? "bg-emerald-50 text-emerald-700 border-emerald-300 font-bold text-[11px]" 
                    : "bg-slate-100 text-slate-700 border-slate-300 font-bold text-[11px]"
                  }
                >
                  {isEmployeeDownloadAllowed ? "🟢 LIVE FOR EMPLOYEES" : "🔒 ADMIN ONLY (HIDDEN)"}
                </Badge>
              </div>
              <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                {isEmployeeDownloadAllowed
                  ? "Qualified employees can see and click the 'Download Certificate' button on their employee portal."
                  : "Certificate download is completely hidden from all employee portals so Admin can test first."}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3 shrink-0 self-end sm:self-center bg-slate-50 dark:bg-slate-800/60 p-2 px-3 rounded-xl border border-slate-200/80 dark:border-slate-700">
            <span className="text-xs font-bold text-slate-700 dark:text-slate-300">
              {isEmployeeDownloadAllowed ? "Live (ON)" : "Hidden (OFF)"}
            </span>
            <Switch
              checked={isEmployeeDownloadAllowed}
              onCheckedChange={handleToggleEmployeeDownload}
              disabled={togglingDownloadAccess}
            />
          </div>
        </div>

        {/* Top Metric Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xs space-y-1">
            <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
              <span>Total Certified</span>
              <CheckCircle2 className="h-4 w-4 text-emerald-600" />
            </div>
            <div className="text-2xl font-black text-slate-900 dark:text-slate-100">
              {metrics.total_certified}
            </div>
            <p className="text-[11px] text-slate-400">Passed assessment</p>
          </div>

          <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xs space-y-1">
            <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
              <span>Total Downloads</span>
              <Download className="h-4 w-4 text-blue-600" />
            </div>
            <div className="text-2xl font-black text-blue-700 dark:text-blue-400">
              {metrics.total_downloads}
            </div>
            <p className="text-[11px] text-slate-400">Total certificate PDFs issued</p>
          </div>

          <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xs space-y-1">
            <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
              <span>Downloaded Candidates</span>
              <FileCheck className="h-4 w-4 text-emerald-600" />
            </div>
            <div className="text-2xl font-black text-emerald-700 dark:text-emerald-400">
              {metrics.downloaded_users}
            </div>
            <p className="text-[11px] text-slate-400">
              {metrics.total_certified > 0
                ? `${Math.round((metrics.downloaded_users / metrics.total_certified) * 100)}% of certified`
                : "0%"}
            </p>
          </div>

          <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xs space-y-1">
            <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
              <span>Never Downloaded</span>
              <AlertCircle className="h-4 w-4 text-amber-500" />
            </div>
            <div className="text-2xl font-black text-amber-700 dark:text-amber-400">
              {metrics.never_downloaded}
            </div>
            <p className="text-[11px] text-slate-400">Reserved Certificate No ready</p>
          </div>
        </div>

        {/* Filters Bar */}
        <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xs space-y-3">
          <div className="flex flex-col md:flex-row items-center gap-3">
            {/* Search Input */}
            <div className="relative flex-1 w-full">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
              <Input
                placeholder="Search by Certificate Number (e.g. HL-2026-00001), candidate name, or code..."
                value={searchInput}
                onChange={(e) => handleSearchChange(e.target.value)}
                className="pl-9 h-10 text-xs rounded-xl"
              />
              {searchInput && (
                <button
                  onClick={() => handleSearchChange("")}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 hover:text-slate-600"
                >
                  Clear
                </button>
              )}
            </div>

            {/* Department Dropdown */}
            <div className="w-full md:w-56 shrink-0">
              <select
                value={selectedDept}
                onChange={(e) => {
                  setSelectedDept(e.target.value);
                  setPage(1);
                }}
                className="w-full h-10 px-3 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500"
              >
                <option value="all">All Departments</option>
                {departments.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </select>
            </div>

            {/* Download Status Tabs */}
            <div className="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200 dark:border-slate-700 w-full md:w-auto shrink-0">
              <button
                type="button"
                onClick={() => {
                  setStatusFilter("all");
                  setPage(1);
                }}
                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${
                  statusFilter === "all"
                    ? "bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 shadow-xs"
                    : "text-slate-500 hover:text-slate-800"
                }`}
              >
                All ({metrics.total_certified})
              </button>
              <button
                type="button"
                onClick={() => {
                  setStatusFilter("downloaded");
                  setPage(1);
                }}
                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${
                  statusFilter === "downloaded"
                    ? "bg-white dark:bg-slate-900 text-emerald-700 dark:text-emerald-400 shadow-xs"
                    : "text-slate-500 hover:text-slate-800"
                }`}
              >
                Downloaded ({metrics.downloaded_users})
              </button>
              <button
                type="button"
                onClick={() => {
                  setStatusFilter("never");
                  setPage(1);
                }}
                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${
                  statusFilter === "never"
                    ? "bg-white dark:bg-slate-900 text-amber-700 dark:text-amber-400 shadow-xs"
                    : "text-slate-500 hover:text-slate-800"
                }`}
              >
                Never ({metrics.never_downloaded})
              </button>
            </div>

            {/* Reset Button */}
            {(debouncedSearch || selectedDept !== "all" || statusFilter !== "all") && (
              <Button
                variant="ghost"
                size="sm"
                onClick={handleResetFilters}
                className="h-10 text-xs font-semibold text-slate-500 hover:text-slate-800 gap-1"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                <span>Reset</span>
              </Button>
            )}
          </div>
        </div>

        {/* Data Table */}
        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-200 dark:border-slate-800 bg-slate-50/75 dark:bg-slate-800/50 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                  <th className="py-3 px-4">Certificate No</th>
                  <th className="py-3 px-4">Employee</th>
                  <th className="py-3 px-4">Emp Code</th>
                  <th className="py-3 px-4">Department</th>
                  <th className="py-3 px-4">Pass Score & Date</th>
                  <th className="py-3 px-4 text-center">Downloads</th>
                  <th className="py-3 px-4">Last Download</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800/80">
                {isLoading ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-slate-400">
                      <div className="flex items-center justify-center gap-2">
                        <Loader2 className="h-5 w-5 animate-spin text-emerald-600" />
                        <span>Loading certificate records...</span>
                      </div>
                    </td>
                  </tr>
                ) : records.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-slate-400">
                      <div className="flex flex-col items-center justify-center gap-1">
                        <Award className="h-8 w-8 text-slate-300 stroke-1" />
                        <span className="font-semibold text-slate-600 dark:text-slate-300">
                          No certified candidates found
                        </span>
                        <span className="text-[11px]">
                          Try adjusting search keywords or filter options.
                        </span>
                      </div>
                    </td>
                  </tr>
                ) : (
                  records.map((r) => {
                    const hasDownloaded = r.download_count > 0;
                    return (
                      <tr
                        key={r.uid}
                        className="hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition-colors group"
                      >
                        {/* Certificate Number (Unique & Reusable) */}
                        <td className="py-3.5 px-4 whitespace-nowrap">
                          <div className="flex items-center gap-1.5">
                            <span className="font-mono text-xs font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 px-2 py-0.5 rounded-lg select-all">
                              {r.certificate_number}
                            </span>
                            <button
                              onClick={() => handleCopy(r.certificate_number)}
                              className="text-slate-400 hover:text-slate-700 p-1 rounded"
                              title="Copy Certificate Number"
                            >
                              {copiedCertNo === r.certificate_number ? (
                                <Check className="h-3.5 w-3.5 text-emerald-600" />
                              ) : (
                                <Copy className="h-3.5 w-3.5" />
                              )}
                            </button>
                          </div>
                        </td>

                        {/* Employee Name & Email */}
                        <td className="py-3.5 px-4 whitespace-nowrap">
                          <div className="flex items-center gap-3">
                            <div className="h-9 w-9 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden shrink-0 flex items-center justify-center font-bold text-slate-600">
                              {r.photo ? (
                                <img
                                  src={r.photo}
                                  alt={r.name}
                                  className="h-full w-full object-cover"
                                  onError={(e) => {
                                    (e.target as HTMLElement).style.display = "none";
                                  }}
                                />
                              ) : (
                                r.name.charAt(0).toUpperCase()
                              )}
                            </div>
                            <div className="min-w-0">
                              <div className="font-bold text-slate-900 dark:text-slate-100 group-hover:text-emerald-600 transition-colors">
                                {r.name}
                              </div>
                              <div className="text-[11px] text-slate-400 font-mono">
                                {r.email}
                              </div>
                            </div>
                          </div>
                        </td>

                        {/* Employee Code */}
                        <td className="py-3.5 px-4 whitespace-nowrap font-mono font-bold text-slate-700 dark:text-slate-300">
                          {r.emp_code}
                        </td>

                        {/* Department */}
                        <td className="py-3.5 px-4 whitespace-nowrap text-slate-600 dark:text-slate-400">
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-[11px] font-medium">
                            <Building2 className="h-3 w-3 text-slate-400" />
                            <span>{r.dept_name}</span>
                          </span>
                        </td>

                        {/* Pass Score & Date */}
                        <td className="py-3.5 px-4 whitespace-nowrap">
                          <div className="space-y-0.5">
                            <div className="inline-flex items-center gap-1 font-bold text-emerald-700 dark:text-emerald-400">
                              <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                              <span>{Math.round(r.quiz_score)}% Score</span>
                              <span className="text-[10px] text-slate-400 font-normal">
                                (Attempt #{r.attempt_number})
                              </span>
                            </div>
                            <div className="text-[11px] text-slate-500 font-mono">
                              {r.passed_date_fmt}
                            </div>
                          </div>
                        </td>

                        {/* Download Count */}
                        <td className="py-3.5 px-4 whitespace-nowrap text-center">
                          {hasDownloaded ? (
                            <button
                              onClick={() => setHistoryRecord(r)}
                              className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 hover:bg-emerald-200 text-emerald-800 dark:bg-emerald-950 dark:hover:bg-emerald-900 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800 cursor-pointer transition-colors shadow-2xs"
                              title="Click to view full download history and all previous timestamps"
                            >
                              <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                              <span>{r.download_count}x</span>
                            </button>
                          ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                              Never
                            </span>
                          )}
                        </td>

                        {/* Last Downloaded At */}
                        <td className="py-3.5 px-4 whitespace-nowrap">
                          {hasDownloaded ? (
                            <div className="flex items-center gap-2">
                              <span className="font-mono text-[11px] text-slate-700 dark:text-slate-300">
                                {r.last_downloaded_fmt || "—"}
                              </span>
                              {r.download_count > 1 && (
                                <button
                                  onClick={() => setHistoryRecord(r)}
                                  className="text-[10px] font-bold text-blue-600 hover:text-blue-800 dark:text-blue-400 hover:underline cursor-pointer inline-flex items-center gap-0.5 bg-blue-50 dark:bg-blue-950/60 px-1.5 py-0.5 rounded border border-blue-200 dark:border-blue-800"
                                  title="Click to view all download timestamps"
                                >
                                  <span>View all {r.download_count}x</span>
                                </button>
                              )}
                            </div>
                          ) : (
                            <span className="font-mono text-[11px] text-slate-400">—</span>
                          )}
                        </td>

                        {/* Actions */}
                        <td className="py-3.5 px-4 whitespace-nowrap text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            {/* Live Preview Button */}
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => handleOpenPreview(r)}
                              className="h-8 px-2.5 rounded-xl border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 hover:text-emerald-600 hover:border-emerald-300 gap-1 shadow-2xs"
                              title="Live On-Screen Certificate Preview"
                            >
                              <Eye className="h-3.5 w-3.5 text-slate-500" />
                              <span>Preview</span>
                            </Button>

                            {/* Download Button */}
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={downloadingUid === r.uid}
                              onClick={() => handleDownload(r)}
                              className="h-8 px-2.5 rounded-xl border-emerald-300 text-xs font-bold text-emerald-800 bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-400 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800 gap-1.5 shadow-2xs"
                              title="Download Official PDF Certificate (Reuses permanent Cert No)"
                            >
                              {downloadingUid === r.uid ? (
                                <Loader2 className="h-3.5 w-3.5 animate-spin text-emerald-600" />
                              ) : (
                                <Download className="h-3.5 w-3.5 text-emerald-600" />
                              )}
                              <span>Download</span>
                            </Button>
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
          {pagination.total > 0 && (
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 border-t border-slate-100 dark:border-slate-800 text-xs">
              <div className="text-slate-500 font-medium">
                Showing{" "}
                <span className="font-bold text-slate-800 dark:text-slate-200">
                  {(pagination.page - 1) * pagination.limit + 1}
                </span>{" "}
                to{" "}
                <span className="font-bold text-slate-800 dark:text-slate-200">
                  {Math.min(pagination.page * pagination.limit, pagination.total)}
                </span>{" "}
                of{" "}
                <span className="font-bold text-slate-800 dark:text-slate-200">
                  {pagination.total}
                </span>{" "}
                certified candidates
              </div>

              {pagination.totalPages > 1 && (
                <div className="flex items-center gap-1.5">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={!pagination.hasPrev || isFetching}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    className="h-8 px-2.5 rounded-lg border-slate-200 gap-1 disabled:opacity-40"
                  >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    <span>Previous</span>
                  </Button>
                  <span className="px-3 py-1 font-mono text-xs text-slate-600 dark:text-slate-400">
                    Page {pagination.page} / {pagination.totalPages}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={!pagination.hasNext || isFetching}
                    onClick={() => setPage((p) => p + 1)}
                    className="h-8 px-2.5 rounded-lg border-slate-200 gap-1 disabled:opacity-40"
                  >
                    <span>Next</span>
                    <ChevronRight className="h-3.5 w-3.5" />
                  </Button>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Real-time Certificate Live Preview Modal */}
        <Dialog
          open={Boolean(previewRecord)}
          onOpenChange={(open) => {
            if (!open) {
              setPreviewRecord(null);
              setPreviewImageUrl(null);
            }
          }}
        >
          <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto p-6 rounded-2xl">
            <DialogHeader className="space-y-1">
              <div className="flex items-center gap-2">
                <span className="p-1.5 bg-emerald-100 text-emerald-800 rounded-lg">
                  <Award className="h-5 w-5" />
                </span>
                <DialogTitle className="text-lg font-black">
                  Certificate Preview — {previewRecord?.name}
                </DialogTitle>
              </div>
              <DialogDescription className="text-xs">
                Official HexaLearn Induction Assessment Certificate with dynamic candidate name, Employee ID (
                {previewRecord?.emp_code}), Certificate No (<strong>{previewRecord?.certificate_number}</strong>), and completion date ({previewRecord?.passed_date_fmt}).
              </DialogDescription>
            </DialogHeader>

            {/* Preview Body */}
            <div className="my-3 flex flex-col items-center justify-center min-h-[300px] bg-slate-950/5 dark:bg-slate-900 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
              {generatingPreview ? (
                <div className="flex flex-col items-center justify-center gap-2 py-16">
                  <Loader2 className="h-8 w-8 animate-spin text-emerald-600" />
                  <span className="text-xs font-semibold text-slate-500">
                    Rendering high-resolution 300 DPI preview...
                  </span>
                </div>
              ) : previewImageUrl ? (
                <div className="w-full flex items-center justify-center overflow-hidden rounded-lg shadow-lg border border-slate-200 dark:border-slate-700">
                  <img
                    src={previewImageUrl}
                    alt={`Certificate for ${previewRecord?.name}`}
                    className="w-full h-auto object-contain block max-h-[60vh] select-none"
                  />
                </div>
              ) : (
                <div className="text-xs text-rose-500 py-12">
                  Failed to load certificate preview.
                </div>
              )}
            </div>

            {/* Modal Footer */}
            <DialogFooter className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
              <div className="text-xs text-slate-500 flex flex-wrap items-center gap-2">
                <span>Cert No:</span>
                <Badge variant="outline" className="font-mono font-bold text-emerald-800 bg-emerald-50">
                  {previewRecord?.certificate_number}
                </Badge>
                <span>Total Downloads:</span>
                <Badge variant="outline" className="font-bold text-emerald-700 bg-emerald-50">
                  {previewRecord?.download_count || 0}x
                </Badge>
                {previewRecord?.last_downloaded_fmt && (
                  <span className="text-slate-400 font-mono text-[11px]">
                    (Last: {previewRecord.last_downloaded_fmt})
                  </span>
                )}
              </div>

              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  onClick={() => {
                    setPreviewRecord(null);
                    setPreviewImageUrl(null);
                  }}
                  className="text-xs"
                >
                  Close
                </Button>
                {previewRecord && (
                  <Button
                    onClick={() => handleDownload(previewRecord)}
                    disabled={downloadingUid === previewRecord.uid}
                    className="bg-emerald-600 hover:bg-emerald-700 text-white gap-1.5 text-xs font-bold"
                  >
                    {downloadingUid === previewRecord.uid ? (
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    ) : (
                      <Download className="h-3.5 w-3.5" />
                    )}
                    <span>Download Official PDF</span>
                  </Button>
                )}
              </div>
            </DialogFooter>
          </DialogContent>
        </Dialog>
        {/* Full Download History Audit Log Modal */}
        <Dialog open={!!historyRecord} onOpenChange={(open) => !open && setHistoryRecord(null)}>
          <DialogContent className="max-w-xl p-6 rounded-2xl">
            <DialogHeader className="space-y-1">
              <div className="flex items-center gap-2">
                <span className="p-1.5 bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300 rounded-lg">
                  <History className="h-5 w-5" />
                </span>
                <DialogTitle className="text-lg font-black">
                  Certificate Download History & Audit Log
                </DialogTitle>
              </div>
              <DialogDescription className="text-xs">
                Detailed chronological log of every time this candidate's certificate was generated and downloaded.
              </DialogDescription>
            </DialogHeader>

            {historyRecord && (
              <div className="space-y-4 my-2">
                {/* Candidate Overview Card */}
                <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <div className="font-bold text-slate-900 dark:text-white text-sm">
                      {historyRecord.name}
                    </div>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
                      <span className="font-mono font-semibold">{historyRecord.emp_code}</span>
                      <span>•</span>
                      <span>{historyRecord.dept_name}</span>
                    </div>
                  </div>
                  <div className="text-right shrink-0">
                    <span className="font-mono text-xs font-bold bg-emerald-50 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 px-2 py-0.5 rounded-md block">
                      {historyRecord.certificate_number}
                    </span>
                    <span className="text-[11px] font-bold text-slate-600 dark:text-slate-400 mt-1 block">
                      Total: {historyRecord.download_count} download{historyRecord.download_count > 1 ? "s" : ""}
                    </span>
                  </div>
                </div>

                {/* Download Timeline / Table */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-100/70 dark:bg-slate-800/60 text-[11px] font-bold uppercase text-muted-foreground">
                      <tr>
                        <th className="px-3.5 py-2.5">#</th>
                        <th className="px-3.5 py-2.5">Date & Time (IST)</th>
                        <th className="px-3.5 py-2.5">Downloaded By</th>
                        <th className="px-3.5 py-2.5 text-right">IP Address</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {(!historyRecord.download_history || historyRecord.download_history.length === 0) ? (
                        <tr>
                          <td colSpan={4} className="py-6 text-center text-muted-foreground">
                            No download records found.
                          </td>
                        </tr>
                      ) : (
                        historyRecord.download_history.map((dl, idx) => (
                          <tr key={dl.id || idx} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td className="px-3.5 py-2.5 font-bold">
                              <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold ${
                                idx === 0
                                  ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                                  : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                              }`}>
                                #{dl.download_number} {idx === 0 ? "(Latest)" : ""}
                              </span>
                            </td>
                            <td className="px-3.5 py-2.5 font-mono font-medium text-slate-900 dark:text-white">
                              {dl.downloaded_at_fmt}
                            </td>
                            <td className="px-3.5 py-2.5">
                              <span className={`inline-flex items-center gap-1 font-semibold text-[11px] ${
                                dl.is_self ? "text-slate-700 dark:text-slate-300" : "text-purple-700 dark:text-purple-400 font-bold"
                              }`}>
                                {dl.downloaded_by_name}
                              </span>
                            </td>
                            <td className="px-3.5 py-2.5 text-right font-mono text-[11px] text-muted-foreground">
                              {dl.ip_address || "—"}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            <DialogFooter className="pt-2 border-t border-slate-100 dark:border-slate-800">
              <Button variant="outline" size="sm" onClick={() => setHistoryRecord(null)} className="text-xs">
                Close
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </Layout>
  );
}
