import { useEffect, useState, useMemo, useCallback } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Users,
  BarChart3,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  LayoutGrid,
  List,
  Mail,
  Calendar,
  CheckCircle2,
  Search,
  Loader2,
  Unlock,
  Lock,
  Clock,
  Building2,
  Percent,
  Filter,
  RotateCcw,
  Award,
  X,
  Download,
  Check,
  XCircle
} from "lucide-react";
import { apiGet } from "@/lib/api";
import { useQuery } from "@tanstack/react-query";
import { useToast } from "@/components/ui/use-toast";
import { AdminCourseAccessModal } from "@/components/AdminCourseAccessModal";
import { AdminExtendDeadlineModal } from "@/components/AdminExtendDeadlineModal";
import { downloadCertificatePdf } from "@/lib/certificateGenerator";

interface AdminEmployeesResponse {
  success: boolean;
  employees: any[];
  totalModules: number;
  pagination: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
    hasPrev: boolean;
    hasNext: boolean;
  };
  summary: {
    totalStaff: number;
    moodleEnrolled: number;
    notEnrolled: number;
    completed?: number;
    certified?: number;
    deadlineLocked?: number;
    certificatesDownloaded?: number;
    enrolledBreakdown?: {
      all: number;
      courses_done: number;
      courses_incomplete: number;
      assess_passed: number;
      assess_failed: number;
      assess_never: number;
    };
    completedBreakdown?: {
      all: number;
      assess_passed: number;
      assess_failed: number;
      assess_never: number;
    };
    lockedBreakdown?: {
      all: number;
      courses_done: number;
      courses_incomplete: number;
      assess_attempted: number;
      assess_never: number;
    };
    departmentBreakdown?: Array<{
      id: number;
      name: string;
      count: number;
    }>;
    currentEnrolled?: number;
    exEnrolled?: number;
    empType?: string;
  };
  activeFilters?: {
    q?: string;
    dept?: number;
    status?: string;
    compRange?: string;
    compMin?: number;
    compMax?: number;
  };
}

interface DepartmentItem {
  id: number;
  name: string;
  employee_count: number;
}

/** Round circular SVG progress bar for grid cards */
const CircularProgress = ({
  percent,
  size = 52,
  strokeWidth = 4.5,
}: {
  percent: number;
  size?: number;
  strokeWidth?: number;
}) => {
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  const validPercent = Math.min(100, Math.max(0, percent));
  const strokeDashoffset = circumference - (validPercent / 100) * circumference;

  return (
    <div className="relative flex items-center justify-center shrink-0" style={{ width: size, height: size }}>
      <svg
        className="-rotate-90 origin-center"
        width={size}
        height={size}
        viewBox={`0 0 ${size} ${size}`}
      >
        {/* Background Track Circle */}
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="transparent"
          stroke="#E2E8F0"
          strokeWidth={strokeWidth}
        />
        {/* Colored Progress Arc */}
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="transparent"
          stroke={validPercent === 100 ? "#00A389" : validPercent > 0 ? "#00A389" : "#CBD5E1"}
          strokeWidth={strokeWidth}
          strokeDasharray={circumference}
          strokeDashoffset={strokeDashoffset}
          strokeLinecap="round"
          className="transition-all duration-500 ease-out"
        />
      </svg>
      {/* Centered Percentage Value */}
      <div className="absolute inset-0 flex items-center justify-center text-center">
        {validPercent === 100 ? (
          <CheckCircle2 className="h-4 w-4 text-[#00A389]" />
        ) : (
          <span className={`text-[11px] font-black leading-none ${validPercent > 0 ? "text-[#00A389]" : "text-slate-400"}`}>
            {validPercent}%
          </span>
        )}
      </div>
    </div>
  );
};

const AdminEmployees = () => {
  const [searchParams] = useSearchParams();
  const targetUidParam = searchParams.get('uid');
  const navigate = useNavigate();
  const { toast } = useToast();

  // Core Pagination State
  const [page, setPage] = useState(1);
  const [limit] = useState(10);

  // Filter States - Unified Admin Management
  const [filterTab, setFilterTab] = useState<'all' | 'enrolled' | 'completed' | 'certified' | 'locked' | 'downloaded' | 'not_enrolled'>('all');
  const [empType, setEmpType] = useState<'current' | 'ex'>('current');
  const [selectedDept, setSelectedDept] = useState<string>('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'not_started' | 'in_progress' | 'completed' | 'not_enrolled'>('all');
  const [subFilter, setSubFilter] = useState<string>('all');
  const [assessmentFilter, setAssessmentFilter] = useState<'all' | 'passed' | 'attempted' | 'never_attempted'>('all');
  const [compRange, setCompRange] = useState<string>('all');
  const [customMin, setCustomMin] = useState<string>('');
  const [customMax, setCustomMax] = useState<string>('');

  // Instant Typing-Based Search with 350ms Debounce
  const [searchInput, setSearchInput] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearch(searchInput.trim());
    }, 350);
    return () => clearTimeout(handler);
  }, [searchInput]);

  // Reset page to 1 whenever debounced search or filters change
  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, selectedDept, statusFilter, compRange, customMin, customMax, empType, filterTab, subFilter, assessmentFilter]);

  // Modal and View Mode States
  const [accessModalEmployee, setAccessModalEmployee] = useState<any | null>(null);
  const [extendModalEmployee, setExtendModalEmployee] = useState<any | null>(null);
  const [downloadingCertUid, setDownloadingCertUid] = useState<number | null>(null);

  const handleDownloadCert = async (emp: any) => {
    setDownloadingCertUid(emp.uid);
    try {
      await downloadCertificatePdf({
        name: emp.name,
        empCode: emp.emp_code || `HL${emp.uid}`,
        dateStr: emp.passed_date_fmt || emp.assessment?.passed_date_fmt || 'November 2026',
        certNumber: emp.certificate?.certificate_number,
      });
      toast({
        title: "Certificate Downloaded",
        description: `Official certificate for ${emp.name} downloaded successfully.`,
      });
    } catch (err: any) {
      toast({
        title: "Download Failed",
        description: err?.message || "Failed to download certificate.",
        variant: "destructive",
      });
    } finally {
      setDownloadingCertUid(null);
    }
  };
  const [viewMode, setViewMode] = useState<'grid' | 'table'>(() => {
    const saved = localStorage.getItem('admin_employee_view_mode_v2');
    if (saved === 'grid' || saved === 'table') return saved;
    return 'table';
  });

  const handleViewModeChange = (mode: 'grid' | 'table') => {
    setViewMode(mode);
    try {
      localStorage.setItem('admin_employee_view_mode_v2', mode);
      localStorage.setItem('admin_employee_view_mode', mode);
    } catch {
      // storage unavailable
    }
  };

  // Fetch Dynamic Departments
  const { data: deptData } = useQuery<{ success: boolean; departments: DepartmentItem[] }>({
    queryKey: ['admin_departments_list'],
    queryFn: () => apiGet<{ success: boolean; departments: DepartmentItem[] }>('/admin/list_departments.php'),
    staleTime: 10 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
  });
  const departments = useMemo(() => deptData?.departments || [], [deptData]);

  // Build Filtered Request URL
  const queryUrl = useMemo(() => {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('limit', String(limit));
    params.set('filter', filterTab);
    params.set('emp_type', empType);

    if (debouncedSearch) {
      params.set('q', debouncedSearch);
    }
    if (selectedDept !== 'all' && selectedDept !== '') {
      params.set('dept', selectedDept);
    }
    if (statusFilter !== 'all') {
      params.set('status_filter', statusFilter);
    }
    if (subFilter !== 'all') {
      params.set('subfilter', subFilter);
      if (filterTab === 'locked') {
        params.set('locked_subfilter', subFilter);
      }
    }
    if (assessmentFilter !== 'all') {
      params.set('assessment_status', assessmentFilter);
    }
    if (compRange !== 'all') {
      params.set('comp_range', compRange);
      if (compRange === 'custom') {
        if (customMin !== '') params.set('comp_min', customMin);
        if (customMax !== '') params.set('comp_max', customMax);
      }
    }

    return `/admin/employees.php?${params.toString()}`;
  }, [page, limit, filterTab, empType, debouncedSearch, selectedDept, statusFilter, subFilter, assessmentFilter, compRange, customMin, customMax]);

  // Server-Side Query Execution
  const { data, isLoading: loading, isFetching, refetch } = useQuery<AdminEmployeesResponse>({
    queryKey: ['admin_employees', queryUrl],
    queryFn: () => apiGet<AdminEmployeesResponse>(queryUrl),
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    placeholderData: (previousData) => previousData,
  });

  const isInitialLoading = Boolean(loading && !data);
  const rows = useMemo(() => data?.employees || [], [data]);
  const totalModules = data?.totalModules || 3;

  const pagination = useMemo(() => data?.pagination || {
    page: 1,
    limit: 10,
    total: 0,
    totalPages: 1,
    hasPrev: false,
    hasNext: false
  }, [data]);

  const summary = useMemo(() => data?.summary || {
    totalStaff: 0,
    moodleEnrolled: 0,
    notEnrolled: 0,
    completed: 0
  }, [data]);

  const completedCount = useMemo(() => {
    if (summary.completed !== undefined) return summary.completed;
    return rows.filter(r => (r.progress?.percent || 0) === 100).length;
  }, [summary, rows]);

  // Selected Department Label
  const selectedDeptLabel = useMemo(() => {
    if (selectedDept === 'all') return 'All Departments';
    const match = departments.find(d => String(d.id) === String(selectedDept));
    return match ? match.name : `Dept #${selectedDept}`;
  }, [selectedDept, departments]);

  // Synchronized Status Dropdown Value
  const currentStatusDropdownValue = useMemo(() => {
    if (subFilter === 'courses_incomplete') return 'courses_incomplete';
    if (subFilter === 'assess_never') return 'assess_never';
    if (filterTab === 'completed' || subFilter === 'courses_done') return 'courses_done';
    if (filterTab === 'certified' || subFilter === 'assess_passed') return 'certified';
    if (filterTab === 'locked' || subFilter === 'locked') return 'locked';
    if (filterTab === 'not_enrolled' || subFilter === 'not_enrolled') return 'not_enrolled';
    if (filterTab === 'enrolled') return 'enrolled';
    return 'all';
  }, [filterTab, subFilter]);

  // Check if any non-default filters are active
  const hasActiveFilters = useMemo(() => {
    return Boolean(
      debouncedSearch !== '' ||
      searchInput.trim() !== '' ||
      selectedDept !== 'all' ||
      statusFilter !== 'all' ||
      subFilter !== 'all' ||
      assessmentFilter !== 'all' ||
      compRange !== 'all' ||
      customMin !== '' ||
      customMax !== '' ||
      filterTab !== 'all' ||
      empType !== 'current'
    );
  }, [debouncedSearch, searchInput, selectedDept, statusFilter, subFilter, assessmentFilter, compRange, customMin, customMax, filterTab, empType]);

  const handleClearAllFilters = () => {
    setSearchInput('');
    setDebouncedSearch('');
    setSelectedDept('all');
    setStatusFilter('all');
    setSubFilter('all');
    setAssessmentFilter('all');
    setCompRange('all');
    setCustomMin('');
    setCustomMax('');
    setFilterTab('all');
    setEmpType('current');
    setPage(1);
  };

  const openLmsReport = (emp: any) => {
    navigate(`/admin/employees/${emp.uid}/report`);
  };

  useEffect(() => {
    if (targetUidParam && rows.length > 0) {
      const match = rows.find(r => String(r.uid) === String(targetUidParam));
      if (match) {
        openLmsReport(match);
      }
    }
  }, [targetUidParam, rows]);

  useEffect(() => {
    window.scrollTo({ top: 0, left: 0, behavior: "instant" });
    if (document.documentElement) document.documentElement.scrollTop = 0;
    if (document.body) document.body.scrollTop = 0;
  }, [page]);

  const handleFilterCardClick = (newFilter: 'all' | 'enrolled' | 'completed' | 'certified' | 'locked' | 'downloaded' | 'not_enrolled') => {
    if (filterTab === newFilter) {
      setFilterTab('all');
    } else {
      setFilterTab(newFilter);
    }
    setSubFilter('all');
    if (newFilter === 'not_enrolled') {
      setEmpType('current');
      setStatusFilter('all');
    }
    setPage(1);
  };

  return (
    <Layout isAdmin>
      <div className="space-y-6 pb-12">
        {/* Header Card */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-2xs space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div className="flex items-center gap-3.5">
              <div className="h-11 w-11 rounded-2xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0 shadow-2xs">
                <Users className="h-5 w-5" />
              </div>
              <div>
                <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                  Employee Learning &amp; Progress
                </h1>
                <p className="text-xs text-slate-400 font-medium mt-0.5">
                  Centralized Onboarding, Assessment Results, Deadlines &amp; Certificate Management (10 per page)
                </p>
              </div>
            </div>
          </div>

          {/* 6 High-Impact KPI Metric Cards - Click any card to filter */}
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 pt-3 border-t border-slate-100">
            {/* 1. Total Employees */}
            <button
              type="button"
              onClick={() => handleFilterCardClick('all')}
              className={`p-3.5 rounded-2xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${filterTab === 'all'
                ? 'border-slate-800 bg-white ring-2 ring-slate-800/20 shadow-xs'
                : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
              title="Show all company roster"
            >
              <div className="flex items-center justify-between">
                <span className={`text-[10px] font-black uppercase tracking-wider ${filterTab === 'all' ? 'text-slate-900' : 'text-slate-500'}`}>
                  Total Employees
                </span>
                <span className="text-[9px] font-bold text-slate-500 px-1.5 py-0.5 rounded bg-slate-100">
                  All
                </span>
              </div>
              <div className="text-2xl font-black text-slate-800 mt-1">
                {isInitialLoading ? "..." : summary.totalStaff}
              </div>
              <div className="text-[11px] text-slate-500 font-medium mt-0.5 flex items-center justify-between">
                <span>Company roster</span>
                <span className="text-[10px] font-bold text-slate-500 group-hover:translate-x-0.5 transition-transform">Filter →</span>
              </div>
            </button>

            {/* 2. Enrolled Employees */}
            <button
              type="button"
              onClick={() => handleFilterCardClick('enrolled')}
              className={`p-3.5 rounded-2xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${filterTab === 'enrolled'
                ? 'border-[#00A389] bg-white ring-2 ring-[#00A389]/25 shadow-xs'
                : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
              title="Active Moodle LMS Onboarding Employees"
            >
              <div className="flex items-center justify-between">
                <span className={`text-[10px] font-black uppercase tracking-wider ${filterTab === 'enrolled' ? 'text-emerald-800' : 'text-slate-500'}`}>
                  Enrolled
                </span>
                <span className="inline-flex items-center gap-1 text-[9px] font-bold text-emerald-700 bg-emerald-100/80 px-1.5 py-0.5 rounded-full border border-emerald-200">
                  <span className="h-1.5 w-1.5 rounded-full bg-[#00A389] animate-pulse" />
                  Active
                </span>
              </div>
              <div className="text-2xl font-black text-[#00A389] mt-1">
                {isInitialLoading ? "..." : summary.moodleEnrolled}
              </div>
              <div className="text-[11px] text-emerald-700 font-medium mt-0.5 flex items-center justify-between">
                <span>Active in LMS</span>
                <span className="text-[10px] font-bold text-emerald-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
              </div>
            </button>

            {/* 3. Completed Courses */}
            <button
              type="button"
              onClick={() => handleFilterCardClick('completed')}
              className={`p-3.5 rounded-2xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${filterTab === 'completed'
                ? 'border-emerald-600 bg-white ring-2 ring-emerald-600/25'
                : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
              title="Employees who finished 10 of 10 courses"
            >
              <div className="flex items-center justify-between">
                <span className={`text-[10px] font-black uppercase tracking-wider ${filterTab === 'completed' ? 'text-emerald-800' : 'text-slate-500'}`}>
                  Courses Done
                </span>
                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
              </div>
              <div className="text-2xl font-black text-slate-800 mt-1">
                {isInitialLoading ? "..." : (summary.completed ?? completedCount)}
              </div>
              <div className="text-[11px] text-slate-500 font-medium mt-0.5 flex items-center justify-between">
                <span>10 of 10 finished</span>
                <span className="text-[10px] font-bold text-emerald-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
              </div>
            </button>

            {/* 4. Certified (Passed Induction Assessment) */}
            <button
              type="button"
              onClick={() => handleFilterCardClick('certified')}
              className={`p-3.5 rounded-2xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${filterTab === 'certified'
                ? 'border-teal-600 bg-white ring-2 ring-teal-600/25'
                : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
              title="Passed Induction Assessment (Certified)"
            >
              <div className="flex items-center justify-between">
                <span className={`text-[10px] font-black uppercase tracking-wider ${filterTab === 'certified' ? 'text-teal-800' : 'text-slate-500'}`}>
                  Certified
                </span>
                <Award className="h-3.5 w-3.5 text-teal-600" />
              </div>
              <div className="text-2xl font-black text-teal-700 mt-1">
                {isInitialLoading ? "..." : (summary.certified ?? 0)}
              </div>
              <div className="text-[11px] text-teal-700 font-medium mt-0.5 flex items-center justify-between">
                <span>Passed assessment</span>
                <span className="text-[10px] font-bold text-teal-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
              </div>
            </button>

            {/* 5. Deadline Locked */}
            <button
              type="button"
              onClick={() => handleFilterCardClick('locked')}
              className={`p-3.5 rounded-2xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${filterTab === 'locked'
                ? 'border-rose-600 bg-white ring-2 ring-rose-600/25'
                : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
              title="Cutoff expired without passing"
            >
              <div className="flex items-center justify-between">
                <span className={`text-[10px] font-black uppercase tracking-wider ${filterTab === 'locked' ? 'text-rose-800' : 'text-slate-500'}`}>
                  Deadline Locked
                </span>
                <Lock className="h-3.5 w-3.5 text-rose-600" />
              </div>
              <div className="text-2xl font-black text-rose-700 mt-1">
                {isInitialLoading ? "..." : (summary.deadlineLocked ?? 0)}
              </div>
              <div className="text-[11px] text-rose-600 font-medium mt-0.5 flex items-center justify-between">
                <span>Cutoff expired</span>
                <span className="text-[10px] font-bold text-rose-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
              </div>
            </button>

            {/* 6. Certificates Downloaded */}
            <button
              type="button"
              onClick={() => handleFilterCardClick('downloaded')}
              className={`p-3.5 rounded-2xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${filterTab === 'downloaded'
                ? 'border-blue-600 bg-white ring-2 ring-blue-600/25'
                : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
              title="Official PDF certificates downloaded"
            >
              <div className="flex items-center justify-between">
                <span className={`text-[10px] font-black uppercase tracking-wider ${filterTab === 'downloaded' ? 'text-blue-800' : 'text-slate-500'}`}>
                  Cert Downloaded
                </span>
                <Download className="h-3.5 w-3.5 text-blue-600" />
              </div>
              <div className="text-2xl font-black text-blue-700 mt-1">
                {isInitialLoading ? "..." : (summary.certificatesDownloaded ?? 0)}
              </div>
              <div className="text-[11px] text-blue-600 font-medium mt-0.5 flex items-center justify-between">
                <span>PDF issued</span>
                <span className="text-[10px] font-bold text-blue-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
              </div>
            </button>
          </div>
        </div>

        {/* =========================================================================
            ADVANCED MULTI-DIMENSIONAL FILTER TOOLBAR
            Instant Typing Search (Debounced 350ms, no Search button)
            Department Dropdown (dynamic from DB)
            Onboarding Status Dropdown
            Completion % Dropdown (preset + custom min/max)
           ========================================================================= */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            {/* 1. Instant Typing Search */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center justify-between">
                <span>Search Employee</span>
                {searchInput !== debouncedSearch && (
                  <span className="text-[10px] text-[#00A389] flex items-center gap-1 font-normal">
                    <Loader2 className="h-3 w-3 animate-spin" /> Typing...
                  </span>
                )}
              </label>
              <div className="relative">
                <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none" />
                <Input
                  className="pl-10 pr-9 h-10 rounded-xl border-slate-200/90 text-xs bg-slate-50/50 focus:bg-white focus-visible:ring-[#00A389]"
                  placeholder="Name, email, emp code, ID..."
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                />
                {searchInput && (
                  <button
                    type="button"
                    onClick={() => {
                      setSearchInput('');
                      setDebouncedSearch('');
                      setPage(1);
                    }}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 cursor-pointer transition-colors"
                    title="Clear search"
                  >
                    <X className="h-3.5 w-3.5" />
                  </button>
                )}
              </div>
            </div>

            {/* 2. Department Dropdown with Dynamic Counts */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                <Building2 className="h-3 w-3 text-slate-400" />
                <span>Department</span>
              </label>
              <div className="relative">
                <select
                  value={selectedDept}
                  onChange={(e) => {
                    setSelectedDept(e.target.value);
                    setPage(1);
                  }}
                  className="w-full h-10 px-3 pr-8 rounded-xl border border-slate-200/90 text-xs font-semibold bg-slate-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/20 focus:border-[#00A389] cursor-pointer text-slate-700 transition-all appearance-none"
                >
                  <option value="all">
                    All Departments ({summary.departmentBreakdown ? summary.departmentBreakdown.reduce((acc, d) => acc + (d.count || 0), 0) : departments.reduce((acc, d) => acc + (d.employee_count || 0), 0)})
                  </option>
                  {(summary.departmentBreakdown && summary.departmentBreakdown.length > 0
                    ? summary.departmentBreakdown
                    : departments.map(d => ({ id: d.id, name: d.name, count: d.employee_count }))
                  ).map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name} ({d.count})
                    </option>
                  ))}
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400 pointer-events-none" />
              </div>
            </div>

            {/* 3. Onboarding Status Dropdown with Live Company Roster Counts */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                <CheckCircle2 className="h-3 w-3 text-slate-400" />
                <span>Onboarding Status</span>
              </label>
              <div className="relative">
                <select
                  value={currentStatusDropdownValue}
                  onChange={(e) => {
                    const val = e.target.value;
                    setPage(1);
                    if (val === 'all') {
                      setFilterTab('all');
                      setSubFilter('all');
                    } else if (val === 'enrolled') {
                      setFilterTab('enrolled');
                      setSubFilter('all');
                    } else if (val === 'courses_done') {
                      setFilterTab('completed');
                      setSubFilter('courses_done');
                    } else if (val === 'courses_incomplete') {
                      setFilterTab('enrolled');
                      setSubFilter('courses_incomplete');
                    } else if (val === 'certified') {
                      setFilterTab('certified');
                      setSubFilter('assess_passed');
                    } else if (val === 'assess_never') {
                      setFilterTab('enrolled');
                      setSubFilter('assess_never');
                    } else if (val === 'locked') {
                      setFilterTab('locked');
                      setSubFilter('all');
                    } else if (val === 'not_enrolled') {
                      setFilterTab('not_enrolled');
                      setSubFilter('all');
                      setEmpType('current');
                    }
                  }}
                  className="w-full h-10 px-3 pr-8 rounded-xl border border-slate-200/90 text-xs font-semibold bg-slate-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/20 focus:border-[#00A389] cursor-pointer text-slate-700 transition-all appearance-none"
                >
                  <option value="all">All Staff ({summary.totalStaff ?? 50})</option>
                  <option value="enrolled">All Enrolled ({summary.enrolledBreakdown?.all ?? summary.moodleEnrolled ?? 48})</option>
                  <option value="courses_done">100% Courses Done ({summary.enrolledBreakdown?.courses_done ?? summary.completed ?? 45})</option>
                  <option value="courses_incomplete">Courses Incomplete ({summary.enrolledBreakdown?.courses_incomplete ?? 3})</option>
                  <option value="certified">Assessment Passed ({summary.enrolledBreakdown?.assess_passed ?? summary.certified ?? 36} Certified)</option>
                  <option value="assess_never">Never Attempted Assessment ({summary.enrolledBreakdown?.assess_never ?? 11})</option>
                  <option value="locked">Deadline Locked ({summary.lockedBreakdown?.all ?? summary.deadlineLocked ?? 12})</option>
                  <option value="not_enrolled">Not Enrolled ({summary.notEnrolled ?? 2})</option>
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400 pointer-events-none" />
              </div>
            </div>

            {/* 4. Assessment Status Dropdown */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                <Award className="h-3 w-3 text-slate-400" />
                <span>Assessment Status</span>
              </label>
              <div className="relative">
                <select
                  value={assessmentFilter}
                  onChange={(e) => {
                    setAssessmentFilter(e.target.value as any);
                    setPage(1);
                  }}
                  className="w-full h-10 px-3 pr-8 rounded-xl border border-slate-200/90 text-xs font-semibold bg-slate-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/20 focus:border-[#00A389] cursor-pointer text-slate-700 transition-all appearance-none"
                >
                  <option value="all">All Assessments</option>
                  <option value="passed">Passed (Certified)</option>
                  <option value="attempted">Attempted (Failed)</option>
                  <option value="never_attempted">Never Attempted</option>
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400 pointer-events-none" />
              </div>
            </div>

            {/* 5. Completion % Dropdown */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                <Percent className="h-3 w-3 text-slate-400" />
                <span>Completion %</span>
              </label>
              <div className="relative">
                <select
                  value={compRange}
                  onChange={(e) => {
                    setCompRange(e.target.value);
                    setPage(1);
                  }}
                  className="w-full h-10 px-3 pr-8 rounded-xl border border-slate-200/90 text-xs font-semibold bg-slate-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/20 focus:border-[#00A389] cursor-pointer text-slate-700 transition-all appearance-none"
                >
                  <option value="all">All Progress (0–100%)</option>
                  <option value="0">0% (Not Started)</option>
                  <option value="1-25">1% – 25%</option>
                  <option value="26-50">26% – 50%</option>
                  <option value="51-75">51% – 75%</option>
                  <option value="76-99">76% – 99%</option>
                  <option value="100">100% (Completed)</option>
                  <option value="custom">Custom Range...</option>
                </select>
                <ChevronDown className="absolute right-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400 pointer-events-none" />
              </div>
            </div>
          </div>

          {/* Optional Custom Min/Max Completion % Range */}
          {compRange === 'custom' && (
            <div className="flex items-center gap-3 pt-3 border-t border-slate-100 flex-wrap">
              <span className="text-xs font-bold text-slate-600">Custom Completion Range:</span>
              <div className="flex items-center gap-2">
                <Input
                  type="number"
                  min="0"
                  max="100"
                  placeholder="Min %"
                  value={customMin}
                  onChange={(e) => { setCustomMin(e.target.value); setPage(1); }}
                  className="w-24 h-9 rounded-lg text-xs"
                />
                <span className="text-slate-400 text-xs">to</span>
                <Input
                  type="number"
                  min="0"
                  max="100"
                  placeholder="Max %"
                  value={customMax}
                  onChange={(e) => { setCustomMax(e.target.value); setPage(1); }}
                  className="w-24 h-9 rounded-lg text-xs"
                />
                <span className="text-slate-400 text-xs">%</span>
              </div>
            </div>
          )}

          {/* Active Filter Pills Bar */}
          {hasActiveFilters && (
            <div className="flex items-center justify-between gap-3 pt-3 border-t border-slate-100 flex-wrap">
              <div className="flex items-center gap-1.5 flex-wrap">
                <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1 pr-1">
                  <Filter className="h-3 w-3 text-slate-400" />
                  Active Filters:
                </span>

                {/* Search query pill */}
                {debouncedSearch && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-[#E6F7F4] text-[#00A389] border border-[#00A389]/30">
                    <span>Search: &ldquo;{debouncedSearch}&rdquo;</span>
                    <button
                      type="button"
                      onClick={() => {
                        setSearchInput('');
                        setDebouncedSearch('');
                        setPage(1);
                      }}
                      className="hover:text-emerald-900 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Department pill */}
                {selectedDept !== 'all' && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                    <span>Dept: {selectedDeptLabel}</span>
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedDept('all');
                        setPage(1);
                      }}
                      className="hover:text-blue-900 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Status pill */}
                {statusFilter !== 'all' && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-50 text-purple-700 border border-purple-200">
                    <span>Status: {statusFilter.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</span>
                    <button
                      type="button"
                      onClick={() => {
                        setStatusFilter('all');
                        setPage(1);
                      }}
                      className="hover:text-purple-900 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Assessment Status pill */}
                {assessmentFilter !== 'all' && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-teal-50 text-teal-700 border border-teal-200">
                    <span>
                      Assessment: {assessmentFilter === 'passed' ? 'Passed (Certified)' : assessmentFilter === 'attempted' ? 'Attempted (Failed)' : 'Never Attempted'}
                    </span>
                    <button
                      type="button"
                      onClick={() => {
                        setAssessmentFilter('all');
                        setPage(1);
                      }}
                      className="hover:text-teal-900 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Active Sub-filter pill */}
                {subFilter !== 'all' && (
                  <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold border ${filterTab === 'locked' || subFilter === 'locked' ? 'bg-rose-50 text-rose-700 border-rose-200' : filterTab === 'completed' ? 'bg-teal-50 text-teal-700 border-teal-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200'}`}>
                    <span>
                      Sub-Filter: {subFilter === 'enrolled' ? 'Enrolled in LMS' : subFilter === 'not_enrolled' ? 'Not Enrolled' : subFilter === 'courses_done' ? '100% Courses Done' : subFilter === 'courses_incomplete' ? 'Courses Incomplete' : subFilter === 'assess_passed' ? 'Assessment Passed' : subFilter === 'assess_failed' ? 'Failed Attempt' : subFilter === 'assess_attempted' ? 'Attempted Assessment' : subFilter === 'locked' ? 'Deadline Locked' : 'Never Attempted Assessment'}
                    </span>
                    <button
                      type="button"
                      onClick={() => {
                        setSubFilter('all');
                        setPage(1);
                      }}
                      className="hover:opacity-75 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Completion range pill */}
                {compRange !== 'all' && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                    <span>
                      Comp: {compRange === 'custom' ? `${customMin || '0'}%–${customMax || '100'}%` : `${compRange}%`}
                    </span>
                    <button
                      type="button"
                      onClick={() => {
                        setCompRange('all');
                        setCustomMin('');
                        setCustomMax('');
                        setPage(1);
                      }}
                      className="hover:text-amber-900 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Legacy filter tab pill if not default 'enrolled' */}
                {filterTab !== 'enrolled' && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                    <span>Tab: {filterTab.replace('_', ' ').toUpperCase()}</span>
                    <button
                      type="button"
                      onClick={() => {
                        setFilterTab('enrolled');
                        setPage(1);
                      }}
                      className="hover:text-slate-900 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}

                {/* Ex-Employee pill if selected */}
                {empType === 'ex' && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-50 text-amber-800 border border-amber-300">
                    <span>Ex-Employees</span>
                    <button
                      type="button"
                      onClick={() => {
                        setEmpType('current');
                        setPage(1);
                      }}
                      className="hover:text-amber-950 cursor-pointer"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                )}
              </div>

              {/* Clear All Filters Button */}
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={handleClearAllFilters}
                className="h-8 text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-xl px-3 gap-1.5 cursor-pointer shrink-0"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                <span>Clear All Filters</span>
              </Button>
            </div>
          )}
        </div>

        {/* =========================================================================
            EMPLOYEE LISTING (TABLE / GRID VIEW)
           ========================================================================= */}
        <div>
          {isInitialLoading ? (
            viewMode === 'table' ? (
              <div className="bg-white border border-slate-200/80 rounded-2xl shadow-2xs overflow-hidden">
                <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                  <div className="h-5 w-48 bg-slate-200 rounded-lg animate-pulse" />
                  <div className="h-4 w-28 bg-slate-100 rounded-lg animate-pulse" />
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-left">
                    <thead>
                      <tr className="bg-slate-50/90 border-b border-slate-200/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                        <th className="py-3.5 px-4">Employee</th>
                        <th className="py-3.5 px-4">Department &amp; Cohort</th>
                        <th className="py-3.5 px-4 min-w-[200px]">Onboarding Progress</th>
                        <th className="py-3.5 px-4">Status</th>
                        <th className="py-3.5 px-4 text-right">Audit &amp; Report</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {[1, 2, 3, 4, 5].map((i) => (
                        <tr key={i} className="animate-pulse">
                          <td className="py-4 px-4">
                            <div className="flex items-center gap-3">
                              <div className="h-10 w-10 rounded-full bg-slate-200 shrink-0" />
                              <div className="space-y-1.5">
                                <div className="h-4 w-32 bg-slate-200 rounded" />
                                <div className="h-3 w-40 bg-slate-100 rounded" />
                              </div>
                            </div>
                          </td>
                          <td className="py-4 px-4">
                            <div className="h-6 w-28 bg-slate-200 rounded-full" />
                          </td>
                          <td className="py-4 px-4 min-w-[200px]">
                            <div className="space-y-2">
                              <div className="h-2 w-full bg-slate-200 rounded-full" />
                              <div className="h-3 w-36 bg-slate-100 rounded" />
                            </div>
                          </td>
                          <td className="py-4 px-4">
                            <div className="h-6 w-20 bg-slate-200 rounded-full" />
                          </td>
                          <td className="py-4 px-4 text-right">
                            <div className="h-8 w-24 bg-slate-200 rounded-lg ml-auto" />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 animate-pulse">
                {[1, 2, 3, 4, 5, 6].map((i) => (
                  <div key={i} className="bg-white rounded-2xl border border-slate-200/80 p-5 space-y-4 shadow-2xs h-64 flex flex-col justify-between">
                    <div className="flex items-start justify-between">
                      <div className="flex items-center gap-3">
                        <div className="h-12 w-12 rounded-full bg-slate-200 shrink-0" />
                        <div className="space-y-1.5">
                          <div className="h-4 w-28 bg-slate-200 rounded" />
                          <div className="h-3 w-20 bg-slate-200 rounded" />
                        </div>
                      </div>
                      <div className="h-12 w-12 rounded-full bg-slate-200 shrink-0" />
                    </div>
                    <div className="space-y-2 pt-2 border-t border-slate-100">
                      <div className="h-3 w-full bg-slate-200 rounded" />
                      <div className="h-3 w-2/3 bg-slate-200 rounded" />
                    </div>
                    <div className="h-9 w-full bg-slate-200 rounded-xl mt-auto" />
                  </div>
                ))}
              </div>
            )
          ) : rows.length === 0 ? (
            <div className="bg-white border border-slate-200/80 rounded-2xl p-12 text-center space-y-3">
              <Users className="h-12 w-12 text-slate-300 mx-auto" />
              <h3 className="text-base font-bold text-slate-800">No employees match your filter criteria</h3>
              <p className="text-xs text-slate-500 max-w-md mx-auto">
                No records matched the selected combination of search keyword, department, onboarding status, and completion range.
              </p>
              {hasActiveFilters && (
                <div className="pt-2">
                  <Button
                    type="button"
                    onClick={handleClearAllFilters}
                    className="h-9 px-4 rounded-xl bg-[#00A389] hover:bg-[#008f78] text-white text-xs font-bold shadow-2xs gap-1.5"
                  >
                    <RotateCcw className="h-3.5 w-3.5" />
                    <span>Reset All Filters</span>
                  </Button>
                </div>
              )}
            </div>
          ) : (
            <div className="space-y-4">
              {/* View Switcher & Counter Toolbar */}
              <div className="bg-white border border-slate-200/80 rounded-2xl px-5 py-3.5 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-2.5 flex-wrap">
                  <h2 className="text-sm font-black text-slate-800 tracking-tight">
                    {empType === 'ex'
                      ? 'Ex-Employees (Relieved)'
                      : filterTab === 'enrolled'
                        ? 'Onboarding Employees'
                        : filterTab === 'completed'
                          ? 'Completed Onboarding'
                          : filterTab === 'not_enrolled'
                            ? 'Staff Not Enrolled'
                            : 'All Company Staff'}
                  </h2>
                  <span className="text-[10px] font-bold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-2.5 py-0.5 rounded-full">
                    {pagination.total} matching {pagination.total === 1 ? 'employee' : 'employees'}
                  </span>
                  {isFetching && !isInitialLoading && (
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500">
                      <Loader2 className="h-3 w-3 animate-spin text-[#00A389]" />
                      Updating...
                    </span>
                  )}
                </div>

                <div className="flex items-center gap-2.5 flex-wrap">
                  {/* Current / Ex-Employees Segmented Toggle */}
                  {filterTab !== 'not_enrolled' ? (
                    <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200/80">
                      <button
                        type="button"
                        onClick={() => {
                          setEmpType('current');
                          setPage(1);
                        }}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${empType === 'current'
                          ? 'bg-white text-emerald-800 shadow-2xs'
                          : 'text-slate-500 hover:text-slate-800'
                          }`}
                      >
                        <span className={`h-2 w-2 rounded-full ${empType === 'current' ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                        <span>Current</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setEmpType('ex');
                          setPage(1);
                        }}
                        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${empType === 'ex'
                          ? 'bg-white text-amber-800 shadow-2xs'
                          : 'text-slate-500 hover:text-slate-800'
                          }`}
                      >
                        <span className={`h-2 w-2 rounded-full ${empType === 'ex' ? 'bg-amber-500' : 'bg-slate-300'}`} />
                        <span>Ex-Staff</span>
                      </button>
                    </div>
                  ) : (
                    <div className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold bg-white text-emerald-800 border border-slate-200 shadow-2xs">
                      <span className="h-2 w-2 rounded-full bg-emerald-500" />
                      <span>Current Staff</span>
                    </div>
                  )}

                  {/* Table / Grid Mode Toggle */}
                  <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200/80 self-start sm:self-auto">
                    <button
                      type="button"
                      onClick={() => handleViewModeChange('table')}
                      className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${viewMode === 'table'
                        ? 'bg-white text-[#00A389] shadow-2xs'
                        : 'text-slate-500 hover:text-slate-800'
                        }`}
                    >
                      <List className="h-3.5 w-3.5" />
                      <span>Table</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => handleViewModeChange('grid')}
                      className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${viewMode === 'grid'
                        ? 'bg-white text-[#00A389] shadow-2xs'
                        : 'text-slate-500 hover:text-slate-800'
                        }`}
                    >
                      <LayoutGrid className="h-3.5 w-3.5" />
                      <span>Grid</span>
                    </button>
                  </div>
                </div>
              </div>

              {/* View Mode 1: Responsive Grid Format */}
              {viewMode === 'grid' ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {rows.map((r) => {
                    const totalMod = r.progress?.total || totalModules || 3;
                    const completedMod = r.progress?.completed || 0;
                    const percent = totalMod > 0 ? Math.min(100, Math.round((completedMod / totalMod) * 100)) : (r.progress?.percent || 0);
                    const isCompleted = percent === 100;
                    const isInProgress = (percent > 0 && percent < 100) || (r.is_moodle_enrolled && r.progress?.status === 'In Progress');
                    const dlInfo = r.deadline_info;
                    // Strict Certification Rule: Trainee is Certified ONLY if they took and passed assessment (≥ 80%)
                    const isTraineeCertified = Boolean(r.assessment?.is_passed || dlInfo?.has_passed);
                    const isTraineeLocked = Boolean(!isTraineeCertified && dlInfo?.is_deadline_locked);
                    const isTraineeExtended = Boolean(!isTraineeCertified && dlInfo?.is_extended);

                    return (
                      <div
                        key={r.uid}
                        className="bg-white border border-slate-200/90 hover:border-[#00A389]/50 rounded-2xl p-5 shadow-2xs hover:shadow-md transition-all flex flex-col justify-between gap-4 group"
                      >
                        {/* Header: Avatar, Name, Email, Department */}
                        <div className="space-y-3">
                          <div className="flex items-start justify-between gap-3">
                            <div className="flex items-center gap-3 min-w-0">
                              <div className="h-11 w-11 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center font-black text-slate-700 shrink-0 shadow-2xs">
                                {r.photo ? (
                                  <img
                                    src={r.photo}
                                    alt={r.name}
                                    className="h-full w-full object-cover"
                                    onError={(e) => { (e.target as HTMLElement).style.display = 'none'; }}
                                  />
                                ) : (
                                  <span>{String(r.name || '').split(' ').map((n: string) => n[0]).join('').slice(0, 2)}</span>
                                )}
                              </div>
                              <div className="min-w-0">
                                <div className="flex items-center gap-1.5 flex-wrap">
                                  <h3 className="font-bold text-slate-800 text-sm truncate group-hover:text-[#00A389] transition-colors">
                                    {r.name}
                                  </h3>
                                  {r.emp_code && (
                                    <span className="font-mono text-[9px] font-bold text-slate-600 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                                      {r.emp_code}
                                    </span>
                                  )}
                                </div>
                                <div className="text-[11px] text-slate-400 truncate flex items-center gap-1 mt-0.5">
                                  <Mail className="h-3 w-3 shrink-0" />
                                  <span className="truncate">{r.email || `UID: #${r.uid}`}</span>
                                </div>
                              </div>
                            </div>

                            <span
                              className={`text-[10px] font-bold px-2.5 py-0.5 rounded-full border shrink-0 ${!r.is_moodle_enrolled
                                ? 'bg-amber-50 text-amber-700 border-amber-200'
                                : isTraineeCertified
                                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                  : isTraineeLocked
                                    ? 'bg-red-50 text-red-700 border-red-200'
                                    : isTraineeExtended
                                      ? 'bg-blue-50 text-blue-700 border-blue-200'
                                      : isInProgress
                                        ? 'bg-[#E6F7F4] text-[#00A389] border-[#00A389]/30'
                                        : 'bg-slate-100 text-slate-600 border-slate-200'
                                }`}
                            >
                              {!r.is_moodle_enrolled
                                ? 'Not Enrolled'
                                : isTraineeCertified
                                  ? '✓ Certified'
                                  : isTraineeLocked
                                    ? '🔒 Locked'
                                    : isTraineeExtended
                                      ? '⏱ Extended'
                                      : isInProgress
                                        ? 'In Progress'
                                        : 'Not Started'}
                            </span>
                          </div>

                          {/* Department & Enrollment Meta */}
                          <div className="flex items-center gap-2 flex-wrap text-[10px]">
                            {/* Department Badge */}
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-bold bg-blue-50 text-blue-700 border border-blue-200">
                              <Building2 className="h-3 w-3 text-blue-500" />
                              <span>{r.department_name || 'General'}</span>
                            </span>

                            {r.is_ex_employee && (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-full font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                Ex-Employee
                              </span>
                            )}
                            {r.is_moodle_enrolled ? (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                Moodle #{r.moodle_user_id || r.uid}
                              </span>
                            ) : (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-full font-bold bg-slate-100 text-slate-500 border border-slate-200">
                                General Staff
                              </span>
                            )}
                            {r.joiningdate_fmt && (
                              <span className="inline-flex items-center gap-1 text-slate-400 font-medium">
                                <Calendar className="h-3 w-3" />
                                Joined {r.joiningdate_fmt}
                              </span>
                            )}
                          </div>
                        </div>

                        {/* Progress Section: Round Circular Progress + Metric Details */}
                        <div className="pt-2 border-t border-slate-100">
                          <div className="p-3 rounded-xl bg-slate-50/80 border border-slate-100/90 flex items-center gap-3.5">
                            {/* Round Circular Progress Ring */}
                            <CircularProgress percent={percent} size={52} strokeWidth={4.5} />

                            {/* Details */}
                            <div className="flex-1 min-w-0 space-y-1">
                              <div className="flex items-center justify-between">
                                <span className="text-xs font-bold text-slate-700">
                                  {isCompleted ? 'Finished All Modules' : `${completedMod} of ${totalMod} Courses`}
                                </span>
                                <span className={`text-xs font-black ${percent > 0 ? 'text-[#00A389]' : 'text-slate-400'}`}>
                                  {percent}%
                                </span>
                              </div>

                              {/* Progress bar line */}
                              <div className="h-1.5 w-full bg-slate-200/80 rounded-full overflow-hidden">
                                <div
                                  className={`h-full rounded-full transition-all duration-500 ${isCompleted ? 'bg-[#00A389]' : percent > 0 ? 'bg-[#00A389]' : 'bg-slate-300'
                                    }`}
                                  style={{ width: `${percent}%` }}
                                />
                              </div>

                              <div className="flex items-center justify-between text-[10px] text-slate-400 font-medium pt-0.5">
                                <span>{isCompleted ? 'Onboarding Complete' : `${Math.max(0, totalMod - completedMod)} remaining`}</span>
                                <span>Curriculum</span>
                              </div>
                            </div>
                          </div>
                        </div>

                        {/* Card Footer: Action Buttons */}
                        <div className="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                          {r.is_moodle_enrolled ? (
                            <>
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setAccessModalEmployee(r)}
                                className="h-8 px-2.5 rounded-xl border-amber-200 text-xs font-bold text-amber-800 bg-amber-50/60 hover:bg-amber-100 hover:border-amber-300 gap-1.5 cursor-pointer shadow-2xs transition-all"
                                title="Course Access Management (Unlock / Relaunch courses)"
                              >
                                <Unlock className="h-3 w-3 text-amber-600" />
                                <span>Access</span>
                              </Button>
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openLmsReport(r)}
                                className="flex-1 h-8 rounded-xl border-slate-200 text-xs font-bold text-slate-700 hover:text-[#00A389] hover:bg-[#E6F7F4]/60 hover:border-[#00A389]/40 gap-1.5 cursor-pointer shadow-2xs transition-all"
                              >
                                <BarChart3 className="h-3.5 w-3.5 text-[#00A389]" />
                                <span>View Report</span>
                                <ChevronRight className="h-3 w-3 text-slate-400 ml-auto" />
                              </Button>
                            </>
                          ) : (
                            <span className="text-[11px] text-slate-400 font-medium italic py-1">
                              Not enrolled in LMS curriculum
                            </span>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              ) : (
                /* View Mode 2: Enterprise Table Format */
                <div className="bg-white border border-slate-200/80 rounded-2xl shadow-2xs overflow-hidden">
                  <div className="overflow-x-auto">
                    <table className="w-full text-left">
                      <thead>
                        <tr className="bg-slate-50/90 border-b border-slate-200/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                          <th className="py-3.5 px-4 min-w-[220px]">Employee</th>
                          <th className="py-3.5 px-4 min-w-[170px]">Courses (10)</th>
                          <th className="py-3.5 px-4 min-w-[150px]">Assessment</th>
                          <th className="py-3.5 px-4 min-w-[170px]">Deadline &amp; Access</th>
                          <th className="py-3.5 px-4 min-w-[160px]">Certificate</th>
                          <th className="py-3.5 px-4 text-right min-w-[190px]">Audit &amp; Report</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {rows.map((r) => {
                          const totalMod = r.progress?.total || totalModules || 10;
                          const completedMod = r.progress?.completed || 0;
                          const percent = totalMod > 0 ? Math.min(100, Math.round((completedMod / totalMod) * 100)) : (r.progress?.percent || 0);
                          const isCompleted = percent === 100;
                          const isInProgress = (percent > 0 && percent < 100) || (r.is_moodle_enrolled && r.progress?.status === 'In Progress');
                          const dlInfo = r.deadline_info;
                          // Strict Certification Rule: Trainee is Certified ONLY if they took and passed assessment (≥ 80%)
                          const isTraineeCertified = Boolean(r.assessment?.is_passed || dlInfo?.has_passed);
                          const isTraineeLocked = Boolean(!isTraineeCertified && dlInfo?.is_deadline_locked);
                          const isTraineeExtended = Boolean(!isTraineeCertified && dlInfo?.is_extended);
                          const assessData = r.assessment;
                          const certData = r.certificate;

                          return (
                            <tr
                              key={r.uid}
                              className="hover:bg-slate-50/70 transition-colors group"
                            >
                              {/* 1. Employee Column */}
                              <td className="py-4 px-4 whitespace-nowrap">
                                <div className="flex items-center gap-3">
                                  <div className="h-10 w-10 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center font-bold text-slate-700 shrink-0 shadow-2xs">
                                    {r.photo ? (
                                      <img
                                        src={r.photo}
                                        alt={r.name}
                                        className="h-full w-full object-cover"
                                        onError={(e) => { (e.target as HTMLElement).style.display = 'none'; }}
                                      />
                                    ) : (
                                      <span>{String(r.name || '').split(' ').map((n: string) => n[0]).join('').slice(0, 2)}</span>
                                    )}
                                  </div>
                                  <div className="min-w-0">
                                    <div className="flex items-center gap-1.5 flex-wrap">
                                      <span className="font-bold text-slate-800 text-sm group-hover:text-[#00A389] transition-colors">
                                        {r.name}
                                      </span>
                                      {r.emp_code && (
                                        <span className="font-mono text-[9px] font-bold text-slate-600 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                                          {r.emp_code}
                                        </span>
                                      )}
                                    </div>
                                    <div className="text-[11px] text-slate-400 truncate flex items-center gap-1 mt-0.5">
                                      <Mail className="h-3 w-3 shrink-0" />
                                      <span>{r.email || `UID: #${r.uid}`}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                        <Building2 className="h-2.5 w-2.5 text-blue-500" />
                                        <span>{r.department_name || 'General'}</span>
                                      </span>
                                      {r.joiningdate_fmt && (
                                        <span className="text-[9px] text-slate-400">
                                          Joined {r.joiningdate_fmt}
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                </div>
                              </td>

                              {/* 2. Courses (10) Column */}
                              <td className="py-4 px-4 min-w-[170px]">
                                {r.is_moodle_enrolled ? (
                                  <div className="space-y-1.5">
                                    <div className="flex items-center justify-between gap-2">
                                      <div className="h-2 flex-1 bg-slate-100 rounded-full overflow-hidden">
                                        <div
                                          className={`h-full rounded-full transition-all duration-500 ${isCompleted ? 'bg-[#00A389]' : percent > 0 ? 'bg-[#00A389]' : 'bg-slate-200'
                                            }`}
                                          style={{ width: `${percent}%` }}
                                        />
                                      </div>
                                      <span className={`font-black text-xs w-10 text-right shrink-0 ${percent > 0 ? 'text-[#00A389]' : 'text-slate-400'}`}>
                                        {percent}%
                                      </span>
                                    </div>
                                    <div className="text-[10px] font-medium text-slate-500 flex items-center justify-between">
                                      <span>{completedMod} of {totalMod} completed</span>
                                      <span className="font-mono text-[9px] text-slate-400">UID #{r.uid}</span>
                                    </div>
                                  </div>
                                ) : (
                                  <span className="text-[11px] text-slate-400 font-medium italic">
                                    Not enrolled
                                  </span>
                                )}
                              </td>

                              {/* 3. Assessment Column */}
                              <td className="py-4 px-4 whitespace-nowrap min-w-[150px]">
                                {assessData && (assessData.attempts_count > 0 || assessData.latest_attempt > 0 || assessData.attempt_number > 0 || assessData.result === 'Passed' || assessData.result === 'Failed') ? (
                                  <div className="space-y-1">
                                    <div className="flex items-center gap-1.5">
                                      <span
                                        className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold border ${assessData.is_passed
                                          ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                          : 'bg-rose-50 text-rose-700 border-rose-200'
                                          }`}
                                      >
                                        {assessData.is_passed ? (
                                          <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                                        ) : (
                                          <XCircle className="h-3 w-3 text-rose-600" />
                                        )}
                                        <span>{assessData.result || (assessData.is_passed ? 'Passed' : 'Failed')}</span>
                                      </span>
                                      {(assessData.score !== null || assessData.latest_score !== null) && (
                                        <span className="font-bold text-xs text-slate-700">
                                          {Math.round(Number(assessData.score ?? assessData.latest_score))}%
                                        </span>
                                      )}
                                      {(assessData.attempt_number > 0 || assessData.latest_attempt > 0) && (
                                        <span className="text-[10px] text-slate-400 font-medium">
                                          (Att #{assessData.attempt_number || assessData.latest_attempt})
                                        </span>
                                      )}
                                    </div>
                                    {(assessData.passed_date_fmt || assessData.submitted_at_fmt) && (
                                      <div className="text-[10px] text-slate-400">
                                        {assessData.passed_date_fmt || assessData.submitted_at_fmt}
                                      </div>
                                    )}
                                  </div>
                                ) : (
                                  <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                    Not Attempted
                                  </span>
                                )}
                              </td>

                              {/* 4. Deadline & Access Column */}
                              <td className="py-4 px-4 whitespace-nowrap min-w-[170px]">
                                <div className="space-y-1">
                                  <div>
                                    {isTraineeCertified ? (
                                      <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                                        <span>Certified</span>
                                      </span>
                                    ) : isTraineeLocked ? (
                                      <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                        <Lock className="h-3 w-3 text-rose-600" />
                                        <span>Deadline Locked</span>
                                      </span>
                                    ) : isTraineeExtended ? (
                                      <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                        <Clock className="h-3 w-3 text-blue-600" />
                                        <span>Extended</span>
                                      </span>
                                    ) : (
                                      <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        <Clock className="h-3 w-3 text-amber-600" />
                                        <span>Pending Cutoff</span>
                                      </span>
                                    )}
                                  </div>
                                  <div className="text-[10px] text-slate-400 font-mono">
                                    {dlInfo?.effective_deadline_fmt || dlInfo?.effective_deadline || '24-Nov-2026 11:59 PM'}
                                  </div>
                                  {isTraineeLocked && (
                                    <div>
                                      <button
                                        type="button"
                                        onClick={() => setExtendModalEmployee(r)}
                                        className="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 inline-flex items-center gap-1 cursor-pointer transition-colors shadow-2xs"
                                        title="Grant deadline extension"
                                      >
                                        <Clock className="h-2.5 w-2.5" />
                                        <span>Extend Deadline</span>
                                      </button>
                                    </div>
                                  )}
                                </div>
                              </td>

                              {/* 5. Certificate Column */}
                              <td className="py-4 px-4 whitespace-nowrap min-w-[150px]">
                                {isTraineeCertified && certData?.certificate_number ? (
                                  <div className="space-y-1">
                                    <div>
                                      <span className="font-mono text-[10px] font-bold text-teal-800 bg-teal-50 border border-teal-200 px-2 py-0.5 rounded">
                                        {certData.certificate_number}
                                      </span>
                                    </div>
                                    <div>
                                      {certData && certData.download_count > 0 ? (
                                        <span className="text-[9px] font-bold text-blue-700 bg-blue-50 border border-blue-200 px-1.5 py-0.5 rounded-full inline-flex items-center gap-1">
                                          <Check className="h-2.5 w-2.5" />
                                          <span>Downloaded ({certData.download_count})</span>
                                        </span>
                                      ) : (
                                        <span className="text-[9px] font-medium text-slate-500 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded-full">
                                          Not Downloaded
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                ) : (
                                  <span className="text-[11px] text-slate-400 italic">
                                    Pending Assessment
                                  </span>
                                )}
                              </td>

                              {/* 6. Audit & Report Column */}
                              <td className="py-4 px-4 text-right whitespace-nowrap min-w-[190px]">
                                {r.is_moodle_enrolled ? (
                                  <div className="flex items-center justify-end gap-2">
                                    <Button
                                      variant="outline"
                                      size="sm"
                                      onClick={() => setAccessModalEmployee(r)}
                                      className="h-8 px-2.5 rounded-xl border-amber-200 text-xs font-bold text-amber-800 bg-amber-50/60 hover:bg-amber-100 hover:border-amber-300 gap-1.5 cursor-pointer shadow-2xs transition-all"
                                      title="Course Access Management (Unlock / Relaunch courses)"
                                    >
                                      <Unlock className="h-3 w-3 text-amber-600" />
                                      <span>Access</span>
                                    </Button>
                                    <Button
                                      variant="outline"
                                      size="sm"
                                      onClick={() => openLmsReport(r)}
                                      className="h-8 px-3 rounded-xl border-slate-200 text-xs font-bold text-slate-700 hover:text-[#00A389] hover:bg-[#E6F7F4]/60 hover:border-[#00A389]/40 gap-1.5 cursor-pointer shadow-2xs transition-all"
                                      title="View detailed employee training audit report"
                                    >
                                      <BarChart3 className="h-3.5 w-3.5 text-[#00A389]" />
                                      <span>View Report</span>
                                      <ChevronRight className="h-3 w-3 text-slate-400" />
                                    </Button>
                                  </div>
                                ) : (
                                  <span className="text-[11px] text-slate-400 font-medium italic pr-3 select-none">
                                    —
                                  </span>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* Server-Side Pagination Bar */}
              {pagination.total > 0 && (
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100 text-xs">
                  <div className="text-slate-500 font-medium">
                    Showing <span className="font-bold text-slate-800">{(pagination.page - 1) * pagination.limit + 1}</span> to{' '}
                    <span className="font-bold text-slate-800">{Math.min(pagination.page * pagination.limit, pagination.total)}</span> of{' '}
                    <span className="font-bold text-slate-800">{pagination.total}</span> employees (10 per page)
                  </div>

                  {pagination.totalPages > 1 && (
                    <div className="flex items-center gap-1.5">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={!pagination.hasPrev || isFetching}
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                        className="h-8 px-2.5 rounded-lg border-slate-200 text-slate-600 hover:text-[#00A389] hover:border-[#00A389]/40 gap-1 cursor-pointer disabled:opacity-40"
                      >
                        <ChevronLeft className="h-3.5 w-3.5" />
                        <span className="hidden sm:inline">Prev</span>
                      </Button>

                      {/* Page Numbers */}
                      <div className="flex items-center gap-1">
                        {Array.from({ length: pagination.totalPages }, (_, i) => i + 1).map((pageNum) => {
                          if (
                            pageNum === 1 ||
                            pageNum === pagination.totalPages ||
                            (pageNum >= pagination.page - 1 && pageNum <= pagination.page + 1)
                          ) {
                            return (
                              <button
                                key={pageNum}
                                disabled={isFetching}
                                onClick={() => setPage(pageNum)}
                                className={`h-8 w-8 rounded-lg text-xs font-bold transition-all cursor-pointer ${pageNum === pagination.page
                                  ? 'bg-[#00A389] text-white shadow-2xs'
                                  : 'bg-white hover:bg-slate-50 text-slate-600 border border-slate-200'
                                  }`}
                              >
                                {pageNum}
                              </button>
                            );
                          }
                          if (
                            (pageNum === 2 && pagination.page > 3) ||
                            (pageNum === pagination.totalPages - 1 && pagination.page < pagination.totalPages - 2)
                          ) {
                            return (
                              <span key={pageNum} className="px-1 text-slate-400 select-none">
                                ...
                              </span>
                            );
                          }
                          return null;
                        })}
                      </div>

                      <Button
                        variant="outline"
                        size="sm"
                        disabled={!pagination.hasNext || isFetching}
                        onClick={() => setPage((p) => Math.min(pagination.totalPages, p + 1))}
                        className="h-8 px-2.5 rounded-lg border-slate-200 text-slate-600 hover:text-[#00A389] hover:border-[#00A389]/40 gap-1 cursor-pointer disabled:opacity-40"
                      >
                        <span className="hidden sm:inline">Next</span>
                        <ChevronRight className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <AdminCourseAccessModal
        isOpen={Boolean(accessModalEmployee)}
        onClose={() => setAccessModalEmployee(null)}
        employeeUid={accessModalEmployee?.uid}
        employeeName={accessModalEmployee?.name}
        onUpdated={() => refetch()}
      />

      <AdminExtendDeadlineModal
        isOpen={Boolean(extendModalEmployee)}
        onClose={() => setExtendModalEmployee(null)}
        employee={extendModalEmployee}
        onUpdated={() => refetch()}
      />
    </Layout>
  );
};

export default AdminEmployees;
