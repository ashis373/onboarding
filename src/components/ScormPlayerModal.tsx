import { useEffect, useState, useRef } from "react";
import { Button } from "@/components/ui/button";
import { X, Maximize2, Minimize2, GraduationCap, RotateCw } from "lucide-react";

interface ScormPlayerModalProps {
  isOpen: boolean;
  onClose: () => void;
  launchUrl: string | null;
  courseTitle: string;
}

export const ScormPlayerModal = ({
  isOpen,
  onClose,
  launchUrl,
  courseTitle,
}: ScormPlayerModalProps) => {
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [iframeKey, setIframeKey] = useState(0);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);

  // Lock body scroll and handle keyboard Escape key to close
  useEffect(() => {
    if (isOpen) {
      const originalOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";

      const handleKeyDown = (e: KeyboardEvent) => {
        if (e.key === "Escape") {
          onClose();
        }
      };
      window.addEventListener("keydown", handleKeyDown);

      return () => {
        document.body.style.overflow = originalOverflow;
        window.removeEventListener("keydown", handleKeyDown);
      };
    }
  }, [isOpen, onClose]);

  const toggleFullscreen = () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch(() => {});
      setIsFullscreen(true);
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
      }
      setIsFullscreen(false);
    }
  };

  // Refresh ONLY the course iframe without reloading the portal page
  const handleRefreshIframe = () => {
    setIsRefreshing(true);
    setIframeKey((prev) => prev + 1);
    setTimeout(() => {
      setIsRefreshing(false);
    }, 600);
  };

  if (!isOpen || !launchUrl) return null;

  return (
    <div
      className="fixed inset-0 z-50 bg-slate-950 flex flex-col w-screen h-screen animate-in fade-in duration-150 select-none"
      role="dialog"
      aria-modal="true"
    >
      {/* Top Navigation Bar - Keeps Moodle URL 100% hidden */}
      <div className="h-14 bg-slate-900 border-b border-slate-800 px-4 sm:px-6 flex items-center justify-between shrink-0 shadow-md">
        <div className="flex items-center gap-3 min-w-0">
          <div className="h-8 w-8 rounded-lg bg-[#00A389]/15 border border-[#00A389]/30 flex items-center justify-center shrink-0">
            <GraduationCap className="h-4 w-4 text-[#00A389]" />
          </div>
          <div className="min-w-0">
            <h2 className="text-sm font-bold text-white truncate max-w-xs sm:max-w-md md:max-w-xl">
              {courseTitle || "Onboarding Training Module"}
            </h2>
            <p className="text-[11px] text-slate-400 hidden sm:block">
              HexaLearn Interactive Learning System • Progress is recorded automatically
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Refresh Course (Iframe Only) Button */}
          <Button
            size="sm"
            variant="outline"
            onClick={handleRefreshIframe}
            className="h-8 px-3 rounded-xl border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white text-xs font-semibold flex items-center gap-1.5 cursor-pointer transition-all shadow-xs"
            title="Refresh Course (Refreshes only this course without reloading the page)"
          >
            <RotateCw className={`h-3.5 w-3.5 text-[#00A389] ${isRefreshing ? "animate-spin" : ""}`} />
            <span>Refresh</span>
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
            onClick={onClose}
            className="h-8 px-4 rounded-xl bg-[#00A389] hover:bg-[#008f78] text-white text-xs font-bold gap-1.5 shadow-sm cursor-pointer transition-all"
            title="Close course and update dashboard"
          >
            <X className="h-3.5 w-3.5" />
            <span>Close Course</span>
          </Button>
        </div>
      </div>

      {/* Direct Clean SCORM Player Viewport - Zero loading screen, zero overlay */}
      <div className="flex-1 w-full h-full relative bg-black overflow-hidden">
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

export default ScormPlayerModal;
