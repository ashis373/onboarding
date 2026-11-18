import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { useToast } from "@/components/ui/use-toast";
import { apiGet, apiPost } from "@/lib/api";
import {
  Lock,
  Unlock,
  ShieldCheck,
  CheckCircle2,
  Clock,
  AlertTriangle,
  History,
  Calendar,
  Sparkles,
  RotateCcw,
  User,
  X,
  Loader2,
  FileText
} from "lucide-react";

interface AdminCourseAccessModalProps {
  isOpen: boolean;
  onClose: () => void;
  employeeUid: number | string | null;
  employeeName?: string;
  onUpdated?: () => void;
}

export const AdminCourseAccessModal = ({
  isOpen,
  onClose,
  employeeUid,
  employeeName,
  onUpdated,
}: AdminCourseAccessModalProps) => {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [activeTab, setActiveTab] = useState<'courses' | 'audit'>('courses');
  const [unlockModalCourse, setUnlockModalCourse] = useState<any | null>(null);
  const [unlockReason, setUnlockReason] = useState("");
  const [extendingAccess, setExtendingAccess] = useState(false);

  // Fetch real-time course access and overrides for this employee
  const {
    data,
    isLoading,
    refetch,
    isFetching,
  } = useQuery({
    queryKey: ['admin_course_access', employeeUid],
    queryFn: async () => {
      if (!employeeUid) return null;
      const res = await apiGet<any>(`/admin/course_access.php?uid=${employeeUid}`);
      if (res && res.success) return res;
      throw new Error(res?.message || "Failed to load course access details");
    },
    enabled: Boolean(isOpen && employeeUid),
    staleTime: 0,
    gcTime: 5 * 60 * 1000,
  });

  // Unlock course mutation
  const unlockMutation = useMutation({
    mutationFn: async ({
      courseId,
      moduleId,
      cmid,
      courseName,
      reason,
    }: {
      courseId?: number;
      moduleId?: number;
      cmid?: number;
      courseName: string;
      reason: string;
    }) => {
      const res = await apiPost<any>('/admin/course_access.php', {
        action: 'unlock',
        uid: employeeUid,
        course_id: courseId,
        module_id: moduleId,
        cmid: cmid,
        course_name: courseName,
        reason: reason || 'Manager approval granted for relaunch',
      });
      if (res && res.success) return res;
      throw new Error(res?.message || 'Failed to unlock course');
    },
    onSuccess: (res) => {
      toast({
        title: "Course Unlocked",
        description: res?.message || "Course is now unlocked and playable for this employee.",
      });
      setUnlockModalCourse(null);
      setUnlockReason("");
      refetch();
      queryClient.invalidateQueries({ queryKey: ['admin_employees'] });
      queryClient.invalidateQueries({ queryKey: ['admin_employee_report'] });
      if (onUpdated) onUpdated();
    },
    onError: (err: any) => {
      toast({
        title: "Unlock Failed",
        description: err.message || "Failed to save unlock override",
        variant: "destructive",
      });
    },
  });

  // Revoke unlock mutation
  const revokeMutation = useMutation({
    mutationFn: async ({
      overrideId,
      courseId,
      moduleId,
    }: {
      overrideId?: number;
      courseId?: number;
      moduleId?: number;
    }) => {
      const res = await apiPost<any>('/admin/course_access.php', {
        action: 'revoke',
        uid: employeeUid,
        override_id: overrideId,
        course_id: courseId,
        module_id: moduleId,
      });
      if (res && res.success) return res;
      throw new Error(res?.message || 'Failed to revoke override');
    },
    onSuccess: () => {
      toast({
        title: "Override Revoked",
        description: "Course access returned to standard locking rules.",
      });
      refetch();
      queryClient.invalidateQueries({ queryKey: ['admin_employees'] });
      queryClient.invalidateQueries({ queryKey: ['admin_employee_report'] });
      if (onUpdated) onUpdated();
    },
    onError: (err: any) => {
      toast({
        title: "Revoke Failed",
        description: err.message || "Failed to revoke override",
        variant: "destructive",
      });
    },
  });

  // Extend 7-day access window mutation
  const extendAccessMutation = useMutation({
    mutationFn: async (days: number) => {
      const res = await apiPost<any>('/admin/course_access.php', {
        action: 'extend_access',
        uid: employeeUid,
        days: days,
        reason: `Admin extended onboarding access window by ${days} days.`,
      });
      if (res && res.success) return res;
      throw new Error(res?.message || 'Failed to extend access window');
    },
    onSuccess: (res) => {
      toast({
        title: "Access Extended",
        description: res?.message || "Employee access window extended successfully.",
      });
      setExtendingAccess(false);
      refetch();
      queryClient.invalidateQueries({ queryKey: ['admin_employees'] });
      queryClient.invalidateQueries({ queryKey: ['admin_employee_report'] });
      if (onUpdated) onUpdated();
    },
    onError: (err: any) => {
      toast({
        title: "Extension Failed",
        description: err.message || "Could not extend access",
        variant: "destructive",
      });
    },
  });

  const employee = data?.employee;
  const accessWindow = data?.access_window;
  const courses = data?.courses || [];
  const auditLog = data?.audit_log || [];

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent 
        className="max-w-4xl max-h-[90vh] flex flex-col p-0 overflow-hidden bg-slate-50 sm:rounded-3xl border-slate-200 shadow-2xl"
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        {/* Header */}
        <div className="bg-white border-b border-slate-200/80 px-6 py-5">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div className="flex items-center gap-3.5">
              <div className="h-11 w-11 rounded-2xl bg-amber-500/10 text-amber-600 flex items-center justify-center shrink-0 border border-amber-500/20">
                <ShieldCheck className="h-6 w-6 text-amber-600" />
              </div>
              <div>
                <DialogTitle className="text-lg font-black text-slate-800 tracking-tight flex items-center gap-2">
                  Course Access &amp; Relaunch Management
                  <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 text-[10px] font-bold">
                    Admin Override
                  </Badge>
                </DialogTitle>
                <p className="text-xs text-slate-500 mt-0.5 font-medium">
                  Manually unlock any course for individual employees to authorize relaunch or retakes.
                </p>
              </div>
            </div>

            {/* Employee Meta Pill */}
            <div className="flex items-center gap-3 bg-slate-50 border border-slate-200 px-3 py-2 rounded-2xl shrink-0">
              <div className="h-8 w-8 rounded-full bg-[#00A389] text-white flex items-center justify-center font-black text-xs shrink-0 shadow-2xs">
                {employee?.profile_img ? (
                  <img src={employee.profile_img} alt={employee.name} className="h-full w-full rounded-full object-cover" />
                ) : (
                  <span>{(employee?.name || employeeName || 'E').charAt(0).toUpperCase()}</span>
                )}
              </div>
              <div className="text-left min-w-0 pr-1">
                <div className="text-xs font-bold text-slate-800 truncate max-w-[180px]">
                  {employee?.name || employeeName || `Employee #${employeeUid}`}
                </div>
                <div className="text-[10px] text-slate-400 font-mono">
                  UID #{employeeUid} {employee?.moodle_user_id ? `· LMS #${employee?.moodle_user_id}` : ''}
                </div>
              </div>
            </div>
          </div>

          {/* 7-Day Window Status & Quick Extension Bar */}
          <div className="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3 text-xs">
            <div className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-slate-400 shrink-0" />
              <span className="font-semibold text-slate-600">Onboarding Access Window:</span>
              {accessWindow?.is_expired ? (
                <span className="inline-flex items-center gap-1 font-bold text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-full text-[11px]">
                  <AlertTriangle className="h-3 w-3 text-rose-600" />
                  Expired ({accessWindow?.days_elapsed} days elapsed)
                </span>
              ) : accessWindow?.has_extension ? (
                <span className="inline-flex items-center gap-1 font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-full text-[11px]">
                  <Calendar className="h-3 w-3 text-blue-600" />
                  Extended until {accessWindow?.extension_date_fmt}
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full text-[11px]">
                  <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                  Active (Day {accessWindow?.days_elapsed || 1} of 7)
                </span>
              )}
            </div>

            {/* Extension Buttons */}
            <div className="flex items-center gap-1.5 ml-auto">
              <span className="text-[11px] text-slate-400 font-medium mr-1 hidden sm:inline">Extend Access:</span>
              <Button
                variant="outline"
                size="sm"
                disabled={extendAccessMutation.isPending}
                onClick={() => extendAccessMutation.mutate(7)}
                className="h-7 text-[11px] font-bold px-2.5 rounded-lg border-slate-200 text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 cursor-pointer shadow-2xs"
              >
                +7 Days
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={extendAccessMutation.isPending}
                onClick={() => extendAccessMutation.mutate(14)}
                className="h-7 text-[11px] font-bold px-2.5 rounded-lg border-slate-200 text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 cursor-pointer shadow-2xs"
              >
                +14 Days
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={extendAccessMutation.isPending}
                onClick={() => extendAccessMutation.mutate(30)}
                className="h-7 text-[11px] font-bold px-2.5 rounded-lg border-slate-200 text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 cursor-pointer shadow-2xs"
              >
                +30 Days
              </Button>
            </div>
          </div>

          {/* Tab Navigation */}
          <div className="flex items-center gap-2 mt-4">
            <button
              type="button"
              onClick={() => setActiveTab('courses')}
              className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 ${
                activeTab === 'courses'
                  ? 'bg-[#00A389] text-white shadow-2xs'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              <Lock className="h-3.5 w-3.5" />
              <span>Course Access List ({courses.length})</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('audit')}
              className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 ${
                activeTab === 'audit'
                  ? 'bg-[#00A389] text-white shadow-2xs'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              <History className="h-3.5 w-3.5" />
              <span>Audit History ({auditLog.length})</span>
            </button>
            {isFetching && (
              <span className="text-[11px] text-slate-400 flex items-center gap-1 ml-auto">
                <Loader2 className="h-3 w-3 animate-spin text-[#00A389]" />
                Refreshing...
              </span>
            )}
          </div>
        </div>

        {/* Scrollable Body */}
        <div className="p-6 overflow-y-auto flex-1 space-y-4">
          {isLoading ? (
            <div className="py-16 text-center space-y-3">
              <Loader2 className="h-8 w-8 text-[#00A389] animate-spin mx-auto" />
              <p className="text-xs text-slate-500 font-medium">Loading course access telemetry...</p>
            </div>
          ) : activeTab === 'courses' ? (
            <div className="space-y-3">
              {/* Notice Banner */}
              <div className="bg-sky-50 border border-sky-200/80 rounded-2xl p-3.5 flex items-start gap-3 shadow-2xs">
                <Sparkles className="h-4 w-4 text-sky-600 shrink-0 mt-0.5" />
                <div className="text-xs text-sky-900 leading-relaxed font-medium">
                  <span className="font-bold">Admin Override Control:</span> Unlocking a course allows this specific employee to launch and replay it immediately. Existing completion marks and SCORM telemetry are fully preserved. Other employees remain strictly locked.
                </div>
              </div>

              {/* Course Table */}
              <div className="bg-white border border-slate-200/90 rounded-2xl overflow-hidden shadow-2xs">
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="bg-slate-50/90 border-b border-slate-200/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                        <th className="py-3 px-4 w-12">#</th>
                        <th className="py-3 px-4 min-w-[220px]">Course Name</th>
                        <th className="py-3 px-4 min-w-[140px]">Employee Progress</th>
                        <th className="py-3 px-4 min-w-[170px]">Access Status</th>
                        <th className="py-3 px-4 text-right min-w-[160px]">Admin Action</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {courses.map((c: any) => {
                        const isDone = c.progress?.is_completed;
                        const inProg = c.progress?.status === 'in_progress';
                        const isUnlockedByAdmin = c.access?.admin_unlocked;
                        const isLocked = c.access?.is_locked;

                        return (
                          <tr key={c.module_id || c.index} className="hover:bg-slate-50/60 transition-colors">
                            <td className="py-3.5 px-4 font-bold text-slate-400">
                              {c.index}
                            </td>
                            <td className="py-3.5 px-4">
                              <div className="font-bold text-slate-800 leading-snug">
                                {c.title}
                              </div>
                              <div className="text-[11px] text-slate-400 font-medium mt-0.5 flex items-center gap-2">
                                <span>{c.duration_minutes} min</span>
                                {c.is_assessment && (
                                  <span className="text-amber-700 font-bold bg-amber-50 px-1.5 py-0.2 rounded text-[10px]">
                                    Assessment
                                  </span>
                                )}
                              </div>
                            </td>
                            <td className="py-3.5 px-4">
                              {isDone ? (
                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                  <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                                  Completed (100%)
                                </span>
                              ) : inProg ? (
                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#E6F7F4] text-[#00A389] border border-[#00A389]/30">
                                  <span className="h-1.5 w-1.5 rounded-full bg-[#00A389] animate-pulse" />
                                  In Progress ({c.progress?.percent}%)
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200">
                                  Not Started
                                </span>
                              )}
                              {c.progress?.score !== null && (
                                <div className="text-[10px] text-slate-500 font-medium mt-0.5">
                                  Score: {c.progress.score}%
                                </div>
                              )}
                            </td>
                            <td className="py-3.5 px-4">
                              {isUnlockedByAdmin ? (
                                <div className="space-y-0.5">
                                  <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-300 shadow-2xs">
                                    <Unlock className="h-3 w-3 text-emerald-600" />
                                    Admin Unlocked
                                  </span>
                                  <div className="text-[10px] text-emerald-700 font-medium truncate max-w-[190px]" title={c.access?.override?.reason}>
                                    By {c.access?.override?.admin_name}: {c.access?.override?.reason}
                                  </div>
                                </div>
                              ) : isLocked ? (
                                <div className="space-y-0.5">
                                  <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200 shadow-2xs">
                                    <Lock className="h-3 w-3 text-slate-400" />
                                    {isDone ? 'Completed · Locked' : 'Locked'}
                                  </span>
                                  <div className="text-[10px] text-slate-400 font-medium truncate max-w-[190px]" title={c.access?.locked_reason}>
                                    {c.access?.locked_reason || 'Requires unlock'}
                                  </div>
                                </div>
                              ) : (
                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200 shadow-2xs">
                                  <CheckCircle2 className="h-3 w-3 text-sky-600" />
                                  Available (Active)
                                </span>
                              )}
                            </td>
                            <td className="py-3.5 px-4 text-right whitespace-nowrap">
                              {isUnlockedByAdmin ? (
                                <Button
                                  variant="outline"
                                  size="sm"
                                  disabled={revokeMutation.isPending}
                                  onClick={() =>
                                    revokeMutation.mutate({
                                      overrideId: c.access?.override?.id,
                                      courseId: c.moodle_course_id,
                                      moduleId: c.module_id,
                                    })
                                  }
                                  className="h-8 rounded-xl border-amber-200 text-amber-800 bg-amber-50 hover:bg-amber-100 font-bold text-xs gap-1.5 shadow-2xs cursor-pointer"
                                >
                                  <RotateCcw className="h-3 w-3 text-amber-700" />
                                  <span>Revoke Unlock</span>
                                </Button>
                              ) : (
                                <Button
                                  size="sm"
                                  disabled={unlockMutation.isPending}
                                  onClick={() => {
                                    setUnlockModalCourse(c);
                                    setUnlockReason(
                                      isDone
                                        ? "Manager authorization granted for course relaunch"
                                        : "Admin unlocked course access override"
                                    );
                                  }}
                                  className="h-8 rounded-xl bg-[#00A389] hover:bg-[#008f77] text-white font-bold text-xs gap-1.5 shadow-2xs cursor-pointer"
                                >
                                  <Unlock className="h-3 w-3" />
                                  <span>{isDone ? 'Allow Relaunch' : 'Unlock Course'}</span>
                                </Button>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          ) : (
            /* Audit Log View */
            <div className="space-y-3">
              <div className="bg-white border border-slate-200/90 rounded-2xl overflow-hidden shadow-2xs">
                {auditLog.length > 0 ? (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs border-collapse">
                      <thead>
                        <tr className="bg-slate-50/90 border-b border-slate-200/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                          <th className="py-3 px-4">Date &amp; Time</th>
                          <th className="py-3 px-4">Course / Action</th>
                          <th className="py-3 px-4">Authorized By</th>
                          <th className="py-3 px-4">Status</th>
                          <th className="py-3 px-4">Reason / Notes</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {auditLog.map((log: any) => (
                          <tr key={log.id} className="hover:bg-slate-50/60 transition-colors">
                            <td className="py-3 px-4 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                              {log.date_fmt}
                            </td>
                            <td className="py-3 px-4 font-bold text-slate-800">
                              {log.course_name}
                            </td>
                            <td className="py-3 px-4 text-slate-700 font-medium">
                              {log.admin_name}
                            </td>
                            <td className="py-3 px-4">
                              {log.is_unlocked ? (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                  <Unlock className="h-2.5 w-2.5" />
                                  Active Unlock
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200">
                                  Revoked
                                </span>
                              )}
                            </td>
                            <td className="py-3 px-4 text-slate-600 text-[11px] leading-relaxed">
                              {log.reason}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <div className="py-12 text-center text-slate-400 text-xs">
                    No admin overrides have been recorded yet for this employee.
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="bg-white border-t border-slate-200/80 px-6 py-4 flex items-center justify-between">
          <span className="text-[11px] text-slate-400 font-medium">
            Changes take effect immediately on the employee&apos;s Roadmap &amp; Player.
          </span>
          <Button
            variant="outline"
            onClick={onClose}
            className="rounded-xl border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-100"
          >
            Close
          </Button>
        </div>
      </DialogContent>

      {/* Confirmation Sub-Modal for Reason Input */}
      {unlockModalCourse && (
        <Dialog open={Boolean(unlockModalCourse)} onOpenChange={(open) => !open && setUnlockModalCourse(null)}>
          <DialogContent 
            className="max-w-md p-6 bg-white sm:rounded-2xl border-slate-200 shadow-xl"
            onOpenAutoFocus={(e) => e.preventDefault()}
          >
            <DialogHeader>
              <DialogTitle className="text-base font-bold text-slate-900 tracking-tight flex items-center gap-2">
                <Unlock className="h-4 w-4 text-[#00A389]" />
                Authorize Course Unlock &amp; Relaunch
              </DialogTitle>
            </DialogHeader>

            <div className="space-y-3.5 my-2">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1">
                <div className="font-bold text-slate-800">{unlockModalCourse.title}</div>
                <div className="text-slate-500">
                  Target Employee: <span className="font-semibold text-slate-700">{employee?.name || employeeName}</span> (UID #{employeeUid})
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-bold text-slate-700">
                  Override Authorization Reason <span className="text-rose-500">*</span>
                </label>
                <Input
                  value={unlockReason}
                  onChange={(e) => setUnlockReason(e.target.value)}
                  placeholder="e.g. Manager approval granted for policy review"
                  className="rounded-xl border-slate-200 text-xs focus-visible:ring-[#00A389]"
                />
                <p className="text-[11px] text-slate-400 font-medium">
                  This reason is permanently recorded in the audit log with your admin identity and timestamp.
                </p>
              </div>
            </div>

            <div className="flex items-center justify-end gap-2.5 pt-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setUnlockModalCourse(null)}
                className="rounded-xl border-slate-200 text-xs font-bold"
              >
                Cancel
              </Button>
              <Button
                size="sm"
                disabled={unlockMutation.isPending || !unlockReason.trim()}
                onClick={() =>
                  unlockMutation.mutate({
                    courseId: unlockModalCourse.moodle_course_id,
                    moduleId: unlockModalCourse.module_id,
                    cmid: unlockModalCourse.moodle_cmid,
                    courseName: unlockModalCourse.title,
                    reason: unlockReason.trim(),
                  })
                }
                className="rounded-xl bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold gap-1.5 shadow-2xs"
              >
                {unlockMutation.isPending ? (
                  <>
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    Unlocking...
                  </>
                ) : (
                  <>
                    <Unlock className="h-3.5 w-3.5" />
                    Confirm Unlock Override
                  </>
                )}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </Dialog>
  );
};

