import { useState, useEffect } from "react";
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
import { apiPost } from "@/lib/api";
import {
  Calendar,
  Clock,
  Lock,
  Unlock,
  AlertCircle,
  CheckCircle2,
  User,
  ShieldCheck,
  Loader2,
  X
} from "lucide-react";

interface AdminExtendDeadlineModalProps {
  isOpen: boolean;
  onClose: () => void;
  employee: any | null;
  onUpdated?: () => void;
}

export const AdminExtendDeadlineModal = ({
  isOpen,
  onClose,
  employee,
  onUpdated,
}: AdminExtendDeadlineModalProps) => {
  const { toast } = useToast();

  const [newDate, setNewDate] = useState("");
  const [reason, setReason] = useState("Manager Approval");
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Set default extension date (Nov 29, 2026 or +4 days from today)
  useEffect(() => {
    if (isOpen && employee) {
      const currentEff = employee.deadline_info?.effective_deadline;
      if (currentEff) {
        try {
          const d = new Date(currentEff);
          d.setDate(d.getDate() + 5);
          const yyyy = d.getFullYear();
          const mm = String(d.getMonth() + 1).padStart(2, '0');
          const dd = String(d.getDate()).padStart(2, '0');
          setNewDate(`${yyyy}-${mm}-${dd}`);
        } catch {
          setNewDate("2026-11-29");
        }
      } else {
        setNewDate("2026-11-29");
      }
      setReason("Manager Approval");
    }
  }, [isOpen, employee]);

  if (!employee) return null;

  const deadlineInfo = employee.deadline_info;
  const isLocked = Boolean(deadlineInfo?.is_deadline_locked);
  const isCertified = Boolean(deadlineInfo?.has_passed || deadlineInfo?.is_completed);
  const effectiveDeadlineFmt = deadlineInfo?.effective_deadline_fmt || deadlineInfo?.effective_deadline || "24-Nov-2026 11:59 PM";

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newDate) {
      toast({
        title: "Date Required",
        description: "Please select a valid extension date.",
        variant: "destructive",
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await apiPost<any>("/admin/extend_deadline.php", {
        user_id: employee.uid,
        new_deadline: newDate,
        reason: reason.trim() || "Manager Approval",
      });

      if (res && res.success) {
        toast({
          title: "Deadline Extended Successfully",
          description: res.message || `Extended until ${newDate} 11:59 PM.`,
        });
        if (onUpdated) {
          onUpdated();
        }
        onClose();
      } else {
        toast({
          title: "Extension Failed",
          description: res?.message || "Failed to update employee deadline.",
          variant: "destructive",
        });
      }
    } catch (err: any) {
      toast({
        title: "Error",
        description: err?.message || "Failed to submit deadline extension.",
        variant: "destructive",
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg p-0 overflow-hidden border border-slate-200/80 shadow-2xl rounded-2xl bg-white">
        {/* Header */}
        <div className="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 px-6 py-5 text-white flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="h-10 w-10 rounded-xl bg-blue-500/20 border border-blue-400/30 flex items-center justify-center text-blue-300">
              <Calendar className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle className="text-base font-bold text-white tracking-tight">
                Grant Individual Extension
              </DialogTitle>
              <p className="text-xs text-slate-300 font-medium">
                Extend onboarding deadline for this employee only
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-white transition-colors p-1 rounded-lg hover:bg-slate-700/50"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-5">
          {/* Employee Summary Card */}
          <div className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 flex items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="h-11 w-11 rounded-full bg-slate-200 flex items-center justify-center font-bold text-slate-700 text-sm overflow-hidden shrink-0">
                {employee.photo ? (
                  <img src={employee.photo} alt={employee.name} className="h-full w-full object-cover" />
                ) : (
                  <User className="h-5 w-5 text-slate-500" />
                )}
              </div>
              <div>
                <h4 className="text-sm font-bold text-slate-900 leading-tight">
                  {employee.name}
                </h4>
                <div className="flex items-center gap-2 mt-0.5 text-xs text-slate-500 font-medium">
                  <span>{employee.emp_code || `UID #${employee.uid}`}</span>
                  <span>•</span>
                  <span>{employee.department_name || "General"}</span>
                </div>
              </div>
            </div>

            <div>
              {isCertified ? (
                <Badge className="bg-emerald-100 text-emerald-800 border-emerald-300 gap-1 text-[11px] font-bold">
                  <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                  Certified
                </Badge>
              ) : isLocked ? (
                <Badge className="bg-red-100 text-red-800 border-red-300 gap-1 text-[11px] font-bold">
                  <Lock className="h-3.5 w-3.5 text-red-600" />
                  Locked
                </Badge>
              ) : (
                <Badge className="bg-blue-100 text-blue-800 border-blue-300 gap-1 text-[11px] font-bold">
                  <Clock className="h-3.5 w-3.5 text-blue-600" />
                  Active
                </Badge>
              )}
            </div>
          </div>

          {/* Current Deadline Details */}
          <div className="text-xs p-3.5 rounded-xl border border-amber-200 bg-amber-50/70 space-y-1">
            <div className="flex items-center justify-between text-amber-900 font-semibold">
              <span className="flex items-center gap-1.5">
                <Clock className="h-3.5 w-3.5 text-amber-700" />
                Current Effective Deadline:
              </span>
              <span className="font-mono font-bold">{effectiveDeadlineFmt}</span>
            </div>
            {deadlineInfo?.is_extended && (
              <p className="text-[11px] text-amber-800">
                Previously extended by Manager. (Reason: {deadlineInfo?.extension_reason || 'N/A'})
              </p>
            )}
          </div>

          {/* New Deadline Datepicker */}
          <div className="space-y-1.5">
            <label className="text-xs font-bold text-slate-800 flex items-center justify-between">
              <span>New Extended Date (Valid until 11:59:59 PM IST)</span>
              <span className="text-[11px] text-slate-500 font-normal">End of Selected Day</span>
            </label>
            <Input
              type="date"
              value={newDate}
              onChange={(e) => setNewDate(e.target.value)}
              className="rounded-xl border-slate-300 text-sm font-semibold h-10 cursor-pointer"
              required
            />
          </div>

          {/* Extension Reason */}
          <div className="space-y-1.5">
            <label className="text-xs font-bold text-slate-800">
              Reason / Approval Note
            </label>
            <Input
              type="text"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Manager approved extension for medical leave"
              className="rounded-xl border-slate-300 text-sm h-10"
              required
            />
            <div className="flex flex-wrap gap-1.5 pt-1">
              {["Manager Approval", "Medical Leave", "Workload / Project Delivery", "Delayed System Access"].map((preset) => (
                <button
                  type="button"
                  key={preset}
                  onClick={() => setReason(preset)}
                  className="text-[11px] px-2 py-0.5 rounded-lg border border-slate-200 bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors"
                >
                  {preset}
                </button>
              ))}
            </div>
          </div>

          {/* Isolation Notice Banner */}
          <div className="text-[11px] text-blue-900 bg-blue-50/80 border border-blue-200 rounded-xl p-3 flex items-start gap-2.5">
            <ShieldCheck className="h-4 w-4 text-blue-600 shrink-0 mt-0.5" />
            <p className="leading-relaxed">
              <strong>Individual Isolation:</strong> Submitting this extension unlocks <strong>{employee.name}</strong> only. All other incomplete trainees remain locked until their respective manager approvals.
            </p>
          </div>

          {/* Action Buttons */}
          <div className="flex items-center justify-end gap-3 pt-2">
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={isSubmitting}
              className="rounded-xl h-10 px-4 text-xs font-semibold"
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={isSubmitting}
              className="rounded-xl h-10 px-5 text-xs font-bold bg-[#00A389] hover:bg-[#008f78] text-white gap-2 shadow-sm"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Saving...
                </>
              ) : (
                <>
                  <Unlock className="h-4 w-4" />
                  Unlock & Extend Deadline
                </>
              )}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
};
