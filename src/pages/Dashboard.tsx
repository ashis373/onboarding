import { useState, useEffect, useMemo, useCallback, useRef } from "react";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Leaf,
  Users,
  ShieldCheck,
  Building,
  HeartPulse,
  Wrench,
  Shield,
  Clock,
  TrendingUp,
  BookOpen,
  GraduationCap,
  Lock,
  Unlock,
  Play,
  Check,
  Flag,
  ChevronRight,
  ChevronDown,
  Trophy,
  ArrowRight,
  Sparkles,
  Calendar,
  Layers,
  CheckCircle,
  CheckCircle2,
  ExternalLink,
  Mail,
  FileText,
  RotateCcw,
  Footprints,
  HelpCircle,
  Compass,
  RefreshCw,
  AlertTriangle,
  X,
  BarChart3,
  Link2,
  Briefcase,
  Search,
  ArrowUpDown,
  Eye,
  Lightbulb,
  XCircle,
  LayoutGrid,
  Home,
  CircleDot,
  Target,
  AlertCircle,
  PauseCircle,
  Pause,
  Box,
  StepForward
} from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import { apiGet, apiPost, API_BASE } from "@/lib/api";
import { useNavigate, useSearchParams } from "react-router-dom";
import { COURSES_DATA, CourseDefinition } from "@/data/coursesData";
import { useCourseProgress } from "@/hooks/useCourseProgress";
import { CoursePlayerModal } from "@/components/CoursePlayerModal";
import { ScormPlayerModal } from "@/components/ScormPlayerModal";
import { ActiveCourseModal, useActiveCourseSession } from "@/components/ActiveCourseModal";
import { useQuery } from "@tanstack/react-query";
import { toast } from "@/hooks/use-toast";

export const IS_MAINTENANCE_MODE = false;

export interface DashboardProps {
  forcedTab?: 'roadmap' | 'scorm_report' | 'attempt_history' | 'all';
}

export const Dashboard = ({ forcedTab }: DashboardProps = {}) => {
  const { user, loading } = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const isLocalCorHis = typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis');
  const corhisBase = isLocalCorHis ? '/internal/corhis' : '';

  // Course player modal state
  const [activeCourseId, setActiveCourseId] = useState<number | null>(null);

  // Profile avatar image state with graceful error recovery
  const [avatarImgFailed, setAvatarImgFailed] = useState(false);
  const [avatarSrc, setAvatarSrc] = useState<string>('');

  useEffect(() => {
    const rawUrl = user?.profile_img_url || (user?.profile_img ? `${corhisBase}/dist/img/user/${user.profile_img}` : '');
    if (rawUrl) {
      let url = rawUrl;
      try {
        url = encodeURI(decodeURI(url));
      } catch { }
      setAvatarSrc(url);
      setAvatarImgFailed(false);
    } else {
      setAvatarSrc('');
      setAvatarImgFailed(false);
    }
  }, [user?.profile_img_url, user?.profile_img]);

  const handleAvatarError = () => {
    if (avatarSrc && avatarSrc.startsWith('/internal/') && !avatarSrc.startsWith('http')) {
      setAvatarSrc(`https://hexalearn.com${avatarSrc}`);
    } else {
      setAvatarImgFailed(true);
    }
  };

  // Hook managing persistent sequential locking and slide progression
  const {
    coursesState,
    getCourseStatus,
    completedCount,
    totalCourses,
    progressPercentage,
    isFinalAssessmentUnlocked,
    currentActiveCourseId,
    finalAssessmentCompleted,
    finalAssessmentScore,
    resetAllProgress,
  } = useCourseProgress();

  // 1. Cached Dashboard Summary Query
  const { data: summaryData, isLoading: loadingSummary, refetch: refetchSummary } = useQuery({
    queryKey: ['dashboard_summary', user?.uid],
    queryFn: async () => {
      try {
        const data = await apiGet<{ success: boolean; summary: any }>(`/dashboard/summary.php`);
        return data?.success ? data.summary : null;
      } catch (_e) {
        return null;
      }
    },
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
  });
  const summary = summaryData ?? null;

  // 2. Cached Moodle Courses Query (Event-driven sync on course close; 0 calls on refresh/tab switch)
  const forceSyncRef = useRef(false);
  const syncCourseIdRef = useRef<string | number | null>(null);
  const {
    data: moodleDataRaw,
    isLoading: syncingLms,
    refetch: fetchMoodleCourses,
  } = useQuery<{
    success: boolean;
    linked: boolean;
    employee?: { uid: number; username: string; name: string; moodle_user_id: number };
    moodle_status?: { connected: boolean; has_warning?: boolean; warnings?: string[]; error?: string; synced_at?: string };
    error?: string;
    courses?: any[];
  } | null>({
    queryKey: ['moodle_courses', user?.uid],
    queryFn: async () => {
      try {
        const shouldSync = forceSyncRef.current;
        const syncCid = syncCourseIdRef.current;
        forceSyncRef.current = false;
        syncCourseIdRef.current = null;
        const url = shouldSync
          ? `/moodle/courses.php?sync_progress=1${syncCid ? `&sync_course_id=${encodeURIComponent(String(syncCid))}` : ''}`
          : `/moodle/courses.php`;
        const res = await apiGet<any>(url);
        if (res && res.success !== false && Array.isArray(res.courses) && res.courses.length > 0) {
          return res;
        }
        if (res && res.success === false) {
          throw new Error(res.error || 'Failed to connect to Moodle API');
        }
        return res || null;
      } catch (err: any) {
        // Throw so React Query retains previously cached valid data rather than overwriting with empty
        throw err;
      }
    },
    placeholderData: (previousData) => previousData,
    enabled: !!user?.uid,
    staleTime: 5 * 1000, // 5 seconds fresh sync so admin visibility changes reflect instantly
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false, // Prevent redundant background fetches on simple window clicks
    refetchInterval: false, // Disable background polling to prevent Moodle REST request flooding; progress refreshes automatically on course completion/closure
    retry: 1,
  });

  const moodleData = moodleDataRaw ?? null;
  const isInitialLoading = Boolean(syncingLms && !moodleData);

  const moodleError = useMemo(() => {
    if (!moodleData) return null;
    if (!moodleData.success && moodleData.error) return moodleData.error;
    if (moodleData.moodle_status && !moodleData.moodle_status.connected && moodleData.moodle_status.error) {
      return moodleData.moodle_status.error;
    }
    if (moodleData.courses && moodleData.courses[0]?.section_error) {
      return `Moodle Warning: ${moodleData.courses[0].section_error}`;
    }
    return null;
  }, [moodleData]);

  const tabParam = forcedTab || searchParams.get('tab');
  const getInitialTab = (): 'roadmap' | 'scorm_report' | 'attempt_history' | 'all' => {
    if (forcedTab) return forcedTab;
    if (tabParam === 'roadmap' || tabParam === 'scorm_report' || tabParam === 'attempt_history' || tabParam === 'all') {
      return tabParam;
    }
    return 'all';
  };
  const [activeTab, setActiveTab] = useState<'roadmap' | 'scorm_report' | 'attempt_history' | 'all'>(getInitialTab);

  useEffect(() => {
    if (forcedTab) {
      setActiveTab(forcedTab);
      return;
    }
    const currentParam = searchParams.get('tab');
    if (currentParam === 'roadmap' || currentParam === 'scorm_report' || currentParam === 'attempt_history' || currentParam === 'all') {
      setActiveTab(currentParam);
    } else if (!currentParam) {
      setActiveTab('all');
    }
  }, [forcedTab, searchParams]);

  const handleTabChange = (newTab: 'all' | 'roadmap' | 'scorm_report' | 'attempt_history') => {
    setActiveTab(newTab);
    window.scrollTo({ top: 0, left: 0, behavior: "instant" });
    if (document.documentElement) document.documentElement.scrollTop = 0;
    if (document.body) document.body.scrollTop = 0;
    if (newTab === 'all') {
      navigate('/dashboard');
    } else if (newTab === 'roadmap') {
      navigate('/modules');
    } else if (newTab === 'scorm_report') {
      navigate('/modules');
    } else if (newTab === 'attempt_history') {
      navigate('/attempt-history');
    }
  };

  const [activityFilter, setActivityFilter] = useState<'all' | 'training' | 'assessments'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState<'recommended' | 'name' | 'progress'>('recommended');

  // In-portal SCORM player state with sessionStorage persistence across browser F5 refresh
  const [playerState, setPlayerState] = useState<{
    isOpen: boolean;
    url: string | null;
    title: string;
  }>(() => {
    try {
      const saved = sessionStorage.getItem('hexalearn_active_scorm');
      if (saved) {
        const parsed = JSON.parse(saved);
        if (parsed.url) {
          return { isOpen: true, url: parsed.url, title: parsed.title || 'Onboarding Training Module' };
        }
      }
    } catch { }
    return { isOpen: false, url: null, title: '' };
  });

    // Debounced, single refresh coordinator to eliminate redundant concurrent requests
    const lastRefreshTimeRef = useRef<number>(0);
    const refreshTimerRef = useRef<NodeJS.Timeout | null>(null);
    const activePollingIntervalRef = useRef<NodeJS.Timeout | null>(null);

    const triggerUnifiedRefresh = useCallback((forceSync = false, courseId?: string | number | null) => {
      const now = Date.now();
      if (forceSync) {
        forceSyncRef.current = true;
        if (courseId) {
          syncCourseIdRef.current = courseId;
        }
      }
      // Enforce minimum 2s interval between network refreshes
      if (now - lastRefreshTimeRef.current < 2000) {
        if (!refreshTimerRef.current) {
          refreshTimerRef.current = setTimeout(() => {
            refreshTimerRef.current = null;
            lastRefreshTimeRef.current = Date.now();
            fetchMoodleCourses();
            refetchSummary();
          }, 2000);
        }
        return;
      }
      lastRefreshTimeRef.current = now;
      fetchMoodleCourses();
      refetchSummary();
    }, [fetchMoodleCourses, refetchSummary]);

    const {
      activeSession,
      launchCourseWindow,
      focusCourseWindow,
      closeActiveSession,
    } = useActiveCourseSession({
      onCourseClosed: (closedCourseId) => triggerUnifiedRefresh(true, closedCourseId),
    });

    const openPlayer = (url: string, title: string, itemOptions?: any) => {
      if (IS_MAINTENANCE_MODE) {
        toast({
          title: "System Maintenance in Progress",
          description: "Course launching and playback are temporarily suspended while platform maintenance is underway. Please check back shortly.",
          variant: "destructive",
        });
        return;
      }

      const cmid = itemOptions?.cmid || (url && url.match(/[?&]cm(?:id)?=(\d+)/)?.[1]);
      const mode = itemOptions?.mode || (url && url.includes('mode=review') ? 'review' : undefined);
      const newattempt = itemOptions?.newattempt || (url && (url.includes('newattempt=on') || url.includes('newattempt=1')) ? 'on' : undefined);
      const scoid = itemOptions?.scoid || (url && url.match(/[?&]scoid=(\d+)/)?.[1]);
      const currentorg = itemOptions?.organization || itemOptions?.currentorg || (url && url.match(/[?&]currentorg=([^&]+)/)?.[1]);
      const courseid = itemOptions?.course_id || (url && url.match(/[?&]courseid=(\d+)/)?.[1]);
      const moduleId = itemOptions?.module_id || (url && url.match(/[?&]module_id=(\d+)/)?.[1]);

      const playParams = new URLSearchParams();
      if (cmid) playParams.set('cmid', String(cmid));
      if (title) playParams.set('title', title);
      if (mode) playParams.set('mode', mode);
      if (newattempt) playParams.set('newattempt', 'on');
      if (scoid) playParams.set('scoid', String(scoid));
      if (courseid) playParams.set('courseid', String(courseid));
      if (moduleId) playParams.set('module_id', String(moduleId));
      if (currentorg) playParams.set('currentorg', String(currentorg));
      if (!cmid && url) playParams.set('url', url);

      const playUrl = `${routerBase}/play?${playParams.toString()}`;

      // Open course in dedicated separate window (not tab) with multi-tab coordination
      const courseWin = launchCourseWindow(playUrl, title, {
        cmid,
        scoid,
        courseid,
        module_id: moduleId,
        ...itemOptions,
      });

      if (!courseWin) {
        // Fallback: If browser popup blocker intercepts, open inside in-portal modal
        const state = { isOpen: true, url, title };
        try {
          sessionStorage.setItem('hexalearn_active_scorm', JSON.stringify(state));
        } catch { }
        setPlayerState(state);
      }
    };

  const handleLaunchModule = (item: any) => {
    if (!item || item.status === 'locked' || item.is_locked) return;
    if (item.status === 'completed' && !item.admin_unlocked) {
      toast({
        title: "Course Completed & Locked",
        description: "Once you complete a course, you cannot launch it again without your manager's approval. If you wish to relaunch the course, please contact your manager for authorization.",
        variant: "destructive",
      });
      return;
    }
    const launchUrl = item.url || item.launch_url || (item.cmid
      ? `${API_BASE}/moodle/launch.php?cmid=${item.cmid}${item.scoid ? `&scoid=${item.scoid}` : ''}${item.course_id || item.moodle_id ? `&courseid=${item.course_id || item.moodle_id}` : ''}`
      : (item.course_id || item.moodle_id ? `${API_BASE}/moodle/launch.php?courseid=${item.course_id || item.moodle_id}` : `${API_BASE}/moodle/launch.php`));
    openPlayer(launchUrl, item.title || item.name || 'Onboarding Training Module', item);
  };

  const handleRetakeModule = (item: any) => {
    if (!item) return;
    const retakeUrl = item.retake_url || `${item.url}&newattempt=on`;
    openPlayer(retakeUrl, `${item.title || item.name || 'Assessment'} (Retake)`, { ...item, newattempt: 'on' });
  };

  const handleReviewModule = (item: any) => {
    if (!item) return;
    if (item.status === 'completed' && !item.admin_unlocked) {
      toast({
        title: "Course Completed & Locked",
        description: "Once you complete a course, you cannot launch it again without your manager's approval. If you wish to relaunch the course, please contact your manager for authorization.",
        variant: "destructive",
      });
      return;
    }
    const reviewUrl = item.review_url || `${item.url}&mode=review`;
    openPlayer(reviewUrl, `${item.title || item.name || 'Assessment'} (Review)`, { ...item, mode: 'review' });
  };

  const handleClosePlayer = () => {
    try {
      sessionStorage.removeItem('hexalearn_active_scorm');
    } catch { }
    setPlayerState({ isOpen: false, url: null, title: '' });
    // Automatically reflect latest progress, score, and summary upon closing the course via unified refresh
    triggerUnifiedRefresh();
  };

  // Consolidated auto-refresh: BroadcastChannel, storage events, window focus, visibility, and bfcache pageshow
  useEffect(() => {
    // 1. Mount & Route Transition Check: Discovers SCORM exit even when Dashboard was unmounted
    const checkPendingSync = () => {
      try {
        const needsSync = sessionStorage.getItem('hexalearn_scorm_needs_sync') || localStorage.getItem('hexalearn_scorm_last_closed');
        const lastHandled = sessionStorage.getItem('hexalearn_scorm_last_handled');
        if (needsSync && (!lastHandled || Number(needsSync) > Number(lastHandled))) {
          sessionStorage.setItem('hexalearn_scorm_last_handled', needsSync);
          sessionStorage.removeItem('hexalearn_scorm_needs_sync');
          const lastCid = localStorage.getItem('hexalearn_last_closed_course_id') || sessionStorage.getItem('hexalearn_last_closed_course_id');
          triggerUnifiedRefresh(true, lastCid);
        }
      } catch { }
    };
    checkPendingSync();

    let bc: BroadcastChannel | null = null;
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        bc = new BroadcastChannel('hexalearn_scorm_channel');
        bc.onmessage = (e) => {
          if (e.data?.type === 'COURSE_CLOSED' || e.data?.type === 'COURSE_PROGRESS') {
            triggerUnifiedRefresh(true, e.data?.courseid);
          }
        };
      }
    } catch { }

    const handleStorageChange = (e: StorageEvent) => {
      if (e.key === 'hexalearn_scorm_last_closed') {
        const lastCid = localStorage.getItem('hexalearn_last_closed_course_id');
        triggerUnifiedRefresh(true, lastCid);
      }
    };
    window.addEventListener('storage', handleStorageChange);

    // Sync only when a course window actually closed (no timed polling on tab switches)
    const handleFocusOrVisibility = () => {
      if (document.visibilityState === 'visible') {
        checkPendingSync();
      }
    };
    window.addEventListener('focus', handleFocusOrVisibility);
    document.addEventListener('visibilitychange', handleFocusOrVisibility);
    window.addEventListener('pageshow', handleFocusOrVisibility);

    return () => {
      if (bc) {
        try { bc.close(); } catch { }
      }
      window.removeEventListener('storage', handleStorageChange);
      window.removeEventListener('focus', handleFocusOrVisibility);
      document.removeEventListener('visibilitychange', handleFocusOrVisibility);
      window.removeEventListener('pageshow', handleFocusOrVisibility);

      if (refreshTimerRef.current) {
        clearTimeout(refreshTimerRef.current);
        refreshTimerRef.current = null;
      }
      if (activePollingIntervalRef.current) {
        clearInterval(activePollingIntervalRef.current);
        activePollingIntervalRef.current = null;
      }
    };
  }, [triggerUnifiedRefresh]);

  // Decode HTML entities (e.g. &amp; -> &) in course and module titles
  const decodeCourseTitle = (raw?: string) => {
    if (!raw) return '';
    return raw
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#039;/g, "'")
      .replace(/&apos;/g, "'");
  };

  // Extract all real learning modules dynamically from Moodle (Filtering out non-learning forums & labels)
  const moodleModules = useMemo(() => {
    if (!moodleData?.courses || moodleData.courses.length === 0) return [];

    const list: Array<{
      id: number;
      cmid?: number;
      instance?: number;
      title: string;
      subtitle: string;
      duration: string;
      section: string;
      modname: string;
      status: 'completed' | 'failed' | 'in_progress' | 'available' | 'locked';
      url: string;
      retake_url?: string;
      review_url?: string;
      passing_score?: number | null;
      total_time: string;
      score: number | null;
      attempts?: number;
      attempts_history?: any[];
      timemodified?: string | number | null;
      first_started_at?: string | null;
      last_played_at?: string | null;
      current_slide?: number;
      total_slides?: number;
      progress_percent?: number;
      course_id?: number;
      course_image?: string;
    }> = [];

    let autoId = 1;
    moodleData.courses.forEach((c: any, cIdx: number) => {
      const courseIdNum = Number(c.id) || (cIdx === 0 ? 2 : cIdx === 1 ? 3 : 4);
      const courseImage = c.course_image || `${API_BASE}/moodle/course_image.php?course_id=${courseIdNum}`;
      const isCourseCompleted = Boolean(c.completed || c.is_completed || c.status === 'completed' || Number(c.progress_percent) === 100);

      if (c.modules && Array.isArray(c.modules) && c.modules.length > 0) {
        c.modules.forEach((m: any) => {
          // Strictly exclude forums (like Announcements) and labels from the learning roadmap
          const modType = (m.modname || '').toLowerCase();
          if (['forum', 'label', 'chat', 'news'].includes(modType)) {
            return;
          }

          const hasTimeSpent = (t?: string) => {
            if (!t) return false;
            const clean = t.trim().split('.')[0];
            return clean !== '' && clean !== '00:00:00' && clean !== '0' && clean !== '0s';
          };

          const courseDurMin = m.duration_minutes || c.duration_minutes || null;
          const durationFormatted = hasTimeSpent(m.total_time)
            ? `Spent: ${m.total_time.split('.')[0]}`
            : (m.duration || (courseDurMin ? `${courseDurMin} min` : (isAssess ? '20 min' : '30 min')));

          const targetUrl = m.url || m.launch_url || (m.cmid ? `${API_BASE}/moodle/launch.php?cmid=${m.cmid}` : '');

          const isAssess = Boolean(
            m.is_assessment ||
            m.title?.toLowerCase().includes('assess') ||
            m.title?.toLowerCase().includes('quiz')
          );
          const passingThreshold = isAssess ? (m.passing_score !== undefined && m.passing_score !== null ? Number(m.passing_score) : 80) : null;
          const currentScore = isAssess && m.score !== null && m.score !== undefined ? Number(m.score) : null;

          const isFailed = Boolean(isAssess && (
            m.is_failed ||
            m.status === 'failed' ||
            (currentScore !== null && passingThreshold !== null && currentScore < passingThreshold)
          ));

          const isDone = Boolean(
            !isFailed && (
              isCourseCompleted ||
              (isAssess
                ? ((currentScore !== null && passingThreshold !== null && currentScore >= passingThreshold) || m.is_passed || m.status === 'passed' || m.status === 'completed')
                : (m.is_completed || ['completed', 'passed'].includes(m.status?.toLowerCase() || '') || Number(m.progress_percent) === 100))
            )
          );

          const inProgress = !isDone && !isFailed && (
            m.is_in_progress ||
            m.status === 'in_progress' ||
            m.status === 'incomplete' ||
            m.status === 'browsed' ||
            m.status === 'suspend' ||
            (Number(m.progress_percent) > 0 && Number(m.progress_percent) < 100) ||
            hasTimeSpent(m.total_time)
          );

          const statusVal: 'completed' | 'failed' | 'in_progress' | 'available' = isFailed
            ? 'failed'
            : isDone
              ? 'completed'
              : inProgress
                ? 'in_progress'
                : 'available';

          const rawSub = m.section && m.section !== 'Single activity' && m.section !== 'General'
            ? m.section
            : (c.fullname || 'Onboarding Training');

          const totalSlidesCount = Number(m.total_slides) || 10;
          const currentSlideVal = isDone ? totalSlidesCount : ((statusVal === 'available' || statusVal === 'not_started' || Number(m.progress_percent) === 0) ? 0 : (Number(m.current_slide) || 1));

          list.push({
            id: autoId++,
            cmid: m.cmid,
            scoid: m.scoid || (targetUrl && targetUrl.match(/[?&]scoid=(\d+)/)?.[1]) || null,
            currentorg: m.organization || (targetUrl && targetUrl.match(/[?&]currentorg=([^&]+)/)?.[1]) || null,
            module_id: m.module_id || m.id || null,
            instance: m.instance,
            title: decodeCourseTitle(m.title || c.fullname || `Module ${autoId}`),
            subtitle: decodeCourseTitle(rawSub),
            description: m.description || c.description || null,
            duration_minutes: courseDurMin,
            duration: durationFormatted,
            section: m.section || 'General',
            modname: m.modname || 'scorm',
            is_assessment: isAssess,
            status: statusVal,
            url: targetUrl,
            retake_url: m.retake_url || `${targetUrl}&newattempt=on`,
            review_url: m.review_url || `${targetUrl}&mode=review`,
            passing_score: isAssess ? (m.passing_score !== undefined && m.passing_score !== null ? Number(m.passing_score) : 80) : null,
            total_time: m.total_time || '',
            score: isAssess && m.score !== null && m.score !== undefined ? Number(m.score) : null,
            attempts: isAssess ? Number(m.attempts || 0) : 0,
            attempts_history: isAssess ? (m.attempts_history || []).filter((att: any) => {
              const hasDate = Boolean(att.date && att.date !== '' && att.date !== '—');
              const hasScore = att.score !== null && att.score !== undefined;
              const hasTime = Boolean(att.total_time && att.total_time !== '' && att.total_time !== '00:00:00' && att.total_time !== '0s');
              const hasRealStatus = Boolean(att.status && att.status !== 'not_started' && att.result !== 'Incomplete');
              return hasDate || hasScore || hasTime || hasRealStatus;
            }) : [],
            timemodified: m.timemodified || null,
            first_started_at: m.first_started_at || null,
            last_played_at: m.last_played_at || null,
            current_slide: currentSlideVal,
            total_slides: totalSlidesCount,
            progress_percent: isDone ? 100 : (m.progress_percent !== undefined && m.progress_percent !== null && Number(m.progress_percent) > 0 ? Number(m.progress_percent) : (inProgress ? 35 : 0)),
            course_id: courseIdNum,
            course_image: courseImage,
            admin_unlocked: Boolean(m.admin_unlocked || c.admin_unlocked),
          });
        });
      } else if (c.scorms && Array.isArray(c.scorms) && c.scorms.length > 0) {
        c.scorms.forEach((sc: any) => {
          const hasTimeSpent = (t?: string) => {
            if (!t) return false;
            const clean = t.trim().split('.')[0];
            return clean !== '' && clean !== '00:00:00' && clean !== '0' && clean !== '0s';
          };
          const scDurMin = sc.duration_minutes || c.duration_minutes || null;
          const durationFormatted = hasTimeSpent(sc.total_time)
            ? `Spent: ${sc.total_time.split('.')[0]}`
            : (sc.duration || (scDurMin ? `${scDurMin} min` : (isAssess ? '20 min' : '30 min')));
          const targetUrl = sc.url || sc.launch_url || (sc.id ? `${API_BASE}/moodle/launch.php?cmid=${sc.id}${sc.scoid ? `&scoid=${sc.scoid}` : ''}` : '');
          const isAssess = Boolean(
            sc.is_assessment ||
            sc.name?.toLowerCase().includes('assess') ||
            sc.name?.toLowerCase().includes('quiz')
          );
          const passingThreshold = isAssess ? (sc.passing_score !== undefined && sc.passing_score !== null ? Number(sc.passing_score) : 80) : null;
          const currentScore = isAssess && sc.score !== null && sc.score !== undefined ? Number(sc.score) : null;

          const isFailed = Boolean(isAssess && (
            sc.is_failed ||
            sc.status === 'failed' ||
            (currentScore !== null && passingThreshold !== null && currentScore < passingThreshold)
          ));

          const isDone = Boolean(
            !isFailed && (
              isCourseCompleted ||
              (isAssess
                ? ((currentScore !== null && passingThreshold !== null && currentScore >= passingThreshold) || sc.is_passed || sc.status === 'passed' || sc.status === 'completed')
                : (sc.is_completed || ['completed', 'passed'].includes(sc.status?.toLowerCase() || '') || Number(sc.progress_percent) === 100))
            )
          );

          const inProgress = !isDone && !isFailed && (
            sc.status === 'in_progress' ||
            sc.status === 'incomplete' ||
            sc.status === 'browsed' ||
            sc.status === 'suspend' ||
            (Number(sc.progress_percent) > 0 && Number(sc.progress_percent) < 100) ||
            hasTimeSpent(sc.total_time)
          );

          const statusVal: 'completed' | 'failed' | 'in_progress' | 'available' = isFailed
            ? 'failed'
            : isDone
              ? 'completed'
              : inProgress
                ? 'in_progress'
                : 'available';

          const totalSlidesCount = Number(sc.total_slides) || 10;
          const currentSlideVal = isDone ? totalSlidesCount : ((statusVal === 'available' || statusVal === 'not_started' || Number(sc.progress_percent) === 0) ? 0 : (Number(sc.current_slide) || 1));

          list.push({
            id: autoId++,
            cmid: sc.id,
            scoid: sc.scoid || (targetUrl && targetUrl.match(/[?&]scoid=(\d+)/)?.[1]) || null,
            currentorg: sc.organization || (targetUrl && targetUrl.match(/[?&]currentorg=([^&]+)/)?.[1]) || null,
            module_id: sc.module_id || sc.id || null,
            instance: sc.id,
            title: decodeCourseTitle(sc.name || c.fullname || `SCORM Module ${autoId}`),
            subtitle: decodeCourseTitle(c.fullname || 'Onboarding Training'),
            description: sc.description || c.description || null,
            duration_minutes: scDurMin,
            duration: durationFormatted,
            section: 'SCORM Packages',
            modname: 'scorm',
            is_assessment: isAssess,
            status: statusVal,
            url: targetUrl,
            retake_url: `${targetUrl}&newattempt=on`,
            review_url: `${targetUrl}&mode=review`,
            passing_score: isAssess ? (sc.passing_score !== undefined && sc.passing_score !== null ? Number(sc.passing_score) : 80) : null,
            total_time: sc.total_time || '',
            score: isAssess && sc.score !== null && sc.score !== undefined ? Number(sc.score) : null,
            attempts: isAssess ? Number(sc.attempts || 0) : 0,
            attempts_history: isAssess ? (sc.attempts_history || []).filter((att: any) => {
              const hasDate = Boolean(att.date && att.date !== '' && att.date !== '—');
              const hasScore = att.score !== null && att.score !== undefined;
              const hasTime = Boolean(att.total_time && att.total_time !== '' && att.total_time !== '00:00:00' && att.total_time !== '0s');
              const hasRealStatus = Boolean(att.status && att.status !== 'not_started' && att.result !== 'Incomplete');
              return hasDate || hasScore || hasTime || hasRealStatus;
            }) : [],
            timemodified: sc.timemodified || null,
            first_started_at: sc.first_started_at || null,
            last_played_at: sc.last_played_at || null,
            current_slide: currentSlideVal,
            total_slides: totalSlidesCount,
            progress_percent: isDone ? 100 : (sc.progress_percent !== undefined && sc.progress_percent !== null && Number(sc.progress_percent) > 0 ? Number(sc.progress_percent) : (inProgress ? 35 : 0)),
            course_id: courseIdNum,
            course_image: courseImage,
            admin_unlocked: Boolean(sc.admin_unlocked || c.admin_unlocked),
          });
        });
      } else {
        // Fallback for course without explicit modules or scorms array
        const courseDurMin = c.duration_minutes || 30;
        const isAssess = Boolean(
          c.is_assessment ||
          c.fullname?.toLowerCase().includes('assess') ||
          c.fullname?.toLowerCase().includes('quiz')
        );
        const isDone = Boolean(isCourseCompleted || c.completed || Number(c.progress_percent) === 100);
        const inProgress = !isDone && Number(c.progress_percent) > 0;
        const statusVal: 'completed' | 'failed' | 'in_progress' | 'available' = isDone ? 'completed' : inProgress ? 'in_progress' : 'available';
        const targetUrl = `${API_BASE}/moodle/launch.php?courseid=${courseIdNum}`;
        const totalSlidesCount = 10;
        const currentSlideVal = isDone ? totalSlidesCount : 0;

        list.push({
          id: autoId++,
          cmid: c.cmid || undefined,
          instance: c.id,
          title: decodeCourseTitle(c.displayname || c.fullname || `Module ${autoId}`),
          subtitle: decodeCourseTitle(c.fullname || 'Onboarding Training'),
          description: c.summary || c.description || null,
          duration_minutes: courseDurMin,
          duration: `${courseDurMin} min`,
          section: 'Course Modules',
          modname: 'scorm',
          is_assessment: isAssess,
          status: statusVal,
          url: targetUrl,
          retake_url: `${targetUrl}&newattempt=on`,
          review_url: `${targetUrl}&mode=review`,
          passing_score: isAssess ? 80 : null,
          total_time: '',
          score: null,
          attempts: 0,
          attempts_history: [],
          timemodified: null,
          first_started_at: null,
          last_played_at: null,
          current_slide: currentSlideVal,
          total_slides: totalSlidesCount,
          progress_percent: isDone ? 100 : (Number(c.progress_percent) > 0 ? Number(c.progress_percent) : 0),
          course_id: courseIdNum,
          course_image: courseImage,
          admin_unlocked: Boolean(c.admin_unlocked),
        });
      }
    });

    // Sequential Progression Rule: Default to true as core LMS sequential policy
    const isSequentialLocking = (moodleData as any)?.sequential_locking !== false;

    let allPrevCompleted = true;
    let prevTitle = '';
    return list.map((item) => {
      // If Admin has unlocked this course for this employee, bypass locking!
      if (item.admin_unlocked) {
        return {
          ...item,
          is_locked: false,
          admin_unlocked: true,
          locked_reason: null,
        };
      }
      if (item.status === 'completed') {
        return {
          ...item,
          is_locked: true,
          locked_reason: "Once you complete a course, you cannot launch it again without your manager's approval. If you wish to relaunch the course, please contact your manager for authorization."
        };
      }
      if (item.status === 'failed') {
        // Failed module remains accessible so user can retake it!
        return { ...item, is_locked: false };
      }
      if (!isSequentialLocking || allPrevCompleted) {
        allPrevCompleted = false;
        prevTitle = item.title;
        return item.status === 'in_progress' ? { ...item, is_locked: false } : { ...item, status: 'available' as const, is_locked: false };
      }
      return {
        ...item,
        status: 'locked' as const,
        is_locked: true,
        locked_reason: `Complete '${prevTitle || 'previous modules'}' to unlock this course`
      };
    });
  }, [moodleData]);

  // Pure live Moodle modules
  const displayModules = moodleModules;
  const displayTotalCourses = displayModules.length;
  const displayCompletedCount = displayModules.filter((m) => m.status === 'completed').length;
  const displayProgressPercentage = displayTotalCourses > 0
    ? Math.round((displayCompletedCount / displayTotalCourses) * 100)
    : 0;
  const isFinalUnlocked = displayTotalCourses > 0 && displayCompletedCount >= displayTotalCourses;

  // Dynamic employee data from authenticated session (No hardcoded employee names)
  const fullName = user
    ? (`${user.firstname || ''} ${user.lastname || ''}`.trim() || user.username || 'HexaLearn Teammate')
    : 'HexaLearn Teammate';
  const empCode = user?.emp_code || '';
  const startDate = user && user.joiningdate ? new Date(user.joiningdate).toLocaleDateString() : 'Today';
  const avatarLetter = user && (user.firstname && user.lastname)
    ? `${user.firstname.charAt(0)}${user.lastname.charAt(0)}`.toUpperCase()
    : (fullName && fullName !== 'HexaLearn Teammate' ? fullName.charAt(0).toUpperCase() : 'H');

  const currentModuleObj = displayModules.find((m) => !m.is_locked && (m.status === 'in_progress' || m.status === 'available'));

  // Identify Assessment Module: STRICTLY Employee_Assessment or modules marked as assessment
  const matchedAssessment = displayModules.find((m) =>
    Boolean(m.is_assessment) ||
    m.title.toLowerCase().includes('assess') ||
    m.title.toLowerCase().includes('quiz') ||
    m.title.toLowerCase().includes('employee_assessment')
  );

  const assessmentModule = useMemo(() => {
    if (matchedAssessment) return matchedAssessment;
    return {
      id: 9999,
      cmid: 4,
      title: 'Induction Assessment',
      subtitle: 'Final Induction Assessment',
      duration: '20 min',
      duration_minutes: 20,
      is_assessment: true,
      status: 'available' as const,
      url: `${API_BASE}/moodle/launch.php?cmid=4&courseid=3`,
      retake_url: `${API_BASE}/moodle/launch.php?cmid=4&courseid=3&newattempt=on`,
      review_url: `${API_BASE}/moodle/launch.php?cmid=4&courseid=3&mode=review`,
      passing_score: 80,
      is_locked: false,
      score: null,
      attempts: 0,
      attempts_history: []
    };
  }, [matchedAssessment]);

  // Learning Modules ONLY for SCORM Module Report (Strictly excludes assessments/quizzes)
  const scormLearningModules = useMemo(() => {
    return displayModules.filter((m) => {
      const isAssess = Boolean(
        m.is_assessment ||
        m.title.toLowerCase().includes('assess') ||
        m.title.toLowerCase().includes('quiz') ||
        m.title.toLowerCase().includes('employee_assessment')
      );
      return !isAssess;
    });
  }, [displayModules]);

  const formatDurationStr = (timeStr?: string) => {
    if (!timeStr || timeStr === '00:00:00' || timeStr === '00:00:00.00') return '0s';
    const parts = timeStr.split(':');
    if (parts.length >= 3) {
      const hours = parseInt(parts[0], 10);
      const mins = parseInt(parts[1], 10);
      const secs = parseInt(parts[2].split('.')[0], 10);
      if (hours > 0) return `${hours}h ${mins}m`;
      if (mins > 0) return `${mins}m ${secs}s`;
      if (secs > 0) return `${secs}s`;
      return '0s';
    }
    return timeStr;
  };

  const displayedAttempts = useMemo(() => {
    if (assessmentModule?.attempts_history && assessmentModule.attempts_history.length > 0) {
      return assessmentModule.attempts_history
        .filter((att: any) => {
          const hasDate = Boolean(att.date && att.date !== '' && att.date !== '—');
          const hasScore = att.score !== null && att.score !== undefined;
          const hasTime = Boolean(att.total_time && att.total_time !== '' && att.total_time !== '00:00:00' && att.total_time !== '0s');
          const hasRealStatus = Boolean(att.status && att.status !== 'not_started' && att.result !== 'Incomplete');
          return hasDate || hasScore || hasTime || hasRealStatus;
        })
        .map((att: any) => ({
          ...att,
          formattedDuration: formatDurationStr(att.total_time)
        }));
    }
    return [];
  }, [assessmentModule]);

  // Overall onboarding progress metrics (dynamically computed from live Moodle LMS records)
  const totalAssignedActivities = displayModules.length;
  const completedActivitiesCount = useMemo(() => {
    return displayModules.filter((m) => m.status === 'completed').length;
  }, [displayModules]);

  const inProgressActivitiesCount = useMemo(() => {
    return displayModules.filter((m) => m.status === 'in_progress' || m.status === 'failed').length;
  }, [displayModules]);

  const notStartedActivitiesCount = useMemo(() => {
    return displayModules.filter((m) => m.status === 'available' || m.status === 'not_started' || m.status === 'locked').length;
  }, [displayModules]);

  // Alert conditions (Item 5: Important alerts — only when needed)
  const failedAssessment = useMemo(() => {
    return displayModules.find((m) => m.status === 'failed' || (m.is_assessment && m.score !== null && m.score !== undefined && m.score < (m.passing_score ?? 80)));
  }, [displayModules]);

  const hasIncompleteMandatory = useMemo(() => {
    if (!displayModules || displayModules.length === 0) return false;
    const allScormDone = scormLearningModules.length > 0 && scormLearningModules.every(m => m.status === 'completed');
    return allScormDone && assessmentModule && !assessmentModule.is_locked && assessmentModule.status !== 'completed';
  }, [displayModules, scormLearningModules, assessmentModule]);

  const alertData = useMemo(() => {
    if (failedAssessment) {
      return {
        type: 'error' as const,
        title: 'Assessment Retake Required',
        message: `You scored ${failedAssessment.score ?? 0}% on ${failedAssessment.title} (Passing mark: ${failedAssessment.passing_score ?? 80}%). A retake is required to complete your onboarding certification.`,
        buttonText: 'Retake Assessment →',
        historyLinkText: 'View Attempt History',
        action: () => {
          const url = failedAssessment.retake_url || `${failedAssessment.url}&newattempt=on`;
          openPlayer(url, `${failedAssessment.title || 'Assessment'} (Retake)`);
        },
      };
    }
    if (hasIncompleteMandatory && assessmentModule) {
      return {
        type: 'warning' as const,
        title: 'Mandatory Assessment Pending',
        message: `You have completed all learning modules! Please take your final mandatory assessment (${assessmentModule.title}) to complete your onboarding.`,
        buttonText: 'Start Assessment →',
        historyLinkText: null,
        action: () => handleLaunchModule(assessmentModule),
      };
    }
    return null;
  }, [failedAssessment, hasIncompleteMandatory, assessmentModule]);

  // Backward compatibility KPI calculations for modules tab
  const totalModulesCount = scormLearningModules.length;
  const inProgressCount = inProgressActivitiesCount;
  const notStartedCount = notStartedActivitiesCount;
  const assessmentsCount = useMemo(() => {
    return displayModules.filter(m => m.is_assessment || m.title.toLowerCase().includes('assess') || m.title.toLowerCase().includes('quiz')).length;
  }, [displayModules]);

  // Percentages for 3 Status Cards matching reference image
  const completedPercentage = totalAssignedActivities > 0
    ? Math.round((completedActivitiesCount / totalAssignedActivities) * 100)
    : 0;
  const inProgressPercentage = totalAssignedActivities > 0
    ? Math.round((inProgressActivitiesCount / totalAssignedActivities) * 100)
    : 0;
  const notStartedPercentage = totalAssignedActivities > 0
    ? Math.round((notStartedActivitiesCount / totalAssignedActivities) * 100)
    : 0;

  // Assessment Score and Requirements matching reference image
  const latestAttempt = displayedAttempts.length > 0 ? displayedAttempts[displayedAttempts.length - 1] : null;
  const hasAttempted = displayedAttempts.length > 0;
  const lastScore = latestAttempt?.score !== undefined && latestAttempt?.score !== null
    ? Number(latestAttempt.score)
    : null;
  const passingScore = assessmentModule?.passing_score ?? 80;
  const isPassed = Boolean(
    (lastScore !== null && lastScore >= passingScore) ||
    assessmentModule?.status === 'completed' ||
    latestAttempt?.is_passed
  );
  const isFailed = Boolean(hasAttempted && !isPassed && lastScore !== null && lastScore < passingScore);
  const neededMore = lastScore !== null ? Math.max(0, passingScore - lastScore) : null;
  const isAssessmentLocked = Boolean(!assessmentModule || assessmentModule.is_locked || assessmentModule.status === 'locked');

  // Next Recommended Activity (Single active uncompleted activity for Dashboard overview)
  const nextRecommendedActivity = useMemo(() => {
    if (!displayModules || displayModules.length === 0) return null;
    // 1. In sequential progression, the genuinely next playable activity is the first uncompleted module that is NOT locked
    const nextPlayable = displayModules.find((m) => !m.is_locked && m.status !== 'completed' && !m.is_assessment && !m.title.toLowerCase().includes('assess') && !m.title.toLowerCase().includes('quiz'));
    if (nextPlayable) return nextPlayable;

    // 2. Fallback: If in-progress course exists that is not locked
    const activeUnlocked = displayModules.find((m) => !m.is_locked && m.status === 'in_progress' && !m.is_assessment && !m.title.toLowerCase().includes('assess'));
    if (activeUnlocked) return activeUnlocked;

    // 3. When all 10 learning courses are completed, the NEXT RECOMMENDED ACTIVITY IS THE ASSESSMENT!
    if (assessmentModule && !isAssessmentLocked && !isPassed) {
      return assessmentModule;
    }

    return null;
  }, [displayModules, assessmentModule, isAssessmentLocked, isPassed]);

  // Authoritative Moodle LMS course image thumbnail resolver
  const getCourseThumbnail = (item: any) => {
    if (item?.course_image && !item.course_image.includes('placeholder')) {
      return item.course_image;
    }
    const cId = Number(item?.course_id || item?.moodle_course_id || item?.id);
    if (cId > 0) {
      return `${API_BASE}/moodle/course_image.php?course_id=${cId}`;
    }
    return `${API_BASE}/moodle/course_image.php?course_id=2`;
  };

  // Recent Activities for Bottom Section: Completely dynamic, includes all courses (Course 1, 2, 3, 4, etc.)
  const recentActivities = useMemo(() => {
    if (!displayModules || displayModules.length === 0) return [];

    const list = [...displayModules];

    // Dynamic sorting:
    // 1. Interacted courses first (most recently modified / attempted / in-progress)
    // 2. Unstarted courses follow in their assigned roadmap sequence
    return list.sort((a, b) => {
      const timeA = a.timemodified ? (typeof a.timemodified === 'number' ? (a.timemodified > 1e11 ? a.timemodified / 1000 : a.timemodified) : new Date(a.timemodified).getTime() / 1000) : 0;
      const timeB = b.timemodified ? (typeof b.timemodified === 'number' ? (b.timemodified > 1e11 ? b.timemodified / 1000 : b.timemodified) : new Date(b.timemodified).getTime() / 1000) : 0;

      const isInteractedA = a.status === 'in_progress' || a.status === 'completed' || a.status === 'failed' || timeA > 0;
      const isInteractedB = b.status === 'in_progress' || b.status === 'completed' || b.status === 'failed' || timeB > 0;

      if (isInteractedA && isInteractedB) {
        return timeB - timeA; // Most recently modified first
      }
      if (isInteractedA) return -1;
      if (isInteractedB) return 1;

      // Both not started: keep natural roadmap sequence
      return (a.id || 0) - (b.id || 0);
    });
  }, [displayModules]);

  // Real timestamp formatting without ANY hardcoded fake dates
  const formatRecentActivityDate = (item: any) => {
    // 1. Check latest attempt timestamp
    if (item.attempts_history && item.attempts_history.length > 0) {
      const lastAtt = item.attempts_history[item.attempts_history.length - 1];
      if (lastAtt.date) return lastAtt.date;
    }

    // 2. Check live Moodle timemodified, last_played_at, or first_started_at
    const timeVal = item.last_played_at || item.timemodified || item.first_started_at;
    if (timeVal) {
      try {
        const timestampMs = typeof timeVal === 'number'
          ? (timeVal > 1e11 ? timeVal : timeVal * 1000)
          : new Date(timeVal).getTime();

        const d = new Date(timestampMs);
        if (!isNaN(d.getTime())) {
          return d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
          });
        }
      } catch { }
    }

    // 3. For completed modules without timestamp
    if (item.status === 'completed') {
      return 'Completed';
    }

    // 4. For unstarted or active modules: honest, real status label
    if (item.status === 'in_progress') {
      return 'Active session';
    }
    return 'Ready to start';
  };

  const getCourseDescription = (title: string, defaultSub?: string, customDesc?: string) => {
    const t = (title || '').toLowerCase();
    if (t.includes('onboard')) {
      return "Learn about our company, culture, values and what to expect in your first days.";
    }
    if (t.includes('assessment') || t.includes('quiz')) {
      return "Test your knowledge and understanding of the onboarding program.";
    }
    if (t.includes('work rules') || t.includes('work life')) {
      return "Understand workplace policies, code of conduct and work-life balance.";
    }
    if (t.includes('leave') || t.includes('holiday')) {
      return "Explore leave policies, holidays, attendance regulations, and workplace guidelines.";
    }
    if (t.includes('security') || t.includes('confidential') || t.includes('it')) {
      return "Comprehensive information security, intellectual property handling, password compliance, and workplace confidentiality standards.";
    }
    if (customDesc && customDesc.trim() && customDesc !== 'Single activity' && customDesc !== 'General') {
      return customDesc.trim()
        .replace(/course module/gi, 'course')
        .replace(/\bmodule\b/gi, 'course');
    }
    if (defaultSub && defaultSub.trim() && defaultSub !== 'Single activity' && defaultSub !== 'General') {
      return defaultSub.trim()
        .replace(/course module/gi, 'course')
        .replace(/\bmodule\b/gi, 'course');
    }
    return "Explore and complete this assigned onboarding course.";
  };

  const getDisplayDuration = (title: string, rawDuration?: string, durationMinutes?: number) => {
    if (durationMinutes && durationMinutes > 0) {
      return `${durationMinutes} min`;
    }
    if (rawDuration && !rawDuration.includes('15 min')) {
      return rawDuration;
    }
    const t = (title || '').toLowerCase();
    if (t.includes('onboard')) return "45 min";
    if (t.includes('assessment') || t.includes('quiz')) return "20 min";
    if (t.includes('work rules') || t.includes('work life')) return "30 min";
    if (t.includes('leave') || t.includes('holiday')) return "30 min";
    if (t.includes('security') || t.includes('confidential') || t.includes('it')) return "25 min";
    return rawDuration || "30 min";
  };

  const getModuleProgress = (item: any) => {
    if (!item) return 0;
    if (item.status === 'completed') return 100;
    // If not started, progress is strictly 0%
    if (item.status === 'available' || item.status === 'not_started' || item.status === 'locked') return 0;

    // Direct progress percent from MySQL API (single source of truth)
    if (item.progress_percent !== undefined && item.progress_percent !== null && Number(item.progress_percent) >= 0) {
      return Math.min(100, Math.max(0, Math.round(Number(item.progress_percent))));
    }
    if (item.progress !== undefined && item.progress !== null && Number(item.progress) >= 0) {
      return Math.min(100, Math.max(0, Math.round(Number(item.progress))));
    }

    // Direct slide-based calculation ONLY when in-progress
    const isAssess = Boolean(
      item.is_assessment ||
      item.title?.toLowerCase().includes('assess') ||
      item.title?.toLowerCase().includes('quiz')
    );
    if (!isAssess && item.status === 'in_progress' && item.current_slide && item.total_slides && Number(item.total_slides) > 0) {
      const slidePct = Math.round((Number(item.current_slide) / Number(item.total_slides)) * 100);
      if (slidePct > 0) return Math.min(95, Math.max(5, slidePct));
    }

    if (item.status === 'failed') {
      return item.score !== null && item.score !== undefined ? Math.round(Number(item.score)) : 0;
    }
    return 0;
  };

  const filteredActivities = useMemo(() => {
    return displayModules.filter((item) => {
      const isAssess = Boolean(
        item.is_assessment ||
        item.title.toLowerCase().includes('assess') ||
        item.title.toLowerCase().includes('quiz')
      );
      if (activityFilter === 'training' && isAssess) return false;
      if (activityFilter === 'assessments' && !isAssess) return false;
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        return (
          item.title.toLowerCase().includes(q) ||
          item.subtitle.toLowerCase().includes(q) ||
          getCourseDescription(item.title, item.subtitle, item.description).toLowerCase().includes(q)
        );
      }
      return true;
    });
  }, [displayModules, activityFilter, searchQuery]);

  // Helper for Course Icon & Colors matching screenshot
  const getCourseMeta = (courseId: number) => {
    switch (courseId) {
      case 1:
        return { Icon: Leaf, iconColor: "text-emerald-600", iconBg: "bg-emerald-50 border-emerald-100" };
      case 2:
        return { Icon: Users, iconColor: "text-blue-600", iconBg: "bg-blue-50 border-blue-100" };
      case 3:
        return { Icon: ShieldCheck, iconColor: "text-sky-600", iconBg: "bg-sky-50 border-sky-100" };
      case 4:
        return { Icon: Building, iconColor: "text-indigo-600", iconBg: "bg-indigo-50 border-indigo-100" };
      case 5:
        return { Icon: HeartPulse, iconColor: "text-cyan-600", iconBg: "bg-cyan-50 border-cyan-100" };
      case 6:
        return { Icon: Wrench, iconColor: "text-sky-600", iconBg: "bg-sky-50 border-sky-100" };
      case 7:
        return { Icon: Shield, iconColor: "text-blue-600", iconBg: "bg-blue-50 border-blue-100" };
      case 8:
        return { Icon: Users, iconColor: "text-violet-600", iconBg: "bg-violet-50 border-violet-100" };
      case 9:
        return { Icon: Clock, iconColor: "text-purple-600", iconBg: "bg-purple-50 border-purple-100" };
      case 10:
        return { Icon: TrendingUp, iconColor: "text-blue-600", iconBg: "bg-blue-50 border-blue-100" };
      default:
        return { Icon: BookOpen, iconColor: "text-emerald-600", iconBg: "bg-emerald-50 border-emerald-100" };
    }
  };

  // Circular gauge math for "Your Onboarding Progress"
  const radius = 36;
  const circumference = 2 * Math.PI * radius;
  const strokeDashoffset = circumference - (displayProgressPercentage / 100) * circumference;


  // Sidebar Employee Profile & Training Progress Widget (Positioned at bottom of left aside)
  const sidebarEmployeeContent = (
    <div className="bg-white border border-slate-200/90 rounded-2xl p-4 space-y-3.5 shadow-xs">
      {/* 1. Employee Profile Header (Avatar on 1st row centered, details below image) */}
      <div className="flex flex-col items-center text-center space-y-2.5">
        <div className="relative">
          <span className="relative flex overflow-hidden h-20 w-20 rounded-2xl border-2 border-emerald-500/30 dark:border-sky-500/30 shadow-md shrink-0 bg-slate-100 items-center justify-center">
            {avatarSrc && !avatarImgFailed ? (
              <img
                className="aspect-square h-full w-full object-cover"
                alt={fullName}
                src={avatarSrc}
                onError={handleAvatarError}
              />
            ) : (
              <span className="text-2xl font-black text-slate-700 select-none">
                {avatarLetter}
              </span>
            )}
          </span>
          <div className="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-emerald-500 border-2 border-white dark:border-zinc-950 shadow-xs flex items-center justify-center">
            <div className="w-1.5 h-1.5 rounded-full bg-white animate-ping" />
          </div>
        </div>

        <div className="w-full text-center space-y-0.5">
          <h3 className="text-sm font-bold text-slate-800 truncate" title={fullName}>
            {fullName}
          </h3>
          {empCode && (
            <p className="text-[11px] font-medium text-slate-500">
              Employee ID: <span className="font-semibold text-slate-700">{empCode}</span>
            </p>
          )}
        </div>
      </div>

      {/* 2. Training Completion Section */}
      <div className="pt-2.5 border-t border-slate-100 space-y-2">
        <div className="flex items-center justify-between">
          <h4 className="text-[11px] font-medium text-slate-500">
            Training Completion
          </h4>
          {isInitialLoading ? (
            <div className="h-3.5 w-8 bg-slate-200 rounded animate-pulse" />
          ) : (
            <span className="text-xs font-bold text-[#00A389]">
              {displayProgressPercentage}%
            </span>
          )}
        </div>

        {/* Linear Progress Bar */}
        <div className="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
          {isInitialLoading ? (
            <div className="h-full w-1/3 bg-slate-200 rounded-full animate-pulse" />
          ) : (
            <div
              className="h-full bg-[#00A389] rounded-full transition-all duration-700 ease-out"
              style={{ width: `${Math.max(displayProgressPercentage, 2)}%` }}
            />
          )}
        </div>
      </div>
    </div>
  );

  if (loading) {
    return (
      <Layout
        sidebarContent={sidebarEmployeeContent}
        activeEmployeeTab={activeTab}
        onSelectEmployeeTab={handleTabChange}
      >
        <div className="flex items-center justify-center min-h-[60vh]">
          <div className="h-10 w-10 rounded-full border-4 border-slate-200 border-t-[#00A389] animate-spin" aria-label="Loading" />
        </div>
      </Layout>
    );
  }

  return (
    <Layout
      sidebarContent={sidebarEmployeeContent}
      activeEmployeeTab={activeTab}
      onSelectEmployeeTab={handleTabChange}
    >
      <div className="space-y-6 pb-16">
        {/* =========================================================================
            PLATFORM SYSTEM MAINTENANCE BANNER
           ========================================================================= */}
        {IS_MAINTENANCE_MODE && (
          <div className="rounded-2xl border-2 border-amber-400/90 bg-gradient-to-r from-amber-500/15 via-amber-500/10 to-amber-500/15 p-5 sm:p-6 shadow-md backdrop-blur-xs relative overflow-hidden">
            <div className="absolute top-0 right-0 w-40 h-40 bg-amber-400/10 rounded-full blur-2xl pointer-events-none" />
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 relative z-10">
              <div className="flex items-start gap-4">
                <div className="h-12 w-12 rounded-2xl bg-amber-500 text-white flex items-center justify-center shrink-0 shadow-sm animate-pulse">
                  <Wrench className="h-6 w-6" />
                </div>
                <div className="space-y-1">
                  <div className="flex items-center gap-2.5 flex-wrap">
                    <h3 className="text-base sm:text-lg font-black text-amber-950 tracking-tight">
                      Platform System Maintenance in Progress
                    </h3>
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-200/90 text-amber-900 border border-amber-300">
                      <span className="w-2 h-2 rounded-full bg-amber-600 animate-ping" />
                      Course Launching Temporarily Paused
                    </span>
                  </div>
                  <p className="text-xs sm:text-sm text-amber-900/90 leading-relaxed font-medium max-w-3xl">
                    The Onboarding LMS is currently undergoing scheduled platform maintenance and system updates. <strong>Course launching and playback are temporarily suspended</strong> while maintenance is active. All your saved progress, completed modules, and assessment history remain completely safe.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}
        {/* =========================================================================
            TOP HEADER (FOR NON-OVERVIEW TABS: MODULES, REPORTS, ATTEMPT HISTORY)
           ========================================================================= */}
        {activeTab !== 'all' && (
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2">
            <div className="flex items-center gap-3.5">
              <div className="h-12 w-12 rounded-2xl bg-[#E8F8F4] border border-[#22c55e]/20 flex items-center justify-center text-[#22c55e] shrink-0 shadow-2xs">
                {activeTab === 'roadmap' && <BookOpen className="h-6 w-6 text-[#22c55e]" />}
                {activeTab === 'scorm_report' && <BarChart3 className="h-6 w-6 text-[#00A389]" />}
                {activeTab === 'attempt_history' && <RotateCcw className="h-6 w-6 text-[#00A389]" />}
              </div>
              <div>
                <div className="flex items-center gap-2.5 flex-wrap">
                  <h2 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                    {activeTab === 'roadmap' && 'My Learning Journey'}
                    {activeTab === 'scorm_report' && 'Learning Report'}
                    {activeTab === 'attempt_history' && 'Assessment Attempt History'}
                  </h2>
                  {activeTab === 'attempt_history' && (
                    <span className="text-[11px] font-bold text-slate-700 bg-slate-100 border border-slate-200/80 px-2.5 py-0.5 rounded-full inline-flex items-center gap-1.5 shadow-2xs">
                      Attempts: {displayedAttempts.length}
                    </span>
                  )}
                </div>
                <p className="text-xs text-slate-500 font-medium mt-0.5">
                  {activeTab === 'roadmap' && 'Explore your assigned onboarding courses, complete interactive training, and track your progress.'}
                  {activeTab === 'scorm_report' && 'Track your learning progress, time spent, and module completions.'}
                  {activeTab === 'attempt_history' && 'Review your assessment attempts, scores, and evaluation history.'}
                </p>
              </div>
            </div>

            {/* Right side: Search input on roadmap tab (exact match to reference image) */}
            {activeTab === 'roadmap' && (
              <div className="relative w-full sm:w-64">
                <Search className="h-4 w-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Search courses..."
                  className="w-full pl-9 pr-3.5 py-2 bg-white border border-slate-200/90 rounded-full text-xs placeholder:text-slate-400 text-slate-700 focus:outline-none focus:ring-1 focus:ring-[#00A389] shadow-2xs"
                />
              </div>
            )}
          </div>
        )}

        {/* =========================================================================
            EMPLOYEE DASHBOARD OVERVIEW (5-SECOND AT-A-GLANCE COCKPIT)
            1. Welcome section (Name, Employee ID, Moodle status)
            2. Overall onboarding progress (Percentage, Completed/Total, 1 progress bar)
            3. Three small status cards (Completed, In Progress, Not Started - Counts only!)
            4. Next recommended activity (One activity, status, 1 button: Start or Continue)
            5. Important alerts — only when needed (Failed assessment / retake required)
           ========================================================================= */}
        {/* =========================================================================
            EMPLOYEE DASHBOARD OVERVIEW (EXACT LAYOUT FROM REFERENCE IMAGE)
            Row 1: Overall Onboarding Progress (Donut + Bar) + 3 Small Status Cards
            Row 2: Next Activity (Featured Course) + Assessment Status (Donut & Alert)
            Row 3: Recent Activity (Compact interactive activity list)
           ========================================================================= */}
        {activeTab === 'all' && (
          isInitialLoading ? (
            <div className="space-y-5 animate-pulse">
              {/* ROW 1: Overall Onboarding Progress Skeleton */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs">
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
                  <div className="lg:col-span-7 flex flex-col justify-between pr-0 lg:pr-6 lg:border-r lg:border-slate-200/60 space-y-4">
                    <div className="flex items-center justify-between">
                      <div className="h-5 w-48 bg-slate-200 rounded-lg" />
                      <div className="h-5 w-28 bg-slate-200 rounded-full" />
                    </div>
                    <div className="flex flex-col sm:flex-row items-center gap-5 sm:gap-6">
                      <div className="h-28 w-28 rounded-full bg-slate-200 shrink-0" />
                      <div className="flex-1 w-full space-y-3">
                        <div className="flex justify-between">
                          <div className="h-4 w-36 bg-slate-200 rounded" />
                          <div className="h-4 w-16 bg-slate-200 rounded" />
                        </div>
                        <div className="h-2.5 w-full bg-slate-200 rounded-full" />
                        <div className="h-4 w-3/4 bg-slate-200 rounded" />
                      </div>
                    </div>
                  </div>
                  <div className="lg:col-span-5 grid grid-cols-3 gap-3.5 items-stretch pl-0 lg:pl-1">
                    {[1, 2, 3].map((i) => (
                      <div key={i} className="bg-slate-50 border border-slate-100 rounded-2xl p-4 flex flex-col justify-between h-28">
                        <div className="h-6 w-6 rounded-full bg-slate-200" />
                        <div className="space-y-1.5 mt-2">
                          <div className="h-7 w-12 bg-slate-200 rounded" />
                          <div className="h-3 w-16 bg-slate-200 rounded" />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              {/* ROW 2: Next Activity + Assessment Status Skeleton */}
              <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
                <div className="lg:col-span-7 bg-[#F0FAF7]/60 border border-[#CCEBE3]/80 rounded-2xl p-5 sm:p-6 space-y-4 h-64 flex flex-col justify-between">
                  <div className="flex items-center gap-3">
                    <div className="h-8 w-8 rounded-full bg-[#00A389]/20" />
                    <div className="space-y-1.5">
                      <div className="h-4 w-32 bg-slate-200 rounded" />
                      <div className="h-3 w-48 bg-slate-200 rounded" />
                    </div>
                  </div>
                  <div className="flex gap-4 items-center">
                    <div className="h-20 w-32 rounded-xl bg-slate-200 shrink-0" />
                    <div className="space-y-2 flex-1">
                      <div className="h-4 w-3/4 bg-slate-200 rounded" />
                      <div className="h-3 w-1/2 bg-slate-200 rounded" />
                    </div>
                  </div>
                  <div className="h-10 w-32 bg-slate-200 rounded-xl mt-auto" />
                </div>

                <div className="lg:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 space-y-4 h-64 flex flex-col justify-between">
                  <div className="flex items-center gap-3">
                    <div className="h-8 w-8 rounded-full bg-slate-200" />
                    <div className="space-y-1.5">
                      <div className="h-4 w-32 bg-slate-200 rounded" />
                      <div className="h-3 w-36 bg-slate-200 rounded" />
                    </div>
                  </div>
                  <div className="flex items-center justify-center py-2">
                    <div className="h-24 w-24 rounded-full bg-slate-200" />
                  </div>
                  <div className="h-9 w-full bg-slate-200 rounded-xl" />
                </div>
              </div>

              {/* ROW 3: Recent Activity Skeleton */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 space-y-4">
                <div className="flex items-center justify-between">
                  <div className="h-5 w-36 bg-slate-200 rounded" />
                  <div className="h-4 w-24 bg-slate-200 rounded" />
                </div>
                <div className="divide-y divide-slate-100 space-y-3">
                  {[1, 2, 3].map((i) => (
                    <div key={i} className="pt-3 flex items-center justify-between gap-4">
                      <div className="flex items-center gap-3">
                        <div className="h-12 w-16 rounded-xl bg-slate-200 shrink-0" />
                        <div className="space-y-1.5">
                          <div className="h-4 w-48 bg-slate-200 rounded" />
                          <div className="h-3 w-32 bg-slate-200 rounded" />
                        </div>
                      </div>
                      <div className="h-6 w-20 bg-slate-200 rounded-full" />
                    </div>
                  ))}
                </div>
              </div>
            </div>
          ) : (
            <div className="space-y-5">
              {/* ROW 1: Overall Onboarding Progress + 3 Small Status Cards */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs">
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">

                  {/* Left Section: Overall Onboarding Progress */}
                  <div className="lg:col-span-7 flex flex-col justify-between pr-0 lg:pr-6 lg:border-r lg:border-slate-200/60 space-y-4">
                    <div className="flex items-center justify-between">
                      <h3 className="text-sm sm:text-base font-black text-slate-800 tracking-tight">
                        Overall Onboarding Progress
                      </h3>
                      <span className="inline-flex items-center gap-1.5 text-[11px] font-bold text-emerald-800 bg-emerald-50 border border-emerald-200/80 px-2.5 py-0.5 rounded-full shadow-2xs">
                        <span className="h-1.5 w-1.5 rounded-full bg-[#00A389] animate-pulse" />
                        {completedActivitiesCount} of {totalAssignedActivities} Completed
                      </span>
                    </div>

                    <div className="flex flex-col sm:flex-row items-center gap-5 sm:gap-6">
                      {/* Donut Chart (Clean, perfectly centered, NO overflow) */}
                      <div className="relative h-28 w-28 shrink-0 flex items-center justify-center">
                        <svg className="w-28 h-28 transform -rotate-90">
                          <circle
                            cx="56"
                            cy="56"
                            r="44"
                            stroke="#E2E8F0"
                            strokeWidth="8"
                            fill="transparent"
                          />
                          <circle
                            cx="56"
                            cy="56"
                            r="44"
                            stroke="#00A389"
                            strokeWidth="8"
                            strokeDasharray={2 * Math.PI * 44}
                            strokeDashoffset={2 * Math.PI * 44 * (1 - Math.min(100, Math.max(0, displayProgressPercentage)) / 100)}
                            strokeLinecap="round"
                            fill="transparent"
                            className="transition-all duration-700 ease-out"
                          />
                        </svg>
                        <div className="absolute inset-0 flex flex-col items-center justify-center text-center pointer-events-none">
                          <span className="text-2xl font-black text-slate-800 leading-none tracking-tight">
                            {displayProgressPercentage}%
                          </span>
                          <span className="text-[10px] font-bold text-[#00A389] uppercase tracking-wider mt-1">
                            Done
                          </span>
                        </div>
                      </div>

                      {/* Linear Progress Bar & Context Details */}
                      <div className="flex-1 w-full space-y-2.5">
                        <div className="flex items-center justify-between text-xs">
                          <span className="font-bold text-slate-700">
                            {completedActivitiesCount} of {totalAssignedActivities} activities finished
                          </span>
                          <span className="text-[11px] font-semibold text-slate-400">
                            {Math.max(0, totalAssignedActivities - completedActivitiesCount)} to go
                          </span>
                        </div>

                        <div className="h-2.5 w-full bg-slate-100 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-[#00A389] rounded-full transition-all duration-700 ease-out"
                            style={{ width: `${Math.max(displayProgressPercentage, 2)}%` }}
                          />
                        </div>

                        <div className="flex items-start gap-2 text-xs text-slate-600 font-medium pt-1">
                          <Calendar className="h-4 w-4 text-slate-400 shrink-0 mt-0.5" />
                          <p className="leading-relaxed">
                            {displayProgressPercentage === 100
                              ? "🎉 Congratulations! You have successfully completed your entire onboarding journey."
                              : "Keep going! You're one step closer to completing your onboarding."}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Right Section: 3 Small Status Cards */}
                  <div className="lg:col-span-5 grid grid-cols-3 gap-3.5 items-stretch pl-0 lg:pl-1">
                    {/* Card 1: Completed */}
                    <div
                      onClick={() => handleTabChange('roadmap')}
                      className="bg-[#EDFBF4] border border-emerald-100/60 rounded-2xl p-4 flex flex-col justify-between hover:shadow-xs transition-all cursor-pointer group"
                      title="View completed modules"
                    >
                      <div className="h-6 w-6 rounded-full bg-[#10B981] text-white flex items-center justify-center shadow-2xs">
                        <Check className="h-3.5 w-3.5 stroke-[3]" />
                      </div>
                      <div className="mt-3">
                        <div className="text-2xl sm:text-3xl font-black text-slate-800 leading-none group-hover:text-emerald-700 transition-colors">
                          {completedActivitiesCount}
                        </div>
                        <div className="text-xs font-bold text-slate-700 mt-1.5">Completed</div>
                        <div className="text-[11px] text-emerald-700 font-bold mt-0.5">({completedPercentage}%)</div>
                      </div>
                    </div>

                    {/* Card 2: In Progress */}
                    <div
                      onClick={() => handleTabChange('roadmap')}
                      className="bg-[#FFFBEB] border border-amber-100/60 rounded-2xl p-4 flex flex-col justify-between hover:shadow-xs transition-all cursor-pointer group"
                      title="View in-progress modules"
                    >
                      <div className="h-6 w-6 rounded-full bg-[#F59E0B] text-white flex items-center justify-center shadow-2xs">
                        <Clock className="h-3.5 w-3.5" />
                      </div>
                      <div className="mt-3">
                        <div className="text-2xl sm:text-3xl font-black text-slate-800 leading-none group-hover:text-amber-700 transition-colors">
                          {inProgressActivitiesCount}
                        </div>
                        <div className="text-xs font-bold text-slate-700 mt-1.5">In Progress</div>
                        <div className="text-[11px] text-amber-700 font-bold mt-0.5">({inProgressPercentage}%)</div>
                      </div>
                    </div>

                    {/* Card 3: Not Started */}
                    <div
                      onClick={() => handleTabChange('roadmap')}
                      className="bg-[#EEF4FA] border border-slate-200/60 rounded-2xl p-4 flex flex-col justify-between hover:shadow-xs transition-all cursor-pointer group"
                      title="View upcoming modules"
                    >
                      <div className="h-6 w-6 rounded-full bg-[#385376] text-white flex items-center justify-center shadow-2xs">
                        <StepForward className="h-3 w-3 fill-white stroke-white stroke-[2]" />
                      </div>
                      <div className="mt-3">
                        <div className="text-2xl sm:text-3xl font-black text-slate-800 leading-none group-hover:text-slate-900 transition-colors">
                          {notStartedActivitiesCount}
                        </div>
                        <div className="text-xs font-bold text-slate-700 mt-1.5">Not Started</div>
                        <div className="text-[11px] text-slate-500 font-bold mt-0.5">({notStartedPercentage}%)</div>
                      </div>
                    </div>
                  </div>

                </div>
              </div>

              {/* ROW 2: Next Activity + Assessment Status */}
              <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">

                {/* Left Card: Next Activity */}
                <div className="lg:col-span-7 xl:col-span-7 bg-[#F0FAF7] border border-[#CCEBE3] rounded-2xl p-5 sm:p-6 shadow-2xs flex flex-col justify-between space-y-4">
                  <div className="flex items-start gap-3">
                    <div className="h-8 w-8 rounded-full bg-[#00A389]/15 text-[#00A389] flex items-center justify-center shrink-0 border border-[#00A389]/20">
                      <Target className="h-4 w-4" />
                    </div>
                    <div>
                      <h3 className="text-sm sm:text-base font-black text-slate-800 tracking-tight leading-tight">
                        Next Activity
                      </h3>
                      <p className="text-xs text-slate-500 font-medium mt-0.5">
                        Continue your onboarding with the next course.
                      </p>
                    </div>
                  </div>

                  {nextRecommendedActivity ? (
                    <div className="bg-white border border-slate-200/60 rounded-2xl p-4 sm:p-5 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
                      {/* Thumbnail */}
                      <div className="h-20 w-28 sm:h-22 sm:w-32 rounded-xl overflow-hidden bg-slate-100 border border-slate-200/60 shrink-0 shadow-2xs relative">
                        <img
                          src={getCourseThumbnail(nextRecommendedActivity)}
                          alt={nextRecommendedActivity.title}
                          className="absolute inset-0 w-full h-full object-cover object-center"
                          onError={(e) => {
                            const target = e.target as HTMLImageElement;
                            const cId = nextRecommendedActivity.course_id || 2;
                            if (!target.src.includes('course_image.php')) {
                              target.src = `${API_BASE}/moodle/course_image.php?course_id=${cId}`;
                            }
                          }}
                        />
                      </div>

                      {/* Details */}
                      <div className="flex-1 min-w-0 space-y-1.5 w-full">
                        <div>
                          <span className="px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-[#EAF5FE] text-[#0284C7] border border-sky-100">
                            {nextRecommendedActivity.is_assessment ? 'Assessment' : 'Training'}
                          </span>
                        </div>
                        <h4 className="text-sm sm:text-base font-extrabold text-slate-800 truncate" title={nextRecommendedActivity.title}>
                          {nextRecommendedActivity.title}
                        </h4>
                        <p className="text-[11px] text-slate-400 line-clamp-2 leading-relaxed">
                          {getCourseDescription(nextRecommendedActivity.title, nextRecommendedActivity.subtitle, nextRecommendedActivity.description)}
                        </p>
                        <div className="flex items-center gap-3 text-[11px] text-slate-400 font-medium">
                          <span className="inline-flex items-center gap-1">
                            <Clock className="h-3 w-3" />
                            {getDisplayDuration(nextRecommendedActivity.title, nextRecommendedActivity.duration, nextRecommendedActivity.duration_minutes)}
                          </span>
                        </div>

                        {/* Progress bar with percentage on right */}
                        <div className="flex items-center gap-2.5 pt-1">
                          <div className="h-2 flex-1 bg-slate-100 rounded-full overflow-hidden">
                            <div
                              className="h-full bg-[#00A389] rounded-full transition-all duration-500"
                              style={{ width: `${getModuleProgress(nextRecommendedActivity)}%` }}
                            />
                          </div>
                          <span className="text-[11px] font-bold text-slate-700 shrink-0">
                            {getModuleProgress(nextRecommendedActivity)}%
                          </span>
                        </div>
                      </div>

                      {/* Continue / Start Button */}
                      <button
                        onClick={() => handleLaunchModule(nextRecommendedActivity)}
                        className="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-[#00A389] hover:bg-[#008f77] text-white text-xs sm:text-sm font-bold transition-all shadow-xs shrink-0 flex items-center justify-center gap-1 cursor-pointer"
                      >
                        <span>
                          {nextRecommendedActivity.admin_unlocked && nextRecommendedActivity.status === 'completed'
                            ? 'Relaunch →'
                            : nextRecommendedActivity.status === 'in_progress'
                              ? 'Continue →'
                              : nextRecommendedActivity.status === 'failed'
                                ? 'Retake →'
                                : 'Start →'}
                        </span>
                      </button>
                    </div>
                  ) : (
                    <div className="bg-white border border-emerald-100 rounded-2xl p-5 sm:p-6 shadow-xs flex items-center gap-4 text-left">
                      <div className="h-12 w-12 rounded-2xl bg-emerald-50 border border-emerald-200 text-[#00A389] flex items-center justify-center shrink-0">
                        <CheckCircle2 className="h-6 w-6 text-[#00A389]" />
                      </div>
                      <div className="space-y-1">
                        <h4 className="text-sm font-extrabold text-slate-800">
                          All Onboarding Courses Completed!
                        </h4>
                        <p className="text-xs text-slate-500 font-medium">
                          You have successfully finished all assigned learning modules. No pending activities.
                        </p>
                      </div>
                    </div>
                  )}
                </div>

                {/* Right Card: Assessment Status */}
                <div className="lg:col-span-5 xl:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs flex flex-col justify-between space-y-4">
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2.5">
                      <div className={`h-8 w-8 rounded-full flex items-center justify-center shrink-0 border ${isPassed
                        ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                        : isFailed
                          ? 'bg-rose-50 text-rose-600 border-rose-200'
                          : 'bg-[#E6F7F4] text-[#00A389] border-[#00A389]/20'
                        }`}>
                        {isPassed ? (
                          <CheckCircle2 className="h-4 w-4" />
                        ) : isFailed ? (
                          <AlertTriangle className="h-4 w-4" />
                        ) : (
                          <GraduationCap className="h-4 w-4 text-[#00A389]" />
                        )}
                      </div>
                      <div>
                        <h3 className="text-sm sm:text-base font-black text-slate-800 tracking-tight leading-tight">
                          Assessment Status
                        </h3>
                        <p className="text-[11px] text-slate-400 font-medium">
                          Complete your final assessment to finish onboarding.
                        </p>
                      </div>
                    </div>

                    {(isPassed || isFailed) && (
                      <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold border shrink-0 ${isPassed
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-rose-50 text-rose-700 border-rose-200'
                        }`}>
                        {isPassed ? 'Passed' : 'Retake Needed'}
                      </span>
                    )}
                  </div>

                  <div className="flex flex-col sm:flex-row items-center gap-5 sm:gap-6 mt-1 flex-1">
                    {/* Donut Score Chart - Significantly Enlarged to Fill Space */}
                    <div className="relative h-36 w-36 sm:h-40 sm:w-40 shrink-0 flex items-center justify-center">
                      <svg className="w-full h-full transform -rotate-90" viewBox="0 0 140 140">
                        <circle
                          cx="70"
                          cy="70"
                          r="56"
                          stroke="#F1F5F9"
                          strokeWidth="11"
                          fill="transparent"
                        />
                        <circle
                          cx="70"
                          cy="70"
                          r="56"
                          stroke={isPassed ? "#00A389" : isFailed ? "#F43F5E" : "#00A389"}
                          strokeWidth="11"
                          strokeDasharray={2 * Math.PI * 56}
                          strokeDashoffset={2 * Math.PI * 56 * (1 - (hasAttempted && lastScore !== null ? Math.min(100, Math.max(0, lastScore)) : 0) / 100)}
                          strokeLinecap="round"
                          fill="transparent"
                          className="transition-all duration-700 ease-out"
                        />
                      </svg>
                      <div className="absolute inset-0 flex flex-col items-center justify-center text-center px-1">
                        <span className="text-3xl sm:text-4xl font-black text-slate-800 leading-none tracking-tight">
                          {hasAttempted && lastScore !== null ? `${Math.round(lastScore)}%` : '0%'}
                        </span>
                        <span className="text-[11px] font-bold text-slate-400 mt-1 uppercase tracking-wider">
                          {hasAttempted ? 'Last Score' : 'Not Started'}
                        </span>
                      </div>
                    </div>

                    {/* Assessment Status Info & Actions */}
                    <div className="flex-1 w-full space-y-3 text-center sm:text-left flex flex-col justify-center">
                      <div className="flex items-center justify-center sm:justify-start gap-1.5 text-xs font-bold text-slate-700">
                        <Target className="h-4 w-4 text-[#00A389] shrink-0" />
                        <span>Passing Requirement: <strong className="text-slate-900 font-extrabold">{passingScore}%</strong></span>
                      </div>

                      {/* Status Message Box */}
                      {isPassed ? (
                        <div className="bg-emerald-50/90 border border-emerald-200/90 rounded-xl p-3 text-xs font-bold text-emerald-800 flex items-center gap-2.5 shadow-2xs">
                          <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                          <span>Passed! You achieved {Math.round(lastScore ?? 0)}%.</span>
                        </div>
                      ) : isFailed ? (
                        <div className="bg-rose-50/90 border border-rose-200/80 rounded-xl p-3 text-xs font-semibold text-rose-800 flex items-center gap-2 shadow-2xs">
                          <AlertTriangle className="h-4 w-4 text-rose-500 shrink-0" />
                          <span>Scored {Math.round(lastScore ?? 0)}%. Need {Math.round(neededMore ?? 0)}% more to pass.</span>
                        </div>
                      ) : (
                        <div className="bg-slate-50 border border-slate-200/80 rounded-xl p-3 text-xs font-medium text-slate-600 shadow-2xs">
                          <span>Take the assessment to record your score.</span>
                        </div>
                      )}

                      <div className="flex items-center justify-center sm:justify-start gap-2.5 pt-0.5 flex-wrap">
                        {assessmentModule && !isAssessmentLocked && !isPassed && (
                          <button
                            onClick={() => handleLaunchModule(assessmentModule)}
                            className="px-4 py-2 rounded-xl bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold transition-all shadow-xs inline-flex items-center gap-1.5 cursor-pointer"
                          >
                            <Play className="w-3.5 h-3.5 fill-white text-white" />
                            <span>{hasAttempted ? 'Retake Assessment →' : 'Take Assessment →'}</span>
                          </button>
                        )}
                        <button
                          onClick={() => handleTabChange('attempt_history')}
                          className="px-4 py-2 rounded-xl border border-sky-300 hover:bg-sky-50 text-sky-700 text-xs font-bold transition-all shadow-2xs inline-flex items-center gap-1.5 cursor-pointer hover:shadow-xs"
                        >
                          <span>View Details →</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

              </div>

              {/* ROW 3: Recent Activity (Full Width matching reference image) */}
              <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <div className="h-8 w-8 rounded-full bg-[#E6F7F4] text-[#00A389] flex items-center justify-center shrink-0 border border-[#00A389]/20">
                      <Clock className="h-4 w-4" />
                    </div>
                    <h3 className="text-sm sm:text-base font-black text-slate-800 tracking-tight">
                      Recent Activity
                    </h3>
                  </div>

                  <button
                    onClick={() => handleTabChange('roadmap')}
                    className="text-xs sm:text-sm font-bold text-[#0284C7] hover:underline inline-flex items-center gap-1 cursor-pointer"
                  >
                    <span>View Learning Journey</span>
                    <ArrowRight className="h-3.5 w-3.5" />
                  </button>
                </div>

                <div className="divide-y divide-slate-100">
                  {recentActivities.map((item, idx) => {
                    const isAssess = Boolean(
                      item.is_assessment ||
                      item.title.toLowerCase().includes('assess') ||
                      item.title.toLowerCase().includes('quiz')
                    );

                      const isItemLocked = item.status !== 'completed' && Boolean(item.status === 'locked' || item.is_locked);

                      return (
                        <div
                          key={item.id || idx}
                          onClick={() => {
                            if (isItemLocked) {
                              toast({
                                title: "Course Locked",
                                description: item.locked_reason || "This course is currently locked.",
                                variant: "destructive",
                              });
                              return;
                            }
                            if (item.course_id || item.cmid || item.id) {
                              navigate(`/modules/${item.course_id || item.cmid || item.id}`);
                            } else {
                              navigate('/modules');
                            }
                          }}
                          className={`py-3 px-2 sm:px-3 flex items-center justify-between gap-3 sm:gap-4 rounded-xl transition-colors ${
                            isItemLocked
                              ? 'opacity-70 cursor-not-allowed hover:bg-slate-50/50'
                              : 'hover:bg-slate-50/80 cursor-pointer group'
                          }`}
                        >
                          {/* Thumbnail & Title Info */}
                          <div className="flex items-center gap-3 sm:gap-4 min-w-0">
                            <div className="h-11 w-16 sm:h-12 sm:w-20 rounded-xl overflow-hidden bg-slate-100 border border-slate-200/60 shrink-0 shadow-2xs relative">
                              <img
                                src={getCourseThumbnail(item)}
                                alt={item.title}
                                className="absolute inset-0 w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-300"
                                onError={(e) => {
                                  const target = e.target as HTMLImageElement;
                                  const cId = item.course_id || item.moodle_course_id || 2;
                                  if (!target.src.includes('course_image.php')) {
                                    target.src = `${API_BASE}/moodle/course_image.php?course_id=${cId}`;
                                  }
                                }}
                              />
                              {isItemLocked && (
                                <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-[0.5px] flex items-center justify-center">
                                  <Lock className="h-3.5 w-3.5 text-white/90" />
                                </div>
                              )}
                            </div>

                          <div className="min-w-0">
                            <h4 className="text-xs sm:text-sm font-bold text-slate-800 truncate group-hover:text-[#00A389] transition-colors">
                              {item.title}
                            </h4>
                            <p className="text-[11px] text-slate-400 font-medium truncate mt-0.5">
                              {isAssess ? 'Assessment' : 'Training'}
                            </p>
                          </div>
                        </div>

                        {/* Status, Date & Chevron */}
                        <div className="flex items-center gap-3 sm:gap-6 shrink-0">
                          {/* Status badge with colored circular icon inside matching screenshot */}
                          {isItemLocked ? (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#F0F5FA] text-slate-500 border border-slate-200">
                              <Lock className="h-3 w-3 text-slate-400" />
                              Locked
                            </span>
                          ) : item.status === 'completed' ? (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#EAFBF3] text-[#059669] border border-[#A7F3D0]">
                              <div className="h-3.5 w-3.5 rounded-full bg-[#10B981] text-white flex items-center justify-center shrink-0">
                                <Check className="h-2.5 w-2.5 stroke-[3]" />
                              </div>
                              <span>Completed</span>
                            </span>
                          ) : item.status === 'failed' ? (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#FEE2E2] text-[#DC2626] border border-[#FECACA]">
                              <div className="h-3.5 w-3.5 rounded-full bg-rose-500 text-white flex items-center justify-center shrink-0">
                                <X className="h-2.5 w-2.5 stroke-[3]" />
                              </div>
                              Failed
                            </span>
                          ) : item.status === 'in_progress' ? (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#FEF3C7] text-[#D97706] border border-[#FDE68A]">
                              <div className="h-3.5 w-3.5 rounded-full bg-amber-500 text-white flex items-center justify-center shrink-0">
                                <Clock className="h-2.5 w-2.5" />
                              </div>
                              In Progress
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#F0F5FA] text-slate-600 border border-slate-200">
                              Ready
                            </span>
                          )}

                          {/* Date timestamp */}
                          <div className="hidden md:block text-xs text-slate-400 font-medium">
                            {formatRecentActivityDate(item)}
                          </div>

                          {/* Chevron */}
                          <ChevronRight className="h-4 w-4 text-slate-400 group-hover:text-slate-600 transition-colors" />
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>

            </div>
          )
        )}

        {/* =========================================================================
            DEDICATED FULL VIEWS FOR MODULES, REPORTS, AND ATTEMPT HISTORY
           ========================================================================= */}
        {activeTab !== 'all' && (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

            {/* =======================================================================
                LEFT / MAIN SECTION
               ======================================================================= */}
            <div className={`${activeTab === 'roadmap' ? 'lg:col-span-9 xl:col-span-9' : 'col-span-12'} space-y-4`}>

              {/* LEARNING ROADMAP CARDS & CONTROLS (VISIBLE ONLY IN MODULES TAB) */}
              {activeTab === 'roadmap' && (
                <div className="space-y-4">
                  {/* 4. GRID OF 4 COURSE CARDS (SINGLE ROW ON DESKTOP) */}
                  {isInitialLoading ? (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-3.5 animate-pulse">
                      {[1, 2, 3, 4].map((n) => (
                        <div
                          key={n}
                          className="bg-white rounded-2xl border border-slate-200/90 p-3.5 flex flex-col justify-between space-y-3.5 shadow-2xs h-[375px]"
                        >
                          <div>
                            <div className="flex items-center justify-between pb-2">
                              <div className="h-6 w-6 rounded-full bg-slate-200" />
                              <div className="h-5 w-16 rounded-full bg-slate-200" />
                            </div>
                            <div className="h-28 w-full rounded-xl bg-slate-200 mt-1" />
                            <div className="h-4 w-3/4 bg-slate-200 rounded mt-3" />
                            <div className="h-3 w-full bg-slate-200 rounded mt-2" />
                            <div className="h-3 w-2/3 bg-slate-200 rounded mt-1.5" />
                          </div>
                          <div className="pt-3 border-t border-slate-100 space-y-2.5">
                            <div className="flex items-center justify-between">
                              <div className="h-3 w-14 bg-slate-200 rounded" />
                              <div className="h-3 w-14 bg-slate-200 rounded" />
                            </div>
                            <div className="h-9 w-full bg-slate-200 rounded-xl" />
                          </div>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-4">
                      {filteredActivities.length > 0 ? (
                        filteredActivities.map((item, idx) => {
                          const isAssess = Boolean(
                            item.is_assessment ||
                            item.title.toLowerCase().includes('assess') ||
                            item.title.toLowerCase().includes('quiz')
                          );
                          const courseNum = idx + 1;
                          const desc = getCourseDescription(item.title, item.subtitle, item.description);
                          const dur = getDisplayDuration(item.title, item.duration, item.duration_minutes);
                          const prog = getModuleProgress(item);

                          const isCardLocked = item.status !== 'completed' && Boolean(item.status === 'locked' || item.is_locked);
                          return (
                            <div
                              key={item.id}
                              onClick={isCardLocked ? undefined : () => navigate(`/modules/${item.course_id || item.cmid || item.id}`)}
                              className={`bg-white rounded-2xl border transition-all duration-200 flex flex-col justify-between p-3.5 sm:p-4 ${isCardLocked
                                ? 'cursor-not-allowed border-slate-200 shadow-2xs select-none'
                                : 'border-slate-200/90 shadow-2xs hover:shadow-xs cursor-pointer hover:border-[#22c55e]/60 hover:shadow-md group'
                                }`}
                              title={isCardLocked ? (item.locked_reason || 'Course locked • Prerequisite required') : undefined}
                            >
                              <div>
                                {/* Card Top: Solid Green Number Circle on Left, Status Badge on Right */}
                                <div className="flex items-center justify-between gap-2 pb-1">
                                  <div className="h-6 w-6 rounded-full bg-[#22c55e] text-white flex items-center justify-center text-xs font-bold shrink-0">
                                    {courseNum}
                                  </div>

                                  <div>
                                    {item.status === 'completed' ? (
                                      <span className="text-xs font-semibold text-[#16a34a] bg-[#ecfdf5] border border-[#bbf7d0] px-2.5 py-0.5 rounded-full flex items-center gap-1 shadow-2xs">
                                        <Check className="h-3.5 w-3.5 text-[#16a34a] stroke-[2.5]" />
                                        Completed
                                      </span>
                                    ) : item.status === 'in_progress' ? (
                                      <span className="text-xs font-semibold text-[#2563eb] bg-[#eff6ff] border border-[#bfdbfe] px-2.5 py-0.5 rounded-full flex items-center gap-1.5 shadow-2xs">
                                        <span className="h-3 w-3 rounded-full border-2 border-[#2563eb] border-t-transparent animate-spin inline-block" />
                                        In Progress
                                      </span>
                                    ) : isCardLocked ? (
                                      <span className="text-xs font-semibold text-slate-500 bg-slate-100 border border-slate-200 px-2.5 py-0.5 rounded-full flex items-center gap-1 shadow-2xs">
                                        <Lock className="h-3 w-3 text-slate-400" />
                                        Locked
                                      </span>
                                    ) : isAssess && item.status === 'failed' ? (
                                      <span className="text-xs font-semibold text-rose-800 bg-rose-50 border border-rose-300 px-2.5 py-0.5 rounded-full flex items-center gap-1 shadow-2xs">
                                        <XCircle className="h-3 w-3 text-rose-600" />
                                        Score: {item.score !== null && item.score !== undefined ? `${item.score}%` : '0%'}
                                      </span>
                                    ) : (
                                      <span className="text-xs font-semibold text-[#16a34a] bg-[#ecfdf5] border border-[#bbf7d0] px-2.5 py-0.5 rounded-full flex items-center gap-1 shadow-2xs">
                                        <Unlock className="h-3 w-3 text-[#16a34a]" />
                                        Available
                                      </span>
                                    )}
                                  </div>
                                </div>

                                {/* Cover Image with Exact Overlays */}
                                <div className="w-full relative h-32 sm:h-36 rounded-xl overflow-hidden bg-slate-100 border border-slate-200/60 my-2.5 shadow-2xs">
                                  <img
                                    src={getCourseThumbnail(item)}
                                    alt={item.title}
                                    className="absolute inset-0 w-full h-full object-cover object-center transition-transform duration-300 group-hover:scale-105"
                                    loading="eager"
                                    onError={(e) => {
                                      const target = e.target as HTMLImageElement;
                                      const cId = item.course_id || item.moodle_course_id || 2;
                                      if (!target.src.includes('course_image.php')) {
                                        target.src = `${API_BASE}/moodle/course_image.php?course_id=${cId}`;
                                      }
                                    }}
                                  />
                                  {item.status === 'completed' && (
                                    <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                                      <div className="h-10 w-10 rounded-full bg-[#22c55e] border-2 border-white flex items-center justify-center text-white shadow-md">
                                        <Check className="h-5 w-5 stroke-[3]" />
                                      </div>
                                    </div>
                                  )}
                                  {isCardLocked && (
                                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-[1px] flex items-center justify-center pointer-events-none">
                                      <div className="h-11 w-11 rounded-full bg-slate-800/80 border-2 border-white/80 flex items-center justify-center text-white shadow-lg">
                                        <Lock className="h-5 w-5" />
                                      </div>
                                    </div>
                                  )}
                                </div>

                                {/* Title */}
                                <h3 className="text-xs sm:text-sm font-bold text-slate-900 leading-snug line-clamp-1 mt-1" title={item.title}>
                                  {item.title}
                                </h3>

                                {/* Description */}
                                <p className="text-[11px] sm:text-xs text-slate-500 font-normal leading-relaxed line-clamp-2 mt-1 min-h-[34px]">
                                  {desc}
                                </p>

                                {/* Meta: Duration • Details */}
                                <div className="flex items-center justify-between text-[11px] sm:text-xs text-slate-500 font-medium mt-2 pt-0.5">
                                  <div className="flex items-center gap-1 text-slate-500">
                                    <Clock className="h-3.5 w-3.5 text-slate-400" />
                                    <span>{dur}</span>
                                  </div>
                                  {!(item.status === 'completed' && !item.admin_unlocked) && (
                                    <span className="text-[#16a34a] font-bold text-[11px] sm:text-xs group-hover:underline flex items-center gap-0.5 shrink-0">
                                      Details <ChevronRight className="h-3 w-3 inline" />
                                    </span>
                                  )}
                                </div>

                                {/* In-Progress Slim Progress Bar */}
                                {item.status === 'in_progress' && (
                                  <div className="flex items-center gap-2 mt-3 mb-0.5">
                                    <div className="flex-1 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                      <div
                                        className="bg-[#2563eb] h-1.5 rounded-full transition-all duration-300"
                                        style={{ width: `${Math.max(prog, 10)}%` }}
                                      />
                                    </div>
                                    <span className="text-[11px] font-bold text-slate-700 shrink-0">
                                      {prog || 50}%
                                    </span>
                                  </div>
                                )}
                              </div>

                              {/* Action Button(s) at bottom */}
                              <div className="pt-3 mt-auto">
                                {(item.status === 'completed' && !item.admin_unlocked) ? (
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      navigate(`/modules/${item.course_id || item.cmid || item.id}`);
                                    }}
                                    className="w-full bg-[#ecfdf5] hover:bg-[#d1fae5] border-[#bbf7d0] text-[#16a34a] hover:text-[#15803d] py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer"
                                    title="Course completed — click to view course details and progress (relaunch disabled)"
                                  >
                                    <span>View Details</span>
                                    <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                                  </Button>
                                ) : item.admin_unlocked && item.status === 'completed' ? (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleLaunchModule(item);
                                    }}
                                    className="w-full bg-[#22c55e] hover:bg-[#16a34a] text-white py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-between shadow-2xs cursor-pointer"
                                  >
                                    <div className="flex items-center gap-1.5">
                                      <CheckCircle2 className="h-3.5 w-3.5 text-white shrink-0" />
                                      <span>Relaunch Course</span>
                                    </div>
                                    <Play className="h-3 w-3 fill-white text-white shrink-0" />
                                  </button>
                                ) : item.status === 'in_progress' ? (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleLaunchModule(item);
                                    }}
                                    className="w-full bg-[#eff6ff] hover:bg-[#dbeafe] text-[#2563eb] border border-[#bfdbfe] py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer"
                                  >
                                    <span>Continue Course</span>
                                    <Play className="h-3 w-3 fill-[#2563eb] text-[#2563eb] shrink-0" />
                                  </button>
                                ) : isCardLocked ? (
                                  <button
                                    disabled
                                    className="w-full bg-slate-100 border border-slate-200 text-slate-400 py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-not-allowed shadow-2xs select-none"
                                    title={item.locked_reason || "Complete previous course to unlock"}
                                  >
                                    <Lock className="h-3.5 w-3.5 text-slate-400 shrink-0" />
                                    <span>Locked</span>
                                  </button>
                                ) : isAssess && item.status === 'failed' ? (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleRetakeModule(item);
                                    }}
                                    className="w-full bg-[#F59E0B] hover:bg-[#D97706] text-white py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer"
                                  >
                                    <RotateCcw className="h-3.5 w-3.5" />
                                    <span>Retake Assessment</span>
                                  </button>
                                ) : (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleLaunchModule(item);
                                    }}
                                    className="w-full bg-[#22c55e] hover:bg-[#16a34a] text-white py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer"
                                  >
                                    <span>Start Course</span>
                                    <Play className="h-3 w-3 fill-white text-white shrink-0" />
                                  </button>
                                )}
                              </div>
                            </div>
                          );
                        })
                      ) : (
                        <div className="col-span-full py-12 text-center bg-white border border-dashed border-slate-200 rounded-2xl p-6">
                          <div className="h-12 w-12 rounded-full bg-emerald-50 text-[#00A389] flex items-center justify-center mx-auto mb-3">
                            <BookOpen className="h-6 w-6" />
                          </div>
                          <h3 className="text-base font-bold text-slate-800">No modules match your filter</h3>
                          <p className="text-xs text-slate-500 max-w-md mx-auto mt-1">
                            Try resetting your search or filter pills to see all onboarding activities.
                          </p>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Course Completion & Relaunch Policy Notice */}
                  <div className="bg-amber-50/80 border border-amber-200/90 rounded-2xl p-4 sm:p-5 flex items-start gap-3.5 shadow-2xs mt-4">
                    <div className="h-9 w-9 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center shrink-0 mt-0.5">
                      <AlertTriangle className="h-5 w-5 text-amber-700" />
                    </div>
                    <div className="space-y-1">
                      <h4 className="text-xs font-bold text-amber-900 tracking-tight">
                        Course Completion &amp; Relaunch Policy
                      </h4>
                      <p className="text-xs text-amber-800 leading-relaxed font-medium">
                        Once you complete a course, you cannot launch it again without your manager&apos;s approval. If you wish to relaunch the course, please contact your manager for authorization.
                      </p>
                    </div>
                  </div>

                  {/* 5. FOOTER OF CARDS */}
                  {/* <div className="flex items-center justify-between pt-2 text-xs">
                  <span className="text-slate-400 font-medium">
                    Showing {filteredActivities.length} of {displayTotalCourses} activities
                  </span>
                  <button
                    className="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3.5 py-1.5 rounded-xl font-bold flex items-center gap-1.5 shadow-2xs cursor-pointer"
                  >
                    <Check className="h-3.5 w-3.5 text-[#00A389]" />
                    <span>Load more</span>
                  </button>
                </div> */}

                </div>
              )}

              {/* LIVE SCORM LEARNING & MODULE TRACKING REPORT (REPORTS TAB ONLY) */}
              {activeTab === 'scorm_report' && (
                <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs space-y-4">
                  {/* Dynamic Module Progress Table */}
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                      <thead>
                        <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-wider text-slate-400">
                          <th className="py-2.5 px-3">#</th>
                          <th className="py-2.5 px-3">Module Name</th>
                          <th className="py-2.5 px-3">Status</th>
                          <th className="py-2.5 px-3">Time Spent</th>
                          <th className="py-2.5 px-3 text-right">Action</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 text-xs">
                        {scormLearningModules.length > 0 ? (
                          scormLearningModules.map((m, idx) => {
                            const isDone = m.status === 'completed';
                            const isProgress = m.status === 'in_progress';
                            const isLocked = m.status === 'locked';

                            return (
                              <tr key={m.id || idx} className="hover:bg-slate-50/60 transition-colors">
                                <td className="py-3 px-3 font-bold text-slate-500">
                                  {idx + 1}
                                </td>
                                <td className="py-3 px-3">
                                  <div className="font-bold text-slate-800">{m.title}</div>
                                  <div className="text-[11px] text-slate-400 font-medium">{m.subtitle || m.section || 'Single activity · SCORM'}</div>
                                </td>
                                <td className="py-3 px-3">
                                  {isDone ? (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full shadow-2xs">
                                      <CheckCircle2 className="h-2.5 w-2.5 text-emerald-600" /> Completed
                                    </span>
                                  ) : isProgress ? (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-2 py-0.5 rounded-full">
                                      <span className="h-1.5 w-1.5 rounded-full bg-[#00A389] animate-pulse" />
                                      In Progress
                                    </span>
                                  ) : isLocked ? (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-400 bg-slate-50 border border-slate-200 px-2 py-0.5 rounded-full">
                                      <Lock className="h-2.5 w-2.5" /> Locked
                                    </span>
                                  ) : (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-300 px-2 py-0.5 rounded-full shadow-2xs">
                                      <Unlock className="h-2.5 w-2.5 text-emerald-600" /> Unlocked
                                    </span>
                                  )}
                                </td>
                                <td className="py-3 px-3 font-semibold text-slate-600">
                                  {m.total_time && m.total_time !== '00:00:00' ? m.total_time.split('.')[0] : (isProgress ? 'Active' : '—')}
                                </td>
                                <td className="py-3 px-3 text-right">
                                  {isDone ? (
                                    m.admin_unlocked ? (
                                      <button
                                        onClick={() => handleLaunchModule(m)}
                                        className="text-[11px] font-bold text-white bg-emerald-600 hover:bg-emerald-700 px-3 py-1 rounded-lg transition-all inline-flex items-center gap-1 cursor-pointer shadow-xs"
                                        title="Relaunch Module"
                                      >
                                        Relaunch →
                                      </button>
                                    ) : (
                                      <button
                                        onClick={() => navigate(`/modules/${m.course_id || m.cmid || m.id}`)}
                                        className="text-[11px] font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-2.5 py-1 rounded-lg transition-all inline-flex items-center gap-1 cursor-pointer"
                                        title="View course details and progress"
                                      >
                                        View Details →
                                      </button>
                                    )
                                  ) : isProgress ? (
                                    <button
                                      onClick={() => handleLaunchModule(m)}
                                      className="text-[11px] font-bold text-white bg-[#00A389] hover:bg-[#008f77] px-3 py-1 rounded-lg transition-all inline-flex items-center gap-1 cursor-pointer shadow-xs"
                                      title="Continue Module"
                                    >
                                      Continue →
                                    </button>
                                  ) : !isLocked ? (
                                    <button
                                      onClick={() => handleLaunchModule(m)}
                                      className="text-[11px] font-bold text-white bg-[#00A389] hover:bg-[#008f77] px-3 py-1 rounded-lg transition-all inline-flex items-center gap-1 cursor-pointer shadow-xs"
                                      title="Start Module"
                                    >
                                      Start →
                                    </button>
                                  ) : (
                                    <span className="text-[11px] text-slate-300 font-medium">Locked</span>
                                  )}
                                </td>
                              </tr>
                            );
                          })
                        ) : (
                          <tr>
                            <td colSpan={5} className="py-8 text-center text-slate-400 text-xs">
                              No learning modules loaded yet.
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* =========================================================================
                ASSESSMENT ATTEMPT HISTORY (DEDICATED SEPARATE TABLE - ATTEMPT HISTORY TAB ONLY)
                Columns: Attempt | Score | Result | Duration | Date & Time | Action
               ========================================================================= */}
              {activeTab === 'attempt_history' && (
                <div className="bg-white border border-slate-200/80 rounded-2xl p-5 sm:p-6 shadow-2xs space-y-4">
                  {/* Attempt History Table */}
                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse text-xs">
                      <thead>
                        <tr className="border-b border-slate-200 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                          <th className="py-2.5 px-3">Attempt</th>
                          <th className="py-2.5 px-3">Score</th>
                          <th className="py-2.5 px-3">Result</th>
                          <th className="py-2.5 px-3">Duration</th>
                          <th className="py-2.5 px-3">Date & Time</th>
                          <th className="py-2.5 px-3 text-right">Action</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {displayedAttempts.length > 0 ? (
                          displayedAttempts.map((row: any, idx: number) => {
                            const isPassed = row.is_passed || row.result === 'Passed';
                            const isFailed = row.is_failed || row.result === 'Failed';
                            return (
                              <tr key={idx} className="hover:bg-slate-50/70 transition-colors">
                                <td className="py-3.5 px-3">
                                  <div className="flex items-center gap-2">
                                    <span className="font-bold text-slate-800 text-xs">
                                      Attempt {row.attempt}
                                    </span>
                                    {row.attempt === 1 && (
                                      <span className="text-[10px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-medium">
                                        Initial
                                      </span>
                                    )}
                                    {isPassed && (
                                      <span className="text-[10px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded font-medium">
                                        Qualified
                                      </span>
                                    )}
                                  </div>
                                </td>
                                <td className="py-3.5 px-3">
                                  <span className={`text-sm font-black ${isPassed ? 'text-emerald-600' : 'text-red-600'}`}>
                                    {row.score !== null && row.score !== undefined ? `${row.score}%` : '0%'}
                                  </span>
                                </td>
                                <td className="py-3.5 px-3">
                                  {isPassed ? (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-full">
                                      <CheckCircle className="h-3 w-3" /> Passed
                                    </span>
                                  ) : isFailed ? (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-0.5 rounded-full">
                                      <AlertTriangle className="h-3 w-3" /> Failed
                                    </span>
                                  ) : (
                                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-slate-700 bg-slate-100 border border-slate-200 px-2.5 py-0.5 rounded-full">
                                      {row.result}
                                    </span>
                                  )}
                                </td>
                                <td className="py-3.5 px-3 font-semibold text-slate-600">
                                  {row.formattedDuration || '—'}
                                </td>
                                <td className="py-3.5 px-3 text-slate-500 font-medium text-[11px]">
                                  {row.date || '—'}
                                </td>
                                <td className="py-3.5 px-3 text-right">
                                  <div className="flex items-center justify-end gap-1.5">
                                    {assessmentModule && (
                                      <>
                                        <button
                                          onClick={() => handleReviewModule(assessmentModule)}
                                          className="text-[11px] font-bold text-slate-700 hover:text-slate-900 bg-white hover:bg-slate-50 border border-slate-200 px-2.5 py-1 rounded-lg transition-all inline-flex items-center gap-1 cursor-pointer shadow-2xs"
                                          title="Review Quiz"
                                        >
                                          Review Quiz
                                        </button>
                                        {!isPassed && (
                                          <button
                                            onClick={() => handleRetakeModule(assessmentModule)}
                                            className="text-[11px] font-bold text-white bg-amber-600 hover:bg-amber-700 px-2.5 py-1 rounded-lg transition-all inline-flex items-center gap-1 cursor-pointer shadow-xs"
                                            title="Retake Quiz / Assessment"
                                          >
                                            Retake Quiz →
                                          </button>
                                        )}
                                      </>
                                    )}
                                  </div>
                                </td>
                              </tr>
                            );
                          })
                        ) : (
                          <tr>
                            <td colSpan={6} className="py-8 text-center text-slate-400">
                              <p className="font-semibold text-slate-600">No attempts recorded yet.</p>
                              <p className="text-[11px] text-slate-400 mt-1">Take the assessment to record your attempt and score in real time.</p>
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>

                  {/* Helper Footer Note */}
                  <div className="bg-slate-50 border border-slate-200/70 rounded-xl p-3 flex items-center text-xs">
                    <div className="flex items-center gap-2 text-slate-600">
                      <AlertTriangle className="h-4 w-4 text-amber-500 shrink-0" />
                      <span>
                        Passing threshold: <strong>{assessmentModule?.passing_score ?? 80}%</strong>. Retakes record new attempts automatically.
                      </span>
                    </div>
                  </div>
                </div>
              )}

            </div>

            {/* =======================================================================
              RIGHT SIDEBAR (Col-span 12 lg:col-span-3 xl:col-span-3):
              Learning Path Vertical Timeline Card (Matching Mockup)
             ======================================================================= */}
            {activeTab === 'roadmap' && (
              <div className="lg:col-span-3 xl:col-span-3 space-y-4">
                <div className="bg-white border border-slate-200/90 rounded-2xl p-4 sm:p-5 shadow-2xs space-y-4">
                  <div className="flex items-center gap-2 mb-4">
                    <Flag className="h-5 w-5 text-[#22c55e] fill-[#22c55e] shrink-0" />
                    <h3 className="text-base font-bold text-slate-900 tracking-tight">
                      Learning Path
                    </h3>
                  </div>

                  <div className="space-y-4 relative">
                    {displayModules.length > 0 ? (
                      displayModules.map((item, idx) => {
                        const prog = getModuleProgress(item);
                        const isItemLocked = item.status !== 'completed' && Boolean(item.status === 'locked' || item.is_locked);
                        const isLast = idx === displayModules.length - 1;

                        let subtitle = 'Not Started';
                        if (item.status === 'completed') {
                          subtitle = 'Completed · 100%';
                        } else if (item.status === 'in_progress') {
                          subtitle = `In Progress · ${prog || 50}%`;
                        } else if (isItemLocked) {
                          subtitle = 'Locked';
                        } else if (item.status === 'failed') {
                          subtitle = `Failed · Score: ${item.score ?? 0}%`;
                        }

                        // Number circle background matching uploaded screenshot
                        const circleBgClass = item.status === 'completed'
                          ? 'bg-[#ecfdf5] text-[#15803d]'
                          : item.status === 'in_progress'
                            ? 'bg-[#eff6ff] text-[#2563eb]'
                            : isItemLocked
                              ? 'bg-[#f1f5f9] text-slate-700'
                              : 'bg-slate-100 text-slate-600';

                        return (
                          <div key={item.id} className="relative">
                            {/* Vertical connector line between step circles */}
                            {!isLast && (
                              <div className="absolute left-[15px] top-8 bottom-[-16px] w-[2px] bg-slate-100 z-0" />
                            )}

                            <div
                              onClick={isItemLocked ? undefined : () => navigate(`/modules/${item.course_id || item.cmid || item.id}`)}
                              className={`flex items-center justify-between gap-3 relative z-10 py-1 ${isItemLocked ? 'cursor-not-allowed select-none' : 'cursor-pointer group'}`}
                              title={isItemLocked ? (item.locked_reason || 'Locked • Prerequisite required') : item.title}
                            >
                              <div className="flex items-center gap-3.5 min-w-0">
                                <div className={`h-8 w-8 rounded-full flex items-center justify-center text-xs font-bold shrink-0 ${circleBgClass}`}>
                                  {idx + 1}
                                </div>

                                <div className="min-w-0">
                                  <h4 className={`text-xs font-bold text-slate-900 truncate transition-colors ${!isItemLocked && 'group-hover:text-[#22c55e]'}`}>
                                    {item.title}
                                  </h4>
                                  <div className="text-[11px] font-medium text-slate-400 truncate mt-0.5">
                                    {subtitle}
                                  </div>
                                </div>
                              </div>

                              <div className="shrink-0 flex items-center justify-center">
                                {item.status === 'completed' ? (
                                  <div className="h-5 w-5 rounded-full bg-[#22c55e] text-white flex items-center justify-center shadow-2xs">
                                    <Check className="h-3 w-3 stroke-[3]" />
                                  </div>
                                ) : item.status === 'in_progress' ? (
                                  <div className="h-5 w-5 rounded-full border-2 border-[#2563eb] border-t-transparent animate-spin" />
                                ) : isItemLocked ? (
                                  <div className="h-5 w-5 rounded-md bg-[#64748b] text-white flex items-center justify-center shrink-0 shadow-2xs">
                                    <Lock className="h-3 w-3 text-white" />
                                  </div>
                                ) : (
                                  <div className="h-5 w-5 rounded-full border border-slate-300" />
                                )}
                              </div>
                            </div>
                          </div>
                        );
                      })
                    ) : (
                      <div className="py-6 text-center text-xs text-slate-400">
                        Awaiting Moodle courses...
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )}

          </div>
        )}

        {/* =========================================================================
            COURSE PLAYER MODAL
           ========================================================================= */}
        <CoursePlayerModal
          courseId={activeCourseId}
          isOpen={activeCourseId !== null}
          onClose={() => setActiveCourseId(null)}
          onOpenCourse={(newId) => setActiveCourseId(newId)}
        />

        {/* In-Portal Protected Full-Screen SCORM Player (Zero loading overlay, 100% hidden Moodle URL) */}
        <ScormPlayerModal
          isOpen={playerState.isOpen}
          onClose={handleClosePlayer}
          launchUrl={playerState.url}
          courseTitle={playerState.title}
        />

        {/* Dedicated Window SCORM Player Active Modal (Synced across all open tabs) */}
        <ActiveCourseModal
          activeSession={activeSession}
          onFocus={focusCourseWindow}
          onCloseSession={closeActiveSession}
        />
      </div>
    </Layout>
  );
};

export default Dashboard;