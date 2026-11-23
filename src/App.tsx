import { lazy, Suspense } from "react";
import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import ScrollToTop from "./components/ScrollToTop";
import Index from "./pages/Index";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import ModulesPage from "./pages/ModulesPage";
import CourseDetailPage from "./pages/CourseDetailPage";
import ScormReportPage from "./pages/ScormReportPage";
import AttemptHistoryPage from "./pages/AttemptHistoryPage";
import ProtectedRoute from "./components/ProtectedRoute";
import { useAuth } from "@/hooks/useAuth";
import InductionContent from "./pages/InductionContent";
import AssessmentPage from "./pages/AssessmentPage";
import ScormPlayerPage from "./pages/ScormPlayerPage";
import NotFound from "./pages/NotFound";

const AdminDashboard = lazy(() => import("./pages/AdminDashboard"));
const AdminEmployees = lazy(() => import("./pages/AdminEmployees"));
const AdminContent = lazy(() => import("./pages/AdminContent"));
const AdminQuizzes = lazy(() => import("./pages/AdminQuizzes"));
const AdminSettings = lazy(() => import("./pages/AdminSettings"));
const AdminQuestions = lazy(() => import("./pages/AdminQuestions"));
const AdminPools = lazy(() => import("./pages/AdminPools"));
const AdminAnalytics = lazy(() => import("./pages/AdminAnalytics"));
const AdminEmployeeReport = lazy(() => import("./pages/AdminEmployeeReport"));
const AdminEmails = lazy(() => import("./pages/AdminEmails"));
const AdminApiUsage = lazy(() => import("./pages/AdminApiUsage"));
const AdminAssessmentResults = lazy(() => import("./pages/AdminAssessmentResults"));
const AdminCertificates = lazy(() => import("./pages/AdminCertificates"));
const AdminDeadlineLocked = lazy(() => import("./pages/AdminDeadlineLocked"));

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60 * 1000,
      gcTime: 10 * 60 * 1000,
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
});

function AdminGate({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '40vh' }}>
        <div style={{ width: 40, height: 40, borderRadius: '50%', border: '4px solid #e5e7eb', borderTopColor: '#3b82f6', animation: 'spin 1s linear infinite' }} />
      </div>
    );
  }
  const allowed = user && (['26', '64', '205'].includes(String(user.uid)) || String(user.user_role) === '1');
  if (!allowed) {
    return <div style={{ padding: 24 }}>Unauthorized</div>;
  }
  return <>{children}</>;
}

const App = () => (
  <QueryClientProvider client={queryClient}>
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem storageKey="theme">
      <TooltipProvider>
        <Toaster />
        <Sonner />
        <BrowserRouter basename={(import.meta.env.VITE_ROUTER_BASENAME as string) || (typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis/onboard') ? '/internal/corhis/onboard' : '/onboard')}>
          <ScrollToTop />
          <Suspense fallback={<div className="min-h-screen flex items-center justify-center text-sm text-slate-500">Loading...</div>}>
          <Routes>
          <Route path="/" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
          <Route path="/login" element={<Login />} />
          <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />

          {/* Dedicated Employee Pages & Separate Routes */}
          <Route path="/modules" element={<ProtectedRoute><ModulesPage /></ProtectedRoute>} />
          <Route path="/modules/:id" element={<ProtectedRoute><CourseDetailPage /></ProtectedRoute>} />
          <Route path="/courses/:id" element={<ProtectedRoute><CourseDetailPage /></ProtectedRoute>} />
          <Route path="/course/:id" element={<ProtectedRoute><CourseDetailPage /></ProtectedRoute>} />
          <Route path="/roadmap" element={<ProtectedRoute><ModulesPage /></ProtectedRoute>} />
          <Route path="/dashboard/roadmap" element={<ProtectedRoute><ModulesPage /></ProtectedRoute>} />
          <Route path="/dashboard/modules" element={<ProtectedRoute><ModulesPage /></ProtectedRoute>} />
          <Route path="/dashboard/modules/:id" element={<ProtectedRoute><CourseDetailPage /></ProtectedRoute>} />

          <Route path="/reports" element={<Navigate to="/modules" replace />} />
          <Route path="/scorm-report" element={<Navigate to="/modules" replace />} />
          <Route path="/dashboard/report" element={<Navigate to="/modules" replace />} />
          <Route path="/dashboard/reports" element={<Navigate to="/modules" replace />} />
          <Route path="/dashboard/scorm-report" element={<Navigate to="/modules" replace />} />

          <Route path="/attempt-history" element={<ProtectedRoute><AttemptHistoryPage /></ProtectedRoute>} />
          <Route path="/dashboard/attempt-history" element={<ProtectedRoute><AttemptHistoryPage /></ProtectedRoute>} />

          {/* Dedicated Full-Screen Player Route in New Tab (Iframe with 100% hidden Moodle URL) */}
          <Route path="/play" element={<ProtectedRoute><ScormPlayerPage /></ProtectedRoute>} />
          <Route path="/player" element={<ProtectedRoute><ScormPlayerPage /></ProtectedRoute>} />

          <Route
            path="/admin"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminDashboard />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/employees"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminEmployees />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/employees/:uid/report"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminEmployeeReport />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/employee-report/:uid"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminEmployeeReport />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/employee-report"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminEmployeeReport />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/assessments"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminAssessmentResults />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/deadline-locked-employees"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminDeadlineLocked />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route path="/admin/deadline-lockouts" element={<Navigate to="/admin/deadline-locked-employees" replace />} />
          <Route path="/admin/locked-employees" element={<Navigate to="/admin/deadline-locked-employees" replace />} />
          <Route
            path="/admin/courses"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminContent />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/content"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminContent />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/reports"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminAnalytics />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/certificates"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminCertificates />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route path="/admin/certificate-downloads" element={<Navigate to="/admin/certificates" replace />} />
          <Route path="/admin/certificate-history" element={<Navigate to="/admin/certificates" replace />} />
          <Route
            path="/admin/emails"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminEmails />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route path="/admin/email" element={<Navigate to="/admin/emails" replace />} />
          <Route path="/admin/email-settings" element={<Navigate to="/admin/emails" replace />} />
          <Route
            path="/admin/quizzes"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminQuizzes />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/questions"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminQuestions />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/pools"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminPools />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/analytics"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminAnalytics />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/settings"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminSettings />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/api-usage"
            element={
              <ProtectedRoute>
                <AdminGate>
                  <AdminApiUsage />
                </AdminGate>
              </ProtectedRoute>
            }
          />
          <Route path="/induction" element={<Navigate to="/dashboard" replace />} />
          <Route path="/assessments" element={<Navigate to="/dashboard" replace />} />
          {/* ADD ALL CUSTOM ROUTES ABOVE THE CATCH-ALL "*" ROUTE */}
          <Route path="*" element={<NotFound />} />
          </Routes>
          </Suspense>
        </BrowserRouter>
      </TooltipProvider>
    </ThemeProvider>
  </QueryClientProvider>
);

export default App;
