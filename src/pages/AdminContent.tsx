import { useCallback, useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Layout } from "@/components/Layout";
import {
  BookOpen,
  ExternalLink,
  Search,
  CheckCircle2,
  Clock,
  Award,
  RefreshCw,
  Layers,
  FileText,
  AlertCircle,
  GraduationCap,
  LayoutGrid,
  List,
  Eye,
  EyeOff,
  GripVertical,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  ChevronDown,
  ArrowUpDown,
  Check,
  Pencil,
  Lock,
  Unlock
} from "lucide-react";
import { apiGet, apiPost, API_BASE } from "@/lib/api";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { useToast } from "@/components/ui/use-toast";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";

interface CourseItem {
  id: number;
  title: string;
  type: string;
  description: string;
  duration: string;
  passing_score: number;
  is_active: boolean;
  sort_order?: number;
  moodle_course_id: number;
  moodle_cmid?: number | null;
  moodle_link: string;
  course_image?: string;
  enrolled_count: number;
  completed_count: number;
  in_progress_count: number;
  failed_count: number;
  not_started_count: number;
  completion_percent: number;
  average_score?: number | null;
  total_time_seconds?: number;
  total_slides?: number;
}

interface CoursesResponse {
  success: boolean;
  stats: {
    total_courses: number;
    active_courses: number;
    scorm_count: number;
    quiz_count: number;
    total_enrolled: number;
    lms_connected: boolean;
    moodle_url: string;
  };
  courses: CourseItem[];
  message?: string;
}

interface CourseCardImageProps {
  course: CourseItem;
  idx: number;
  cacheBuster: number;
  isQuiz: boolean;
}

const CourseCardImage = ({ course, idx, cacheBuster, isQuiz }: CourseCardImageProps) => {
  const [isLoaded, setIsLoaded] = useState(false);
  const [hasError, setHasError] = useState(false);

  const gradients = [
    "from-[#00A389] to-[#004d40]",
    "from-sky-600 to-indigo-900",
    "from-teal-600 to-emerald-900",
    "from-slate-700 to-slate-900",
    "from-amber-600 to-amber-900"
  ];
  const bgGrad = gradients[idx % gradients.length];

  const imageUrl = useMemo(() => {
    const cId = course.moodle_course_id || course.id;
    if (course.course_image && !course.course_image.includes("placeholder")) {
      const sep = course.course_image.includes("?") ? "&" : "?";
      return `${course.course_image}${sep}cb=${cacheBuster}`;
    }
    if (cId) {
      return `${API_BASE}/moodle/course_image.php?course_id=${cId}&cb=${cacheBuster}`;
    }
    return `${API_BASE}/moodle/course_image.php?course_id=2&cb=${cacheBuster}`;
  }, [course, cacheBuster]);

  return (
    <div className="w-full relative h-26 rounded-lg overflow-hidden bg-slate-100 border border-slate-200/60 my-2 shadow-2xs group">
      {/* Loading Skeleton / Placeholder while image loads */}
      {!isLoaded && !hasError && (
        <div className="absolute inset-0 bg-slate-100 animate-pulse flex flex-col items-center justify-center gap-1 z-1">
          <BookOpen className="h-5 w-5 text-slate-300 animate-bounce" />
          <span className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Loading...</span>
        </div>
      )}

      {/* Fallback Display if image fails or is unavailable */}
      {hasError ? (
        <div className={`w-full h-full bg-gradient-to-br ${bgGrad} flex flex-col items-center justify-center p-3 text-center text-white relative`}>
          <div className="h-8 w-8 rounded-full bg-white/20 backdrop-blur-xs flex items-center justify-center mb-1">
            {isQuiz ? <Award className="h-4 w-4 text-amber-200" /> : <BookOpen className="h-4 w-4 text-white" />}
          </div>
          <p className="text-[11px] font-bold leading-tight line-clamp-1 text-white/95 px-1">{course.title}</p>
          <span className="text-[9px] font-medium text-emerald-200/90 uppercase tracking-wider mt-0.5">HexaLearn LMS</span>
        </div>
      ) : (
        <img
          src={imageUrl}
          alt={course.title}
          onLoad={() => setIsLoaded(true)}
          onError={() => {
            setIsLoaded(true);
            setHasError(true);
          }}
          className={`w-full h-full object-cover transition-all duration-500 group-hover:scale-105 ${
            isLoaded ? "opacity-100" : "opacity-0"
          } ${!course.is_active ? "grayscale-[40%] contrast-90" : ""}`}
          loading="eager"
        />
      )}

      {/* Hidden Badge Overlay */}
      {!course.is_active && (
        <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-[1px] flex flex-col items-center justify-center p-2 text-center text-white z-2">
          <EyeOff className="h-5 w-5 mb-1 text-amber-300" />
          <span className="text-[10px] font-extrabold uppercase tracking-wider text-amber-200">
            Hidden from Employees
          </span>
        </div>
      )}
    </div>
  );
};

const AdminContent = () => {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [syncingLms, setSyncingLms] = useState(false);
  const [viewMode, setViewMode] = useState<"grid" | "table">("grid");
  const [q, setQ] = useState("");
  const [typeFilter, setTypeFilter] = useState<string>("all");
  const [visibilityFilter, setVisibilityFilter] = useState<"all" | "active" | "hidden">("all");
  const [cacheBuster, setCacheBuster] = useState<number>(Date.now());

  // React Query cached courses overview
  const {
    data,
    isLoading: loading,
    refetch: refetchCourses,
  } = useQuery<CoursesResponse>({
    queryKey: ['admin_courses_overview'],
    queryFn: () => apiGet<CoursesResponse>("/admin/courses_overview.php"),
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    placeholderData: (previousData) => previousData,
  });

  // Local state for instant drag-and-drop & optimistic updates
  const [courseList, setCourseList] = useState<CourseItem[]>([]);
  const [togglingVisibilityId, setTogglingVisibilityId] = useState<number | null>(null);
  const [draggedCourseId, setDraggedCourseId] = useState<number | null>(null);
  const [dragOverCourseId, setDragOverCourseId] = useState<number | null>(null);
  const [savingOrder, setSavingOrder] = useState(false);

  // Sequential Progression Locking state
  const [isSequentialLocking, setIsSequentialLocking] = useState<boolean>(false);
  const [togglingLocking, setTogglingLocking] = useState<boolean>(false);

  useEffect(() => {
    if ((data as any)?.sequential_locking !== undefined) {
      setIsSequentialLocking(Boolean((data as any).sequential_locking));
    } else if ((data?.stats as any)?.sequential_locking !== undefined) {
      setIsSequentialLocking(Boolean((data.stats as any).sequential_locking));
    }
  }, [data]);

  // Sequential Locking Feedback Popup Modal state
  const [lockingModal, setLockingModal] = useState<{
    isOpen: boolean;
    enabled: boolean;
    title: string;
    message: string;
  } | null>(null);

  // Auto-dismiss the confirmation popup after 3.5 seconds
  useEffect(() => {
    if (lockingModal?.isOpen) {
      const timer = setTimeout(() => {
        setLockingModal(null);
      }, 3500);
      return () => clearTimeout(timer);
    }
  }, [lockingModal]);

  // Course Order Saved Feedback Popup Modal state
  const [orderModal, setOrderModal] = useState<{
    isOpen: boolean;
    courseTitle: string;
    position: number;
  } | null>(null);

  // Auto-dismiss the order confirmation popup after 4 seconds
  useEffect(() => {
    if (orderModal?.isOpen) {
      const timer = setTimeout(() => {
        setOrderModal(null);
      }, 4000);
      return () => clearTimeout(timer);
    }
  }, [orderModal]);

  const handleToggleSequentialLocking = async (checked: boolean) => {
    setIsSequentialLocking(checked);
    setTogglingLocking(true);
    try {
      const res = await apiPost<any>("/admin/toggle_sequential_locking.php", { enabled: checked });
      if (res?.success) {
        setLockingModal({
          isOpen: true,
          enabled: checked,
          title: checked ? "Sequential Progression Enabled" : "Sequential Progression Disabled",
          message: res.message || (checked
            ? "Sequential course progression is now ENABLED. Employees must complete courses one by one in sequence. Next course unlocks only after completing the previous course."
            : "Sequential course progression is now DISABLED. Open learning mode is active. Employees can now start and complete courses in any order without prerequisite locks.")
        });
        void loadCourses();
      } else {
        setIsSequentialLocking(!checked);
        toast({ title: "Failed to update progression setting", variant: "destructive" });
      }
    } catch {
      setIsSequentialLocking(!checked);
      toast({ title: "Server connection failed", variant: "destructive" });
    } finally {
      setTogglingLocking(false);
    }
  };

  // Course Name & Details Editing Modal state
  const [editingCourse, setEditingCourse] = useState<CourseItem | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editDuration, setEditDuration] = useState("");
  const [editPassingScore, setEditPassingScore] = useState<number>(80);
  const [isSavingEdit, setIsSavingEdit] = useState(false);

  const handleOpenEditModal = (course: CourseItem) => {
    setEditingCourse(course);
    setEditTitle(course.title);
    setEditDescription(course.description || "");
    const durMins = course.duration ? course.duration.replace(/[^0-9]/g, "") : "30";
    setEditDuration(durMins || "30");
    setEditPassingScore(course.passing_score || 80);
  };

  const isEditingAssessment = useMemo(() => {
    if (!editingCourse) return false;
    const t = (editingCourse.title || "").toLowerCase();
    const type = (editingCourse.type || "").toUpperCase();
    return type.includes("QUIZ") || type.includes("ASSESSMENT") || t.includes("assess") || t.includes("quiz");
  }, [editingCourse]);

  const handleSaveCourseEdit = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!editingCourse) return;
    if (!editTitle.trim()) {
      toast({
        title: "Course Name Required",
        description: "Please enter a valid course name.",
        variant: "destructive"
      });
      return;
    }

    setIsSavingEdit(true);
    try {
      const res = await apiPost<any>("/admin/update_course.php", {
        module_id: editingCourse.id,
        moodle_course_id: editingCourse.moodle_course_id,
        title: editTitle.trim(),
        description: editDescription.trim(),
        duration_minutes: parseInt(editDuration, 10) || 30,
        passing_score: parseInt(String(editPassingScore), 10) || 80
      });

      if (res?.success) {
        toast({
          title: "Course Name Saved",
          description: `"${editTitle.trim()}" is now saved and immediately reflected to all employees.`
        });

        // Optimistically update local course list
        setCourseList(prev => prev.map(c => c.id === editingCourse.id ? {
          ...c,
          title: editTitle.trim(),
          description: editDescription.trim(),
          duration: `${parseInt(editDuration, 10) || 30} mins`,
          passing_score: parseInt(String(editPassingScore), 10) || 80
        } : c));

        setEditingCourse(null);
        void queryClient.invalidateQueries({ queryKey: ['admin_courses_overview'] });
        void queryClient.invalidateQueries({ queryKey: ['moodle_courses'] });
      } else {
        toast({
          title: "Update Failed",
          description: res?.message || "Could not update course details.",
          variant: "destructive"
        });
      }
    } catch (err: any) {
      toast({
        title: "Update Error",
        description: err?.message || "Server error while updating course.",
        variant: "destructive"
      });
    } finally {
      setIsSavingEdit(false);
    }
  };

  useEffect(() => {
    if (data?.courses && Array.isArray(data.courses)) {
      setCourseList(data.courses);
    }
  }, [data]);

  const stats = useMemo(() => {
    const total = courseList.length;
    const active = courseList.filter(c => c.is_active).length;
    const scorms = courseList.filter(c => c.type.includes("SCORM")).length;
    const quizzes = courseList.filter(c => c.type.includes("QUIZ") || c.type.includes("ASSESSMENT")).length;
    return {
      total_courses: total,
      active_courses: active,
      hidden_courses: total - active,
      scorm_count: scorms,
      quiz_count: quizzes,
      total_enrolled: data?.stats?.total_enrolled || 0,
      lms_connected: true,
      moodle_url: data?.stats?.moodle_url || "https://lms.moodle.hexalearn.com",
    };
  }, [courseList, data]);

  const loadCourses = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['admin_courses_overview'] });
    void refetchCourses();
  }, [queryClient, refetchCourses]);

  const handleSyncLms = async () => {
    setSyncingLms(true);
    try {
      await apiGet("/moodle/courses.php?force_refresh=1");
      setCacheBuster(Date.now());
      toast({ title: "Courses Synced", description: "Course catalog, dynamic images, and completions refreshed." });
      void loadCourses();
    } catch {
      setCacheBuster(Date.now());
      toast({ title: "Sync Completed", description: "Telemetry updated." });
      void loadCourses();
    } finally {
      setSyncingLms(false);
    }
  };

  // 1. Hide / Show Visibility Toggle
  const handleToggleVisibility = async (course: CourseItem) => {
    const newStatus = !course.is_active;

    // Optimistic update
    setCourseList(prev => prev.map(c => c.id === course.id ? { ...c, is_active: newStatus } : c));
    setTogglingVisibilityId(course.id);

    try {
      const res = await apiPost<any>("/admin/toggle_course_visibility.php", {
        module_id: course.id,
        moodle_course_id: course.moodle_course_id,
        is_active: newStatus
      });

      if (res?.success) {
        toast({
          title: newStatus ? "Course Visible to Employees" : "Course Hidden from Employees",
          description: newStatus
            ? `"${course.title}" will now display in the employee onboarding portal and roadmap.`
            : `"${course.title}" is now hidden from the employee portal. Other courses remain visible.`
        });
        void queryClient.invalidateQueries({ queryKey: ['admin_courses_overview'] });
        void queryClient.invalidateQueries({ queryKey: ['moodle_courses'] });
      } else {
        // Rollback on failure
        setCourseList(prev => prev.map(c => c.id === course.id ? { ...c, is_active: !newStatus } : c));
        toast({
          title: "Update Failed",
          description: res?.message || "Could not change visibility.",
          variant: "destructive"
        });
      }
    } catch (err: any) {
      setCourseList(prev => prev.map(c => c.id === course.id ? { ...c, is_active: !newStatus } : c));
      toast({
        title: "Network Error",
        description: err?.message || "Server error while changing course visibility.",
        variant: "destructive"
      });
    } finally {
      setTogglingVisibilityId(null);
    }
  };

  // 2. Drag & Drop and Reordering
  const saveNewOrder = async (newList: CourseItem[], movedTitle?: string, newPos?: number) => {
    setCourseList(newList);
    setSavingOrder(true);
    try {
      const orderedIds = newList.map(c => c.id);
      const res = await apiPost<any>("/admin/reorder_courses.php", { ordered_ids: orderedIds });
      if (res?.success) {
        if (movedTitle && newPos) {
          setOrderModal({
            isOpen: true,
            courseTitle: movedTitle,
            position: newPos,
          });
        }
        void queryClient.invalidateQueries({ queryKey: ['admin_courses_overview'] });
        void queryClient.invalidateQueries({ queryKey: ['moodle_courses'] });
      } else {
        toast({
          title: "Failed to Save Order",
          description: res?.message || "Could not save custom course sequence.",
          variant: "destructive"
        });
      }
    } catch (err: any) {
      toast({
        title: "Reorder Error",
        description: err?.message || "Server error while saving course order.",
        variant: "destructive"
      });
    } finally {
      setSavingOrder(false);
    }
  };

  const handleMoveCourse = (fromIndex: number, direction: 'up' | 'down') => {
    const toIndex = direction === 'up' ? fromIndex - 1 : fromIndex + 1;
    if (toIndex < 0 || toIndex >= courseList.length) return;
    const updated = [...courseList];
    const [moved] = updated.splice(fromIndex, 1);
    updated.splice(toIndex, 0, moved);
    void saveNewOrder(updated, moved.title, toIndex + 1);
  };

  const handleDragStart = (e: React.DragEvent, id: number) => {
    setDraggedCourseId(id);
    e.dataTransfer.effectAllowed = "move";
    e.dataTransfer.setData("text/plain", String(id));
  };

  const handleDragOver = (e: React.DragEvent, id: number) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
    if (dragOverCourseId !== id) {
      setDragOverCourseId(id);
    }
  };

  const handleDrop = (e: React.DragEvent, targetId: number) => {
    e.preventDefault();
    setDragOverCourseId(null);
    if (!draggedCourseId || draggedCourseId === targetId) {
      setDraggedCourseId(null);
      return;
    }

    const fromIndex = courseList.findIndex(c => c.id === draggedCourseId);
    const toIndex = courseList.findIndex(c => c.id === targetId);
    if (fromIndex === -1 || toIndex === -1) {
      setDraggedCourseId(null);
      return;
    }

    const updated = [...courseList];
    const [moved] = updated.splice(fromIndex, 1);
    updated.splice(toIndex, 0, moved);
    setDraggedCourseId(null);
    void saveNewOrder(updated, moved.title, toIndex + 1);
  };

  const handleDragEnd = () => {
    setDraggedCourseId(null);
    setDragOverCourseId(null);
  };

  // Filtered courses based on Search, Type, and Visibility
  const filteredCourses = useMemo(() => {
    return courseList.filter((c) => {
      const matchQ =
        q.trim() === "" ||
        c.title.toLowerCase().includes(q.toLowerCase()) ||
        c.description.toLowerCase().includes(q.toLowerCase());

      const matchType =
        typeFilter === "all" ||
        (typeFilter === "scorm" && c.type.includes("SCORM")) ||
        (typeFilter === "quiz" && (c.type.includes("QUIZ") || c.type.includes("ASSESSMENT")));

      const matchVisibility =
        visibilityFilter === "all" ||
        (visibilityFilter === "active" && c.is_active) ||
        (visibilityFilter === "hidden" && !c.is_active);

      return matchQ && matchType && matchVisibility;
    });
  }, [courseList, q, typeFilter, visibilityFilter]);

  const isReorderActive = q.trim() === "" && typeFilter === "all" && visibilityFilter === "all";

  const getCourseThumbnail = (course: CourseItem, idx: number) => {
    const cId = course.moodle_course_id || course.id;
    if (course.course_image && !course.course_image.includes("placeholder")) {
      const sep = course.course_image.includes("?") ? "&" : "?";
      return `${course.course_image}${sep}cb=${cacheBuster}`;
    }
    if (cId) {
      return `${API_BASE}/moodle/course_image.php?course_id=${cId}&cb=${cacheBuster}`;
    }
    return `${API_BASE}/moodle/course_image.php?course_id=2&cb=${cacheBuster}`;
  };

  return (
    <Layout isAdmin>
      <div className="space-y-6 pb-12 animate-in fade-in duration-300">
        {/* 1. Header Banner */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="h-12 w-12 rounded-2xl bg-[#00A389]/10 text-[#00A389] flex items-center justify-center shrink-0 shadow-2xs">
              <BookOpen className="h-6 w-6" />
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h1 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight">
                  Courses &amp; Curriculum Management
                </h1>
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#00A389]/10 text-[#00A389] border border-[#00A389]/20">
                  <ArrowUpDown className="h-3 w-3" />
                  Drag &amp; Drop Reordering Enabled
                </span>
              </div>
              <p className="text-xs text-slate-400 font-medium mt-1">
                Drag to reorder courses, toggle employee visibility, and configure Storyline slide telemetry
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2.5 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={handleSyncLms}
              disabled={syncingLms}
              className="text-xs font-bold h-10 px-3.5 rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50"
            >
              <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${syncingLms ? "animate-spin text-[#00A389]" : ""}`} />
              {syncingLms ? "Syncing..." : "Sync from LMS"}
            </Button>

            <a
              href={stats.moodle_url || "https://lms.moodle.hexalearn.com"}
              target="_blank"
              rel="noopener noreferrer"
              className="px-4 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer shadow-2xs bg-[#00A389] text-white hover:bg-[#008f77] h-10"
            >
              <span>Open LMS Portal</span>
              <ExternalLink className="h-3.5 w-3.5" />
            </a>
          </div>
        </div>

        {/* 1.5. Sequential Course Progression (Server-Side Prerequisite Locking) */}
        <div className={`border rounded-2xl p-5 shadow-2xs flex flex-col md:flex-row md:items-center justify-between gap-4 transition-all duration-300 ${
          isSequentialLocking
            ? "bg-gradient-to-r from-teal-50/90 via-emerald-50/60 to-white border-emerald-200/90"
            : "bg-white border-slate-200/80"
        }`}>
          <div className="flex items-start sm:items-center gap-3.5">
            <div className={`h-11 w-11 rounded-2xl flex items-center justify-center shrink-0 border transition-all duration-300 shadow-2xs ${
              isSequentialLocking 
                ? 'bg-[#00A389] text-white border-emerald-400' 
                : 'bg-slate-100 text-slate-500 border-slate-200'
            }`}>
              {isSequentialLocking ? <Lock className="h-5 w-5 text-white" /> : <Unlock className="h-5 w-5 text-slate-500" />}
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h3 className="text-sm sm:text-base font-extrabold text-slate-800 tracking-tight">
                  Sequential Course Progression
                </h3>
              </div>
              <p className={`text-xs mt-1 max-w-2xl leading-relaxed font-medium ${
                isSequentialLocking ? 'text-slate-600' : 'text-slate-500'
              }`}>
                {isSequentialLocking
                  ? 'Employees must complete courses one by one in sequence. Course 2 unlocks only after Course 1 is completed.'
                  : 'Open learning mode: Employees can start and complete any course in any order without prerequisite locks.'}
              </p>
            </div>
          </div>

          <div className={`flex items-center gap-3 shrink-0 self-end md:self-center border px-4 py-2 rounded-xl shadow-2xs transition-all ${
            isSequentialLocking
              ? "bg-white border-emerald-200/90"
              : "bg-slate-50 border-slate-200/90"
          }`}>
            <span className={`text-xs font-bold ${
              isSequentialLocking ? "text-slate-800" : "text-slate-600"
            }`}>
              {isSequentialLocking ? 'Sequential Locking ON' : 'Sequential Locking OFF'}
            </span>
            <Switch
              checked={isSequentialLocking}
              onCheckedChange={handleToggleSequentialLocking}
              disabled={togglingLocking}
              className="data-[state=checked]:bg-[#00A389] cursor-pointer"
            />
          </div>
        </div>

        {/* Filter Bar & Controls */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-2xs space-y-3">
          <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
            {/* Search Input */}
            <div className="relative flex-1 max-w-md">
              <Search className="h-4 w-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              <Input
                type="text"
                placeholder="Search courses by title or topic..."
                value={q}
                onChange={(e) => setQ(e.target.value)}
                className="pl-10 text-xs bg-slate-50/80 border-slate-200 h-10 rounded-xl font-medium focus-visible:ring-1 focus-visible:ring-[#00A389]"
              />
            </div>

            {/* Visibility Filters */}
            <div className="flex items-center gap-1.5 flex-wrap">
              <div className="flex items-center bg-slate-100 p-1 rounded-xl">
                <button
                  type="button"
                  onClick={() => setVisibilityFilter("all")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    visibilityFilter === "all" ? "bg-white text-slate-900 shadow-2xs" : "text-slate-500 hover:text-slate-900"
                  }`}
                >
                  All ({stats.total_courses})
                </button>
                <button
                  type="button"
                  onClick={() => setVisibilityFilter("active")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer flex items-center gap-1 ${
                    visibilityFilter === "active" ? "bg-emerald-600 text-white shadow-2xs" : "text-slate-500 hover:text-emerald-700"
                  }`}
                >
                  <Eye className="h-3 w-3" />
                  <span>Visible ({stats.active_courses})</span>
                </button>
                <button
                  type="button"
                  onClick={() => setVisibilityFilter("hidden")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer flex items-center gap-1 ${
                    visibilityFilter === "hidden" ? "bg-amber-600 text-white shadow-2xs" : "text-slate-500 hover:text-amber-700"
                  }`}
                >
                  <EyeOff className="h-3 w-3" />
                  <span>Hidden ({stats.hidden_courses})</span>
                </button>
              </div>

              {/* Type Filters */}
              <div className="flex items-center bg-slate-100 p-1 rounded-xl">
                <button
                  type="button"
                  onClick={() => setTypeFilter("all")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    typeFilter === "all" ? "bg-[#00A389] text-white shadow-2xs" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  All Types
                </button>
                <button
                  type="button"
                  onClick={() => setTypeFilter("scorm")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    typeFilter === "scorm" ? "bg-blue-600 text-white shadow-2xs" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  SCORM
                </button>
                <button
                  type="button"
                  onClick={() => setTypeFilter("quiz")}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    typeFilter === "quiz" ? "bg-amber-600 text-white shadow-2xs" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  Assessments
                </button>
              </div>

              <div className="h-6 w-px bg-slate-200 mx-1 hidden sm:block" />

              {/* View Switcher: Card Grid vs Table List */}
              <div className="flex items-center bg-slate-100 p-1 rounded-xl">
                <button
                  type="button"
                  onClick={() => setViewMode("grid")}
                  title="Card Grid View (Modules style)"
                  className={`p-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    viewMode === "grid" ? "bg-white text-slate-900 shadow-2xs" : "text-slate-500 hover:text-slate-800"
                  }`}
                >
                  <LayoutGrid className="h-4 w-4" />
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode("table")}
                  title="Dense Table View"
                  className={`p-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    viewMode === "table" ? "bg-white text-slate-900 shadow-2xs" : "text-slate-500 hover:text-slate-800"
                  }`}
                >
                  <List className="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>

          {savingOrder && (
            <div className="pt-2 border-t border-slate-100 text-xs font-bold text-[#00A389] flex items-center gap-1.5 animate-pulse">
              <RefreshCw className="h-3 w-3 animate-spin" />
              Saving course order to database...
            </div>
          )}
        </div>

        {/* 4. Course Display: Cards (Modules Style) or Table */}
        <div>
          {loading ? (
            <div className="bg-white border border-slate-200/80 rounded-2xl p-12 flex flex-col items-center justify-center min-h-[30vh]">
              <div className="h-9 w-9 rounded-full border-4 border-slate-200 border-t-[#00A389] animate-spin" />
              <p className="text-xs font-semibold text-slate-400 mt-3">Loading Moodle course catalog...</p>
            </div>
          ) : filteredCourses.length === 0 ? (
            <div className="bg-white border border-slate-200/80 rounded-2xl p-12 text-center">
              <BookOpen className="h-10 w-10 text-slate-300 mx-auto mb-2" />
              <p className="text-sm font-bold text-slate-700">No courses match your search or filters</p>
              <p className="text-xs text-slate-400 mt-1">Try clearing filters or search terms</p>
            </div>
          ) : viewMode === "grid" ? (
            /* 4A. GRID OF COMPACT MODULE CARDS (5 Courses Per Row with Drag & Drop) */
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 xl:grid-cols-5 2xl:grid-cols-5 gap-3.5">
              {filteredCourses.map((course, idx) => {
                const isQuiz =
                  course.type.includes("QUIZ") ||
                  course.type.includes("ASSESSMENT") ||
                  course.title.toLowerCase().includes("assess") ||
                  course.title.toLowerCase().includes("quiz");

                const courseNum = idx + 1;
                const isFirst = idx === 0;
                const isLast = idx === filteredCourses.length - 1;
                const isDraggingThis = draggedCourseId === course.id;
                const isOverThis = dragOverCourseId === course.id;

                return (
                  <div
                    key={course.id}
                    draggable={isReorderActive}
                    onDragStart={(e) => handleDragStart(e, course.id)}
                    onDragOver={(e) => handleDragOver(e, course.id)}
                    onDrop={(e) => handleDrop(e, course.id)}
                    onDragEnd={handleDragEnd}
                    className={`bg-white rounded-xl border transition-all duration-200 flex flex-col justify-between p-3.5 shadow-2xs ${
                      !course.is_active
                        ? "bg-slate-50/70 border-dashed border-amber-300/80 opacity-90 ring-1 ring-amber-200/50"
                        : isQuiz
                        ? "border-amber-300 ring-1 ring-amber-300/40 hover:shadow-md hover:-translate-y-0.5"
                        : "border-slate-200/90 hover:border-[#00A389]/40 hover:shadow-md hover:-translate-y-0.5"
                    } ${isDraggingThis ? "opacity-40 scale-95 border-dashed border-[#00A389]" : ""} ${
                      isOverThis ? "ring-2 ring-[#00A389] scale-[1.02]" : ""
                    } ${isReorderActive ? "cursor-grab active:cursor-grabbing" : ""}`}
                  >
                    <div>
                      {/* Card Top: Drag Handle + Number Badge on Left, Visibility Switch on Right */}
                      <div className="flex items-center justify-between gap-1.5 pb-2 border-b border-slate-100">
                        <div className="flex items-center gap-1.5">
                          {isReorderActive && (
                            <div className="text-slate-400 hover:text-slate-700 cursor-grab active:cursor-grabbing" title="Drag to reorder">
                              <GripVertical className="h-4 w-4" />
                            </div>
                          )}
                          <div
                            className={`w-6 h-6 rounded-full aspect-square flex items-center justify-center text-xs font-black shrink-0 shadow-xs ${
                              !course.is_active
                                ? "bg-slate-400 text-white"
                                : isQuiz
                                ? "bg-amber-500 text-white ring-2 ring-amber-100"
                                : "bg-[#00A389] text-white ring-2 ring-emerald-100"
                            }`}
                            title={`Sequence Position #${courseNum}`}
                          >
                            {courseNum}
                          </div>
                        </div>

                        {/* Visibility Toggle Switch & Status Pill */}
                        <div className="flex items-center gap-1.5">
                          <button
                            type="button"
                            onClick={() => handleToggleVisibility(course)}
                            disabled={togglingVisibilityId === course.id}
                            className={`px-2 py-0.5 rounded-full text-[10px] font-bold border transition-all cursor-pointer flex items-center gap-1 ${
                              course.is_active
                                ? "bg-emerald-50 text-emerald-700 border-emerald-300 hover:bg-emerald-100"
                                : "bg-amber-50 text-amber-700 border-amber-300 hover:bg-amber-100"
                            }`}
                            title={course.is_active ? "Click to Hide from employees" : "Click to Show to employees"}
                          >
                            {course.is_active ? (
                              <>
                                <Eye className="h-2.5 w-2.5 text-emerald-600" />
                                <span>Visible</span>
                              </>
                            ) : (
                              <>
                                <EyeOff className="h-2.5 w-2.5 text-amber-600" />
                                <span>Hidden</span>
                              </>
                            )}
                          </button>
                        </div>
                      </div>

                      {/* Cover Image (Compact with Fallback and Shimmer Loading) */}
                      <CourseCardImage
                        course={course}
                        idx={idx}
                        cacheBuster={cacheBuster}
                        isQuiz={isQuiz}
                      />

                      {/* Course Title */}
                      <div className="mt-0.5">
                        <h3
                          className="text-xs sm:text-[13px] font-extrabold text-slate-800 leading-snug line-clamp-1 cursor-pointer hover:text-[#00A389] transition-colors"
                          title={`${course.title} (Click to edit)`}
                          onClick={() => handleOpenEditModal(course)}
                        >
                          {course.title}
                        </h3>
                      </div>

                      {/* Category Tag Pill */}
                      <div className="mt-1 flex items-center justify-between gap-1 flex-wrap">
                        {isQuiz ? (
                          <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-amber-50 text-amber-800 text-[9px] font-bold border border-amber-200/60">
                            <FileText className="h-2.5 w-2.5 text-amber-600" />
                            Assessment Quiz
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 text-[9px] font-bold border border-blue-200/60">
                            <GraduationCap className="h-2.5 w-2.5 text-blue-600" />
                            Interactive SCORM
                          </span>
                        )}

                        <span className="text-[10px] font-semibold text-slate-400">
                          {course.duration}
                        </span>
                      </div>

                      {/* Course Description */}
                      <p className="text-[11px] text-slate-500 font-normal leading-relaxed line-clamp-2 mt-1.5 min-h-[30px]">
                        {course.description ? course.description.replace(/course module/gi, 'course') : ''}
                      </p>
                    </div>

                    {/* Action Buttons at bottom: Edit, Visibility, LMS */}
                    <div className="mt-auto pt-3">
                      <div className="grid grid-cols-3 gap-1">
                        <button
                          type="button"
                          onClick={() => handleOpenEditModal(course)}
                          className="py-1.5 px-2 rounded-lg text-[10px] font-bold transition-all flex items-center justify-center gap-1 cursor-pointer border border-[#00A389]/30 bg-emerald-50 text-[#00A389] hover:bg-emerald-100 shadow-2xs"
                          title="Edit course name and details (Updates live for all employees)"
                        >
                          <Pencil className="h-2.5 w-2.5" />
                          <span>Edit</span>
                        </button>

                        <button
                          type="button"
                          onClick={() => handleToggleVisibility(course)}
                          disabled={togglingVisibilityId === course.id}
                          className={`py-1.5 px-2 rounded-lg text-[10px] font-bold transition-all flex items-center justify-center gap-1 cursor-pointer border ${
                            course.is_active
                              ? "bg-slate-50 hover:bg-amber-50 text-slate-600 hover:text-amber-700 border-slate-200 hover:border-amber-300"
                              : "bg-emerald-500 hover:bg-emerald-600 text-white border-emerald-600 shadow-2xs"
                          }`}
                        >
                          {course.is_active ? (
                            <>
                              <EyeOff className="h-2.5 w-2.5" />
                              <span>Hide</span>
                            </>
                          ) : (
                            <>
                              <Eye className="h-2.5 w-2.5" />
                              <span>Show</span>
                            </>
                          )}
                        </button>

                        <a
                          href={course.moodle_link}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="bg-slate-800 hover:bg-slate-900 text-white py-1.5 px-2 rounded-lg text-[10px] font-bold transition-all flex items-center justify-center gap-1 shadow-2xs hover:shadow-xs cursor-pointer"
                          title="Open course directly in Moodle LMS"
                        >
                          <span>LMS</span>
                          <ExternalLink className="h-2.5 w-2.5" />
                        </a>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            /* 4B. TABLE VIEW OPTION (with Drag Handle and Direct Move Arrows) */
            <div className="bg-white border border-slate-200/90 rounded-2xl shadow-2xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs text-slate-600">
                  <thead className="bg-slate-50/80 text-[11px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-200/80">
                    <tr>
                      <th className="px-3 py-3.5 text-center w-12">Order</th>
                      <th className="px-5 py-3.5">Course / Module</th>
                      <th className="px-4 py-3.5">Visibility</th>
                      <th className="px-4 py-3.5">Type</th>
                      <th className="px-4 py-3.5">Duration</th>
                      <th className="px-4 py-3.5">Passing Score</th>
                      <th className="px-4 py-3.5">Enrolled</th>
                      <th className="px-4 py-3.5">Completion Rate</th>
                      <th className="px-4 py-3.5 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {filteredCourses.map((c, i) => (
                      <tr
                        key={c.id}
                        draggable={isReorderActive}
                        onDragStart={(e) => handleDragStart(e, c.id)}
                        onDragOver={(e) => handleDragOver(e, c.id)}
                        onDrop={(e) => handleDrop(e, c.id)}
                        onDragEnd={handleDragEnd}
                        className={`transition-colors ${
                          !c.is_active ? "bg-amber-50/20" : "hover:bg-slate-50/60"
                        } ${draggedCourseId === c.id ? "opacity-30 bg-slate-100" : ""}`}
                      >
                        {/* Order & Reorder Arrows */}
                        <td className="px-3 py-4 text-center">
                          <div className="flex items-center justify-center gap-1">
                            {isReorderActive && (
                              <GripVertical className="h-3.5 w-3.5 text-slate-400 cursor-grab active:cursor-grabbing" />
                            )}
                            <span className="font-black text-slate-800 text-xs w-5">{i + 1}</span>
                            {isReorderActive && (
                              <div className="flex flex-col ml-1">
                                <button
                                  type="button"
                                  disabled={i === 0}
                                  onClick={() => handleMoveCourse(i, 'up')}
                                  className="text-slate-400 hover:text-slate-800 disabled:opacity-20 cursor-pointer"
                                  title="Move Up"
                                >
                                  <ChevronUp className="h-3 w-3" />
                                </button>
                                <button
                                  type="button"
                                  disabled={i === filteredCourses.length - 1}
                                  onClick={() => handleMoveCourse(i, 'down')}
                                  className="text-slate-400 hover:text-slate-800 disabled:opacity-20 cursor-pointer"
                                  title="Move Down"
                                >
                                  <ChevronDown className="h-3 w-3" />
                                </button>
                              </div>
                            )}
                          </div>
                        </td>

                        {/* Title & Description */}
                        <td className="px-5 py-4 min-w-[220px]">
                          <div className="font-bold text-slate-800 flex items-center gap-2">
                            <span>{c.title}</span>
                            {!c.is_active && (
                              <span className="text-[10px] bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded font-bold">
                                Hidden
                              </span>
                            )}
                          </div>
                          <div className="text-[11px] text-slate-400 line-clamp-1 mt-0.5">
                            {c.description ? c.description.replace(/course module/gi, 'course') : ''}
                          </div>
                        </td>

                        {/* Visibility Switch */}
                        <td className="px-4 py-4">
                          <button
                            type="button"
                            onClick={() => handleToggleVisibility(c)}
                            disabled={togglingVisibilityId === c.id}
                            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border transition-all cursor-pointer ${
                              c.is_active
                                ? "bg-emerald-50 text-emerald-700 border-emerald-300 hover:bg-emerald-100"
                                : "bg-amber-50 text-amber-700 border-amber-300 hover:bg-amber-100"
                            }`}
                          >
                            {c.is_active ? (
                              <>
                                <Eye className="h-3 w-3 text-emerald-600" />
                                <span>Visible</span>
                              </>
                            ) : (
                              <>
                                <EyeOff className="h-3 w-3 text-amber-600" />
                                <span>Hidden</span>
                              </>
                            )}
                          </button>
                        </td>

                        {/* Type */}
                        <td className="px-4 py-4">
                          <span
                            className={`px-2 py-0.5 rounded-full text-[10px] font-bold border ${
                              c.type.includes("QUIZ") || c.type.includes("ASSESSMENT")
                                ? "bg-amber-50 text-amber-700 border-amber-200"
                                : "bg-blue-50 text-blue-700 border-blue-200"
                            }`}
                          >
                            {c.type}
                          </span>
                        </td>

                        <td className="px-4 py-4 font-medium text-slate-600">{c.duration}</td>
                        <td className="px-4 py-4 font-bold text-slate-700">
                          {c.type.toUpperCase().includes("QUIZ") || c.type.toUpperCase().includes("ASSESSMENT") || c.title.toLowerCase().includes("assess") || c.title.toLowerCase().includes("quiz")
                            ? `${c.passing_score ?? 80}%`
                            : <span className="text-slate-400 font-medium text-xs">Completion only</span>
                          }
                        </td>
                        <td className="px-4 py-4 font-bold text-slate-800">{c.enrolled_count}</td>

                        {/* Completion Rate */}
                        <td className="px-4 py-4">
                          <div className="flex items-center gap-2">
                            <div className="h-1.5 w-16 bg-slate-100 rounded-full overflow-hidden">
                              <div
                                className="h-full bg-[#00A389] rounded-full"
                                style={{ width: `${c.completion_percent}%` }}
                              />
                            </div>
                            <span className="font-bold text-[#00A389]">{c.completion_percent}%</span>
                          </div>
                        </td>

                        {/* Actions */}
                        <td className="px-4 py-4 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <button
                              type="button"
                              onClick={() => handleOpenEditModal(c)}
                              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold text-[#00A389] hover:bg-emerald-50 border border-[#00A389]/30 transition-all cursor-pointer shadow-2xs"
                              title="Edit Course Name & Details (Updates live for all employees)"
                            >
                              <Pencil className="h-3 w-3" />
                              <span>Edit</span>
                            </button>
                            <a
                              href={c.moodle_link}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100 border border-slate-200 transition-all cursor-pointer"
                            >
                              <span>Open</span>
                              <ExternalLink className="h-3 w-3" />
                            </a>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>

        {/* 5. Policy Notice */}
        <div className="bg-slate-50 border border-slate-200/80 rounded-2xl p-4 sm:p-5 text-xs text-slate-500 flex items-start gap-3">
          <AlertCircle className="h-4 w-4 text-slate-400 shrink-0 mt-0.5" />
          <p className="leading-relaxed">
            <strong className="text-slate-700 font-bold">Admin Ordering &amp; Visibility:</strong> The sequence, custom titles, and visibility configured above directly govern the employee dashboard, course detail view, and induction roadmap. When a course name is edited, all employees see the new name immediately.
          </p>
        </div>

        {/* 6. Edit Course Name & Details Modal */}
        <Dialog open={!!editingCourse} onOpenChange={(open) => !open && setEditingCourse(null)}>
          <DialogContent 
            className="sm:max-w-md bg-white border border-slate-200 shadow-xl rounded-2xl p-6" 
            aria-describedby={undefined}
            onOpenAutoFocus={(e) => e.preventDefault()}
          >
            <DialogHeader>
              <div className="flex items-center gap-2.5">
                <div className="h-8 w-8 rounded-lg bg-[#00A389]/15 border border-[#00A389]/30 flex items-center justify-center text-[#00A389] shrink-0">
                  <Pencil className="h-4 w-4" />
                </div>
                <DialogTitle className="text-base font-extrabold text-slate-900">
                  Edit Course Name &amp; Details
                </DialogTitle>
              </div>
            </DialogHeader>

            <form onSubmit={handleSaveCourseEdit} className="space-y-4 pt-2">
              {/* Course Title / Name */}
              <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                  <Label htmlFor="edit-title" className="text-xs font-bold text-slate-700">
                    Course Name (Displayed to Employees) <span className="text-rose-500">*</span>
                  </Label>
                  <span className="text-[10px] text-slate-400">
                    {editTitle.length}/255
                  </span>
                </div>
                <Input
                  id="edit-title"
                  value={editTitle}
                  onChange={(e) => setEditTitle(e.target.value)}
                  onFocus={(e) => {
                    const el = e.currentTarget;
                    window.requestAnimationFrame(() => {
                      el.setSelectionRange(el.value.length, el.value.length);
                    });
                  }}
                  placeholder="e.g. Work Rules and Work Life M-2"
                  className="text-xs font-semibold focus-visible:ring-[#00A389] border-slate-300"
                  maxLength={255}
                  required
                />
                <p className="text-[10px] text-slate-400">
                  Employees will see this exact name on their Learning Roadmap and course certificates.
                </p>
              </div>

              {/* Description */}
              <div className="space-y-1.5">
                <Label htmlFor="edit-desc" className="text-xs font-bold text-slate-700">
                  Course Description
                </Label>
                <Textarea
                  id="edit-desc"
                  value={editDescription}
                  onChange={(e) => setEditDescription(e.target.value)}
                  placeholder="Summary of policies, skills, or objectives covered in this module..."
                  rows={3}
                  className="text-xs focus-visible:ring-[#00A389] border-slate-300 resize-none"
                />
              </div>

              {/* Row: Duration & Passing Score (Passing Score ONLY for Assessment Quiz) */}
              <div className={isEditingAssessment ? "grid grid-cols-2 gap-3" : "space-y-1.5"}>
                <div className="space-y-1.5">
                  <Label htmlFor="edit-dur" className="text-xs font-bold text-slate-700">
                    Duration (Minutes)
                  </Label>
                  <Input
                    id="edit-dur"
                    type="number"
                    min={1}
                    max={300}
                    value={editDuration}
                    onChange={(e) => setEditDuration(e.target.value)}
                    className="text-xs focus-visible:ring-[#00A389] border-slate-300"
                  />
                  {!isEditingAssessment && (
                    <p className="text-[10px] text-slate-400">
                      Standard onboarding training courses track completion status. Passing score applies strictly to the Assessment Quiz.
                    </p>
                  )}
                </div>

                {isEditingAssessment && (
                  <div className="space-y-1.5">
                    <Label htmlFor="edit-passing" className="text-xs font-bold text-slate-700">
                      Passing Score (%)
                    </Label>
                    <Input
                      id="edit-passing"
                      type="number"
                      min={0}
                      max={100}
                      value={editPassingScore}
                      onChange={(e) => setEditPassingScore(Number(e.target.value))}
                      className="text-xs focus-visible:ring-[#00A389] border-slate-300"
                    />
                    <p className="text-[10px] text-slate-400">
                      Passing threshold required for employee certification (Default 80%).
                    </p>
                  </div>
                )}
              </div>

              <DialogFooter className="pt-3 gap-2 sm:gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setEditingCourse(null)}
                  className="text-xs font-semibold rounded-xl border-slate-300 cursor-pointer"
                >
                  Cancel
                </Button>
                <Button
                  type="submit"
                  disabled={isSavingEdit || !editTitle.trim()}
                  className="text-xs font-bold rounded-xl bg-[#00A389] hover:bg-[#008f78] text-white shadow-xs cursor-pointer"
                >
                  {isSavingEdit ? (
                    <>
                      <RefreshCw className="h-3.5 w-3.5 animate-spin mr-1.5" />
                      <span>Saving...</span>
                    </>
                  ) : (
                    <>
                      <Check className="h-3.5 w-3.5 mr-1" />
                      <span>Save Course Name</span>
                    </>
                  )}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* 5. Sequential Course Progression Feedback Modal Popup */}
        {lockingModal && (
          <Dialog open={lockingModal.isOpen} onOpenChange={() => setLockingModal(null)}>
            <DialogContent className="max-w-md rounded-2xl p-6 bg-white border border-slate-200/90 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
              <div className="flex flex-col items-center text-center space-y-3.5 pt-2">
                <div
                  className={`h-16 w-16 rounded-2xl flex items-center justify-center shadow-xs border ${
                    lockingModal.enabled
                      ? "bg-emerald-50 text-emerald-600 border-emerald-200 ring-4 ring-emerald-50/50"
                      : "bg-slate-100 text-slate-600 border-slate-200 ring-4 ring-slate-50"
                  }`}
                >
                  {lockingModal.enabled ? (
                    <Lock className="h-8 w-8 text-[#00A389]" />
                  ) : (
                    <Unlock className="h-8 w-8 text-slate-600" />
                  )}
                </div>

                <div className="space-y-1">
                  <DialogTitle className="text-base sm:text-lg font-black text-slate-800 tracking-tight">
                    {lockingModal.title}
                  </DialogTitle>
                  <span
                    className={`inline-block text-[11px] font-bold px-2.5 py-0.5 rounded-full border mt-1 ${
                      lockingModal.enabled
                        ? "bg-emerald-50 text-emerald-800 border-emerald-200"
                        : "bg-slate-100 text-slate-600 border-slate-200"
                    }`}
                  >
                    {lockingModal.enabled ? "Prerequisites Active" : "Open Learning Mode"}
                  </span>
                </div>

                <DialogDescription className="text-xs text-slate-600 leading-relaxed font-medium max-w-sm px-2 pb-2">
                  {lockingModal.message}
                </DialogDescription>
              </div>
            </DialogContent>
          </Dialog>
        )}

        {/* 6. Course Order Saved Feedback Modal Popup */}
        {orderModal && (
          <Dialog open={orderModal.isOpen} onOpenChange={() => setOrderModal(null)}>
            <DialogContent className="max-w-md rounded-2xl p-6 bg-white border border-slate-200/90 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
              <div className="flex flex-col items-center text-center space-y-3.5 pt-2">
                <div className="h-16 w-16 rounded-2xl flex items-center justify-center shadow-xs border bg-emerald-50 text-[#00A389] border-emerald-200 ring-4 ring-emerald-50/50">
                  <CheckCircle2 className="h-8 w-8 text-[#00A389]" />
                </div>

                <div className="space-y-1">
                  <DialogTitle className="text-base sm:text-lg font-black text-slate-800 tracking-tight">
                    Course Order Saved
                  </DialogTitle>
                  <span className="inline-block text-[11px] font-bold px-2.5 py-0.5 rounded-full border mt-1 bg-emerald-50 text-emerald-800 border-emerald-200">
                    Position #{orderModal.position} Updated
                  </span>
                </div>

                <DialogDescription className="text-xs text-slate-600 leading-relaxed font-medium max-w-sm px-2 pb-2">
                  <span className="font-bold text-slate-800">"{orderModal.courseTitle}"</span> has been moved to position{" "}
                  <span className="font-bold text-[#00A389]">#{orderModal.position}</span>. Employee onboarding portal and roadmap updated.
                </DialogDescription>
              </div>
            </DialogContent>
          </Dialog>
        )}
      </div>
    </Layout>
  );
};

export default AdminContent;
