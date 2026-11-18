import { useEffect, useState, useRef, useCallback } from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { ExternalLink, ArrowLeft, GraduationCap, Sparkles } from "lucide-react";

export interface ActiveCourseSession {
  active: boolean;
  title: string;
  cmid?: string | number;
  courseid?: string | number;
  moduleId?: string | number;
  openedAt: number;
}

export interface UseActiveCourseModalProps {
  onCourseClosed?: (courseId?: string | number | null) => void;
}

/**
 * Multi-tab coordinated hook for managing dedicated SCORM player window sessions.
 * Coordinates across all open browser tabs via BroadcastChannel and localStorage.
 */
export function useActiveCourseSession({ onCourseClosed }: UseActiveCourseModalProps = {}) {
  const [activeSession, setActiveSession] = useState<ActiveCourseSession | null>(null);
  const activeSessionRef = useRef<ActiveCourseSession | null>(null);
  const courseWinRef = useRef<Window | null>(null);
  const pollingTimerRef = useRef<NodeJS.Timeout | null>(null);
  const onCourseClosedRef = useRef(onCourseClosed);

  useEffect(() => {
    activeSessionRef.current = activeSession;
  }, [activeSession]);

  useEffect(() => {
    onCourseClosedRef.current = onCourseClosed;
  }, [onCourseClosed]);

  // Helper to clear session & notify all open tabs
  const notifyClosed = useCallback(() => {
    let closedCourseId: string | number | null = activeSessionRef.current?.courseid ?? null;
    try {
      if (!closedCourseId) {
        const storedRaw = localStorage.getItem("hexalearn_active_course_session");
        if (storedRaw) {
          closedCourseId = JSON.parse(storedRaw)?.courseid ?? null;
        }
      }
      localStorage.removeItem("hexalearn_active_course_session");
      const ts = Date.now().toString();
      localStorage.setItem("hexalearn_scorm_last_closed", ts);
      if (closedCourseId) {
        localStorage.setItem("hexalearn_last_closed_course_id", String(closedCourseId));
        sessionStorage.setItem("hexalearn_last_closed_course_id", String(closedCourseId));
      }
      sessionStorage.setItem("hexalearn_scorm_needs_sync", ts);
      if (typeof BroadcastChannel !== "undefined") {
        const bc = new BroadcastChannel("hexalearn_scorm_channel");
        bc.postMessage({ type: "COURSE_CLOSED", timestamp: ts, courseid: closedCourseId });
        bc.close();
      }
    } catch {}

    setActiveSession(null);
    if (pollingTimerRef.current) {
      clearInterval(pollingTimerRef.current);
      pollingTimerRef.current = null;
    }
    courseWinRef.current = null;
    onCourseClosedRef.current?.(closedCourseId);
  }, []);

  // Check initial state on mount: discovers active session from other tabs or reloads
  useEffect(() => {
    try {
      const stored = localStorage.getItem("hexalearn_active_course_session");
      if (stored) {
        const parsed = JSON.parse(stored) as ActiveCourseSession;
        // Verify session is active and opened within last 12 hours (discard stale)
        if (parsed && parsed.active && Date.now() - (parsed.openedAt || 0) < 12 * 60 * 60 * 1000) {
          setActiveSession(parsed);
        } else {
          localStorage.removeItem("hexalearn_active_course_session");
        }
      }
    } catch {}
  }, []);

  // Listen to cross-tab events: BroadcastChannel & storage events
  useEffect(() => {
    let bc: BroadcastChannel | null = null;
    try {
      if (typeof BroadcastChannel !== "undefined") {
        bc = new BroadcastChannel("hexalearn_scorm_channel");
        bc.onmessage = (e) => {
          if (e.data?.type === "COURSE_LAUNCHED" && e.data?.session) {
            setActiveSession(e.data.session);
          } else if (e.data?.type === "COURSE_CLOSED") {
            setActiveSession(null);
            if (pollingTimerRef.current) {
              clearInterval(pollingTimerRef.current);
              pollingTimerRef.current = null;
            }
            courseWinRef.current = null;
            onCourseClosedRef.current?.();
          }
        };
      }
    } catch {}

    const handleStorage = (e: StorageEvent) => {
      if (e.key === "hexalearn_active_course_session") {
        if (e.newValue) {
          try {
            const parsed = JSON.parse(e.newValue);
            if (parsed && parsed.active) {
              setActiveSession(parsed);
            }
          } catch {}
        } else {
          setActiveSession(null);
          if (pollingTimerRef.current) {
            clearInterval(pollingTimerRef.current);
            pollingTimerRef.current = null;
          }
          courseWinRef.current = null;
          onCourseClosedRef.current?.();
        }
      } else if (e.key === "hexalearn_scorm_last_closed") {
        setActiveSession(null);
        if (pollingTimerRef.current) {
          clearInterval(pollingTimerRef.current);
          pollingTimerRef.current = null;
        }
        courseWinRef.current = null;
        onCourseClosedRef.current?.();
      }
    };

    window.addEventListener("storage", handleStorage);

    return () => {
      if (bc) {
        try { bc.close(); } catch {}
      }
      window.removeEventListener("storage", handleStorage);
      if (pollingTimerRef.current) {
        clearInterval(pollingTimerRef.current);
        pollingTimerRef.current = null;
      }
    };
  }, []);

  // Launch course in a dedicated new window (not tab)
  const launchCourseWindow = useCallback((playUrl: string, title: string, options?: any) => {
    const screenW = window.screen.availWidth || 1280;
    const screenH = window.screen.availHeight || 800;
    const width = Math.min(Math.floor(screenW * 0.95), 1440);
    const height = Math.min(Math.floor(screenH * 0.92), 900);
    const left = Math.max(0, Math.floor((screenW - width) / 2));
    const top = Math.max(0, Math.floor((screenH - height) / 2));
    const features = `width=${width},height=${height},top=${top},left=${left},menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=yes`;

    const session: ActiveCourseSession = {
      active: true,
      title: title || "Onboarding Training Module",
      cmid: options?.cmid,
      courseid: options?.courseid,
      moduleId: options?.module_id,
      openedAt: Date.now(),
    };

    // Broadcast launch to all tabs immediately
    try {
      localStorage.removeItem("hexalearn_scorm_force_close");
      localStorage.setItem("hexalearn_active_course_session", JSON.stringify(session));
      if (typeof BroadcastChannel !== "undefined") {
        const bc = new BroadcastChannel("hexalearn_scorm_channel");
        bc.postMessage({ type: "COURSE_LAUNCHED", session });
        bc.close();
      }
    } catch {}

    setActiveSession(session);

    // Open dedicated window
    const courseWin = window.open(playUrl, "HexaLearnScormPlayer", features);
    courseWinRef.current = courseWin;

    if (courseWin) {
      try {
        courseWin.focus();
      } catch {}

      if (pollingTimerRef.current) {
        clearInterval(pollingTimerRef.current);
      }

      // Check window closure every 500ms
      pollingTimerRef.current = setInterval(() => {
        try {
          if (courseWin.closed) {
            notifyClosed();
          }
        } catch {
          notifyClosed();
        }
      }, 500);
    }

    return courseWin;
  }, [notifyClosed]);

  // Bring course window to front
  const focusCourseWindow = useCallback(() => {
    try {
      if (courseWinRef.current && !courseWinRef.current.closed) {
        courseWinRef.current.focus();
        return;
      }
      // Target existing named window
      const win = window.open("", "HexaLearnScormPlayer");
      if (win) {
        win.focus();
      }
      if (typeof BroadcastChannel !== "undefined") {
        const bc = new BroadcastChannel("hexalearn_scorm_channel");
        bc.postMessage({ type: "FOCUS_PLAYER" });
        bc.close();
      }
    } catch {}
  }, []);

  // Explicitly close the course player window (both directly and across tabs) and dismiss modal
  const closeActiveSession = useCallback(() => {
    try {
      if (courseWinRef.current && !courseWinRef.current.closed) {
        courseWinRef.current.close();
      }
    } catch {}

    try {
      const ts = Date.now().toString();
      localStorage.setItem("hexalearn_scorm_force_close", ts);
      if (typeof BroadcastChannel !== "undefined") {
        const bc = new BroadcastChannel("hexalearn_scorm_channel");
        bc.postMessage({ type: "CLOSE_PLAYER", timestamp: ts });
        bc.close();
      }
    } catch {}

    notifyClosed();
  }, [notifyClosed]);

  return {
    activeSession,
    launchCourseWindow,
    focusCourseWindow,
    closeActiveSession,
    courseWinRef,
  };
}

export interface ActiveCourseModalProps {
  activeSession: ActiveCourseSession | null;
  onFocus: () => void;
  onCloseSession: () => void;
}

/**
 * Modal shown on the main window / all open tabs while a course is actively running in a dedicated window.
 * Displays course title, status badge, focus button, and dismiss/back button.
 */
export const ActiveCourseModal = ({
  activeSession,
  onFocus,
  onCloseSession,
}: ActiveCourseModalProps) => {
  if (!activeSession) return null;

  return (
    <Dialog
      open={!!activeSession}
      onOpenChange={(open) => {
        if (!open) {
          onCloseSession();
        }
      }}
    >
      <DialogContent
        className="sm:max-w-md p-6 sm:p-7 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl text-center select-none"
        onPointerDownOutside={(e) => e.preventDefault()}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Course Active in New Window</DialogTitle>
        </DialogHeader>

        <div className="flex flex-col items-center pt-2">
          {/* Animated Icon with Live Pulse Indicator */}
          <div className="relative mb-5">
            <div className="h-16 w-16 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800/60 flex items-center justify-center text-[#00A389] shadow-inner">
              <GraduationCap className="h-8 w-8 animate-pulse" />
            </div>
            <span className="absolute -top-1 -right-1 flex h-4 w-4">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex rounded-full h-4 w-4 bg-emerald-500 border-2 border-white dark:border-slate-900" />
            </span>
          </div>

          {/* Status Badge */}
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 mb-3">
            <span className="h-2 w-2 rounded-full bg-emerald-500 animate-ping" />
            Active Learning Session in Separate Window
          </span>

          {/* Course Title Card */}
          <div className="w-full bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/60 rounded-xl p-3.5 mb-4">
            <h3 className="text-base font-bold text-slate-900 dark:text-white tracking-tight line-clamp-2">
              {activeSession.title || "Onboarding Training Module"}
            </h3>
          </div>

          {/* Description */}
          <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed mb-6">
            Your course is currently running in a separate, dedicated window. Please complete your slides and activities there.
            <span className="block mt-2 text-xs text-slate-400 dark:text-slate-500 font-medium">
              When you close the player window, this screen will automatically update and return you to your course list.
            </span>
          </p>

          {/* Action Buttons */}
          <div className="w-full flex flex-col gap-2.5">
            <Button
              onClick={onFocus}
              className="w-full h-11 rounded-xl bg-[#00A389] hover:bg-[#008f78] text-white font-semibold text-sm shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer"
            >
              <ExternalLink className="h-4 w-4" />
              <span>Back to Course Window</span>
            </Button>

            <Button
              variant="outline"
              onClick={onCloseSession}
              className="w-full h-10 rounded-xl border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-medium cursor-pointer flex items-center justify-center gap-1.5"
            >
              <ArrowLeft className="h-3.5 w-3.5" />
              <span>Return to Dashboard / Dismiss</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};

