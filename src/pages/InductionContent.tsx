import { useState, useMemo } from "react";
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
  Play,
  Check,
  ChevronRight,
  Trophy,
  ArrowRight,
  Sparkles,
  Calendar,
  Layers,
  CheckCircle,
  Search,
  LayoutGrid,
  List as ListIcon,
  Footprints,
  HelpCircle
} from "lucide-react";
import { COURSES_DATA, CourseDefinition } from "@/data/coursesData";
import { useCourseProgress } from "@/hooks/useCourseProgress";
import { CoursePlayerModal } from "@/components/CoursePlayerModal";
import { CourseJourneyStepper } from "@/components/CourseJourneyStepper";
import { useNavigate } from "react-router-dom";

export const InductionContent = () => {
  const navigate = useNavigate();
  const [activeCourseId, setActiveCourseId] = useState<number | null>(null);
  const [viewMode, setViewMode] = useState<'card' | 'list'>('card');
  const [filter, setFilter] = useState<'all' | 'available' | 'completed' | 'locked'>('all');
  const [searchQuery, setSearchQuery] = useState('');

  const {
    getCourseStatus,
    coursesState,
    completedCount,
    totalCourses,
    progressPercentage,
    isFinalAssessmentUnlocked,
    currentActiveCourseId,
    finalAssessmentCompleted,
    finalAssessmentScore,
  } = useCourseProgress();

  // Helper for Course Icon & Colors matching HexaOne Design
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

  // Filtered courses
  const filteredCourses = useMemo(() => {
    return COURSES_DATA.filter((course) => {
      const status = getCourseStatus(course.id);
      const matchesSearch =
        course.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
        course.subtitle.toLowerCase().includes(searchQuery.toLowerCase()) ||
        course.category.toLowerCase().includes(searchQuery.toLowerCase());

      if (!matchesSearch) return false;
      if (filter === 'available') return status === 'available';
      if (filter === 'completed') return status === 'completed';
      if (filter === 'locked') return status === 'locked';
      return true;
    });
  }, [getCourseStatus, filter, searchQuery]);

  const totalMinutes = COURSES_DATA.reduce((acc, curr) => acc + (curr.duration_minutes || 0), 0);
  const remainingMinutes = COURSES_DATA
    .filter((c) => getCourseStatus(c.id) !== 'completed')
    .reduce((acc, curr) => acc + (curr.duration_minutes || 0), 0);

  const currentCourseObj = COURSES_DATA.find((c) => c.id === currentActiveCourseId);

  return (
    <Layout>
      <div className="space-y-6 pb-16">

        {/* =========================================================================
            1. BREADCRUMB & DATE BAR
           ========================================================================= */}
        <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
          <div className="flex items-center gap-2">
            <span className="text-slate-400">Workspace</span>
            <span className="text-slate-300">/</span>
            <span className="text-[#00A389] font-bold">Induction Curriculum</span>
          </div>
          <div className="flex items-center gap-1.5 bg-white border border-slate-200/80 px-3 py-1 rounded-full shadow-xs text-[11px] font-semibold text-slate-600">
            <Calendar className="h-3 w-3 text-[#00A389]" />
            <span>Today, {new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
          </div>
        </div>

        {/* =========================================================================
            2. TOP HEADER HERO BANNER WITH STATS (HexaOne Style)
           ========================================================================= */}
        <div className="bg-white border border-slate-200/80 rounded-3xl p-6 sm:p-7 shadow-xs">
          <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6">

            <div className="space-y-2">
              <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#E6F7F4] text-[#00A389] text-[11px] font-black uppercase tracking-wider">
                <Sparkles className="h-3.5 w-3.5" />
                SEQUENTIAL LEARNING PATHWAY
              </div>
              <h1 className="text-2xl sm:text-3xl font-black text-slate-800 tracking-tight">
                Induction Curriculum
              </h1>
              <p className="text-xs sm:text-sm text-slate-500 font-medium max-w-2xl leading-relaxed">
                Complete each induction module sequentially by finishing the final slide. Finishing all 10 courses automatically unlocks your comprehensive Final Assessment.
              </p>
            </div>

            {/* Quick KPI Stat Boxes */}
            <div className="grid grid-cols-3 gap-3 w-full lg:w-auto shrink-0">
              <div className="bg-slate-50/80 border border-slate-200/80 rounded-2xl p-3 text-center min-w-[105px] sm:min-w-[120px]">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  COMPLETED
                </span>
                <span className="text-xl font-black text-slate-800 block mt-0.5">
                  {completedCount} <span className="text-xs text-slate-400 font-semibold">/ 10</span>
                </span>
                <span className="text-[10px] font-bold text-[#00A389] block mt-0.5">
                  {progressPercentage}% Done
                </span>
              </div>

              <div className="bg-slate-50/80 border border-slate-200/80 rounded-2xl p-3 text-center min-w-[105px] sm:min-w-[120px]">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  REMAINING
                </span>
                <span className="text-xl font-black text-slate-800 block mt-0.5">
                  {Math.round(remainingMinutes / 60)}h {remainingMinutes % 60}m
                </span>
                <span className="text-[10px] font-bold text-slate-500 block mt-0.5">
                  Self-Paced
                </span>
              </div>

              <div className="bg-slate-50/80 border border-slate-200/80 rounded-2xl p-3 text-center min-w-[105px] sm:min-w-[120px]">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  FINAL TEST
                </span>
                <span className={`text-xl font-black block mt-0.5 ${isFinalAssessmentUnlocked ? 'text-[#00A389]' : 'text-slate-600'}`}>
                  {finalAssessmentCompleted ? `${finalAssessmentScore}%` : isFinalAssessmentUnlocked ? 'Ready' : 'Locked'}
                </span>
                <span className="text-[10px] font-bold text-slate-400 block mt-0.5">
                  {isFinalAssessmentUnlocked ? 'Eligible' : 'Requires 10/10'}
                </span>
              </div>
            </div>

          </div>
        </div>

        {/* =========================================================================
            3. SEQUENTIAL LEARNING STEPPER ROADMAP
           ========================================================================= */}
        <CourseJourneyStepper
          onSelectCourse={(id) => setActiveCourseId(id)}
          onOpenAssessment={() => navigate('/assessments')}
        />

        {/* =========================================================================
            4. FILTER CONTROLS, SEARCH, AND VIEW MODE TOGGLE
           ========================================================================= */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-4 flex flex-col md:flex-row items-center justify-between gap-4 shadow-xs">
          
          {/* Status Filter Buttons */}
          <div className="flex items-center gap-1.5 flex-wrap w-full md:w-auto">
            <button
              onClick={() => setFilter('all')}
              className={`px-3.5 py-1.5 rounded-full text-xs font-bold transition-all ${
                filter === 'all'
                  ? 'bg-[#00A389] text-white shadow-xs'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              All Courses ({COURSES_DATA.length})
            </button>
            <button
              onClick={() => setFilter('available')}
              className={`px-3.5 py-1.5 rounded-full text-xs font-bold transition-all ${
                filter === 'available'
                  ? 'bg-[#00A389] text-white shadow-xs'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              Available ({COURSES_DATA.filter((c) => getCourseStatus(c.id) === 'available').length})
            </button>
            <button
              onClick={() => setFilter('completed')}
              className={`px-3.5 py-1.5 rounded-full text-xs font-bold transition-all ${
                filter === 'completed'
                  ? 'bg-[#00A389] text-white shadow-xs'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              Completed ({completedCount})
            </button>
            <button
              onClick={() => setFilter('locked')}
              className={`px-3.5 py-1.5 rounded-full text-xs font-bold transition-all ${
                filter === 'locked'
                  ? 'bg-[#00A389] text-white shadow-xs'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              Locked ({COURSES_DATA.filter((c) => getCourseStatus(c.id) === 'locked').length})
            </button>
          </div>

          {/* Right: Search and View Mode Switcher */}
          <div className="flex items-center gap-3 w-full md:w-auto justify-between md:justify-end">
            <div className="relative w-full md:w-56">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400" />
              <input
                type="text"
                placeholder="Search courses..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full bg-slate-50 border border-slate-200 rounded-full pl-8 pr-3 py-1.5 text-xs font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#00A389]/40"
              />
            </div>

            <div className="inline-flex bg-slate-100 p-1 rounded-full border border-slate-200 shrink-0">
              <button
                onClick={() => setViewMode('card')}
                className={`p-1.5 rounded-full transition-all ${
                  viewMode === 'card' ? 'bg-white text-slate-800 shadow-xs' : 'text-slate-500 hover:text-slate-800'
                }`}
                title="Grid / Card View"
              >
                <LayoutGrid className="h-3.5 w-3.5" />
              </button>
              <button
                onClick={() => setViewMode('list')}
                className={`p-1.5 rounded-full transition-all ${
                  viewMode === 'list' ? 'bg-white text-slate-800 shadow-xs' : 'text-slate-500 hover:text-slate-800'
                }`}
                title="List View"
              >
                <ListIcon className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

        </div>

        {/* =========================================================================
            5. COURSES DISPLAY (CARD VIEW OR LIST VIEW)
           ========================================================================= */}
        {viewMode === 'card' ? (
          /* CARD GRID (Matching Dashboard Roadmap Style) */
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
            {filteredCourses.map((item) => {
              const status = getCourseStatus(item.id);
              const isCompleted = status === 'completed';
              const isAvailable = status === 'available';
              const isLocked = status === 'locked';
              const courseState = coursesState[item.id];
              const currentSlide = courseState?.currentSlide || 1;
              const { Icon, iconColor, iconBg } = getCourseMeta(item.id);

              return (
                <div
                  key={item.id}
                  className={`bg-white rounded-2xl border transition-all duration-200 flex flex-col justify-between p-4 ${
                    isAvailable
                      ? 'border-[#00A389] ring-2 ring-[#00A389]/20 shadow-md shadow-[#00A389]/5'
                      : isCompleted
                      ? 'border-emerald-200 shadow-xs'
                      : 'border-slate-200/80 opacity-85'
                  }`}
                >
                  {/* Card Header: Number Circle & Status Pill */}
                  <div className="flex items-center justify-between gap-1 pb-2">
                    <div
                      className={`h-6 w-6 rounded-full flex items-center justify-center text-xs font-black shrink-0 ${
                        isCompleted
                          ? 'bg-[#00A389] text-white'
                          : isAvailable
                          ? 'bg-[#00A389] text-white ring-2 ring-[#00A389]/30'
                          : 'bg-slate-100 text-slate-500'
                      }`}
                    >
                      {isCompleted ? <Check className="h-3.5 w-3.5" /> : item.id}
                    </div>

                    <div>
                      {isCompleted ? (
                        <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200 flex items-center gap-1">
                          ✓ Done
                        </span>
                      ) : isAvailable ? (
                        <span className="text-[10px] font-bold text-[#00A389] bg-[#E6F7F4] px-2 py-0.5 rounded-full border border-[#00A389]/30 flex items-center gap-1">
                          <span className="h-1.5 w-1.5 rounded-full bg-[#00A389]" />
                          In Progress
                        </span>
                      ) : (
                        <span className="text-[10px] font-semibold text-slate-400 bg-slate-50 px-2 py-0.5 rounded-full border border-slate-200/60 flex items-center gap-1">
                          <Lock className="h-2.5 w-2.5" /> Locked
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Card Body with Category Icon */}
                  <div className="py-2.5 flex flex-col items-center text-center space-y-2 flex-1">
                    <div className={`h-11 w-11 rounded-full ${iconBg} border flex items-center justify-center ${iconColor} shrink-0`}>
                      <Icon className="h-5 w-5" />
                    </div>

                    <div className="space-y-1 w-full">
                      <span className="text-[9px] font-black uppercase tracking-wider text-slate-400 block">
                        {item.category}
                      </span>
                      <h3 className="text-xs font-bold text-slate-800 leading-snug line-clamp-2 min-h-[32px]">
                        {item.title}
                      </h3>
                      <p className="text-[11px] text-slate-400 font-normal leading-relaxed line-clamp-2 min-h-[30px]">
                        {item.subtitle}
                      </p>
                    </div>
                  </div>

                  {/* Mini Slide Progress Bar */}
                  <div className="py-2 space-y-1">
                    <div className="flex items-center justify-between text-[10px] font-semibold text-slate-400">
                      <span>{item.slides.length} Slides</span>
                      {isAvailable && (
                        <span className="text-[#00A389] font-bold">Slide {currentSlide}/5</span>
                      )}
                    </div>
                    <div className="w-full bg-slate-100 h-1 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-[#00A389] rounded-full transition-all"
                        style={{
                          width: isCompleted ? '100%' : isAvailable ? `${(currentSlide / item.slides.length) * 100}%` : '0%',
                        }}
                      />
                    </div>
                  </div>

                  {/* Bottom Footer: Duration Left, Action Button Right */}
                  <div className="pt-2 border-t border-slate-100 flex items-center justify-between gap-1 text-[11px]">
                    <span className="text-slate-400 font-semibold flex items-center gap-1 shrink-0">
                      <Clock className="h-3 w-3" />
                      {item.duration}
                    </span>

                    <div>
                      {isCompleted ? (
                        <button
                          onClick={() => setActiveCourseId(item.id)}
                          className="text-[11px] font-bold text-emerald-700 hover:text-emerald-800 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/70 transition-all flex items-center gap-1"
                        >
                          Review →
                        </button>
                      ) : isAvailable ? (
                        <button
                          onClick={() => setActiveCourseId(item.id)}
                          className="text-[11px] font-bold text-white bg-[#00A389] hover:bg-[#008f77] px-3 py-1 rounded-lg shadow-xs transition-all flex items-center gap-1"
                        >
                          {currentSlide > 1 ? "Continue →" : "Start →"}
                        </button>
                      ) : (
                        <span className="text-[11px] font-medium text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md flex items-center gap-1">
                          <Lock className="h-2.5 w-2.5" /> Locked
                        </span>
                      )}
                    </div>
                  </div>

                </div>
              );
            })}
          </div>
        ) : (
          /* LIST VIEW */
          <div className="space-y-3">
            {filteredCourses.map((item) => {
              const status = getCourseStatus(item.id);
              const isCompleted = status === 'completed';
              const isAvailable = status === 'available';
              const isLocked = status === 'locked';
              const courseState = coursesState[item.id];
              const currentSlide = courseState?.currentSlide || 1;
              const { Icon, iconColor, iconBg } = getCourseMeta(item.id);

              return (
                <div
                  key={item.id}
                  className={`bg-white rounded-2xl border p-4 sm:p-5 transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-4 ${
                    isAvailable
                      ? 'border-[#00A389] ring-1 ring-[#00A389]/30 shadow-sm'
                      : isCompleted
                      ? 'border-emerald-200 shadow-xs'
                      : 'border-slate-200/70 opacity-80'
                  }`}
                >
                  <div className="flex items-start sm:items-center gap-4">
                    <div className={`h-11 w-11 rounded-2xl ${iconBg} border flex items-center justify-center ${iconColor} shrink-0`}>
                      <Icon className="h-5 w-5" />
                    </div>

                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="text-[10px] font-black uppercase tracking-wider text-[#00A389] bg-[#E6F7F4] px-2 py-0.5 rounded-full">
                          COURSE {String(item.id).padStart(2, '0')} • {item.category}
                        </span>
                        <span className="text-[10px] font-bold text-slate-400 flex items-center gap-1">
                          <Clock className="h-2.5 w-2.5" /> {item.duration}
                        </span>
                      </div>
                      <h3 className="text-sm sm:text-base font-bold text-slate-800 tracking-tight">
                        {item.title}
                      </h3>
                      <p className="text-xs text-slate-500 font-medium max-w-xl">
                        {item.subtitle}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-3 shrink-0 self-end sm:self-center">
                    {isCompleted ? (
                      <span className="text-xs font-bold text-emerald-700 bg-emerald-50 px-3 py-1 rounded-full border border-emerald-200">
                        ✓ Completed
                      </span>
                    ) : isAvailable ? (
                      <span className="text-xs font-bold text-[#00A389] bg-[#E6F7F4] px-3 py-1 rounded-full border border-[#00A389]/30">
                        Slide {currentSlide} of {item.slides.length}
                      </span>
                    ) : (
                      <span className="text-xs font-semibold text-slate-400 bg-slate-100 px-3 py-1 rounded-full flex items-center gap-1">
                        <Lock className="h-3 w-3" /> Locked
                      </span>
                    )}

                    <Button
                      size="sm"
                      disabled={isLocked}
                      onClick={() => setActiveCourseId(item.id)}
                      className={
                        isCompleted
                          ? "text-xs font-bold border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 h-9"
                          : isAvailable
                          ? "bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl shadow-xs h-9 px-4"
                          : "text-xs rounded-xl h-9 text-slate-400 bg-slate-100"
                      }
                      variant={isCompleted ? "outline" : "default"}
                    >
                      {isCompleted ? "Review Slides" : isAvailable ? (currentSlide > 1 ? "Continue" : "Start Course") : "Locked"}
                    </Button>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* =========================================================================
            6. FINAL ASSESSMENT BANNER (Matching Dashboard Purple Banner)
           ========================================================================= */}
        <div className={`rounded-3xl p-5 sm:p-6 transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-4 border ${
          isFinalAssessmentUnlocked
            ? 'bg-[#FAF5FF] border-[#D8B4FE] shadow-sm'
            : 'bg-[#F9F8FE] border-[#E9D5FF]'
        }`}>
          <div className="flex items-center gap-4">
            <div className="h-14 w-14 rounded-2xl bg-[#8B5CF6] text-white flex items-center justify-center shrink-0 shadow-sm">
              <Trophy className="h-7 w-7" />
            </div>
            <div>
              <span className="text-[10px] font-black uppercase tracking-wider text-[#7C3AED] block">
                FINAL ASSESSMENT
              </span>
              <h3 className="text-base sm:text-lg font-black text-slate-800 tracking-tight mt-0.5">
                HexaLearn Onboarding Final Assessment
              </h3>
              <p className="text-xs text-slate-500 font-medium mt-0.5">
                {isFinalAssessmentUnlocked
                  ? "Congratulations! You have completed all 10 courses. Click to take your final comprehensive test."
                  : `Complete all 10 induction courses to unlock your final comprehensive assessment. (${completedCount}/10 completed)`}
              </p>
            </div>
          </div>

          <div className="shrink-0 self-start sm:self-auto">
            {isFinalAssessmentUnlocked ? (
              <Button
                onClick={() => navigate('/assessments')}
                className="bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs rounded-xl px-5 h-10 shadow-sm flex items-center gap-1.5"
              >
                <GraduationCap className="h-4 w-4" />
                {finalAssessmentCompleted ? "View Certificate & Retake" : "Start Final Assessment"}
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
            ) : (
              <button
                disabled
                className="bg-white border border-slate-200 text-slate-400 font-bold text-xs px-4 py-2.5 rounded-xl flex items-center gap-1.5 shadow-2xs cursor-not-allowed"
              >
                <Lock className="h-3.5 w-3.5" />
                <span>Locked</span>
                <ChevronRight className="h-3.5 w-3.5" />
              </button>
            )}
          </div>
        </div>

        {/* =========================================================================
            7. BOTTOM FOOTER HIGHLIGHTS BAR
           ========================================================================= */}
        <div className="bg-white border border-slate-200/80 rounded-2xl p-4 sm:p-5 shadow-xs flex flex-col md:flex-row items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="h-8 w-8 rounded-full bg-[#E6F7F4] flex items-center justify-center text-[#00A389] font-black text-sm">
              <Leaf className="h-4 w-4" />
            </div>
            <div>
              <span className="text-xs font-black text-slate-800 block">
                HexaLearn
              </span>
              <span className="text-[11px] text-slate-400 font-semibold block">
                Onboarding Portal
              </span>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-center gap-3 text-xs font-bold">
            <div className="flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200/60 text-slate-600">
              <Layers className="h-3.5 w-3.5 text-[#00A389]" />
              <span>10 Learning Modules</span>
              <span className="text-slate-400 font-normal hidden sm:inline">• Build your knowledge</span>
            </div>

            <div className="flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200/60 text-slate-600">
              <Trophy className="h-3.5 w-3.5 text-purple-600" />
              <span>1 Final Assessment</span>
              <span className="text-slate-400 font-normal hidden sm:inline">• Get your certificate</span>
            </div>

            <div className="flex items-center gap-2 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200/60 text-slate-600">
              <HelpCircle className="h-3.5 w-3.5 text-sky-500" />
              <span>Support Anytime</span>
              <span className="text-slate-400 font-normal hidden sm:inline">• We are here to help</span>
            </div>
          </div>

          <div className="flex items-center gap-2 text-xs font-semibold text-slate-500 italic">
            <span>"Learning today, leads to a better tomorrow."</span>
            <Leaf className="h-3.5 w-3.5 text-[#00A389]" />
          </div>
        </div>

        {/* Modal Player */}
        <CoursePlayerModal
          courseId={activeCourseId}
          isOpen={activeCourseId !== null}
          onClose={() => setActiveCourseId(null)}
          onOpenCourse={(newId) => setActiveCourseId(newId)}
        />

      </div>
    </Layout>
  );
};

export default InductionContent;