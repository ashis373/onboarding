import { useState, useEffect, useMemo, useCallback } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { 
  CheckCircle, 
  ChevronLeft, 
  ChevronRight, 
  Play, 
  BookOpen, 
  Sparkles, 
  Lock, 
  Unlock, 
  ArrowRight, 
  PartyPopper, 
  Check, 
  X, 
  FileText, 
  Clock, 
  ExternalLink, 
  Download, 
  AlertCircle, 
  Lightbulb, 
  GraduationCap,
  HelpCircle
} from "lucide-react";
import { COURSES_DATA, CourseDefinition, CourseSlide } from "@/data/coursesData";
import { useCourseProgress } from "@/hooks/useCourseProgress";
import { useNavigate } from "react-router-dom";

interface CoursePlayerModalProps {
  courseId: number | null;
  isOpen: boolean;
  onClose: () => void;
  onOpenCourse?: (newCourseId: number) => void;
}

export const CoursePlayerModal = ({
  courseId,
  isOpen,
  onClose,
  onOpenCourse,
}: CoursePlayerModalProps) => {
  const navigate = useNavigate();
  const { 
    coursesState, 
    setCourseSlide, 
    completeCourse, 
  } = useCourseProgress();

  const course = useMemo<CourseDefinition | undefined>(() => {
    return COURSES_DATA.find((c) => c.id === courseId);
  }, [courseId]);

  const courseState = courseId ? coursesState[courseId] : undefined;
  const currentSlideIndex = courseState ? Math.max(1, courseState.currentSlide || 1) : 1;
  const totalSlides = course?.slides.length || 5;

  // Selected interactive check option state
  const [selectedOption, setSelectedOption] = useState<number | null>(null);
  const [checkSubmitted, setCheckSubmitted] = useState<boolean>(false);
  
  // Completion celebration dialog inside modal
  const [showCelebration, setShowCelebration] = useState<boolean>(false);
  const [activeMediaTab, setActiveMediaTab] = useState<'slides' | 'storyline' | 'video'>('slides');

  // Reset interactive check on slide change
  useEffect(() => {
    setSelectedOption(null);
    setCheckSubmitted(false);
  }, [courseId, currentSlideIndex]);

  // Sync slide change to persistent progress hook
  const handleGoToSlide = useCallback((slideNum: number) => {
    if (!courseId) return;
    const clamped = Math.max(1, Math.min(totalSlides, slideNum));
    setCourseSlide(courseId, clamped);
  }, [courseId, totalSlides, setCourseSlide]);

  const handleNextSlide = () => {
    if (currentSlideIndex < totalSlides) {
      handleGoToSlide(currentSlideIndex + 1);
    }
  };

  const handlePrevSlide = () => {
    if (currentSlideIndex > 1) {
      handleGoToSlide(currentSlideIndex - 1);
    }
  };

  // Keyboard navigation
  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "ArrowRight") {
        if (currentSlideIndex < totalSlides) handleNextSlide();
      } else if (e.key === "ArrowLeft") {
        if (currentSlideIndex > 1) handlePrevSlide();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, currentSlideIndex, totalSlides]);

  if (!course || !courseId) return null;

  const currentSlide: CourseSlide = course.slides[currentSlideIndex - 1] || course.slides[0];
  const isLastSlide = currentSlideIndex === totalSlides;
  const isCompleted = !!courseState?.completed;
  const nextCourseId = courseId < 10 ? courseId + 1 : null;
  const nextCourse = nextCourseId ? COURSES_DATA.find((c) => c.id === nextCourseId) : null;

  const handleMarkCourseComplete = () => {
    completeCourse(courseId);
    setShowCelebration(true);
  };

  const handleProceedToNextCourse = () => {
    setShowCelebration(false);
    if (nextCourseId) {
      if (onOpenCourse) {
        onOpenCourse(nextCourseId);
      }
    } else {
      onClose();
      navigate('/assessments');
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="max-w-4xl p-0 gap-0 overflow-hidden bg-white text-slate-800 border-slate-200/90 rounded-2xl shadow-xl">
        
        {/* =========================================================================
            HEADER BAR (HexaOne Style)
           ========================================================================= */}
        <div className="border-b border-slate-100 bg-slate-50/70 p-5 sm:p-6">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <span className="text-[10px] font-black uppercase tracking-wider text-[#00A389] bg-[#E6F7F4] px-2.5 py-0.5 rounded-full border border-[#00A389]/20">
                  COURSE {String(course.id).padStart(2, "0")} OF 10
                </span>
                <span className="text-[10px] font-bold text-slate-500 bg-slate-200/60 px-2 py-0.5 rounded-full">
                  {course.category}
                </span>
                {isCompleted && (
                  <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200 flex items-center gap-1">
                    <CheckCircle className="h-3 w-3 text-emerald-600" /> Completed
                  </span>
                )}
              </div>
              <DialogTitle className="text-xl sm:text-2xl font-black tracking-tight text-slate-800">
                {course.title}
              </DialogTitle>
              <DialogDescription className="text-xs sm:text-sm text-slate-500 font-medium line-clamp-1">
                {course.subtitle}
              </DialogDescription>
            </div>

            {/* Slide Progress Counter Badge */}
            <div className="flex flex-col items-end shrink-0">
              <div className="inline-flex items-center gap-1.5 bg-white px-3 py-1 rounded-full border border-slate-200 text-xs font-bold text-slate-700 shadow-sm">
                <Clock className="h-3.5 w-3.5 text-[#00A389]" />
                <span>Slide {currentSlideIndex} of {totalSlides}</span>
              </div>
              <span className="text-[10px] text-slate-400 mt-1 font-semibold">
                {Math.round((currentSlideIndex / totalSlides) * 100)}% Slide Progress
              </span>
            </div>
          </div>

          {/* Smooth Linear Progress Bar in HexaOne Emerald */}
          <div className="w-full bg-slate-200/80 h-1.5 rounded-full overflow-hidden mt-4">
            <div 
              className="h-full bg-[#00A389] transition-all duration-300 rounded-full"
              style={{ width: `${(currentSlideIndex / totalSlides) * 100}%` }}
            />
          </div>

          {/* Optional Mode Switcher (Storyline / Video / Slides for Course 1 or 2) */}
          {course.scorm_url && (
            <div className="flex items-center gap-2 mt-3 pt-3 border-t border-slate-200/60">
              <button
                onClick={() => setActiveMediaTab('slides')}
                className={`px-3 py-1 rounded-full text-xs font-bold transition-all ${
                  activeMediaTab === 'slides' 
                    ? 'bg-[#00A389] text-white shadow-sm' 
                    : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
                }`}
              >
                Interactive Slides (Recommended)
              </button>
              <button
                onClick={() => setActiveMediaTab('storyline')}
                className={`px-3 py-1 rounded-full text-xs font-bold transition-all ${
                  activeMediaTab === 'storyline' 
                    ? 'bg-[#00A389] text-white shadow-sm' 
                    : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
                }`}
              >
                Articulate Storyline Player
              </button>
              {course.video_url && (
                <button
                  onClick={() => setActiveMediaTab('video')}
                  className={`px-3 py-1 rounded-full text-xs font-bold transition-all ${
                    activeMediaTab === 'video' 
                      ? 'bg-[#00A389] text-white shadow-sm' 
                      : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'
                  }`}
                >
                  Orientation Video
                </button>
              )}
            </div>
          )}
        </div>

        {/* =========================================================================
            SLIDE BODY
           ========================================================================= */}
        <div className="p-6 sm:p-8 max-h-[66vh] overflow-y-auto space-y-5 bg-white">

          {/* If Storyline Tab Selected */}
          {activeMediaTab === 'storyline' && course.scorm_url ? (
            <div className="space-y-3">
              <div className="w-full aspect-video rounded-2xl overflow-hidden border border-slate-200 bg-black shadow-inner">
                <iframe
                  src={course.scorm_url}
                  title="Articulate Storyline Course"
                  className="w-full h-full border-0"
                  allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                />
              </div>
              <div className="flex items-center justify-between text-xs text-slate-500">
                <span>SCORM Course Template Embedded Player</span>
                <Button size="sm" variant="outline" className="text-xs border-slate-200" asChild>
                  <a href={course.scorm_url} target="_blank" rel="noreferrer">
                    <ExternalLink className="h-3 w-3 mr-1.5" />
                    Open in Full Window
                  </a>
                </Button>
              </div>
            </div>
          ) : activeMediaTab === 'video' && course.video_url ? (
            <div className="space-y-3">
              <div className="w-full aspect-video rounded-2xl overflow-hidden border border-slate-200 bg-black shadow-inner">
                <iframe
                  src={course.video_url}
                  title={course.title}
                  className="w-full h-full border-0"
                  allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                  allowFullScreen
                />
              </div>
            </div>
          ) : (
            /* Interactive Slides Deck */
            <div className="space-y-5 animate-in fade-in duration-200">
              
              {/* Slide Title Header */}
              <div className="space-y-1">
                {currentSlide.badge && (
                  <span className="text-[10px] font-black tracking-wider uppercase text-[#00A389] bg-[#E6F7F4] px-2.5 py-0.5 rounded-full inline-block">
                    {currentSlide.badge}
                  </span>
                )}
                <h3 className="text-xl sm:text-2xl font-black text-slate-800 tracking-tight pt-1">
                  {currentSlide.title}
                </h3>
                {currentSlide.subtitle && (
                  <p className="text-xs sm:text-sm font-semibold text-slate-500">
                    {currentSlide.subtitle}
                  </p>
                )}
              </div>

              {/* Main Content Box */}
              <div className="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3">
                <p className="text-xs sm:text-sm text-slate-700 leading-relaxed font-normal">
                  {currentSlide.content}
                </p>

                {/* Bullets */}
                {currentSlide.bullets && currentSlide.bullets.length > 0 && (
                  <ul className="space-y-2 pt-1">
                    {currentSlide.bullets.map((bullet, idx) => (
                      <li key={idx} className="flex items-start gap-2.5 text-xs sm:text-sm text-slate-600 font-medium leading-relaxed">
                        <div className="h-4 w-4 rounded-full bg-[#E6F7F4] border border-[#00A389]/30 flex items-center justify-center shrink-0 mt-0.5">
                          <Check className="h-2.5 w-2.5 text-[#00A389]" />
                        </div>
                        <span>{bullet}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              {/* Callout */}
              {currentSlide.callout && (
                <div className={`p-4 rounded-2xl border flex items-start gap-3.5 ${
                  currentSlide.callout.type === 'quote'
                    ? 'bg-purple-50/70 border-purple-200 text-purple-900'
                    : currentSlide.callout.type === 'tip'
                    ? 'bg-[#E6F7F4] border-[#00A389]/30 text-teal-900'
                    : currentSlide.callout.type === 'important'
                    ? 'bg-amber-50 border-amber-200 text-amber-900'
                    : 'bg-blue-50 border-blue-200 text-blue-900'
                }`}>
                  <div className="shrink-0 mt-0.5">
                    {currentSlide.callout.type === 'quote' && <Sparkles className="h-4 w-4 text-purple-600" />}
                    {currentSlide.callout.type === 'tip' && <Lightbulb className="h-4 w-4 text-[#00A389]" />}
                    {currentSlide.callout.type === 'important' && <AlertCircle className="h-4 w-4 text-amber-600" />}
                    {currentSlide.callout.type === 'checklist' && <CheckCircle className="h-4 w-4 text-blue-600" />}
                  </div>
                  <div className="text-xs font-semibold leading-relaxed">
                    {currentSlide.callout.text}
                  </div>
                </div>
              )}

              {/* Special Preview / Download for Handbook in Course 2 */}
              {course.file_url && (
                <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <FileText className="h-6 w-6 text-[#00A389]" />
                    <div>
                      <h4 className="text-xs font-bold text-slate-800">Full Employee Handbook Document</h4>
                      <p className="text-[11px] text-slate-400">Official company handbook PDF</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 w-full sm:w-auto">
                    <Button size="sm" variant="outline" className="border-slate-200 text-xs font-bold flex-1 sm:flex-initial" asChild>
                      <a href={course.file_url} target="_blank" rel="noreferrer">
                        <ExternalLink className="h-3 w-3 mr-1" /> Preview PDF
                      </a>
                    </Button>
                    <Button size="sm" className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold flex-1 sm:flex-initial" asChild>
                      <a href={course.file_url} download>
                        <Download className="h-3 w-3 mr-1" /> Download
                      </a>
                    </Button>
                  </div>
                </div>
              )}

              {/* Interactive Knowledge Check Checkpoint (Slide 4) */}
              {currentSlide.interactiveCheck && (
                <div className="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                  <div className="flex items-center gap-1.5 text-[#00A389] font-bold text-xs uppercase tracking-wider">
                    <HelpCircle className="h-3.5 w-3.5" /> Quick Reflection Checkpoint
                  </div>
                  <h4 className="text-sm font-bold text-slate-800">
                    {currentSlide.interactiveCheck.question}
                  </h4>

                  <div className="space-y-2">
                    {currentSlide.interactiveCheck.options.map((opt, optIdx) => {
                      const isSelected = selectedOption === optIdx;
                      const isCorrect = optIdx === currentSlide.interactiveCheck?.correctIndex;
                      return (
                        <button
                          key={optIdx}
                          disabled={checkSubmitted}
                          onClick={() => setSelectedOption(optIdx)}
                          className={`w-full text-left p-3 rounded-xl border text-xs font-medium transition-all flex items-center justify-between ${
                            checkSubmitted
                              ? isCorrect
                                ? 'bg-emerald-50 border-emerald-400 text-emerald-800 font-bold'
                                : isSelected
                                ? 'bg-rose-50 border-rose-400 text-rose-800'
                                : 'bg-white border-slate-200 opacity-50'
                              : isSelected
                              ? 'bg-[#E6F7F4] border-[#00A389] text-[#00A389] font-bold ring-1 ring-[#00A389]/30'
                              : 'bg-white border-slate-200 hover:bg-slate-100 text-slate-700'
                          }`}
                        >
                          <span>{opt}</span>
                          {checkSubmitted && isCorrect && <Check className="h-3.5 w-3.5 text-emerald-600" />}
                          {checkSubmitted && isSelected && !isCorrect && <X className="h-3.5 w-3.5 text-rose-600" />}
                        </button>
                      );
                    })}
                  </div>

                  {!checkSubmitted ? (
                    <Button
                      size="sm"
                      disabled={selectedOption === null}
                      onClick={() => setCheckSubmitted(true)}
                      className="bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs rounded-xl"
                    >
                      Check Answer
                    </Button>
                  ) : (
                    <div className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-800 font-medium">
                      {currentSlide.interactiveCheck.explanation}
                    </div>
                  )}
                </div>
              )}

              {/* LAST SLIDE COMPLETION CALLOUT */}
              {isLastSlide && (
                <div className="rounded-2xl border border-emerald-300 bg-emerald-50/70 p-6 text-center space-y-3 shadow-sm">
                  <div className="mx-auto h-12 w-12 rounded-full bg-emerald-100 border border-emerald-300 flex items-center justify-center text-emerald-700 shadow-sm animate-bounce">
                    <PartyPopper className="h-6 w-6" />
                  </div>

                  <div className="space-y-1">
                    <h3 className="text-lg font-black text-slate-800">
                      You've Reached the Final Slide of Course {course.id}!
                    </h3>
                    <p className="text-xs text-slate-600 max-w-lg mx-auto font-medium">
                      {course.id < 10 
                        ? `Click the button below to mark Course ${course.id} complete and automatically unlock Course ${course.id + 1}: "${nextCourse?.title}".`
                        : `Congratulations! You have completed all 10 Induction Courses. Your Final Assessment is now ready to unlock!`}
                    </p>
                  </div>

                  <div className="pt-2">
                    <Button
                      size="lg"
                      className="bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs uppercase tracking-wider shadow-md shadow-[#00A389]/20 px-6 py-5 rounded-xl transition-all"
                      onClick={handleMarkCourseComplete}
                    >
                      <CheckCircle className="h-4 w-4 mr-2" />
                      {course.id < 10
                        ? `Complete Course ${course.id} & Unlock Course ${course.id + 1}`
                        : `Complete All 10 Courses & Unlock Final Assessment`}
                    </Button>
                  </div>
                </div>
              )}

              {/* Key Takeaway */}
              {currentSlide.keyTakeaway && (
                <div className="text-center pt-2">
                  <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    KEY TAKEAWAY:{" "}
                  </span>
                  <span className="text-xs font-semibold text-slate-600">
                    {currentSlide.keyTakeaway}
                  </span>
                </div>
              )}

            </div>
          )}

        </div>

        {/* =========================================================================
            FOOTER CONTROLS
           ========================================================================= */}
        <div className="p-4 border-t border-slate-100 bg-slate-50 flex flex-col sm:flex-row items-center justify-between gap-3">
          
          <Button
            variant="outline"
            size="sm"
            disabled={currentSlideIndex <= 1}
            onClick={handlePrevSlide}
            className="border-slate-200 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold rounded-xl w-full sm:w-auto"
          >
            <ChevronLeft className="h-3.5 w-3.5 mr-1" />
            Previous Slide
          </Button>

          {/* Slide Indicator Dots */}
          <div className="flex items-center gap-1.5">
            {course.slides.map((_, idx) => {
              const slideNum = idx + 1;
              const isActive = slideNum === currentSlideIndex;
              return (
                <button
                  key={idx}
                  onClick={() => handleGoToSlide(slideNum)}
                  className={`h-2 transition-all duration-200 rounded-full ${
                    isActive 
                      ? 'w-6 bg-[#00A389]' 
                      : 'w-2 bg-slate-300 hover:bg-slate-400'
                  }`}
                  title={`Go to slide ${slideNum}`}
                />
              );
            })}
          </div>

          {/* Next / Complete Button */}
          {isLastSlide ? (
            <Button
              size="sm"
              className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl shadow-sm w-full sm:w-auto"
              onClick={handleMarkCourseComplete}
            >
              <CheckCircle className="h-3.5 w-3.5 mr-1" />
              {isCompleted ? "Course Completed" : "Mark Complete"}
            </Button>
          ) : (
            <Button
              size="sm"
              onClick={handleNextSlide}
              className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl shadow-sm w-full sm:w-auto"
            >
              Next Slide
              <ChevronRight className="h-3.5 w-3.5 ml-1" />
            </Button>
          )}

        </div>

      </DialogContent>

      {/* =========================================================================
          CELEBRATION UNLOCK OVERLAY MODAL (HexaOne Style)
         ========================================================================= */}
      <Dialog open={showCelebration} onOpenChange={setShowCelebration}>
        <DialogContent className="max-w-md bg-white border border-slate-200 text-slate-800 p-6 rounded-2xl shadow-xl text-center space-y-4">
          <div className="mx-auto h-16 w-16 rounded-full bg-[#E6F7F4] border-2 border-[#00A389] flex items-center justify-center text-[#00A389] shadow-md animate-pulse">
            {course.id < 10 ? <Unlock className="h-8 w-8" /> : <GraduationCap className="h-8 w-8 text-amber-500" />}
          </div>

          <div className="space-y-1">
            <span className="text-[10px] font-black uppercase tracking-wider text-[#00A389] bg-[#E6F7F4] px-2.5 py-0.5 rounded-full">
              {course.id < 10 ? `COURSE ${course.id} COMPLETED!` : "ALL 10 COURSES COMPLETED!"}
            </span>
            <DialogTitle className="text-xl font-black text-slate-800 pt-1">
              {course.id < 10 
                ? `Course ${course.id + 1} is Now Unlocked!` 
                : "Final Assessment Unlocked!"}
            </DialogTitle>
            <DialogDescription className="text-xs text-slate-500 font-medium leading-relaxed">
              {course.id < 10 
                ? `Great job reaching the final slide! Course ${course.id + 1}: "${nextCourse?.title}" is automatically unlocked and ready to start.`
                : `Outstanding achievement! You have completed all 10 Induction Courses. You are now fully eligible to take your Final Onboarding Assessment.`}
            </DialogDescription>
          </div>

          <div className="flex flex-col gap-2 pt-2">
            {course.id < 10 ? (
              <Button
                className="w-full bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs uppercase tracking-wider rounded-xl shadow-sm h-10"
                onClick={handleProceedToNextCourse}
              >
                Proceed to Course {course.id + 1} Now
                <ArrowRight className="h-3.5 w-3.5 ml-1.5" />
              </Button>
            ) : (
              <Button
                className="w-full bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs uppercase tracking-wider rounded-xl shadow-sm h-10"
                onClick={() => {
                  setShowCelebration(false);
                  onClose();
                  navigate('/assessments');
                }}
              >
                <GraduationCap className="h-4 w-4 mr-1.5" />
                Start Final Assessment Now
              </Button>
            )}

            <Button
              variant="outline"
              size="sm"
              className="w-full border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs rounded-xl"
              onClick={() => {
                setShowCelebration(false);
                onClose();
              }}
            >
              Return to Dashboard
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </Dialog>
  );
};
