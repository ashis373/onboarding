import { useEffect, useState, useRef, useMemo } from "react";
import { useSearchParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { apiPost, API_BASE } from "@/lib/api";
import { X, Maximize2, Minimize2, GraduationCap, RotateCw, ShieldCheck, ArrowLeft, Wrench } from "lucide-react";

const IS_MAINTENANCE_MODE = false;

export const ScormPlayerPage = () => {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const cmidParam = searchParams.get("cmid");
  const courseidParam = searchParams.get("courseid");
  const moduleIdParam = searchParams.get("module_id");
  const rawTitle = searchParams.get("title");
  const modeParam = searchParams.get("mode");
  const newattemptParam = searchParams.get("newattempt");
  const scoidParam = searchParams.get("scoid");
  const currentorgParam = searchParams.get("currentorg");
  const directUrlParam = searchParams.get("url");

  const courseTitle = rawTitle ? decodeURIComponent(rawTitle) : "Onboarding Training Module";

  const [isFullscreen, setIsFullscreen] = useState(false);
  const [iframeKey, setIframeKey] = useState(0);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);

  // Set document title
  useEffect(() => {
    document.title = `${courseTitle} | HexaLearn Interactive Learning`;
  }, [courseTitle]);

  // Handle Fullscreen events
  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(Boolean(document.fullscreenElement));
    };
    document.addEventListener("fullscreenchange", handleFullscreenChange);
    return () => document.removeEventListener("fullscreenchange", handleFullscreenChange);
  }, []);

  // Notify parent dashboard / other tabs when this player window is opened, closed or navigated away
  useEffect(() => {
    const openedAt = Date.now();
    const activeSession = {
      active: true,
      title: courseTitle,
      cmid: cmidParam,
      courseid: courseidParam,
      moduleId: moduleIdParam,
      openedAt,
    };

    try {
      localStorage.removeItem("hexalearn_scorm_force_close");
      localStorage.setItem("hexalearn_active_course_session", JSON.stringify(activeSession));
      if (typeof BroadcastChannel !== 'undefined') {
        const bc = new BroadcastChannel('hexalearn_scorm_channel');
        bc.postMessage({ type: 'COURSE_LAUNCHED', session: activeSession });
        bc.close();
      }
    } catch { }

    const notifyParent = () => {
      try {
        localStorage.removeItem("hexalearn_active_course_session");
        const ts = Date.now().toString();
        localStorage.setItem("hexalearn_scorm_last_closed", ts);
        if (courseidParam) {
          localStorage.setItem("hexalearn_last_closed_course_id", String(courseidParam));
          sessionStorage.setItem("hexalearn_last_closed_course_id", String(courseidParam));
        }
        sessionStorage.setItem("hexalearn_scorm_needs_sync", ts);
        if (typeof BroadcastChannel !== 'undefined') {
          const bc = new BroadcastChannel('hexalearn_scorm_channel');
          bc.postMessage({ type: 'COURSE_CLOSED', timestamp: ts, courseid: courseidParam });
          bc.close();
        }
      } catch { }
    };

    const closePlayerWindow = () => {
      notifyParent();
      try {
        window.close();
      } catch { }
    };

    let channel: BroadcastChannel | null = null;
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        channel = new BroadcastChannel('hexalearn_scorm_channel');
        channel.onmessage = (e) => {
          if (e.data?.type === 'FOCUS_PLAYER') {
            try {
              window.focus();
            } catch { }
          } else if (e.data?.type === 'CLOSE_PLAYER') {
            closePlayerWindow();
          }
        };
      }
    } catch { }

    const handleForceCloseStorage = (e: StorageEvent) => {
      if (e.key === 'hexalearn_scorm_force_close' && e.newValue && Number(e.newValue) >= openedAt) {
        closePlayerWindow();
      }
    };
    window.addEventListener("storage", handleForceCloseStorage);

    window.addEventListener("beforeunload", notifyParent);
    window.addEventListener("pagehide", notifyParent);
    return () => {
      if (channel) {
        try { channel.close(); } catch { }
      }
      notifyParent();
      window.removeEventListener("storage", handleForceCloseStorage);
      window.removeEventListener("beforeunload", notifyParent);
      window.removeEventListener("pagehide", notifyParent);
    };
  }, [courseTitle, cmidParam, courseidParam, moduleIdParam]);

  // Compute authenticated Moodle launch URL
  const launchUrl = useMemo(() => {
    if (directUrlParam) {
      try {
        return decodeURIComponent(directUrlParam);
      } catch {
        return directUrlParam;
      }
    }

    if (cmidParam || courseidParam || moduleIdParam || scoidParam) {
      const p = new URLSearchParams();
      if (cmidParam) p.set("cmid", cmidParam);
      if (courseidParam) p.set("courseid", courseidParam);
      if (moduleIdParam) p.set("module_id", moduleIdParam);
      if (rawTitle) p.set("title", rawTitle);
      if (modeParam) p.set("mode", modeParam);
      if (newattemptParam) p.set("newattempt", newattemptParam);
      if (scoidParam) p.set("scoid", scoidParam);
      if (currentorgParam) p.set("currentorg", currentorgParam);
      return `${API_BASE}/moodle/launch.php?${p.toString()}`;
    }

    return "${API_BASE}/moodle/launch.php";
  }, [cmidParam, courseidParam, moduleIdParam, rawTitle, modeParam, newattemptParam, scoidParam, currentorgParam, directUrlParam]);

  const toggleFullscreen = () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch(() => { });
      setIsFullscreen(true);
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen().catch(() => { });
      }
      setIsFullscreen(false);
    }
  };

  // Refresh ONLY the course iframe with a 2s cooldown to prevent rapid-click Moodle floods
  const handleRefreshIframe = () => {
    if (isRefreshing) return;
    setIsRefreshing(true);
    setIframeKey((prev) => prev + 1);
    apiPost("/moodle/log_error.php", {
      error_type: "player_retry",
      course_id: courseidParam ? Number(courseidParam) : null,
      cmid: cmidParam ? Number(cmidParam) : null,
      course_title: courseTitle,
      message: "Employee clicked Refresh inside course player window",
    }).catch(() => {});
    setTimeout(() => {
      setIsRefreshing(false);
    }, 2000);
  };

  // Handle Close tab or return to portal
  const handleClose = () => {
    try {
      localStorage.removeItem("hexalearn_active_course_session");
      const ts = Date.now().toString();
      localStorage.setItem("hexalearn_scorm_last_closed", ts);
      sessionStorage.setItem("hexalearn_scorm_needs_sync", ts);
      if (typeof BroadcastChannel !== 'undefined') {
        const bc = new BroadcastChannel('hexalearn_scorm_channel');
        bc.postMessage({ type: 'COURSE_CLOSED', timestamp: ts });
        bc.close();
      }
    } catch { }

    // First attempt to close the window
    window.close();

    // If browser prevents closing (e.g. if opened by direct URL), navigate to modules
    setTimeout(() => {
      navigate("/modules");
    }, 150);
  };

  if (IS_MAINTENANCE_MODE) {
    return (
      <div className="fixed inset-0 z-50 bg-slate-950 flex flex-col items-center justify-center p-6 text-center select-none">
        <div className="max-w-md w-full bg-slate-900 border border-slate-800 rounded-3xl p-8 sm:p-10 shadow-2xl flex flex-col items-center">
          <div className="h-16 w-16 rounded-2xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center text-amber-500 mb-6">
            <Wrench className="h-8 w-8 animate-pulse" />
          </div>
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30 mb-4 uppercase tracking-wider">
            System Maintenance
          </span>
          <h1 className="text-xl sm:text-2xl font-black text-white tracking-tight mb-3">
            Course Player Unavailable
          </h1>
          <p className="text-sm text-slate-400 leading-relaxed mb-8">
            The CorHis Onboard learning system is currently undergoing scheduled platform maintenance. Course playback is temporarily suspended to ensure learning data consistency. All your previous progress is safely saved.
          </p>
          <Button
            onClick={() => navigate("/dashboard")}
            className="w-full h-11 rounded-xl bg-[#00A389] hover:bg-[#008f78] text-white font-bold text-sm shadow-md cursor-pointer transition-all flex items-center justify-center gap-2"
          >
            <ArrowLeft className="h-4 w-4" />
            <span>Return to Dashboard</span>
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-50 bg-slate-950 flex flex-col w-screen h-screen overflow-hidden select-none">
      {/* Top Navigation Bar - Clean, branded, keeps Moodle URL 100% hidden from address bar */}
      <div className="h-14 bg-slate-900 border-b border-slate-800 px-4 sm:px-6 flex items-center justify-between shrink-0 shadow-md">
        <div className="flex items-center gap-3 min-w-0">
          <div className="h-8 w-8 rounded-lg bg-[#00A389]/15 border border-[#00A389]/30 flex items-center justify-center shrink-0">
            <GraduationCap className="h-4 w-4 text-[#00A389]" />
          </div>
          <div className="min-w-0 flex items-center gap-2.5">
            <h1 className="text-sm font-bold text-white truncate max-w-xs sm:max-w-md md:max-w-lg lg:max-w-xl">
              {courseTitle}
            </h1>

            {modeParam === "review" && (
              <span className="hidden sm:inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-900/60 text-blue-300 border border-blue-700/60">
                Review Mode
              </span>
            )}
            {newattemptParam && (
              <span className="hidden sm:inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-900/60 text-amber-300 border border-amber-700/60">
                Retake
              </span>
            )}

            <p className="text-[11px] text-slate-400 hidden xl:block border-l border-slate-800 pl-2.5 ml-1">
              HexaLearn Interactive Learning System • Progress is recorded automatically
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Refresh Course (Iframe Only) Button */}
          <Button
            size="sm"
            variant="outline"
            disabled={isRefreshing}
            onClick={handleRefreshIframe}
            className="h-8 px-3 rounded-xl border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-all shadow-xs disabled:opacity-60 disabled:cursor-not-allowed"
            title="Refresh Course (Refreshes only this module without reloading the page)"
          >
            <RotateCw className={`h-3.5 w-3.5 text-[#00A389] ${isRefreshing ? "animate-spin" : ""}`} />
            <span className="hidden sm:inline">Refresh</span>
          </Button>

          {/* Fullscreen Toggle */}
          <Button
            size="sm"
            variant="outline"
            onClick={toggleFullscreen}
            className="h-8 px-2.5 rounded-xl border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs hidden sm:flex items-center gap-1 cursor-pointer"
            title={isFullscreen ? "Exit Fullscreen" : "Enter Fullscreen"}
          >
            {isFullscreen ? <Minimize2 className="h-3.5 w-3.5" /> : <Maximize2 className="h-3.5 w-3.5" />}
          </Button>

          {/* Close & Return to Portal Button */}
          <Button
            size="sm"
            onClick={handleClose}
            className="h-8 px-4 rounded-xl bg-[#00A389] hover:bg-[#008f78] text-white text-xs font-bold gap-1.5 shadow-sm cursor-pointer transition-all"
            title="Close course and update dashboard"
          >
            <X className="h-3.5 w-3.5" />
            <span>Close Course</span>
          </Button>
        </div>
      </div>

      {/* Direct Clean SCORM Player Viewport - Zero loading obstruction, zero stuck overlays */}
      <div className="flex-1 w-full h-[calc(100vh-3.5rem)] relative bg-black overflow-hidden">
        <iframe
          key={iframeKey}
          ref={iframeRef}
          src={launchUrl}
          title={courseTitle}
          className="w-full h-full border-0 block"
          allow="autoplay; fullscreen; encrypted-media; camera; microphone; clipboard-write"
        />
      </div>
    </div>
  );
};

export default ScormPlayerPage;
