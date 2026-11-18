import { CheckCircle, Lock, Play, GraduationCap, Sparkles } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { COURSES_DATA } from "@/data/coursesData";
import { useCourseProgress } from "@/hooks/useCourseProgress";

interface CourseJourneyStepperProps {
  onSelectCourse: (courseId: number) => void;
  onOpenAssessment: () => void;
}

export const CourseJourneyStepper = ({
  onSelectCourse,
  onOpenAssessment,
}: CourseJourneyStepperProps) => {
  const { 
    getCourseStatus, 
    coursesState, 
    completedCount, 
    totalCourses, 
    isFinalAssessmentUnlocked 
  } = useCourseProgress();

  return (
    <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-5">
      
      {/* Header with Title and Counter */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-2 border-b border-slate-100">
        <div>
          <div className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-[#E6F7F4] text-[#00A389] text-[11px] font-bold uppercase tracking-wider">
            <Sparkles className="h-3 w-3" />
            SEQUENTIAL LEARNING PATHWAY
          </div>
          <h2 className="text-lg sm:text-xl font-bold text-slate-800 tracking-tight mt-1">
            Your 10-Course Induction Roadmap
          </h2>
          <p className="text-xs text-slate-500 font-medium">
            Each course automatically unlocks when you reach the final slide of the previous course.
          </p>
        </div>

        {/* Status Counter Badges */}
        <div className="flex flex-wrap items-center gap-2">
          <Badge className="bg-[#E6F7F4] text-[#00A389] border border-[#00A389]/30 text-xs font-bold px-3 py-1">
            {completedCount} of 10 Completed
          </Badge>
          <Badge className={isFinalAssessmentUnlocked 
            ? "bg-amber-50 text-amber-700 border border-amber-300 text-xs font-bold px-3 py-1"
            : "bg-slate-100 text-slate-500 border border-slate-200 text-xs font-bold px-3 py-1"
          }>
            {isFinalAssessmentUnlocked ? "🎓 Final Assessment Unlocked" : "🔒 Assessment Locked"}
          </Badge>
        </div>
      </div>

      {/* Interactive Horizontal Stepper Track */}
      <div className="relative overflow-x-auto pb-2 pt-1 no-scrollbar">
        <div className="flex items-center min-w-[960px] gap-2 px-1">
          
          {COURSES_DATA.map((c, index) => {
            const status = getCourseStatus(c.id);
            const courseState = coursesState[c.id];
            const slideProgress = courseState?.currentSlide || 1;
            const isCompleted = status === 'completed';
            const isAvailable = status === 'available';
            const isLocked = status === 'locked';

            return (
              <div key={c.id} className="flex items-center flex-1 min-w-[82px] group">
                <button
                  type="button"
                  disabled={isLocked || isCompleted}
                  onClick={() => onSelectCourse(c.id)}
                  className={`flex flex-col items-center w-full p-2.5 rounded-2xl transition-all duration-200 text-center ${
                    isCompleted
                      ? 'bg-slate-50/70 border border-slate-200/70 opacity-80 cursor-not-allowed text-slate-500'
                      : isAvailable
                      ? 'bg-white hover:bg-slate-50 border-2 border-[#00A389] shadow-md shadow-[#00A389]/15 text-[#00A389] scale-105'
                      : 'bg-slate-50/70 border border-slate-200/70 opacity-60 cursor-not-allowed text-slate-400'
                  }`}
                  title={
                    isCompleted
                      ? `Course ${c.id}: ${c.title} (Completed · Locked)`
                      : isAvailable
                      ? `Course ${c.id}: ${c.title} (Available - Click to learn)`
                      : `Course ${c.id}: Locked (Complete Course ${c.id - 1} first)`
                  }
                >
                  {/* Step Node Circle */}
                  <div className={`h-10 w-10 rounded-full flex items-center justify-center font-bold text-xs mb-1.5 transition-transform duration-200 shadow-sm ${
                    isCompleted
                      ? 'bg-[#00A389] text-white'
                      : isAvailable
                      ? 'bg-[#00A389] text-white ring-4 ring-[#00A389]/20'
                      : 'bg-slate-200 text-slate-400'
                  }`}>
                    {isCompleted ? (
                      <CheckCircle className="h-5 w-5" />
                    ) : isAvailable ? (
                      <Play className="h-4 w-4 ml-0.5 fill-current" />
                    ) : (
                      <Lock className="h-3.5 w-3.5" />
                    )}
                  </div>

                  {/* Course Label */}
                  <span className="text-[11px] font-bold tracking-tight text-slate-700 truncate w-full">
                    Course {c.id}
                  </span>

                  {/* Status Indicator */}
                  <span className="text-[9px] font-semibold mt-0.5 capitalize">
                    {isCompleted ? "Done" : isAvailable ? `Slide ${slideProgress}/5` : "Locked"}
                  </span>
                </button>

                {/* Connecting Line */}
                {index < COURSES_DATA.length - 1 && (
                  <div className={`h-0.5 w-3 mx-0.5 transition-colors ${
                    isCompleted ? 'bg-[#00A389]' : 'bg-slate-200'
                  }`} />
                )}
              </div>
            );
          })}

          {/* Final Step: Assessment */}
          <div className="flex items-center min-w-[105px] pl-2 border-l border-slate-200">
            <button
              type="button"
              disabled={!isFinalAssessmentUnlocked}
              onClick={onOpenAssessment}
              className={`flex flex-col items-center w-full p-2.5 rounded-2xl transition-all duration-200 text-center ${
                isFinalAssessmentUnlocked
                  ? 'bg-amber-50 hover:bg-amber-100 border-2 border-amber-400 shadow-md text-amber-800 scale-105'
                  : 'bg-slate-50/70 border border-slate-200/70 opacity-60 cursor-not-allowed text-slate-400'
              }`}
              title={
                isFinalAssessmentUnlocked
                  ? "Final Assessment Unlocked! Click to start"
                  : "Final Assessment Locked: Complete all 10 courses first"
              }
            >
              <div className={`h-10 w-10 rounded-full flex items-center justify-center font-bold text-xs mb-1.5 shadow-sm ${
                isFinalAssessmentUnlocked
                  ? 'bg-amber-500 text-white'
                  : 'bg-slate-200 text-slate-400'
              }`}>
                {isFinalAssessmentUnlocked ? (
                  <GraduationCap className="h-5 w-5" />
                ) : (
                  <Lock className="h-3.5 w-3.5" />
                )}
              </div>
              <span className="text-[11px] font-bold text-slate-700 uppercase">
                FINAL TEST
              </span>
              <span className="text-[9px] font-semibold mt-0.5">
                {isFinalAssessmentUnlocked ? "Unlocked" : "Locked"}
              </span>
            </button>
          </div>

        </div>
      </div>

    </div>
  );
};
