import { useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Label } from "@/components/ui/label";
import { Layout } from "@/components/Layout";
import { 
  Clock, 
  CheckCircle, 
  AlertCircle, 
  Trophy, 
  ListChecks, 
  Flag, 
  Lock, 
  Play, 
  Sparkles, 
  GraduationCap, 
  RotateCcw, 
  Award, 
  ChevronRight, 
  ChevronLeft 
} from "lucide-react";
import { toast } from "@/hooks/use-toast";
import { apiPost } from "@/lib/api";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { useCourseProgress } from "@/hooks/useCourseProgress";
import { COURSES_DATA } from "@/data/coursesData";
import { useAuth } from "@/hooks/useAuth";
import { useNavigate } from "react-router-dom";
import { CoursePlayerModal } from "@/components/CoursePlayerModal";

interface AssessmentQuestion {
  id: number;
  prompt: string;
  category: string;
  options: string[];
  correctIndex: number;
  explanation: string;
}

const FINAL_ASSESSMENT_QUESTIONS: AssessmentQuestion[] = [
  {
    id: 1,
    prompt: "What is HexaLearn's primary mission in the digital learning ecosystem?",
    category: "Course 1: Company Overview",
    options: [
      "Creating transformative digital learning experiences, custom eLearning, and EdTech platforms",
      "Manufacturing computer hardware, cables, and monitors",
      "Operating retail grocery and warehouse supply chains",
      "Providing airline and hotel ticketing reservations"
    ],
    correctIndex: 0,
    explanation: "HexaLearn specializes in bespoke digital learning, instructional architecture, and enterprise LMS platforms."
  },
  {
    id: 2,
    prompt: "When are daily timesheet entries required to be submitted in Hex-Timesheet?",
    category: "Course 2: Company Handbook",
    options: [
      "At the end of each working day before logging off",
      "Once every six months",
      "Only when explicitly requested by external auditors",
      "Timesheet logging is completely optional"
    ],
    correctIndex: 0,
    explanation: "Timesheets should be logged daily at the end of each working day to maintain transparent project records."
  },
  {
    id: 3,
    prompt: "How many business days in advance should planned vacation leaves be submitted?",
    category: "Course 2: Company Handbook",
    options: [
      "At least 5 business days in advance",
      "30 minutes before leaving the office",
      "After returning from the vacation",
      "Leave requests are never required"
    ],
    correctIndex: 0,
    explanation: "Planned vacation leaves must be submitted at least 5 business days in advance to allow team workload balancing."
  },
  {
    id: 4,
    prompt: "What is the mandatory protocol whenever you step away from your computer workstation?",
    category: "Course 3: IT Security",
    options: [
      "Always lock your screen immediately (Win + L / Cmd + Ctrl + Q)",
      "Leave your screen unlocked with confidential client code visible",
      "Turn off the office lights instead",
      "Ask a passerby to watch your monitor"
    ],
    correctIndex: 0,
    explanation: "Always lock your screen whenever stepping away to prevent unauthorized data exposure."
  },
  {
    id: 5,
    prompt: "What should you do if you receive an email from an unfamiliar address with an urgent request for passwords or suspicious links?",
    category: "Course 3: IT Security",
    options: [
      "Report it as phishing or notify IT Security without clicking links or attachments",
      "Click the attachment immediately to see what it is",
      "Forward the email to your personal email accounts",
      "Reply with your corporate credentials"
    ],
    correctIndex: 0,
    explanation: "Never open suspicious links or attachments; immediately report suspicious emails to the IT security team."
  },
  {
    id: 6,
    prompt: "If you encounter a technical blocker that halts your sprint task for more than 2 hours, what is the best practice?",
    category: "Course 4: Department Intro",
    options: [
      "Communicate early with your mentor, buddy, or team lead on Slack to get unblocked",
      "Wait silently until the sprint ends",
      "Delete your local repository and start over",
      "Mark the task done without testing"
    ],
    correctIndex: 0,
    explanation: "Early communication with your team lead or mentor prevents sprint delays and resolves roadblocks quickly."
  },
  {
    id: 7,
    prompt: "What ergonomic habit helps reduce digital eye fatigue during long computer sessions?",
    category: "Course 5: Health & Safety",
    options: [
      "The 20-20-20 Rule (every 20 minutes, look at an object 20 feet away for 20 seconds)",
      "Staring continuously at the monitor without blinking for 8 hours",
      "Working in total darkness with maximum monitor brightness",
      "Never taking breaks throughout the day"
    ],
    correctIndex: 0,
    explanation: "The 20-20-20 rule relaxes the focusing muscles in your eyes and prevents digital eye strain."
  },
  {
    id: 8,
    prompt: "Under GDPR and HexaLearn confidentiality covenants, which action is strictly prohibited?",
    category: "Course 6: Data Privacy",
    options: [
      "Pasting proprietary client source code or contracts into unapproved public AI tools",
      "Reviewing source code with your designated tech lead",
      "Writing automated unit tests for software modules",
      "Committing code to designated enterprise git repositories"
    ],
    correctIndex: 0,
    explanation: "Proprietary client source code, credentials, and PII must never be pasted into public third-party AI services."
  },
  {
    id: 9,
    prompt: "What does psychological safety mean in an agile, cross-functional engineering team?",
    category: "Course 7: Diversity & Inclusion",
    options: [
      "A shared belief that team members can speak up, ask questions, and share ideas without fear of humiliation",
      "Never communicating with colleagues during the workday",
      "Only allowing senior executives to speak in meetings",
      "Avoiding any code reviews or constructive critiques"
    ],
    correctIndex: 0,
    explanation: "Psychological safety allows every teammate to contribute their perspective and learn from mistakes without fear."
  },
  {
    id: 10,
    prompt: "Under HexaLearn's Code of Conduct and Anti-Harassment (POSH) policy, what standard is upheld?",
    category: "Course 9: Code of Conduct",
    options: [
      "Zero-tolerance for harassment or bullying, with confidential redressal and strict anti-retaliation protection",
      "Ignoring employee grievances",
      "Encouraging retaliation against team members who report concerns",
      "Only investigating complaints that occur outside business hours"
    ],
    correctIndex: 0,
    explanation: "HexaLearn maintains a zero-tolerance policy against harassment and protects anyone reporting concerns in good faith."
  }
];

const AssessmentPage = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const {
    getCourseStatus,
    completedCount,
    totalCourses,
    isFinalAssessmentUnlocked,
    currentActiveCourseId,
    finalAssessmentCompleted,
    finalAssessmentScore,
    finalAssessmentPassed,
    recordFinalAssessmentResult
  } = useCourseProgress();

  // Active quiz state
  const [isQuizActive, setIsQuizActive] = useState(false);
  const [currentQuestionIdx, setCurrentQuestionIdx] = useState(0);
  const [answers, setAnswers] = useState<Record<number, number>>({});
  const [flagged, setFlagged] = useState<Record<number, boolean>>({});
  const [showConfirm, setShowConfirm] = useState(false);
  const [quizFinished, setQuizFinished] = useState(false);
  const [computedScore, setComputedScore] = useState<number | null>(finalAssessmentScore);
  const [hasPassed, setHasPassed] = useState<boolean>(finalAssessmentPassed);

  // Modal player state if user wants to review a course
  const [reviewCourseId, setReviewCourseId] = useState<number | null>(null);

  // Before unload warning during active quiz
  useEffect(() => {
    if (!isQuizActive || quizFinished) return;
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "You have an active assessment in progress. Are you sure you want to leave?";
      return e.returnValue;
    };
    window.addEventListener("beforeunload", handleBeforeUnload);
    return () => window.removeEventListener("beforeunload", handleBeforeUnload);
  }, [isQuizActive, quizFinished]);

  const handleStartFinalAssessment = () => {
    if (!isFinalAssessmentUnlocked) {
      toast({
        title: "Assessment Locked",
        description: `Complete all 10 induction courses first. (${completedCount}/10 completed)`,
      });
      return;
    }
    setIsQuizActive(true);
    setCurrentQuestionIdx(0);
    setAnswers({});
    setFlagged({});
    setQuizFinished(false);
    setComputedScore(null);
  };

  const handleAnswerSelect = (questionId: number, optionIdx: number) => {
    setAnswers((prev) => ({ ...prev, [questionId]: optionIdx }));
  };

  const toggleFlag = (questionId: number) => {
    setFlagged((prev) => ({ ...prev, [questionId]: !prev[questionId] }));
  };

  const handleSubmitQuiz = () => {
    setShowConfirm(false);
    
    // Calculate score
    let correct = 0;
    FINAL_ASSESSMENT_QUESTIONS.forEach((q) => {
      if (answers[q.id] === q.correctIndex) {
        correct++;
      }
    });

    const scorePct = Math.round((correct / FINAL_ASSESSMENT_QUESTIONS.length) * 100);
    const passed = scorePct >= 75;

    setComputedScore(scorePct);
    setHasPassed(passed);
    setQuizFinished(true);
    setIsQuizActive(false);

    recordFinalAssessmentResult(scorePct, passed);

    // Fire-and-forget sync to backend API if available
    try {
      apiPost(`/attempts/submit.php`, {
        assessment_id: 1,
        score: scorePct,
        max_score: 100,
        is_passed: passed ? 1 : 0
      }).catch(() => {});
    } catch {}
  };

  const currentQ = FINAL_ASSESSMENT_QUESTIONS[currentQuestionIdx];
  const answeredCount = Object.keys(answers).length;
  const fullName = user ? (`${user.firstname || ''} ${user.lastname || ''}`.trim() || user.username) : "HexaLearn Teammate";

  // =========================================================================
  // VIEW 1: FINAL ASSESSMENT IS LOCKED (EMPLOYEE HAS NOT COMPLETED 10 COURSES)
  // =========================================================================
  if (!isFinalAssessmentUnlocked) {
    return (
      <Layout>
        <div className="space-y-6 pb-16 max-w-5xl mx-auto">
          
          {/* Locked Hero Card in HexaOne Style */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-8 sm:p-10 shadow-sm text-center space-y-4">
            <div className="mx-auto h-16 w-16 rounded-full bg-amber-50 border-2 border-amber-300 flex items-center justify-center text-amber-600 shadow-sm">
              <Lock className="h-8 w-8" />
            </div>

            <div className="space-y-1.5 max-w-xl mx-auto">
              <span className="text-[10px] font-black uppercase tracking-wider bg-amber-100 text-amber-800 px-3 py-1 rounded-full">
                🔒 FINAL ASSESSMENT LOCKED
              </span>
              <h1 className="text-2xl sm:text-3xl font-black text-slate-800 tracking-tight pt-1">
                Complete All 10 Courses to Unlock
              </h1>
              <p className="text-xs sm:text-sm text-slate-500 font-medium leading-relaxed">
                As per HexaLearn onboarding guidelines, individual course quizzes are not required. You take one comprehensive assessment once all 10 courses have been completed.
              </p>
            </div>

            {/* Progress Bar towards unlocking */}
            <div className="max-w-md mx-auto space-y-1.5 pt-2">
              <div className="flex items-center justify-between text-xs font-bold text-slate-700">
                <span>Induction Progress</span>
                <span className="text-[#00A389]">{completedCount} of {totalCourses} Courses Completed</span>
              </div>
              <div className="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden">
                <div 
                  className="h-full bg-[#00A389] rounded-full transition-all duration-300"
                  style={{ width: `${(completedCount / totalCourses) * 100}%` }}
                />
              </div>
              <span className="text-[11px] text-slate-400 font-medium block">
                {totalCourses - completedCount} more course{totalCourses - completedCount > 1 ? 's' : ''} to unlock your assessment
              </span>
            </div>

            <div className="pt-3 flex flex-wrap items-center justify-center gap-3">
              <Button
                className="bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs uppercase tracking-wider px-6 h-10 rounded-xl shadow-sm"
                onClick={() => setReviewCourseId(currentActiveCourseId)}
              >
                <Play className="h-3.5 w-3.5 mr-1.5 fill-current" />
                Continue Course {currentActiveCourseId}: {COURSES_DATA.find(c => c.id === currentActiveCourseId)?.title}
              </Button>
              <Button
                variant="outline"
                className="border-slate-200 text-slate-600 hover:bg-slate-50 font-bold text-xs h-10 rounded-xl"
                onClick={() => navigate('/dashboard')}
              >
                View Dashboard Roadmap
              </Button>
            </div>
          </div>

          {/* List of 10 Courses with Locked / Available / Completed Badges */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm space-y-4">
            <div>
              <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <ListChecks className="h-4 w-4 text-[#00A389]" />
                Induction Courses Prerequisite Checklist
              </h3>
              <p className="text-xs text-slate-500 mt-0.5 font-medium">
                Review your course progression status below. Courses unlock sequentially as you reach the last slide.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
              {COURSES_DATA.map((course) => {
                const status = getCourseStatus(course.id);
                const isDone = status === 'completed';
                const isReady = status === 'available';
                return (
                  <div
                    key={course.id}
                    className={`p-3.5 rounded-xl border flex items-center justify-between gap-3 transition-all ${
                      isDone
                        ? 'bg-emerald-50/60 border-emerald-200'
                        : isReady
                        ? 'bg-white border-[#00A389] ring-1 ring-[#00A389]/20 shadow-sm'
                        : 'bg-slate-50/70 border-slate-200/60 opacity-60'
                    }`}
                  >
                    <div className="flex items-center gap-3 min-w-0">
                      <div className={`h-8 w-8 rounded-full flex items-center justify-center font-bold text-xs shrink-0 ${
                        isDone
                          ? 'bg-[#00A389] text-white'
                          : isReady
                          ? 'bg-[#00A389] text-white animate-pulse'
                          : 'bg-slate-200 text-slate-400'
                      }`}>
                        {isDone ? <CheckCircle className="h-4 w-4" /> : isReady ? <Play className="h-3.5 w-3.5 ml-0.5 fill-current" /> : <Lock className="h-3.5 w-3.5" />}
                      </div>
                      <div className="min-w-0">
                        <h4 className="text-xs font-bold text-slate-800 truncate">
                          {course.id}. {course.title}
                        </h4>
                        <span className="text-[10px] text-slate-400 font-semibold uppercase">
                          {course.category} • {course.duration}
                        </span>
                      </div>
                    </div>

                    <div className="shrink-0">
                      {isDone ? (
                        <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                          Completed
                        </span>
                      ) : isReady ? (
                        <Button 
                          size="sm" 
                          className="h-7 text-xs font-bold bg-[#00A389] hover:bg-[#008f77] text-white rounded-lg px-3"
                          onClick={() => setReviewCourseId(course.id)}
                        >
                          Start
                        </Button>
                      ) : (
                        <span className="text-[10px] text-slate-400 font-semibold bg-slate-100 px-2 py-0.5 rounded-full">
                          Locked
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Modal player for launching courses */}
          <CoursePlayerModal
            courseId={reviewCourseId}
            isOpen={reviewCourseId !== null}
            onClose={() => setReviewCourseId(null)}
            onOpenCourse={(id) => setReviewCourseId(id)}
          />

        </div>
      </Layout>
    );
  }

  // =========================================================================
  // VIEW 2: ACTIVE QUIZ IN PROGRESS
  // =========================================================================
  if (isQuizActive) {
    return (
      <Layout>
        <div className="max-w-4xl mx-auto space-y-5 pb-16">
          
          {/* Header Bar */}
          <div className="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <span className="text-[10px] font-black uppercase tracking-wider text-[#00A389] bg-[#E6F7F4] px-2.5 py-0.5 rounded-full">
                HEXALEARN ONBOARDING FINAL ASSESSMENT
              </span>
              <h2 className="text-xl font-black text-slate-800 tracking-tight mt-1">
                Question {currentQuestionIdx + 1} of {FINAL_ASSESSMENT_QUESTIONS.length}
              </h2>
            </div>
            
            <div className="flex items-center gap-2.5">
              <Button
                variant="outline"
                size="sm"
                onClick={() => toggleFlag(currentQ.id)}
                className={`text-xs font-bold gap-1 rounded-xl ${
                  flagged[currentQ.id] ? "bg-amber-50 border-amber-300 text-amber-800" : "border-slate-200"
                }`}
              >
                <Flag className="h-3.5 w-3.5 text-amber-600" />
                {flagged[currentQ.id] ? "Flagged" : "Flag Question"}
              </Button>
              <Button
                size="sm"
                className="bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs rounded-xl shadow-sm"
                onClick={() => setShowConfirm(true)}
              >
                Submit Assessment
              </Button>
            </div>
          </div>

          {/* Question Navigation Grid Pills */}
          <div className="flex flex-wrap items-center gap-1.5 p-3 rounded-2xl bg-white border border-slate-200 shadow-sm">
            {FINAL_ASSESSMENT_QUESTIONS.map((q, idx) => {
              const isAnswered = answers[q.id] !== undefined;
              const isCurrent = idx === currentQuestionIdx;
              const isFlag = !!flagged[q.id];
              return (
                <button
                  key={q.id}
                  onClick={() => setCurrentQuestionIdx(idx)}
                  className={`h-8 w-8 rounded-lg text-xs font-bold transition-all flex items-center justify-center relative ${
                    isCurrent
                      ? "bg-[#00A389] text-white shadow-sm ring-2 ring-[#00A389]/30"
                      : isAnswered
                      ? "bg-[#E6F7F4] text-[#00A389] border border-[#00A389]/30"
                      : "bg-slate-50 border border-slate-200 text-slate-500 hover:bg-slate-100"
                  }`}
                  title={`Question ${idx + 1}`}
                >
                  {idx + 1}
                  {isFlag && (
                    <span className="absolute -top-1 -right-1 h-2 w-2 rounded-full bg-amber-500 ring-2 ring-white" />
                  )}
                </button>
              );
            })}
            <div className="ml-auto text-xs text-slate-500 font-semibold px-2">
              {answeredCount} / {FINAL_ASSESSMENT_QUESTIONS.length} Answered
            </div>
          </div>

          {/* Active Question Card */}
          <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
            <div className="p-5 border-b border-slate-100 bg-slate-50/60 flex items-center justify-between">
              <span className="text-xs font-bold text-[#00A389] bg-[#E6F7F4] px-2.5 py-0.5 rounded-full">
                {currentQ.category}
              </span>
              <span className="text-xs font-semibold text-slate-400">
                Single Choice • 10 Points
              </span>
            </div>
            <div className="p-6 sm:p-8 space-y-4">
              <h3 className="text-base sm:text-lg font-bold text-slate-800 leading-relaxed">
                {currentQ.prompt}
              </h3>

              <RadioGroup
                value={answers[currentQ.id] !== undefined ? String(answers[currentQ.id]) : undefined}
                onValueChange={(val) => handleAnswerSelect(currentQ.id, Number(val))}
                className="space-y-2.5 pt-2"
              >
                {currentQ.options.map((optionText, optIdx) => {
                  const isSelected = answers[currentQ.id] === optIdx;
                  return (
                    <div
                      key={optIdx}
                      onClick={() => handleAnswerSelect(currentQ.id, optIdx)}
                      className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                        isSelected
                          ? "bg-[#E6F7F4] border-[#00A389] text-slate-800 font-semibold ring-1 ring-[#00A389]/30"
                          : "bg-white hover:bg-slate-50 border-slate-200 text-slate-700"
                      }`}
                    >
                      <RadioGroupItem value={String(optIdx)} id={`q-${currentQ.id}-opt-${optIdx}`} className="mt-0.5" />
                      <Label htmlFor={`q-${currentQ.id}-opt-${optIdx}`} className="text-xs sm:text-sm font-medium cursor-pointer leading-relaxed flex-1">
                        {optionText}
                      </Label>
                    </div>
                  );
                })}
              </RadioGroup>
            </div>
          </div>

          {/* Footer Controls */}
          <div className="flex items-center justify-between gap-3 pt-1">
            <Button
              variant="outline"
              disabled={currentQuestionIdx === 0}
              onClick={() => setCurrentQuestionIdx((p) => p - 1)}
              className="text-xs font-bold border-slate-200 rounded-xl"
            >
              <ChevronLeft className="h-3.5 w-3.5 mr-1" /> Previous Question
            </Button>

            {currentQuestionIdx < FINAL_ASSESSMENT_QUESTIONS.length - 1 ? (
              <Button
                onClick={() => setCurrentQuestionIdx((p) => p + 1)}
                className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl"
              >
                Next Question <ChevronRight className="h-3.5 w-3.5 ml-1" />
              </Button>
            ) : (
              <Button
                onClick={() => setShowConfirm(true)}
                className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl shadow-sm"
              >
                Finish &amp; Submit Assessment
              </Button>
            )}
          </div>

          {/* Confirmation Submit Dialog */}
          <Dialog open={showConfirm} onOpenChange={setShowConfirm}>
            <DialogContent className="max-w-md rounded-2xl bg-white border border-slate-200">
              <DialogHeader>
                <DialogTitle className="text-lg font-black text-slate-800">Submit Final Assessment?</DialogTitle>
                <DialogDescription className="text-xs sm:text-sm text-slate-500 mt-1">
                  You have answered {answeredCount} of {FINAL_ASSESSMENT_QUESTIONS.length} questions.
                  {answeredCount < FINAL_ASSESSMENT_QUESTIONS.length && (
                    <span className="block text-amber-600 font-bold mt-1">
                      Warning: You have {FINAL_ASSESSMENT_QUESTIONS.length - answeredCount} unanswered question(s).
                    </span>
                  )}
                </DialogDescription>
              </DialogHeader>
              <DialogFooter className="flex gap-2 mt-4">
                <Button variant="outline" onClick={() => setShowConfirm(false)} className="text-xs font-bold rounded-xl">
                  Continue Reviewing
                </Button>
                <Button onClick={handleSubmitQuiz} className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl">
                  Yes, Submit Assessment
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

        </div>
      </Layout>
    );
  }

  // =========================================================================
  // VIEW 3: COMPLETED RESULTS & CERTIFICATE OR START PORTAL (WHEN UNLOCKED)
  // =========================================================================
  return (
    <Layout>
      <div className="space-y-6 pb-16 max-w-5xl mx-auto">
        
        {/* Results Banner if completed */}
        {quizFinished && computedScore !== null && (
          <div className={`p-8 rounded-2xl border text-center space-y-3 shadow-sm ${
            hasPassed
              ? "bg-emerald-50/80 border-emerald-300 text-emerald-900"
              : "bg-rose-50/80 border-rose-300 text-rose-900"
          }`}>
            <div className={`mx-auto h-16 w-16 rounded-full flex items-center justify-center shadow-sm ${
              hasPassed ? "bg-emerald-100 text-emerald-700" : "bg-rose-100 text-rose-700"
            }`}>
              {hasPassed ? <Trophy className="h-8 w-8 text-amber-500" /> : <AlertCircle className="h-8 w-8" />}
            </div>

            <div className="space-y-1">
              <span className={`text-[10px] font-black uppercase tracking-wider px-2.5 py-0.5 rounded-full ${
                hasPassed ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"
              }`}>
                {hasPassed ? "ASSESSMENT PASSED (75% REQUIRED)" : "DID NOT PASS (75% REQUIRED)"}
              </span>
              <h2 className="text-2xl font-black text-slate-800 pt-1">
                {hasPassed ? "Congratulations, Onboarding Certified!" : "Assessment Needs Review"}
              </h2>
              <p className="text-xs sm:text-sm text-slate-600 max-w-md mx-auto">
                {hasPassed
                  ? `You achieved an outstanding score of ${computedScore}%. Your onboarding induction program is officially complete!`
                  : `You scored ${computedScore}%. A passing grade of 75% is required. Please review the course slides and retake the assessment.`}
              </p>
            </div>

            <div className="pt-2 flex justify-center gap-2.5">
              <Button
                onClick={handleStartFinalAssessment}
                className="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-xs font-bold rounded-xl"
              >
                <RotateCcw className="h-3.5 w-3.5 mr-1.5" /> Retake Assessment
              </Button>
              <Button
                onClick={() => navigate('/dashboard')}
                className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl shadow-sm"
              >
                Return to Dashboard
              </Button>
            </div>
          </div>
        )}

        {/* Certificate Card when passed */}
        {hasPassed && (
          <div className="bg-white rounded-2xl border-2 border-amber-300/80 p-8 sm:p-12 text-center text-slate-800 shadow-md space-y-5 relative overflow-hidden">
            <div className="absolute top-0 left-0 right-0 h-2 bg-gradient-to-r from-amber-400 via-[#00A389] to-amber-400" />
            
            <div className="flex items-center justify-center gap-2">
              <div className="h-7 w-7 rounded-lg bg-[#00A389] flex items-center justify-center text-white">
                <GraduationCap className="h-4 w-4" />
              </div>
              <span className="text-lg font-black tracking-tight text-slate-800">HexaLearn Solutions</span>
            </div>

            <div className="space-y-1.5">
              <span className="text-[10px] font-black tracking-widest text-[#00A389] uppercase bg-[#E6F7F4] px-3 py-1 rounded-full">
                CERTIFICATE OF ONBOARDING INDUCTION COMPLETION
              </span>
              <p className="text-xs text-slate-400 pt-2">This certifies that</p>
              <h3 className="text-2xl sm:text-3xl font-black text-slate-800">
                {fullName}
              </h3>
              <p className="text-xs sm:text-sm text-slate-600 max-w-lg mx-auto pt-1 font-medium">
                Has successfully completed all 10 HexaLearn Induction Courses and achieved a score of <strong>{computedScore}%</strong> on the Final Comprehensive Onboarding Assessment.
              </p>
            </div>

            <div className="flex flex-wrap items-center justify-around border-t border-b border-slate-100 py-3 text-xs font-bold text-slate-600">
              <div>
                <span className="block text-[10px] text-slate-400 uppercase">DATE AWARDED</span>
                <span>{new Date().toLocaleDateString()}</span>
              </div>
              <div>
                <span className="block text-[10px] text-slate-400 uppercase">STATUS</span>
                <span className="text-[#00A389]">Induction Verified</span>
              </div>
              <div>
                <span className="block text-[10px] text-slate-400 uppercase">ISSUED BY</span>
                <span>HexaLearn People &amp; Culture</span>
              </div>
            </div>
          </div>
        )}

        {/* Main Final Assessment Overview Showcase */}
        {!quizFinished && (
          <div className="bg-white border border-slate-200/80 rounded-2xl p-6 sm:p-8 shadow-sm space-y-5">
            <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-5">
              <div className="space-y-2 max-w-xl">
                <span className="text-[10px] font-black uppercase tracking-wider text-[#00A389] bg-[#E6F7F4] px-2.5 py-0.5 rounded-full inline-block">
                  ELIGIBLE &amp; READY TO START
                </span>
                <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-slate-800">
                  HexaLearn Onboarding Final Assessment
                </h1>
                <p className="text-xs sm:text-sm text-slate-500 font-medium leading-relaxed">
                  You have completed all 10 induction courses! This comprehensive assessment verifies your understanding of our company vision, handbook policies, IT cybersecurity, data privacy, agile teamwork, and ethical guidelines.
                </p>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center shrink-0 min-w-[180px]">
                <div className="text-2xl font-black text-slate-800">10</div>
                <span className="text-[10px] font-bold text-[#00A389] uppercase">QUESTIONS</span>
                <div className="mt-1 text-[11px] text-slate-400">
                  75% passing mark required
                </div>
              </div>
            </div>

            {/* Assessment Details Bar */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
              <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center gap-3">
                <Clock className="h-5 w-5 text-[#00A389]" />
                <div>
                  <div className="text-xs font-bold text-slate-700">Duration</div>
                  <div className="text-[11px] text-slate-400">15 – 20 Minutes (Untimed)</div>
                </div>
              </div>
              <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center gap-3">
                <Award className="h-5 w-5 text-amber-500" />
                <div>
                  <div className="text-xs font-bold text-slate-700">Passing Score</div>
                  <div className="text-[11px] text-slate-400">75% (8 of 10 correct)</div>
                </div>
              </div>
              <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center gap-3">
                <RotateCcw className="h-5 w-5 text-emerald-600" />
                <div>
                  <div className="text-xs font-bold text-slate-700">Attempts</div>
                  <div className="text-[11px] text-slate-400">Multiple attempts permitted</div>
                </div>
              </div>
            </div>

            <div className="pt-2">
              <Button
                className="bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs uppercase tracking-wider px-6 h-11 rounded-xl shadow-sm"
                onClick={handleStartFinalAssessment}
              >
                <GraduationCap className="h-4 w-4 mr-1.5" />
                Start Final Assessment Now
              </Button>
            </div>
          </div>
        )}

      </div>
    </Layout>
  );
};

export default AssessmentPage;