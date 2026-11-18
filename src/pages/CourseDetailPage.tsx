import { useMemo, useState, useEffect, useRef, useCallback } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Layout } from "@/components/Layout";
import { useAuth } from "@/hooks/useAuth";
import { useQuery } from "@tanstack/react-query";
import { apiGet, API_BASE } from "@/lib/api";
import {
  ArrowLeft,
  Play,
  CheckCircle2,
  Clock,
  RotateCcw,
  Eye,
  Trophy,
  XCircle,
  FileText,
  Calendar,
  Shield,
  Activity,
  History,
  Info,
  ChevronRight,
  Lock,
  Timer
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { ScormPlayerModal } from "@/components/ScormPlayerModal";
import { ActiveCourseModal, useActiveCourseSession } from "@/components/ActiveCourseModal";
import { toast } from "@/hooks/use-toast";

const IS_MAINTENANCE_MODE = false;

export const CourseDetailPage = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const routerBase = (typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis/onboard') ? '/internal/corhis/onboard' : '/onboard');
  const corhisBase = (typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis') ? '/internal/corhis' : '');
  const [activeTab, setActiveTab] = useState<'telemetry' | 'history'>('telemetry');

  // Fetch real Moodle courses data using shared React Query cache (Event-driven sync on course close)
  const forceSyncRef = useRef(false);
  const syncCourseIdRef = useRef<string | number | null>(null);
  const {
    data: moodleDataRaw,
    isLoading,
    refetch: refetchCourses,
  } = useQuery<{
    success: boolean;
    linked: boolean;
    employee?: { uid: number; username: string; name: string; moodle_user_id: number };
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
        throw err;
      }
    },
    placeholderData: (previousData) => previousData,
    enabled: !!user?.uid,
    staleTime: Infinity, // Serve cached state across refreshes & tab switches; sync live on course close
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    refetchInterval: false,
  });

  const moodleData = moodleDataRaw ?? null;

  // Decode HTML entities in course and module titles
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

  // Flatten all modules from Moodle courses
  const allModules = useMemo(() => {
    if (!moodleData?.courses || moodleData.courses.length === 0) return [];
    const list: any[] = [];
    let autoId = 1;

    moodleData.courses.forEach((c: any, cIdx: number) => {
      const courseIdNum = Number(c.id) || (cIdx === 0 ? 2 : cIdx === 1 ? 3 : 4);
      const courseImage = c.course_image || `${API_BASE}/moodle/course_image.php?course_id=${courseIdNum}`;
      const isCourseCompleted = Boolean(c.completed || c.is_completed || c.status === 'completed' || Number(c.progress_percent) === 100);

      if (c.modules && Array.isArray(c.modules) && c.modules.length > 0) {
        c.modules.forEach((m: any) => {
          const modType = (m.modname || '').toLowerCase();
          if (['forum', 'label', 'chat', 'news'].includes(modType)) return;

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
            (m.total_time && m.total_time !== '00:00:00' && m.total_time !== '')
          );

          const statusVal = isFailed ? 'failed' : isDone ? 'completed' : inProgress ? 'in_progress' : 'available';
          const targetUrl = m.url || m.launch_url || (m.cmid ? `${API_BASE}/moodle/launch.php?cmid=${m.cmid}${m.scoid ? `&scoid=${m.scoid}` : ''}${m.course_id || m.moodle_id ? `&courseid=${m.course_id || m.moodle_id}` : ''}` : '');

          const rawSub = m.section && m.section !== 'Single activity' && m.section !== 'General'
            ? m.section
            : (c.fullname || 'Onboarding Activity');

          const totalSlidesCount = Number(m.total_slides) || 10;
          const currentSlideVal = isDone ? totalSlidesCount : ((statusVal === 'available' || statusVal === 'not_started' || Number(m.progress_percent) === 0) ? 0 : (Number(m.current_slide) || 1));

          list.push({
            id: m.module_id || m.id || autoId++,
            module_id: m.module_id || m.id,
            cmid: m.cmid,
            instance: m.instance,
            title: decodeCourseTitle(m.title || c.fullname || `Module ${autoId}`),
            subtitle: decodeCourseTitle(rawSub),
            description: m.description || c.description || null,
            duration_minutes: m.duration_minutes || c.duration_minutes || null,
            duration: m.duration || (m.duration_minutes ? `${m.duration_minutes} min` : (c.duration_minutes ? `${c.duration_minutes} min` : '30 min')),
            section: m.section || 'General',
            modname: m.modname || 'scorm',
            is_assessment: isAssess,
            status: statusVal,
            url: targetUrl,
            retake_url: m.retake_url || `${targetUrl}&newattempt=on`,
            review_url: m.review_url || `${targetUrl}&mode=review`,
            passing_score: passingThreshold,
            total_time: m.total_time || '',
            score: currentScore,
            attempts: isAssess ? (m.attempts !== undefined ? Number(m.attempts) : 0) : 0,
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
            progress_percent: (statusVal === 'available' || statusVal === 'not_started') ? 0 : (isDone ? 100 : (m.progress_percent !== undefined && m.progress_percent !== null && Number(m.progress_percent) > 0 ? Number(m.progress_percent) : (inProgress ? 35 : 0))),
            course_id: courseIdNum,
            course_fullname: decodeCourseTitle(c.fullname || 'HexaLearn Onboarding'),
            course_image: m.course_image || courseImage,
            admin_unlocked: Boolean(m.admin_unlocked || c.admin_unlocked),
          });
        });
      } else if (c.scorms && Array.isArray(c.scorms) && c.scorms.length > 0) {
        c.scorms.forEach((sc: any) => {
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
            (sc.total_time && sc.total_time !== '00:00:00' && sc.total_time !== '')
          );

          const statusVal = isFailed ? 'failed' : isDone ? 'completed' : inProgress ? 'in_progress' : 'available';
          const targetUrl = sc.url || sc.launch_url || (sc.id ? `${API_BASE}/moodle/launch.php?cmid=${sc.id}${sc.scoid ? `&scoid=${sc.scoid}` : ''}` : '');

          const totalSlidesCount = Number(sc.total_slides) || 10;
          const currentSlideVal = isDone ? totalSlidesCount : ((statusVal === 'available' || statusVal === 'not_started' || Number(sc.progress_percent) === 0) ? 0 : (Number(sc.current_slide) || 1));

          list.push({
            id: sc.module_id || sc.id || autoId++,
            module_id: sc.module_id || sc.id,
            scoid: sc.scoid || (targetUrl && targetUrl.match(/[?&]scoid=(\d+)/)?.[1]) || null,
            currentorg: sc.organization || (targetUrl && targetUrl.match(/[?&]currentorg=([^&]+)/)?.[1]) || null,
            cmid: sc.id,
            instance: sc.id,
            title: decodeCourseTitle(sc.name || c.fullname || `SCORM Module ${autoId}`),
            subtitle: decodeCourseTitle(c.fullname || 'Onboarding Training'),
            description: sc.description || c.description || null,
            duration_minutes: sc.duration_minutes || c.duration_minutes || null,
            duration: sc.duration || (sc.duration_minutes ? `${sc.duration_minutes} min` : (c.duration_minutes ? `${c.duration_minutes} min` : '30 min')),
            section: 'SCORM Packages',
            modname: 'scorm',
            is_assessment: isAssess,
            status: statusVal,
            url: targetUrl,
            retake_url: `${targetUrl}&newattempt=on`,
            review_url: `${targetUrl}&mode=review`,
            passing_score: passingThreshold,
            total_time: sc.total_time || '',
            score: currentScore,
            attempts: isAssess ? (sc.attempts !== undefined ? Number(sc.attempts) : 0) : 0,
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
            progress_percent: (statusVal === 'available' || statusVal === 'not_started') ? 0 : (isDone ? 100 : (sc.progress_percent !== undefined && sc.progress_percent !== null && Number(sc.progress_percent) > 0 ? Number(sc.progress_percent) : (inProgress ? 35 : 0))),
            course_id: courseIdNum,
            course_fullname: decodeCourseTitle(c.fullname || 'HexaLearn Onboarding'),
            course_image: sc.course_image || courseImage,
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
        const statusVal = isDone ? 'completed' : inProgress ? 'in_progress' : 'available';
        const targetUrl = `${API_BASE}/moodle/launch.php?courseid=${courseIdNum}`;
        const totalSlidesCount = 10;
        const currentSlideVal = isDone ? totalSlidesCount : 0;

        list.push({
          id: c.module_id || c.id || autoId++,
          module_id: c.module_id || c.id,
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
          course_fullname: decodeCourseTitle(c.fullname || 'HexaLearn Onboarding'),
          course_image: courseImage,
          admin_unlocked: Boolean(c.admin_unlocked),
        });
      }
    });

    // Sequential Progression Rule: Default to enabled as core LMS policy
    const isSequentialLocking = (moodleData as any)?.sequential_locking !== false;

    let allPrevCompleted = true;
    let prevTitle = '';
    return list.map((item) => {
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

  // Find targeted module: Prioritize Moodle Course ID first, then CMID, then title/module_id
  const currentModule = useMemo(() => {
    if (allModules.length === 0) return null;
    if (!id) return allModules[0];
    const targetId = Number(id);

    // 1. Primary & Authoritative: Match by Moodle course_id (e.g. /modules/2, /modules/3, /modules/4, /modules/5, /modules/6)
    if (!isNaN(targetId) && targetId > 0) {
      const byCourseId = allModules.find(m => Number(m.course_id) === targetId);
      if (byCourseId) return byCourseId;

      // 2. Match by Moodle cmid
      const byCmid = allModules.find(m => m.cmid && Number(m.cmid) === targetId);
      if (byCmid) return byCmid;

      // 3. Fallback: Match by HexaLearn local module_id
      const byModuleId = allModules.find(m => m.module_id && Number(m.module_id) === targetId);
      if (byModuleId) return byModuleId;

      // 4. Fallback: Match by instance
      const byInstance = allModules.find(m => m.instance && Number(m.instance) === targetId);
      if (byInstance) return byInstance;
    }

    // 5. Match by title if id is a text name or slug
    const cleanId = String(id).toLowerCase().replace(/[-_]/g, ' ');
    const byTitle = allModules.find(m => {
      const t = (m.title || '').toLowerCase().replace(/[-_]/g, ' ');
      return t.includes(cleanId) || cleanId.includes(t);
    });
    if (byTitle) return byTitle;

    return allModules[0];
  }, [id, allModules]);

  // Image loading & fallback state for Course Detail cover box
  const [imageLoaded, setImageLoaded] = useState(false);
  const [imageError, setImageError] = useState(false);
  const [moduleImageSrc, setModuleImageSrc] = useState<string>('');

  useEffect(() => {
    if (!currentModule) return;
    setImageLoaded(false);
    setImageError(false);
    const cId = currentModule.course_id || 2;
    const initialSrc = (currentModule.course_image && !currentModule.course_image.includes('placeholder') && String(currentModule.course_image).trim() !== '')
      ? currentModule.course_image
      : `${API_BASE}/moodle/course_image.php?course_id=${cId}`;
    setModuleImageSrc(initialSrc);
  }, [currentModule?.id, currentModule?.course_id, currentModule?.course_image]);

  // Format date helper
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
      if (h > 0) return `${h}h ${m} min ${s} sec`;
      if (m > 0) return `${m} min ${s} sec`;
      if (s > 0) return `0 min ${s} sec`;
    }
    return timeStr;
  };

  // Compute total elapsed calendar span between First Started At and Last Activity
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

  // Compact Session Span helper matching screenshot e.g. "43h 1m 21s"
  const computeCompactSpan = (start?: any, end?: any, fallbackDuration?: string) => {
    try {
      const parseTime = (val: any) => {
        if (!val) return 0;
        if (typeof val === 'number') return val > 1e11 ? val : val * 1000;
        const d = new Date(val).getTime();
        return isNaN(d) ? 0 : d;
      };
      const t1 = parseTime(start);
      const t2 = parseTime(end);
      if (t1 > 0 && t2 > 0 && t2 >= t1) {
        const diffSec = Math.round((t2 - t1) / 1000);
        if (diffSec <= 0) return 'Just started';
        if (diffSec < 60) return `${diffSec}s`;
        const h = Math.floor(diffSec / 3600);
        const m = Math.floor((diffSec % 3600) / 60);
        const s = diffSec % 60;
        if (h > 0) return s > 0 ? `${h}h ${m}m ${s}s` : `${h}h ${m}m`;
        if (m > 0 && s > 0) return `${m}m ${s}s`;
        if (m > 0) return `${m}m`;
        return `${diffSec}s`;
      }
    } catch { }

    if (fallbackDuration && fallbackDuration !== '00:00:00' && fallbackDuration !== '0s' && fallbackDuration !== '0 min 0 sec') {
      const clean = fallbackDuration.split('.')[0];
      const parts = clean.split(':');
      if (parts.length === 3) {
        const h = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        const s = parseInt(parts[2], 10);
        if (h > 0) return s > 0 ? `${h}h ${m}m ${s}s` : `${h}h ${m}m`;
        if (m > 0 && s > 0) return `${m}m ${s}s`;
        if (m > 0) return `${m}m`;
        if (s > 0) return `${s}s`;
      }
      return fallbackDuration
        .replace(/ min /g, 'm ')
        .replace(/ min/g, 'm')
        .replace(/ sec/g, 's');
    }
    return '0s';
  };

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
          refetchCourses();
        }, 2000);
      }
      return;
    }
    lastRefreshTimeRef.current = now;
    refetchCourses();
  }, [refetchCourses]);

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
        title: "Platform Under Maintenance",
        description: "Course playback is temporarily suspended while scheduled system maintenance is underway. Please check back shortly.",
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

    // Open dedicated full-screen SCORM player in a new window with multi-tab coordination
    const courseWin = launchCourseWindow(playUrl, title, {
      cmid,
      scoid,
      courseid,
      module_id: moduleId,
      ...itemOptions,
    });

    if (!courseWin) {
      // Fallback: If popup blocker intercepts, open inside in-portal modal
      const state = { isOpen: true, url, title };
      try {
        sessionStorage.setItem('hexalearn_active_scorm', JSON.stringify(state));
      } catch { }
      setPlayerState(state);
    }
  };

  const handleLaunchModule = (item: any) => {
    if (!item) return;
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
    if (!launchUrl) return;
    openPlayer(launchUrl, item.title || item.name || 'Onboarding Training Module', item);
  };

  const handleRetake = (item: any) => {
    if (!item) return;
    const retakeUrl = item.retake_url || `${item.url}&newattempt=on`;
    openPlayer(retakeUrl, `${item.title || item.name || 'Assessment'} (Retake)`, { ...item, newattempt: 'on' });
  };

  const handleReview = (item: any) => {
    if (!item) return;
    const reviewUrl = item.review_url || `${item.url}&mode=review`;
    openPlayer(reviewUrl, `${item.title || item.name || 'Assessment'} (Review)`, { ...item, mode: 'review' });
  };

  const handleClosePlayer = () => {
    try {
      sessionStorage.removeItem('hexalearn_active_scorm');
    } catch { }
    setPlayerState({ isOpen: false, url: null, title: '' });
    // Immediately reflect latest progress, scores, and status upon closing the course
    triggerUnifiedRefresh();
  };

  // Consolidated auto-refresh: BroadcastChannel, storage events, window focus, visibility, and bfcache pageshow
  useEffect(() => {
    // 1. Mount & Route Transition Check: Discovers SCORM exit even when page was unmounted
    const checkPendingSync = () => {
      try {
        const needsSync = sessionStorage.getItem('hexalearn_scorm_needs_sync') || localStorage.getItem('hexalearn_scorm_last_closed');
        const lastHandled = sessionStorage.getItem('hexalearn_scorm_last_handled');
        if (needsSync && (!lastHandled || Number(needsSync) > Number(lastHandled))) {
          sessionStorage.setItem('hexalearn_scorm_last_handled', needsSync);
          triggerUnifiedRefresh();
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

  // Dynamic Course Descriptions with priority for Admin-edited descriptions
  const getCourseDescription = (title: string, fallbackSub?: string, customDesc?: string) => {
    if (customDesc && customDesc.trim() && customDesc !== 'Single activity' && customDesc !== 'General') {
      return customDesc.trim();
    }
    const t = (title || '').toLowerCase();
    if (t.includes('onboard')) {
      return 'Learn about our company, culture, values and what to expect in your first days.';
    }
    if (t.includes('security') || t.includes('confidential') || t.includes('it')) {
      return 'Comprehensive information security, intellectual property handling, password compliance, and workplace confidentiality standards for HexaLearn employees.';
    }
    if (t.includes('work life') || t.includes('culture') || t.includes('ethos') || t.includes('rules')) {
      return 'Understand workplace policies, code of conduct, core values, and work-life balance.';
    }
    if (t.includes('leave') || t.includes('holiday')) {
      return 'Explore leave policies, holidays, attendance regulations, and workplace guidelines.';
    }
    if (t.includes('assess') || t.includes('quiz')) {
      return 'Official onboarding evaluation quiz verifying your understanding of HexaLearn policies, security protocols, and operational workflows.';
    }
    if (fallbackSub && fallbackSub.trim() && fallbackSub !== 'Single activity' && fallbackSub !== 'General' && fallbackSub !== 'SCORM Packages') {
      return fallbackSub.trim();
    }
    return 'Core induction module for new team members at HexaLearn.';
  };

  const employeeName = user ? `${user.firstname || ''} ${user.lastname || ''}`.trim() || user.username : 'Employee';

  if (isLoading) {
    return (
      <Layout activeEmployeeTab="roadmap">
        <div className="w-full space-y-6 animate-pulse">
          <div className="h-8 bg-slate-200 rounded-lg w-48" />
          <div className="h-64 bg-slate-200 rounded-2xl w-full" />
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div className="h-28 bg-slate-200 rounded-xl" />
            <div className="h-28 bg-slate-200 rounded-xl" />
            <div className="h-28 bg-slate-200 rounded-xl" />
            <div className="h-28 bg-slate-200 rounded-xl" />
          </div>
        </div>
      </Layout>
    );
  }

  if (!currentModule) {
    return (
      <Layout activeEmployeeTab="roadmap">
        <div className="max-w-4xl mx-auto py-16 px-4 text-center">
          <div className="h-16 w-16 mx-auto rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-4">
            <Info className="h-8 w-8" />
          </div>
          <h2 className="text-xl font-bold text-slate-800">No Courses Available</h2>
          <p className="text-slate-500 text-sm mt-1 max-w-md mx-auto">
            Your onboarding courses have not yet synced from Moodle. Please check back shortly.
          </p>
          <Button
            onClick={() => navigate('/modules')}
            className="mt-6 bg-[#00A389] hover:bg-[#008f77] text-white"
          >
            <ArrowLeft className="h-4 w-4 mr-2" />
            My Learning Journey
          </Button>
        </div>
      </Layout>
    );
  }

  const isAssess = currentModule.is_assessment;
  const isCompleted = currentModule.status === 'completed' || Boolean(currentModule.is_completed) || Number(currentModule.progress_percent) === 100;
  const isLocked = !isCompleted && Boolean(currentModule.is_locked || currentModule.status === 'locked');
  const isInProgress = !isCompleted && !isLocked && currentModule.status === 'in_progress';
  const isFailed = !isCompleted && !isLocked && currentModule.status === 'failed';

  const formattedStartedAt = formatDateTime(currentModule.first_started_at);
  const formattedLastPlayed = formatDateTime(currentModule.last_played_at);
  const formattedTimeSpent = formatDuration(currentModule.total_time);

  // Total elapsed calendar span between First Started At and Last Activity
  const elapsedSpan = computeElapsedSpan(currentModule.first_started_at, currentModule.last_played_at);
  const compactSessionSpan = computeCompactSpan(currentModule.first_started_at, currentModule.last_played_at, currentModule.total_time);

  return (
    <Layout activeEmployeeTab="roadmap">
      <div className="w-full space-y-6">

        {/* TOP BAR / BREADCRUMB */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <button
            onClick={() => navigate('/modules')}
            className="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 hover:text-[#00A389] transition-colors cursor-pointer group"
          >
            <div className="h-7 w-7 rounded-lg bg-white border border-slate-200 flex items-center justify-center shadow-2xs group-hover:border-[#00A389]/40">
              <ArrowLeft className="h-4 w-4" />
            </div>
            <span>My Learning Journey</span>
          </button>

          <div className="flex items-center gap-2 text-xs text-slate-500">
            <span className="cursor-pointer hover:text-slate-800" onClick={() => navigate('/dashboard')}>Home</span>
            <span>/</span>
            <span className="cursor-pointer hover:text-slate-800" onClick={() => navigate('/modules')}>Learning Roadmap</span>
            <span>/</span>
            <span className="font-semibold text-slate-700 truncate max-w-md">{currentModule.title}</span>
          </div>
        </div>

        {/* TOP SECTION: HERO BANNER (70%) + 3 METRIC CARDS (30%) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">

          {/* Left: Hero Course Banner Card (70% width) */}
          <div className="lg:col-span-8 bg-white rounded-3xl border border-slate-200/90 shadow-xs overflow-hidden flex flex-col justify-between">
            <div className="grid grid-cols-1 md:grid-cols-12 gap-0 h-full flex-1">

              {/* Left: Course Image Thumbnail (100% Box Cover Fit) */}
              <div className="md:col-span-5 lg:col-span-5 xl:col-span-4 relative w-full h-64 md:h-auto min-h-[260px] bg-slate-100 overflow-hidden flex items-center justify-center">
                {/* Skeleton shimmer placeholder while image loads */}
                {!imageLoaded && !imageError && (
                  <div className="absolute inset-0 bg-slate-100 animate-pulse flex flex-col items-center justify-center gap-2 z-1">
                    <div className="h-10 w-10 rounded-xl bg-slate-200 flex items-center justify-center text-slate-400">
                      <Play className="h-5 w-5 fill-slate-400" />
                    </div>
                    <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Loading image...</span>
                  </div>
                )}

                {/* Branded fallback banner if image fails or cannot load */}
                {imageError ? (
                  <div className="absolute inset-0 w-full h-full bg-gradient-to-br from-teal-700 via-emerald-800 to-slate-900 flex flex-col items-center justify-center p-6 text-center text-white">
                    <div className="h-14 w-14 rounded-2xl bg-white/15 backdrop-blur-xs flex items-center justify-center mb-3 shadow-md border border-white/20">
                      {isAssess ? <Trophy className="h-7 w-7 text-amber-300" /> : <Play className="h-7 w-7 text-white fill-white" />}
                    </div>
                    <h3 className="text-sm sm:text-base font-bold text-white max-w-[240px] line-clamp-2 leading-snug">
                      {currentModule.title}
                    </h3>
                    <span className="text-[10px] font-semibold text-emerald-200 uppercase tracking-wider mt-1.5">
                      HexaLearn Onboarding
                    </span>
                  </div>
                ) : (
                  <img
                    key={currentModule.course_id || currentModule.id}
                    src={moduleImageSrc}
                    alt={currentModule.title}
                    loading="eager"
                    onLoad={() => setImageLoaded(true)}
                    onError={() => {
                      const cId = currentModule.course_id || 2;
                      const fallback = `${API_BASE}/moodle/course_image.php?course_id=${cId}`;
                      if (moduleImageSrc !== fallback) {
                        setModuleImageSrc(fallback);
                      } else {
                        setImageError(true);
                      }
                    }}
                    className={`absolute inset-0 w-full h-full object-cover object-center transition-opacity duration-300 ${imageLoaded ? 'opacity-100' : 'opacity-0'
                      }`}
                  />
                )}

                {/* Prerequisite Locked Overlay */}
                {isLocked && (
                  <div className="absolute inset-0 bg-slate-950/75 backdrop-blur-[2px] flex flex-col items-center justify-center text-white p-4 text-center z-10">
                    <div className="h-12 w-12 rounded-2xl bg-amber-500/20 border border-amber-400/40 flex items-center justify-center text-amber-400 mb-2.5 shadow-lg">
                      <Lock className="h-6 w-6" />
                    </div>
                    <span className="text-xs font-bold text-amber-200 uppercase tracking-wider">Prerequisite Locked</span>
                    <span className="text-[11px] text-slate-300 mt-1 max-w-[200px] leading-tight">
                      {currentModule.locked_reason || 'Complete previous module to unlock'}
                    </span>
                  </div>
                )}

                {/* Course Type Pill */}
                <div className="absolute top-4 left-4 flex items-center gap-2 z-20">
                  <span className={`px-2.5 py-1 rounded-full text-[11px] font-black uppercase tracking-wider ${isAssess
                    ? 'bg-amber-500 text-white shadow-sm'
                    : 'bg-[#00A389] text-white shadow-sm'
                    }`}>
                    {isAssess ? 'Assessment' : 'Training Module'}
                  </span>
                </div>
              </div>

              {/* Right: Course Information & Primary CTA */}
              <div className="md:col-span-7 lg:col-span-7 xl:col-span-8 p-6 sm:p-7 flex flex-col justify-between space-y-4">
                <div className="space-y-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-xs font-bold text-slate-400 uppercase tracking-wider">
                      {(() => {
                        const titleClean = (currentModule.title || '').trim().toLowerCase();
                        const subClean = (currentModule.subtitle || '').trim();
                        const fullClean = (currentModule.course_fullname || '').trim();

                        if (subClean && !['single activity', 'general', 'scorm packages'].includes(subClean.toLowerCase()) && subClean.toLowerCase() !== titleClean) {
                          return subClean;
                        }
                        if (fullClean && fullClean.toLowerCase() !== titleClean) {
                          return fullClean;
                        }
                        return 'HexaLearn Onboarding';
                      })()}
                    </span>

                    {/* Status Badge */}
                    <div>
                      {isLocked ? (
                        <span className="text-xs font-bold text-amber-800 bg-amber-50 border border-amber-300 px-3 py-1 rounded-full inline-flex items-center gap-1.5 shadow-2xs">
                          <Lock className="h-3.5 w-3.5 text-amber-600" />
                          Locked
                        </span>
                      ) : isCompleted ? (
                        <span className="text-xs font-bold text-emerald-800 bg-emerald-50 border border-emerald-300 px-3 py-1 rounded-full inline-flex items-center gap-1.5 shadow-2xs">
                          <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                          Completed
                        </span>
                      ) : isFailed ? (
                        <span className="text-xs font-bold text-rose-800 bg-rose-50 border border-rose-300 px-3 py-1 rounded-full inline-flex items-center gap-1.5 shadow-2xs">
                          <XCircle className="h-3.5 w-3.5 text-rose-600" />
                          Failed ({currentModule.score !== null && currentModule.score !== undefined ? `${currentModule.score}%` : '0%'} - Need {currentModule.passing_score ?? 80}%)
                        </span>
                      ) : isInProgress ? (
                        <span className="text-xs font-bold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-3 py-1 rounded-full inline-flex items-center gap-1.5 shadow-2xs">
                          <span className="h-2 w-2 rounded-full bg-[#00A389] animate-pulse" />
                          In Progress ({currentModule.progress_percent}%)
                        </span>
                      ) : (
                        <span className="text-xs font-semibold text-slate-600 bg-slate-100 border border-slate-200 px-3 py-1 rounded-full inline-flex items-center gap-1.5">
                          <Clock className="h-3.5 w-3.5 text-slate-400" />
                          Not Started
                        </span>
                      )}
                    </div>
                  </div>

                  <h1 className="text-xl sm:text-2xl font-bold text-slate-800 leading-tight">
                    {currentModule.title}
                  </h1>

                  <p className="text-xs sm:text-sm text-slate-600 leading-relaxed">
                    {getCourseDescription(currentModule.title, currentModule.subtitle, currentModule.description)}
                  </p>

                  {/* Progress Bar */}
                  <div className="pt-2">
                    <div className="flex items-center justify-between text-xs font-semibold text-slate-500 mb-1.5">
                      <div className="flex items-center gap-2">
                        <span>Course Completion</span>
                        {!isAssess && (isInProgress || isCompleted) && currentModule.total_slides > 0 && (
                          <span className="text-[11px] font-bold text-[#00A389] bg-[#E6F7F4] border border-[#00A389]/30 px-2 py-0.5 rounded-md inline-flex items-center gap-1">
                            {isCompleted
                              ? `Completed (${currentModule.total_slides}/${currentModule.total_slides} Slides)`
                              : `Slide ${currentModule.current_slide} of ${currentModule.total_slides}`
                            }
                          </span>
                        )}
                      </div>
                      <span className="text-slate-800 font-bold">{currentModule.progress_percent}%</span>
                    </div>
                    <div className="h-2.5 w-full bg-slate-100 rounded-full overflow-hidden border border-slate-200/60">
                      <div
                        className={`h-full transition-all duration-500 ${isCompleted
                          ? 'bg-emerald-500'
                          : isFailed
                            ? 'bg-rose-500'
                            : 'bg-[#00A389]'
                          }`}
                        style={{ width: `${currentModule.progress_percent > 0 ? Math.max(4, currentModule.progress_percent) : 0}%` }}
                      />
                    </div>
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="pt-4 border-t border-slate-100 flex flex-wrap items-center gap-3">
                  {isLocked ? (
                    <div className="flex flex-col sm:flex-row sm:items-center gap-3 w-full sm:w-auto">
                      {currentModule.status === 'completed' ? (
                        <div className="inline-flex items-center gap-2 bg-emerald-50 text-emerald-700 border border-emerald-200 px-5 py-2.5 rounded-xl font-bold text-xs">
                          <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                          <span>Completed (Relaunch Disabled)</span>
                        </div>
                      ) : (
                        <Button
                          disabled
                          className="bg-slate-100 text-slate-400 border border-slate-300 px-7 py-2.5 rounded-xl font-bold text-xs shadow-none cursor-not-allowed flex items-center gap-2"
                        >
                          <Lock className="h-4 w-4 text-slate-400" />
                          Course Locked
                        </Button>
                      )}
                      {currentModule.locked_reason && currentModule.status !== 'completed' && (
                        <span className="text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1.5 rounded-lg flex items-center gap-1.5">
                          <Lock className="h-3.5 w-3.5 shrink-0 text-amber-600" />
                          {currentModule.locked_reason}
                        </span>
                      )}
                    </div>
                  ) : isAssess ? (
                    isFailed ? (
                      <>
                        <Button
                          onClick={() => handleRetake(currentModule)}
                          className="bg-[#F59E0B] hover:bg-[#D97706] text-white px-6 py-2.5 rounded-xl font-bold text-xs shadow-xs flex items-center gap-2 cursor-pointer"
                        >
                          <RotateCcw className="h-4 w-4" />
                          Retake Assessment
                        </Button>
                        <Button
                          disabled
                          variant="outline"
                          className="bg-slate-100 border border-slate-200 text-slate-400 px-5 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 cursor-not-allowed"
                        >
                          <Lock className="h-4 w-4" />
                          Locked
                        </Button>
                      </>
                    ) : isCompleted ? (
                      currentModule.admin_unlocked ? (
                        <Button
                          onClick={() => handleLaunchModule(currentModule)}
                          className="bg-[#00A389] hover:bg-[#008f77] text-white px-6 py-2.5 rounded-xl font-bold text-xs shadow-xs flex items-center gap-2 cursor-pointer"
                        >
                          <Play className="h-4 w-4 fill-white" />
                          Relaunch Assessment Now
                        </Button>
                      ) : (
                        <div className="inline-flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-2.5 rounded-xl font-bold text-xs">
                          <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                          <span>Completed (Relaunch Disabled)</span>
                        </div>
                      )
                    ) : (
                      <Button
                        onClick={() => handleLaunchModule(currentModule)}
                        className="bg-[#00A389] hover:bg-[#008f77] text-white px-7 py-2.5 rounded-xl font-bold text-xs shadow-xs flex items-center gap-2 cursor-pointer"
                      >
                        <Play className="h-4 w-4 fill-white" />
                        Start Assessment Now
                      </Button>
                    )
                  ) : isCompleted ? (
                    currentModule.admin_unlocked ? (
                      <Button
                        onClick={() => handleLaunchModule(currentModule)}
                        className="bg-[#00A389] hover:bg-[#008f77] text-white px-7 py-2.5 rounded-xl font-bold text-xs shadow-xs flex items-center gap-2 cursor-pointer"
                      >
                        <Play className="h-4 w-4 fill-white" />
                        Relaunch Course
                      </Button>
                    ) : (
                      <div className="inline-flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-700 px-5 py-2.5 rounded-xl font-bold text-xs">
                        <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                        <span>Completed (Relaunch Disabled)</span>
                      </div>
                    )
                  ) : (
                    <Button
                      onClick={() => handleLaunchModule(currentModule)}
                      className="bg-[#00A389] hover:bg-[#008f77] text-white px-7 py-2.5 rounded-xl font-bold text-xs shadow-xs flex items-center gap-2 cursor-pointer"
                    >
                      <Play className="h-4 w-4 fill-white" />
                      {isInProgress ? 'Continue Learning' : 'Launch Course'}
                    </Button>
                  )}

                  <div className="text-[11px] text-slate-400 font-medium ml-auto flex items-center gap-1.5">
                    <Shield className="h-3.5 w-3.5 text-[#00A389]" />
                    <span>Authenticated via CorHis Moodle SSO</span>
                  </div>
                </div>

              </div>
            </div>
          </div>

          {/* Right: 3 Metric Cards Stacked Vertically (30% width) */}
          <div className="lg:col-span-4 flex flex-col justify-between gap-3.5">

            {/* 1. First Started Date & Time */}
            <div className="bg-white rounded-2xl border border-slate-200/90 p-4 sm:p-5 shadow-2xs flex-1 flex flex-col justify-center gap-1 hover:border-slate-300 transition-colors">
              <div className="flex items-center gap-2 text-slate-400">
                <Calendar className="h-4 w-4 text-[#00A389]" />
                <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">1st Started At</span>
              </div>
              <div className="text-sm sm:text-base font-semibold text-slate-700">
                {formattedStartedAt ? formattedStartedAt : (
                  <span className="text-slate-400 text-xs font-medium">Not started yet</span>
                )}
              </div>
              <div className="text-[10px] sm:text-[11px] text-slate-400 font-medium">
                Date & time you first opened this module
              </div>
            </div>

            {/* 2. Last Activity */}
            <div className="bg-white rounded-2xl border border-slate-200/90 p-4 sm:p-5 shadow-2xs flex-1 flex flex-col justify-center gap-1 hover:border-slate-300 transition-colors">
              <div className="flex items-center gap-2 text-slate-400">
                <History className="h-4 w-4 text-blue-500" />
                <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">Last Activity</span>
              </div>
              <div className="text-sm sm:text-base font-semibold text-slate-700">
                {formattedLastPlayed ? formattedLastPlayed : (
                  <span className="text-slate-400 text-xs font-medium">No session recorded</span>
                )}
              </div>
              <div className="text-[10px] sm:text-[11px] text-slate-400 font-medium">
                Most recent learning session recorded
              </div>
            </div>

            {/* 3. Total Time Spent in Course */}
            <div className="bg-white rounded-2xl border border-slate-200/90 p-4 sm:p-5 shadow-2xs flex-1 flex flex-col justify-center gap-1 hover:border-slate-300 transition-colors">
              <div className="flex items-center gap-2 text-slate-400">
                <Clock className="h-4 w-4 text-amber-500" />
                <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Time Spent</span>
              </div>
              <div className="text-sm sm:text-base font-semibold text-slate-700">
                {formattedTimeSpent !== '0s' ? (
                  <span>{formattedTimeSpent}</span>
                ) : (
                  <span className="text-slate-400 text-xs font-medium">0 min 0 sec</span>
                )}
              </div>
              <div className="text-[10px] sm:text-[11px] text-slate-400 font-medium">
                Active learning time
              </div>
            </div>

          </div>

        </div>

        {/* LEARNING RECORDS SECTION (Sleek Modern UI Matching User Design with No Repeated Dates) */}
        <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs p-5 sm:p-6">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
            <h3 className="text-base sm:text-lg font-bold text-slate-900 tracking-tight">
              Learning Records
            </h3>

            {currentModule.attempts_history && currentModule.attempts_history.length > 0 && (
              <div className="flex items-center bg-slate-100 p-1 rounded-xl">
                <button
                  type="button"
                  onClick={() => setActiveTab('telemetry')}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 ${activeTab === 'telemetry'
                    ? 'bg-white text-slate-900 shadow-2xs'
                    : 'text-slate-500 hover:text-slate-800'
                    }`}
                >
                  <Activity className="h-3.5 w-3.5 text-[#00A389]" />
                  <span>Overview</span>
                </button>
                <button
                  type="button"
                  onClick={() => setActiveTab('history')}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 ${activeTab === 'history'
                    ? 'bg-white text-slate-900 shadow-2xs'
                    : 'text-slate-500 hover:text-slate-800'
                    }`}
                >
                  <History className="h-3.5 w-3.5 text-blue-500" />
                  <span>Attempt History ({currentModule.attempts_history.length})</span>
                </button>
              </div>
            )}
          </div>

          {activeTab === 'telemetry' && (
            <div className="border border-slate-200/80 rounded-2xl divide-y divide-slate-100 bg-white overflow-hidden">
              {/* Row 1: Completion Status */}
              <div className="py-4 px-5 sm:px-6 flex items-center justify-between gap-4">
                <div className="flex items-center gap-3.5 min-w-0">
                  <div className="h-10 w-10 rounded-full bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600 shrink-0">
                    <CheckCircle2 className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <h4 className="text-sm font-bold text-slate-900 leading-snug">
                      Completion Status
                    </h4>
                    <p className="text-xs text-slate-400 font-medium mt-0.5">
                      Official status of your progress
                    </p>
                  </div>
                </div>

                <div className="shrink-0">
                  {isCompleted ? (
                    <span className="px-4 py-1.5 rounded-full text-xs font-bold bg-[#E8F8F0] text-[#00A389] border border-[#00A389]/20 shadow-2xs inline-block">
                      Completed
                    </span>
                  ) : isFailed ? (
                    <span className="px-4 py-1.5 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs inline-block">
                      Needs Retake ({currentModule.score !== null ? `${currentModule.score}%` : '0%'})
                    </span>
                  ) : isInProgress ? (
                    <span className="px-4 py-1.5 rounded-full text-xs font-bold bg-[#E6F7F4] text-[#00A389] border border-[#00A389]/30 shadow-2xs inline-flex items-center gap-1.5">
                      <span className="h-2 w-2 rounded-full bg-[#00A389] animate-pulse" />
                      In Progress
                    </span>
                  ) : (
                    <span className="px-4 py-1.5 rounded-full text-xs font-medium text-slate-600 bg-slate-100 border border-slate-200 shadow-2xs inline-block">
                      Not Started
                    </span>
                  )}
                </div>
              </div>

              {/* Row 2: Slide Progress (for SCORM) or Assessment Score (for Quizzes) */}
              {!isAssess ? (
                <div
                  onClick={() => (!isLocked && !isCompleted) && handleLaunchModule(currentModule)}
                  className={`py-4 px-5 sm:px-6 flex items-center justify-between gap-4 transition-colors ${(!isLocked && !isCompleted) ? 'hover:bg-slate-50/70 cursor-pointer' : ''
                    }`}
                >
                  <div className="flex items-center gap-3.5 min-w-0">
                    <div className="h-10 w-10 rounded-full bg-sky-50 border border-sky-100 flex items-center justify-center text-sky-500 shrink-0">
                      <FileText className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <h4 className="text-sm font-bold text-slate-900 leading-snug">
                        Slide Progress
                      </h4>
                      <p className="text-xs text-slate-400 font-medium mt-0.5">
                        Current slide out of total slides
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 shrink-0">
                    <span className="text-sm font-bold text-slate-900">
                      {isCompleted && currentModule.total_slides > 0
                        ? `${currentModule.total_slides} / ${currentModule.total_slides}`
                        : currentModule.total_slides > 0
                          ? `${currentModule.current_slide || 0} / ${currentModule.total_slides}`
                          : '18 / 18'}
                    </span>
                    <ChevronRight className="h-4 w-4 text-slate-400" />
                  </div>
                </div>
              ) : (
                <div
                  onClick={() => (!isLocked && !isCompleted) && handleLaunchModule(currentModule)}
                  className={`py-4 px-5 sm:px-6 flex items-center justify-between gap-4 transition-colors ${(!isLocked && !isCompleted) ? 'hover:bg-slate-50/70 cursor-pointer' : ''
                    }`}
                >
                  <div className="flex items-center gap-3.5 min-w-0">
                    <div className="h-10 w-10 rounded-full bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-500 shrink-0">
                      <Trophy className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <h4 className="text-sm font-bold text-slate-900 leading-snug">
                        Assessment Score
                      </h4>
                      <p className="text-xs text-slate-400 font-medium mt-0.5">
                        Passing requirement: {currentModule.passing_score ?? 80}% minimum
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 shrink-0">
                    <span className={`text-sm font-bold ${currentModule.score !== null && Number(currentModule.score) >= Number(currentModule.passing_score ?? 80)
                      ? 'text-emerald-600'
                      : currentModule.score !== null
                        ? 'text-rose-600'
                        : 'text-slate-700'
                      }`}>
                      {currentModule.score !== null && currentModule.score !== undefined
                        ? `${currentModule.score}%`
                        : 'Not attempted'}
                    </span>
                    <ChevronRight className="h-4 w-4 text-slate-400" />
                  </div>
                </div>
              )}

              {/* Row 3: Session Span */}
              <div className="py-4 px-5 sm:px-6 flex items-center justify-between gap-4">
                <div className="flex items-center gap-3.5 min-w-0">
                  <div className="h-10 w-10 rounded-full bg-purple-50 border border-purple-100 flex items-center justify-center text-purple-600 shrink-0">
                    <Timer className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <h4 className="text-sm font-bold text-slate-900 leading-snug">
                      Session Span
                    </h4>
                    <p className="text-xs text-slate-400 font-medium mt-0.5">
                      Time between first launch and last activity
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                  <span className="text-sm font-bold text-slate-900">
                    {compactSessionSpan}
                  </span>
                  <ChevronRight className="h-4 w-4 text-slate-400" />
                </div>
              </div>
            </div>
          )}

          {/* TAB: ATTEMPT HISTORY (if active) */}
          {activeTab === 'history' && currentModule.attempts_history && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-extrabold text-slate-800">Learner Attempt Log</h3>
                <span className="text-xs text-slate-500">Total Attempts: {currentModule.attempts_history.length}</span>
              </div>

              <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase text-[10px]">
                    <tr>
                      <th className="py-2.5 px-4">Attempt #</th>
                      <th className="py-2.5 px-4">Date & Time</th>
                      <th className="py-2.5 px-4">Duration</th>
                      <th className="py-2.5 px-4">Score</th>
                      <th className="py-2.5 px-4">Status / Outcome</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 text-slate-700">
                    {currentModule.attempts_history.map((att: any, idx: number) => {
                      const attPassed = att.is_passed || att.result === 'Passed';
                      const attFailed = att.is_failed || att.result === 'Failed';
                      return (
                        <tr key={idx} className="hover:bg-slate-50/80">
                          <td className="py-2.5 px-4 font-bold">Attempt {att.attempt || (idx + 1)}</td>
                          <td className="py-2.5 px-4 text-slate-600">{att.date || formatDateTime(att.timemodified) || 'Recorded'}</td>
                          <td className="py-2.5 px-4 text-slate-600">{formatDuration(att.total_time) || att.formattedDuration || '0s'}</td>
                          <td className="py-2.5 px-4 font-bold text-slate-800">
                            {att.score !== null && att.score !== undefined ? `${att.score}%` : 'N/A'}
                          </td>
                          <td className="py-2.5 px-4">
                            {attPassed ? (
                              <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md">
                                <CheckCircle2 className="h-3 w-3" />
                                Passed
                              </span>
                            ) : attFailed ? (
                              <span className="inline-flex items-center gap-1 text-[11px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-md">
                                <XCircle className="h-3 w-3" />
                                Failed
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md">
                                <Clock className="h-3 w-3" />
                                {att.result || 'Incomplete'}
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
        </div>

      </div>

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
        onCloseSession={() => {
          closeActiveSession();
          navigate('/modules');
        }}
      />
    </Layout>
  );
};

export default CourseDetailPage;
