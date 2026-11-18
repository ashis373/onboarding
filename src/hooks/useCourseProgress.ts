import { useState, useEffect, useCallback, useMemo } from 'react';
import { COURSES_DATA, CourseDefinition } from '@/data/coursesData';
import { apiPost } from '@/lib/api';

const STORAGE_KEY = 'hex_onboard_course_progress_v2';

export interface SingleCourseState {
  completed: boolean;
  currentSlide: number;
  totalSlides: number;
  completedAt?: string | null;
}

export interface StoredProgressState {
  courses: Record<number, SingleCourseState>;
  finalAssessmentCompleted?: boolean;
  finalAssessmentScore?: number | null;
  finalAssessmentPassed?: boolean;
}

// Generate default clean initial state
const getInitialProgressState = (): StoredProgressState => {
  const courses: Record<number, SingleCourseState> = {};
  COURSES_DATA.forEach((course) => {
    courses[course.id] = {
      completed: false,
      currentSlide: 1,
      totalSlides: course.slides.length || 5,
      completedAt: null,
    };
  });
  return {
    courses,
    finalAssessmentCompleted: false,
    finalAssessmentScore: null,
    finalAssessmentPassed: false,
  };
};

export function useCourseProgress() {
  const [state, setState] = useState<StoredProgressState>(() => {
    try {
      if (typeof window !== 'undefined') {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          const parsed = JSON.parse(stored);
          if (parsed && typeof parsed === 'object' && parsed.courses) {
            // Ensure all 10 courses exist in parsed state
            const merged = getInitialProgressState();
            Object.keys(merged.courses).forEach((idKey) => {
              const id = Number(idKey);
              if (parsed.courses[id]) {
                merged.courses[id] = {
                  ...merged.courses[id],
                  ...parsed.courses[id],
                };
              }
            });
            merged.finalAssessmentCompleted = !!parsed.finalAssessmentCompleted;
            merged.finalAssessmentScore = parsed.finalAssessmentScore ?? null;
            merged.finalAssessmentPassed = !!parsed.finalAssessmentPassed;
            return merged;
          }
        }
      }
    } catch (_e) {
      // Fallback
    }
    return getInitialProgressState();
  });

  // Save to localStorage whenever state changes
  useEffect(() => {
    try {
      if (typeof window !== 'undefined') {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
      }
    } catch (_e) {}
  }, [state]);

  // Listen for storage events or custom events across components
  useEffect(() => {
    const handleProgressUpdate = () => {
      try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          setState(JSON.parse(stored));
        }
      } catch {}
    };

    window.addEventListener('onboard-progress-updated', handleProgressUpdate);
    window.addEventListener('storage', handleProgressUpdate);
    return () => {
      window.removeEventListener('onboard-progress-updated', handleProgressUpdate);
      window.removeEventListener('storage', handleProgressUpdate);
    };
  }, []);

  // Determine status of any course
  const getCourseStatus = useCallback(
    (courseId: number): 'completed' | 'available' | 'locked' => {
      const courseState = state.courses[courseId];
      if (courseState?.completed) return 'completed';

      // Course 1 is always available (never locked)
      if (courseId === 1) return 'available';

      // Courses 2-10 unlock when the previous course is completed
      const prevCourseId = courseId - 1;
      const prevCourse = state.courses[prevCourseId];
      if (prevCourse?.completed) {
        return 'available';
      }

      return 'locked';
    },
    [state]
  );

  // Jump to specific slide in course
  const setCourseSlide = useCallback((courseId: number, slideNumber: number) => {
    setState((prev) => {
      const current = prev.courses[courseId] || {
        completed: false,
        currentSlide: 1,
        totalSlides: 5,
      };
      const total = current.totalSlides || 5;
      const clamped = Math.max(1, Math.min(total, slideNumber));
      const nextCourses = {
        ...prev.courses,
        [courseId]: {
          ...current,
          currentSlide: clamped,
        },
      };
      const nextState = { ...prev, courses: nextCourses };
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(nextState));
        window.dispatchEvent(new Event('onboard-progress-updated'));
      } catch {}
      return nextState;
    });
  }, []);

  // Complete a course: marks completed and unlocks next course
  const completeCourse = useCallback((courseId: number) => {
    setState((prev) => {
      const current = prev.courses[courseId] || {
        completed: false,
        currentSlide: 5,
        totalSlides: 5,
      };
      const nextCourses = {
        ...prev.courses,
        [courseId]: {
          ...current,
          completed: true,
          currentSlide: current.totalSlides,
          completedAt: new Date().toISOString(),
        },
      };

      const nextState = { ...prev, courses: nextCourses };
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(nextState));
        window.dispatchEvent(new Event('onboard-progress-updated'));
      } catch {}

      // Fire-and-forget sync to backend API (does not block client if server not active)
      try {
        apiPost(`/modules/complete.php`, { module_id: courseId }).catch(() => {});
        apiPost(`/modules/progress.php`, { module_id: courseId, progress_pct: 100, is_completed: 1 }).catch(() => {});
      } catch {}

      return nextState;
    });
  }, []);

  // Record Final Assessment Result
  const recordFinalAssessmentResult = useCallback((score: number, passed: boolean) => {
    setState((prev) => {
      const nextState: StoredProgressState = {
        ...prev,
        finalAssessmentCompleted: true,
        finalAssessmentScore: score,
        finalAssessmentPassed: passed,
      };
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(nextState));
        window.dispatchEvent(new Event('onboard-progress-updated'));
      } catch {}
      return nextState;
    });
  }, []);

  // Reset all progress back to starting state (Course 1 available, 2-10 locked)
  const resetAllProgress = useCallback(() => {
    const clean = getInitialProgressState();
    setState(clean);
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(clean));
      window.dispatchEvent(new Event('onboard-progress-updated'));
    } catch {}
  }, []);

  // Computed summary metrics
  const completedCount = useMemo(() => {
    return Object.values(state.courses).filter((c) => c.completed).length;
  }, [state]);

  const totalCourses = COURSES_DATA.length; // 10
  const progressPercentage = Math.round((completedCount / totalCourses) * 100);
  const isFinalAssessmentUnlocked = completedCount >= totalCourses;

  // Find current active course (first available uncompleted course)
  const currentActiveCourseId = useMemo(() => {
    for (let id = 1; id <= totalCourses; id++) {
      if (!state.courses[id]?.completed) {
        return id;
      }
    }
    return 10;
  }, [state, totalCourses]);

  return {
    coursesState: state.courses,
    getCourseStatus,
    setCourseSlide,
    completeCourse,
    resetAllProgress,
    completedCount,
    totalCourses,
    progressPercentage,
    isFinalAssessmentUnlocked,
    currentActiveCourseId,
    finalAssessmentCompleted: !!state.finalAssessmentCompleted,
    finalAssessmentScore: state.finalAssessmentScore,
    finalAssessmentPassed: !!state.finalAssessmentPassed,
    recordFinalAssessmentResult,
  };
}
