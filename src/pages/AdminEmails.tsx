import { useEffect, useState, useMemo } from "react";
import { Layout } from "@/components/Layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { 
  Mail, Send, Settings, FileText, History, CheckCircle2, AlertTriangle, 
  Search, Loader2, Server, Key, Eye, EyeOff, Plus, Trash2, Edit3, 
  RefreshCw, Check, Info, Users, Clock, ArrowRight, ShieldCheck, Sparkles, X
} from "lucide-react";
import { apiGet, apiPost } from "@/lib/api";

interface SmtpConfig {
  host: string;
  port: number;
  encryption: 'tls' | 'ssl' | 'none';
  username: string;
  password?: string;
  has_password?: boolean;
  from_name: string;
  from_email: string;
  is_active?: boolean;
  last_tested_at?: string | null;
  last_test_status?: 'success' | 'failed' | null;
  last_test_message?: string | null;
}

interface EmailTemplate {
  id: number;
  name: string;
  slug: string;
  subject: string;
  body_html: string;
  description: string;
  is_active: number;
  updated_at: string;
}

interface Recipient {
  uid: number;
  name: string;
  email: string;
  has_email?: boolean;
  is_temporary?: boolean;
  email_source: string;
  is_moodle_enrolled: boolean;
  completion_percent: number;
  completed_modules: number;
  total_modules: number;
  joining_date?: string;
}

interface EmailLog {
  id: number;
  recipient_uid: number | null;
  recipient_name: string;
  recipient_email: string;
  subject: string;
  body_snippet: string;
  status: 'sent' | 'failed';
  error_message: string | null;
  sender_name: string;
  sent_at: string;
  sent_at_formatted: string;
}

export default function AdminEmails() {
  const [activeTab, setActiveTab] = useState<'send' | 'templates' | 'logs'>('send');

  // SMTP Settings State
  const [smtpConfig, setSmtpConfig] = useState<SmtpConfig>({
    host: 'smtp.gmail.com',
    port: 587,
    encryption: 'tls',
    username: '',
    password: '',
    from_name: 'HexaLearn Onboarding',
    from_email: '',
  });
  const [showPassword, setShowPassword] = useState(false);
  const [isSavingSmtp, setIsSavingSmtp] = useState(false);
  const [smtpSaveSuccess, setSmtpSaveSuccess] = useState<string | null>(null);
  const [smtpSaveError, setSmtpSaveError] = useState<string | null>(null);
  
  // SMTP Test State
  const [testEmailAddress, setTestEmailAddress] = useState('');
  const [isTestingSmtp, setIsTestingSmtp] = useState(false);
  const [smtpTestResult, setSmtpTestResult] = useState<{ success: boolean; message: string } | null>(null);

  // Templates State
  const [templates, setTemplates] = useState<EmailTemplate[]>([]);
  const [isLoadingTemplates, setIsLoadingTemplates] = useState(false);
  const [selectedTemplateId, setSelectedTemplateId] = useState<number | null>(null);
  const [editingTemplate, setEditingTemplate] = useState<Partial<EmailTemplate> | null>(null);
  const [isSavingTemplate, setIsSavingTemplate] = useState(false);

  // Default plain text email templates (No CSS, no colors, no buttons, no extra formatting)
  const DEFAULT_ENROLLED_SUBJECT = "Important: Complete Your HexaLearn Onboarding Course by Friday";
  const DEFAULT_ENROLLED_BODY = `<p>Dear {{employee_name}},</p>

<p>Modules 7, 8, 9, and 10 have been newly added to your HexaLearn Onboarding course.</p>

<p>Please log in to the onboarding portal, access your course, and complete all pending modules, including the newly added modules, by Friday (final deadline).</p>

<p>Kindly ensure that all assigned training activities and assessments are completed before the deadline.</p>

<p>Important Notice: If you do not complete all assigned modules by Friday, your access to the onboarding portal will be locked. If you require access after the deadline, please contact your supervisor or the administrator for assistance.</p>

<p>Thank you for your cooperation.</p>

<p>HexaLearn Onboarding Team</p>`;

  const DEFAULT_NOT_ENROLLED_SUBJECT = "Reminder: HexaLearn Onboarding Course Enrollment – Friday Deadline";
  const DEFAULT_NOT_ENROLLED_BODY = `<p>Dear {{employee_name}},</p>

<p>You have not yet been enrolled in the HexaLearn Onboarding course.</p>

<p>Please complete your onboarding enrollment and all assigned training courses by Friday (final deadline).</p>

<p>Kindly take the necessary action to ensure your onboarding training is completed within the given timeframe.</p>

<p>Important Notice: If you do not complete all assigned modules by Friday, your access to the onboarding portal will be locked. If you require access after the deadline, please contact your supervisor or the administrator for assistance.</p>

<p>Thank you for your cooperation.</p>

<p>HexaLearn Onboarding Team</p>`;

  // Send Campaign State (Exact 4 cohorts matching admin/employees: 'all', 'enrolled', 'completed', 'not_enrolled')
  const [cohort, setCohort] = useState<'all' | 'enrolled' | 'completed' | 'not_enrolled'>('enrolled');
  const [allRecipients, setAllRecipients] = useState<Recipient[]>([]);
  const [selectedUids, setSelectedUids] = useState<Set<number>>(new Set());
  const [recipientSearch, setRecipientSearch] = useState('');
  const [isLoadingRecipients, setIsLoadingRecipients] = useState(false);
  const [cohortCounts, setCohortCounts] = useState<Record<string, number>>({
    all: 49,
    enrolled: 34,
    completed: 6,
    not_enrolled: 15
  });
  
  // Custom subject & body editor
  const [campaignSubject, setCampaignSubject] = useState(DEFAULT_ENROLLED_SUBJECT);
  const [campaignBody, setCampaignBody] = useState(DEFAULT_ENROLLED_BODY);
  const [deadlineDay, setDeadlineDay] = useState('Friday');
  const [isSending, setIsSending] = useState(false);
  const [sendResult, setSendResult] = useState<{ success: boolean; sent_count: number; failed_count: number; message: string; failures?: any[] } | null>(null);

  // Manual / Inline Email Editing for Missing Office Emails
  const [editingEmailUid, setEditingEmailUid] = useState<number | null>(null);
  const [editingEmailValue, setEditingEmailValue] = useState('');
  const [isSavingEmailUid, setIsSavingEmailUid] = useState<number | null>(null);

  // Modern Confirmation & Result Popups
  const [showConfirmModal, setShowConfirmModal] = useState(false);
  const [showResultModal, setShowResultModal] = useState(false);

  // Delivery Logs State
  const [logs, setLogs] = useState<EmailLog[]>([]);
  const [logPage, setLogPage] = useState(1);
  const [logTotalPages, setLogTotalPages] = useState(1);
  const [logTotal, setLogTotal] = useState(0);
  const [logStatusFilter, setLogStatusFilter] = useState<'all' | 'sent' | 'failed'>('all');
  const [logSearch, setLogSearch] = useState('');
  const [logViewMode, setLogViewMode] = useState<'unique' | 'all'>('unique');
  const [logStats, setLogStats] = useState({ total: 0, sent: 0, failed: 0 });
  const [isLoadingLogs, setIsLoadingLogs] = useState(false);
  const [isClearingLogs, setIsClearingLogs] = useState(false);

  // In-App Notice Modal (Eliminating all native browser alert() popups)
  const [noticeModal, setNoticeModal] = useState<{
    isOpen: boolean;
    title: string;
    message: string;
    type?: 'error' | 'warning' | 'info';
  } | null>(null);

  // In-App Action Confirmation Modal (Eliminating all native browser window.confirm() popups)
  const [confirmActionModal, setConfirmActionModal] = useState<{
    isOpen: boolean;
    title: string;
    message: string;
    confirmLabel: string;
    confirmVariant?: 'danger' | 'primary';
    onConfirm: () => void;
  } | null>(null);

  // 1. Load initial SMTP settings
  const loadSmtpSettings = async () => {
    try {
      const res = await apiGet<{ success: boolean; settings: SmtpConfig }>('/email/smtp_settings.php');
      if (res.success && res.settings) {
        setSmtpConfig(res.settings);
        if (!testEmailAddress && res.settings.from_email) {
          setTestEmailAddress(res.settings.from_email);
        }
      }
    } catch (e) {
      console.error('Failed to load SMTP settings:', e);
    }
  };

  // 2. Load Templates
  const loadTemplates = async () => {
    setIsLoadingTemplates(true);
    try {
      const res = await apiGet<{ success: boolean; templates: EmailTemplate[] }>('/email/templates.php');
      if (res.success && res.templates) {
        setTemplates(res.templates);
        // Default select the Friday reminder or first template
        if (res.templates.length > 0 && selectedTemplateId === null) {
          const friday = res.templates.find(t => t.slug === 'friday_reminder' || (t as any).template_type === 'friday_deadline') || res.templates[0];
          setSelectedTemplateId(friday.id);
          setCampaignSubject(friday.subject);
          setCampaignBody(friday.body_html || (friday as any).body || '');
        }
      }
    } catch (e) {
      console.error('Failed to load templates:', e);
    } finally {
      setIsLoadingTemplates(false);
    }
  };

  // 3. Load Recipients based on selected cohort (strictly ofc_mail only)
  const loadRecipients = async (chosenCohort = cohort) => {
    setIsLoadingRecipients(true);
    try {
      const res = await apiGet<{ 
        success: boolean; 
        cohort: string; 
        cohort_counts: Record<string, number>; 
        recipients: Recipient[]; 
        employees?: Recipient[];
      }>(`/email/recipients.php?cohort=${chosenCohort}`);
      
      if (res.success) {
        const list = res.recipients || (res as any).employees || [];
        setAllRecipients(list);
        setCohortCounts(res.cohort_counts || {});
        // Manual selection by default (0 selected initially)
        setSelectedUids(new Set());
      }
    } catch (e) {
      console.error('Failed to load recipients:', e);
    } finally {
      setIsLoadingRecipients(false);
    }
  };

  // 4. Load Delivery Logs (Supports 1 line per recipient 'unique' or all dispatches 'all')
  const loadLogs = async (
    page = logPage, 
    status = logStatusFilter, 
    q = logSearch, 
    view = logViewMode
  ) => {
    setIsLoadingLogs(true);
    try {
      const query = new URLSearchParams({
        page: String(page),
        status: status,
        q: q,
        view: view
      });
      const res = await apiGet<{
        success: boolean;
        logs: EmailLog[];
        pagination: { page: number; limit: number; total: number; total_pages: number };
        stats: { total: number; sent: number; failed: number };
      }>(`/email/logs.php?${query.toString()}`);
      
      if (res.success) {
        setLogs(res.logs || []);
        setLogTotal(res.pagination.total);
        setLogTotalPages(res.pagination.total_pages);
        setLogStats(res.stats || { total: 0, sent: 0, failed: 0 });
      }
    } catch (e) {
      console.error('Failed to load logs:', e);
    } finally {
      setIsLoadingLogs(false);
    }
  };

  useEffect(() => {
    loadSmtpSettings();
    loadTemplates();
    loadRecipients('enrolled');
    loadLogs(1, 'all', '', 'unique');
  }, []);

  // When changing cohort in Send tab
  const handleCohortChange = (newCohort: 'all' | 'enrolled' | 'completed' | 'not_enrolled') => {
    setCohort(newCohort);
    loadRecipients(newCohort);

    if (newCohort === 'not_enrolled') {
      const notEnrolledTmpl = templates.find(t => t.slug === 'not_enrolled_reminder');
      if (notEnrolledTmpl) {
        setSelectedTemplateId(notEnrolledTmpl.id);
        setCampaignSubject(notEnrolledTmpl.subject);
        setCampaignBody(notEnrolledTmpl.body_html || (notEnrolledTmpl as any).body || DEFAULT_NOT_ENROLLED_BODY);
      } else {
        setCampaignSubject(DEFAULT_NOT_ENROLLED_SUBJECT);
        setCampaignBody(DEFAULT_NOT_ENROLLED_BODY);
      }
    } else {
      const enrolledTmpl = templates.find(t => t.slug === 'friday_reminder') || templates[0];
      if (enrolledTmpl) {
        setSelectedTemplateId(enrolledTmpl.id);
        setCampaignSubject(enrolledTmpl.subject);
        setCampaignBody(enrolledTmpl.body_html || (enrolledTmpl as any).body || DEFAULT_ENROLLED_BODY);
      } else {
        setCampaignSubject(DEFAULT_ENROLLED_SUBJECT);
        setCampaignBody(DEFAULT_ENROLLED_BODY);
      }
    }
  };

  // When choosing a template in Send tab
  const handleSelectTemplate = (tmplId: number) => {
    setSelectedTemplateId(tmplId);
    const tmpl = templates.find(t => t.id === tmplId);
    if (tmpl) {
      setCampaignSubject(tmpl.subject);
      setCampaignBody(tmpl.body_html || (tmpl as any).body || '');
    }
  };

  // Filtered recipient list based on search
  const filteredRecipients = useMemo(() => {
    if (!recipientSearch.trim()) return allRecipients;
    const q = recipientSearch.toLowerCase();
    return allRecipients.filter(r => 
      r.name.toLowerCase().includes(q) || 
      r.email.toLowerCase().includes(q) || 
      String(r.uid).includes(q)
    );
  }, [allRecipients, recipientSearch]);

  const selectableRecipients = useMemo(() => {
    return filteredRecipients.filter(r => Boolean(r.email && r.email.trim()));
  }, [filteredRecipients]);

  const toggleSelectAll = () => {
    if (selectedUids.size === selectableRecipients.length && selectableRecipients.length > 0) {
      setSelectedUids(new Set());
    } else {
      setSelectedUids(new Set(selectableRecipients.map(r => r.uid)));
    }
  };

  const toggleRecipient = (uid: number) => {
    const target = allRecipients.find(r => r.uid === uid);
    if (!target || !target.email || !target.email.trim()) return; // Cannot select without office email
    const next = new Set(selectedUids);
    if (next.has(uid)) {
      next.delete(uid);
    } else {
      next.add(uid);
    }
    setSelectedUids(next);
  };

  // Save SMTP Settings
  const handleSaveSmtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSavingSmtp(true);
    setSmtpSaveSuccess(null);
    setSmtpSaveError(null);
    try {
      const res = await apiPost<{ success: boolean; message: string; settings: SmtpConfig }>(
        '/email/smtp_settings.php',
        smtpConfig
      );
      if (res.success) {
        setSmtpSaveSuccess(res.message || 'SMTP settings updated successfully!');
        setSmtpConfig(res.settings);
      } else {
        setSmtpSaveError(res.message || 'Failed to save SMTP settings');
      }
    } catch (err: any) {
      setSmtpSaveError(err.message || 'Network error while saving SMTP settings');
    } finally {
      setIsSavingSmtp(false);
    }
  };

  // Test SMTP Connection
  const handleTestSmtp = async () => {
    if (!testEmailAddress.trim()) {
      setNoticeModal({
        isOpen: true,
        title: 'Recipient Required',
        message: 'Please enter an email address to send the test message to.',
        type: 'warning'
      });
      return;
    }
    setIsTestingSmtp(true);
    setSmtpTestResult(null);
    try {
      const res = await apiPost<{ success: boolean; message: string; log_id?: number }>(
        '/email/smtp_test.php',
        { test_email: testEmailAddress.trim() }
      );
      setSmtpTestResult(res);
      // Refresh logs
      loadLogs(1, 'all', '');
    } catch (err: any) {
      setSmtpTestResult({
        success: false,
        message: err.message || 'Network error while attempting SMTP test dispatch'
      });
    } finally {
      setIsTestingSmtp(false);
    }
  };

  // Inline / Manual Email Management for Missing Emails
  const handleStartEditEmail = (uid: number, currentEmail: string) => {
    setEditingEmailUid(uid);
    setEditingEmailValue(currentEmail || '');
  };

  const handleCancelEditEmail = () => {
    setEditingEmailUid(null);
    setEditingEmailValue('');
  };

  const handleSaveRecipientEmail = (uid: number) => {
    const trimmed = editingEmailValue.trim();
    if (!trimmed || !trimmed.includes('@')) {
      setNoticeModal({
        isOpen: true,
        title: 'Invalid Email Address',
        message: 'Please enter a valid corporate email address (e.g. employee@hexalearn.co.in).',
        type: 'error'
      });
      return;
    }

    // Set email temporarily in state for this send campaign ONLY (NO database modification)
    setAllRecipients((prev) =>
      prev.map((r) => {
        if (r.uid === uid) {
          return {
            ...r,
            email: trimmed,
            has_email: true,
            is_temporary: true,
            email_source: 'temporary'
          };
        }
        return r;
      })
    );

    // Auto-select this newly eligible recipient
    setSelectedUids((prev) => new Set(prev).add(uid));
    setEditingEmailUid(null);
    setEditingEmailValue('');
  };

  // Selected recipients objects for modal preview
  const selectedRecipientsList = useMemo(() => {
    return allRecipients.filter((r) => selectedUids.has(r.uid));
  }, [allRecipients, selectedUids]);

  // Open the modern confirmation modal (Replaces browser window.confirm)
  const handleInitiateSend = () => {
    if (selectedUids.size === 0) {
      setNoticeModal({
        isOpen: true,
        title: 'Select Recipients First',
        message: 'Please check at least one employee from the checklist roster above to receive this reminder.',
        type: 'warning'
      });
      return;
    }
    if (!campaignSubject.trim()) {
      setNoticeModal({
        isOpen: true,
        title: 'Email Subject Required',
        message: 'Please enter a subject line for your reminder email before sending.',
        type: 'warning'
      });
      return;
    }
    if (!campaignBody.trim()) {
      setNoticeModal({
        isOpen: true,
        title: 'Email Body Required',
        message: 'Please provide message paragraphs for the reminder before sending.',
        type: 'warning'
      });
      return;
    }

    setShowConfirmModal(true);
  };

  // Execute Send Campaign after confirmation in modal
  const executeSendCampaign = async () => {
    if (isSending) return; // Prevent double-execution
    setIsSending(true);
    setSendResult(null);

    // Build custom emails map for all selected recipients
    const customEmailMap: Record<number, string> = {};
    allRecipients.forEach((r) => {
      if (r.email && selectedUids.has(r.uid)) {
        customEmailMap[r.uid] = r.email;
      }
    });

    try {
      const res = await apiPost<{
        success: boolean;
        sent_count: number;
        failed_count: number;
        total_recipients: number;
        message: string;
        failures?: any[];
      }>('/email/send.php', {
        template_id: selectedTemplateId,
        subject: campaignSubject,
        body_html: campaignBody,
        deadline_day: deadlineDay,
        uids: Array.from(selectedUids),
        custom_emails: customEmailMap
      });

      setSendResult(res);
      setShowConfirmModal(false);
      setShowResultModal(true);
      // Reload logs and cohort counts
      loadLogs(1, 'all', '', logViewMode);
    } catch (err: any) {
      setSendResult({
        success: false,
        sent_count: 0,
        failed_count: selectedUids.size,
        message: err.message || 'Network error occurred while dispatching emails.'
      });
      setShowConfirmModal(false);
      setShowResultModal(true);
    } finally {
      setIsSending(false);
    }
  };

  // Save/Update Template
  const handleSaveTemplate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingTemplate || !editingTemplate.name || !editingTemplate.subject) return;

    setIsSavingTemplate(true);
    try {
      const res = await apiPost<{ success: boolean; message: string; template: EmailTemplate }>(
        '/email/templates.php',
        editingTemplate
      );
      if (res.success) {
        await loadTemplates();
        setEditingTemplate(null);
      } else {
        setNoticeModal({
          isOpen: true,
          title: 'Template Save Failed',
          message: res.message || 'Failed to save template.',
          type: 'error'
        });
      }
    } catch (err: any) {
      setNoticeModal({
        isOpen: true,
        title: 'Template Error',
        message: err.message || 'Failed to save template.',
        type: 'error'
      });
    } finally {
      setIsSavingTemplate(false);
    }
  };

  // Delete Template (with in-app custom confirmation modal)
  const handleDeleteTemplate = (id: number, name: string) => {
    setConfirmActionModal({
      isOpen: true,
      title: 'Delete Template?',
      message: `Are you sure you want to delete template "${name}"? This action cannot be undone.`,
      confirmLabel: 'Yes, Delete Template',
      confirmVariant: 'danger',
      onConfirm: async () => {
        setConfirmActionModal(null);
        try {
          const res = await apiPost<{ success: boolean; message: string }>(
            '/email/templates.php',
            { action: 'delete', id }
          );
          if (res.success) {
            await loadTemplates();
          } else {
            setNoticeModal({
              isOpen: true,
              title: 'Deletion Failed',
              message: res.message || 'Failed to delete template.',
              type: 'error'
            });
          }
        } catch (err: any) {
          setNoticeModal({
            isOpen: true,
            title: 'Deletion Error',
            message: err.message || 'Failed to delete template.',
            type: 'error'
          });
        }
      }
    });
  };

  // Clear Delivery Logs (with in-app custom confirmation modal)
  const handleClearLogs = () => {
    setConfirmActionModal({
      isOpen: true,
      title: 'Clear Delivery History?',
      message: 'Are you sure you want to clear all email delivery history logs? This will reset the dispatch count and cannot be undone.',
      confirmLabel: 'Yes, Clear All Logs',
      confirmVariant: 'danger',
      onConfirm: async () => {
        setConfirmActionModal(null);
        setIsClearingLogs(true);
        try {
          const res = await apiPost<{ success: boolean; message: string }>('/email/logs.php', { action: 'clear' });
          if (res.success) {
            await loadLogs(1, 'all', '', logViewMode);
          }
        } catch (err: any) {
          setNoticeModal({
            isOpen: true,
            title: 'Clear Logs Error',
            message: err.message || 'Failed to clear logs.',
            type: 'error'
          });
        } finally {
          setIsClearingLogs(false);
        }
      }
    });
  };

  // Live preview with tags replaced for first selected employee or sample employee
  const previewRecipient = useMemo(() => {
    if (selectedUids.size > 0) {
      const firstUid = Array.from(selectedUids)[0];
      const match = allRecipients.find(r => r.uid === firstUid);
      if (match) return match;
    }
    return allRecipients[0] || null;
  }, [selectedUids, allRecipients]);

  const previewSubject = useMemo(() => {
    const raw = campaignSubject;
    const repName = previewRecipient?.name || 'Abhilipsa Panda';
    const repPercent = previewRecipient ? `${previewRecipient.completion_percent}%` : '67%';
    return raw
      .replace(/{{?employee_name}}?/gi, repName)
      .replace(/{{?completion_percent}}?/gi, repPercent)
      .replace(/{{?deadline_day}}?/gi, deadlineDay);
  }, [campaignSubject, previewRecipient, deadlineDay]);

  const previewHtml = useMemo(() => {
    const raw = campaignBody;
    const repName = previewRecipient?.name || 'Abhilipsa Panda';
    const repPercent = previewRecipient ? `${previewRecipient.completion_percent}%` : '67%';
    const repDone = previewRecipient ? String(previewRecipient.completed_modules) : '4';
    const repTotal = previewRecipient ? String(previewRecipient.total_modules) : '6';
    let text = raw
      .replace(/{{?employee_name}}?/gi, repName)
      .replace(/{{?completion_percent}}?/gi, repPercent)
      .replace(/{{?deadline_day}}?/gi, deadlineDay)
      .replace(/{{?completed_modules}}?/gi, repDone)
      .replace(/{{?total_modules}}?/gi, repTotal)
      .replace(/{{?onboard_url}}?/gi, 'https://corhis.hexalearn.com/onboard/');

    if (text && !text.includes('<p>') && !text.includes('<div>')) {
      const lines = text.split(/\r?\n\r?\n/);
      return lines.map(line => `<p style="margin-bottom: 14px;">${line.replace(/\r?\n/g, '<br/>')}</p>`).join('');
    }
    return text;
  }, [campaignBody, previewRecipient, deadlineDay]);

  return (
    <Layout isAdmin>
      <div className="space-y-6 w-full pb-16">
        
        {/* Header Title & Tab Navigation */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2 border-b border-slate-200">
          <div>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-gradient-to-tr from-[#00A389] to-emerald-400 flex items-center justify-center text-white shadow-md shadow-[#00A389]/20">
                <Mail className="h-5 w-5" />
              </div>
              <div>
                <h1 className="text-2xl font-black text-slate-800 tracking-tight">Email & Reminders Hub</h1>
                <p className="text-xs text-slate-500 font-medium">
                  Automate and dispatch official onboarding reminders and track delivery.
                </p>
              </div>
            </div>
          </div>

          {/* Module Tabs Navigation */}
          <div className="flex items-center gap-1.5 bg-slate-100 p-1.5 rounded-2xl border border-slate-200/80 self-start md:self-auto overflow-x-auto max-w-full">
            <button
              onClick={() => setActiveTab('send')}
              className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                activeTab === 'send'
                  ? 'bg-white text-[#00A389] shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <Send className="h-3.5 w-3.5" />
              <span>Send Email</span>
            </button>
            <button
              onClick={() => setActiveTab('templates')}
              className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                activeTab === 'templates'
                  ? 'bg-white text-[#00A389] shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <FileText className="h-3.5 w-3.5" />
              <span>Templates</span>
            </button>
            <button
              onClick={() => {
                setActiveTab('logs');
                loadLogs();
              }}
              className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                activeTab === 'logs'
                  ? 'bg-white text-[#00A389] shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <History className="h-3.5 w-3.5" />
              <span>Delivery Logs</span>
              {logStats.failed > 0 && (
                <span className="h-2 w-2 rounded-full bg-rose-500 animate-pulse" />
              )}
            </button>
          </div>
        </div>

        {/* ============================================================== */}
        {/* TAB 1: SEND EMAIL CAMPAIGN */}
        {/* ============================================================== */}
        {activeTab === 'send' && (
          <div className="space-y-6">
            
            {/* 4 Header Metric Cards - Exactly matching admin/employees */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              {/* 1. Total Employees */}
              <button
                type="button"
                onClick={() => handleCohortChange('all')}
                className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${
                  cohort === 'all'
                    ? 'border-slate-800 bg-white ring-2 ring-slate-800/20 shadow-xs'
                    : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
                title="Show all company roster"
              >
                <div className="flex items-center justify-between">
                  <span className={`text-[10px] font-black uppercase tracking-wider ${cohort === 'all' ? 'text-slate-800' : 'text-slate-500'}`}>
                    Total Employees
                  </span>
                  <span className="text-[9px] font-bold text-slate-500 px-1.5 py-0.5 rounded bg-slate-100">
                    All
                  </span>
                </div>
                <div className="text-2xl font-black text-slate-800 mt-1">
                  {cohortCounts.all ?? 49}
                </div>
                <div className="text-[11px] text-slate-500 font-medium mt-0.5 flex items-center justify-between">
                  <span>Company roster</span>
                  <span className="text-[10px] font-bold text-slate-500 group-hover:translate-x-0.5 transition-transform">Filter →</span>
                </div>
              </button>

              {/* 2. Enrolled Employees */}
              <button
                type="button"
                onClick={() => handleCohortChange('enrolled')}
                className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${
                  cohort === 'enrolled'
                    ? 'border-[#00A389] bg-white ring-2 ring-[#00A389]/25 shadow-xs'
                    : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
                title="Active Moodle LMS Onboarding Employees"
              >
                <div className="flex items-center justify-between">
                  <span className={`text-[10px] font-black uppercase tracking-wider ${cohort === 'enrolled' ? 'text-emerald-800' : 'text-slate-500'}`}>
                    Enrolled
                  </span>
                  <span className="inline-flex items-center gap-1 text-[9px] font-bold text-emerald-700 bg-emerald-100/80 px-1.5 py-0.5 rounded-full border border-emerald-200">
                    <span className="h-1.5 w-1.5 rounded-full bg-[#00A389] animate-pulse" />
                    Active
                  </span>
                </div>
                <div className="text-2xl font-black text-[#00A389] mt-1">
                  {cohortCounts.enrolled ?? 34}
                </div>
                <div className="text-[11px] text-emerald-700 font-medium mt-0.5 flex items-center justify-between">
                  <span>Active in Moodle LMS</span>
                  <span className="text-[10px] font-bold text-emerald-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
                </div>
              </button>

              {/* 3. Completed Onboarding */}
              <button
                type="button"
                onClick={() => handleCohortChange('completed')}
                className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${
                  cohort === 'completed'
                    ? 'border-emerald-600 bg-white ring-2 ring-emerald-600/25 shadow-xs'
                    : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
                title="Employees who finished 100% of courses"
              >
                <div className="flex items-center justify-between">
                  <span className={`text-[10px] font-black uppercase tracking-wider ${cohort === 'completed' ? 'text-emerald-800' : 'text-slate-500'}`}>
                    Completed
                  </span>
                  <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                </div>
                <div className="text-2xl font-black text-slate-800 mt-1">
                  {cohortCounts.completed ?? 6}
                </div>
                <div className="text-[11px] text-slate-500 font-medium mt-0.5 flex items-center justify-between">
                  <span>100% courses finished</span>
                  <span className="text-[10px] font-bold text-emerald-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
                </div>
              </button>

              {/* 4. Not Enrolled (Unlinked) */}
              <button
                type="button"
                onClick={() => handleCohortChange('not_enrolled')}
                className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer relative overflow-hidden group shadow-2xs hover:shadow-md ${
                  cohort === 'not_enrolled'
                    ? 'border-amber-600 bg-white ring-2 ring-amber-600/25 shadow-xs'
                    : 'border-slate-200/90 bg-slate-50/60 hover:bg-white'
                }`}
                title="Staff not yet enrolled in Moodle Onboarding"
              >
                <div className="flex items-center justify-between">
                  <span className={`text-[10px] font-black uppercase tracking-wider ${cohort === 'not_enrolled' ? 'text-amber-800' : 'text-slate-500'}`}>
                    Not Enrolled
                  </span>
                  <span className="text-[9px] font-bold text-amber-700 px-1.5 py-0.5 rounded bg-amber-50 border border-amber-200/60">Unlinked</span>
                </div>
                <div className="text-2xl font-black text-slate-600 mt-1">
                  {cohortCounts.not_enrolled ?? 15}
                </div>
                <div className="text-[11px] text-slate-400 font-medium mt-0.5 flex items-center justify-between">
                  <span>Not yet in onboarding</span>
                  <span className="text-[10px] font-bold text-amber-600 group-hover:translate-x-0.5 transition-transform">Filter →</span>
                </div>
              </button>
            </div>

            {/* Campaign Result Banner */}
            {sendResult && (
              <div className={`p-4 rounded-2xl border transition-all ${
                sendResult.success 
                  ? 'bg-emerald-50/80 border-emerald-200 text-emerald-900' 
                  : 'bg-rose-50/80 border-rose-200 text-rose-900'
              }`}>
                <div className="flex items-start gap-3">
                  {sendResult.success ? (
                    <CheckCircle2 className="h-5 w-5 text-emerald-600 shrink-0 mt-0.5" />
                  ) : (
                    <AlertTriangle className="h-5 w-5 text-rose-600 shrink-0 mt-0.5" />
                  )}
                  <div className="flex-1 space-y-1">
                    <h4 className="text-sm font-black">{sendResult.message}</h4>
                    <div className="text-xs font-medium flex items-center gap-3">
                      <span>Successfully Sent: <strong>{sendResult.sent_count}</strong></span>
                      {sendResult.failed_count > 0 && (
                        <span className="text-rose-700">Failed: <strong>{sendResult.failed_count}</strong></span>
                      )}
                    </div>
                    {sendResult.failures && sendResult.failures.length > 0 && (
                      <div className="mt-2 text-[11px] bg-white/70 p-2 rounded-lg border border-rose-200 max-h-32 overflow-y-auto space-y-1">
                        {sendResult.failures.map((f: any, idx: number) => (
                          <div key={idx} className="text-rose-800">
                            <strong>{f.name}</strong> ({f.email}): {f.error}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )}

            {/* Main Form Split: Left Composition, Right Recipients & Preview */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
              
              {/* Left Column (5 cols): Email Composer */}
              <div className="lg:col-span-5 space-y-4">
                <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-4">
                  <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                    <h3 className="text-sm font-black text-slate-800 flex items-center gap-2">
                      <Sparkles className="h-4 w-4 text-[#00A389]" />
                      <span>Compose Notification</span>
                    </h3>
                    <span className="text-[10px] font-bold text-slate-400">Template Driven</span>
                  </div>

                  {/* Template Picker */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-700">Choose Base Template</label>
                    <select
                      value={selectedTemplateId ?? ''}
                      onChange={(e) => handleSelectTemplate(Number(e.target.value))}
                      className="w-full text-xs font-medium bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/30"
                    >
                      {templates.map((t) => (
                        <option key={t.id} value={t.id}>
                          {t.name || (t as any).title}
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* Deadline Customization */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-700">Deadline Day / Date</label>
                    <div className="flex items-center gap-2">
                      <Input
                        type="text"
                        value={deadlineDay}
                        onChange={(e) => setDeadlineDay(e.target.value)}
                        placeholder="e.g. this Friday, 14-Nov-2026"
                        className="text-xs font-semibold rounded-xl bg-slate-50 h-9"
                      />
                      <button
                        type="button"
                        onClick={() => setDeadlineDay('this Friday')}
                        className="px-2.5 py-1.5 text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg cursor-pointer whitespace-nowrap"
                      >
                        Friday
                      </button>
                      <button
                        type="button"
                        onClick={() => setDeadlineDay('end of this week')}
                        className="px-2.5 py-1.5 text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg cursor-pointer whitespace-nowrap"
                      >
                        End of Week
                      </button>
                    </div>
                  </div>

                  {/* Subject Line */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-700">Subject</label>
                    <Input
                      type="text"
                      value={campaignSubject}
                      onChange={(e) => setCampaignSubject(e.target.value)}
                      placeholder="Email subject..."
                      className="text-xs font-semibold rounded-xl bg-slate-50 h-9"
                    />
                  </div>

                  {/* Dynamic Tags Toolbar */}
                  <div className="space-y-1.5 pt-1">
                    <div className="flex items-center justify-between">
                      <label className="text-[11px] font-bold text-slate-500">Insert Dynamic Tag:</label>
                      <span className="text-[10px] text-slate-400">Click to append</span>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                      {[
                        { tag: '{employee_name}', desc: 'Name' },
                        { tag: '{completion_percent}', desc: 'Progress %' },
                        { tag: '{deadline_day}', desc: 'Deadline' },
                        { tag: '{onboard_url}', desc: 'Portal Link' },
                      ].map((t) => (
                        <button
                          key={t.tag}
                          type="button"
                          onClick={() => setCampaignBody((prev) => prev + `\n<p>${t.tag}</p>`)}
                          className="text-[10px] font-mono font-bold bg-[#E6F7F4] text-[#00A389] hover:bg-[#00A389] hover:text-white px-2 py-1 rounded-md transition-all cursor-pointer"
                          title={`Insert ${t.desc}`}
                        >
                          {t.tag}
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* HTML Body Editor */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-700">Email Body (Plain Text Paragraphs)</label>
                    <textarea
                      value={campaignBody}
                      onChange={(e) => setCampaignBody(e.target.value)}
                      rows={10}
                      className="w-full text-xs font-sans leading-relaxed bg-slate-50 border border-slate-200 rounded-xl p-3 focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/30"
                      placeholder="Type or edit email message paragraphs..."
                    />
                  </div>

                  {/* Send Action Button */}
                  <div className="pt-2">
                    <Button
                      onClick={handleInitiateSend}
                      disabled={isSending || selectedUids.size === 0}
                      className="w-full h-11 bg-gradient-to-r from-[#00A389] to-emerald-600 hover:from-[#008f77] hover:to-emerald-700 text-white font-bold rounded-xl shadow-md cursor-pointer flex items-center justify-center gap-2"
                    >
                      {isSending ? (
                        <>
                          <Loader2 className="h-4 w-4 animate-spin" />
                          <span>Dispatching Emails ({selectedUids.size} queued)...</span>
                        </>
                      ) : (
                        <>
                          <Send className="h-4 w-4" />
                          <span>
                            {selectedUids.size === 0 
                              ? 'Select Recipients from List Above' 
                              : `Send Reminders to ${selectedUids.size} Selected`}
                          </span>
                        </>
                      )}
                    </Button>
                    <p className="text-[11px] text-center text-slate-400 mt-2">
                      Emails are dispatched via configured corporate SMTP server using employee <code>ofc_mail</code>.
                    </p>
                  </div>
                </div>
              </div>

              {/* Right Column (7 cols): Recipient Selector & Live Preview */}
              <div className="lg:col-span-7 space-y-4">
                
                {/* Recipients Checklist Card */}
                <div className="bg-white border border-slate-200/90 rounded-2xl shadow-2xs overflow-hidden">
                  <div className="p-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
                    <div>
                      <h3 className="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <Users className="h-3.5 w-3.5 text-[#00A389]" />
                        <span>Recipients ({selectedUids.size} of {selectableRecipients.length} eligible selected)</span>
                      </h3>
                      <p className="text-[11px] text-slate-400 font-medium mt-0.5">
                        Strictly active office email (<code>user.ofc_mail</code>) · Manual selection
                      </p>
                    </div>

                    <div className="flex items-center gap-2">
                      <div className="relative">
                        <Search className="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                        <Input
                          type="text"
                          value={recipientSearch}
                          onChange={(e) => setRecipientSearch(e.target.value)}
                          placeholder="Search recipients..."
                          className="h-8 pl-8 pr-3 text-xs rounded-xl bg-white w-40 sm:w-48"
                        />
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={toggleSelectAll}
                        className="h-8 text-xs font-bold rounded-xl cursor-pointer"
                      >
                        {selectedUids.size === selectableRecipients.length && selectableRecipients.length > 0 ? 'Deselect All' : 'Select All'}
                      </Button>
                    </div>
                  </div>

                  {/* Recipient Rows */}
                  <div className="max-h-[340px] overflow-y-auto divide-y divide-slate-100">
                    {isLoadingRecipients ? (
                      <div className="p-8 text-center text-slate-400 text-xs flex items-center justify-center gap-2">
                        <Loader2 className="h-4 w-4 animate-spin text-[#00A389]" />
                        <span>Loading recipients...</span>
                      </div>
                    ) : filteredRecipients.length === 0 ? (
                      <div className="p-8 text-center text-slate-400 text-xs">
                        No employees found matching the filter or search criteria.
                      </div>
                    ) : (
                      filteredRecipients.map((r) => {
                        const isChecked = selectedUids.has(r.uid);
                        const hasEmail = Boolean(r.email && r.email.trim());
                        const isEditingThisEmail = editingEmailUid === r.uid;
                        return (
                          <div
                            key={r.uid}
                            onClick={() => hasEmail && !isEditingThisEmail && toggleRecipient(r.uid)}
                            className={`p-3 flex items-center justify-between gap-3 transition-colors ${
                              !hasEmail && !isEditingThisEmail
                                ? 'bg-amber-50/25 hover:bg-amber-50/40'
                                : isChecked
                                ? 'bg-[#E6F7F4]/20 hover:bg-[#E6F7F4]/40 cursor-pointer'
                                : 'hover:bg-slate-50 cursor-pointer'
                            }`}
                            title={!hasEmail ? 'Click Add Email to provide official office email' : undefined}
                          >
                            <div className="flex items-center gap-3 min-w-0 flex-1">
                              <input
                                type="checkbox"
                                checked={isChecked}
                                disabled={!hasEmail}
                                onChange={() => {}} // handled by parent onClick
                                className="h-4 w-4 rounded border-slate-300 text-[#00A389] focus:ring-[#00A389] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40 shrink-0"
                              />
                              <div className="min-w-0 flex-1">
                                <div className="text-xs font-bold text-slate-800 truncate flex items-center gap-1.5">
                                  <span>{r.name}</span>
                                  <span className="text-[10px] text-slate-400 font-mono">UID #{r.uid}</span>
                                </div>

                                {isEditingThisEmail ? (
                                  <div className="flex items-center gap-1.5 mt-1.5" onClick={(e) => e.stopPropagation()}>
                                    <input
                                      type="email"
                                      value={editingEmailValue}
                                      onChange={(e) => setEditingEmailValue(e.target.value)}
                                      placeholder="e.g. employee@hexalearn.co.in"
                                      className="h-7 text-xs px-2.5 rounded-lg border border-[#00A389] bg-white focus:outline-none focus:ring-2 focus:ring-[#00A389]/30 w-52 sm:w-64 shadow-xs"
                                      autoFocus
                                      onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                          e.preventDefault();
                                          handleSaveRecipientEmail(r.uid);
                                        } else if (e.key === 'Escape') {
                                          handleCancelEditEmail();
                                        }
                                      }}
                                    />
                                    <button
                                      type="button"
                                      onClick={() => handleSaveRecipientEmail(r.uid)}
                                      className="h-7 px-2.5 bg-[#00A389] hover:bg-[#008f77] text-white text-[11px] font-bold rounded-lg transition-all cursor-pointer flex items-center gap-1 shadow-xs"
                                      title="Apply temporary email for this send (does not modify database)"
                                    >
                                      <Check className="h-3 w-3" />
                                      <span>Apply</span>
                                    </button>
                                    <button
                                      type="button"
                                      onClick={handleCancelEditEmail}
                                      className="h-7 px-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-[11px] font-bold rounded-lg transition-colors cursor-pointer"
                                    >
                                      Cancel
                                    </button>
                                  </div>
                                ) : (
                                  <div className="text-[11px] truncate flex items-center gap-2 mt-0.5">
                                    {hasEmail ? (
                                      <div className="flex items-center gap-1.5 group/email">
                                        <Mail className="h-3 w-3 shrink-0 text-slate-400" />
                                        <span className="text-slate-600 font-medium">{r.email}</span>
                                        {r.is_temporary && (
                                          <span className="text-[9px] font-bold text-amber-700 bg-amber-100/90 px-1.5 py-0.2 rounded border border-amber-200">
                                            Temporary
                                          </span>
                                        )}
                                        <button
                                          type="button"
                                          onClick={(e) => {
                                            e.stopPropagation();
                                            handleStartEditEmail(r.uid, r.email);
                                          }}
                                          className="opacity-0 group-hover/email:opacity-100 hover:text-[#00A389] text-slate-400 p-0.5 rounded cursor-pointer transition-opacity"
                                          title="Change temporary email"
                                        >
                                          <Edit3 className="h-3 w-3" />
                                        </button>
                                      </div>
                                    ) : (
                                      <div className="flex items-center gap-2">
                                        <span className="text-amber-600 font-semibold italic text-[11px]">
                                          No office email (blank)
                                        </span>
                                        <button
                                          type="button"
                                          onClick={(e) => {
                                            e.stopPropagation();
                                            handleStartEditEmail(r.uid, '');
                                          }}
                                          className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#00A389] hover:bg-[#008f77] text-white text-[10px] font-bold transition-all cursor-pointer shadow-2xs"
                                          title="Add temporary email for sending"
                                        >
                                          <Plus className="h-3 w-3" />
                                          <span>Add Email</span>
                                        </button>
                                      </div>
                                    )}
                                  </div>
                                )}
                              </div>
                            </div>

                            <div className="flex items-center gap-2 shrink-0">
                              <span
                                className={`text-[10px] font-bold px-2 py-0.5 rounded-full border ${
                                  r.completion_percent >= 100
                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                    : r.completion_percent > 0
                                    ? 'bg-[#E6F7F4] text-[#00A389] border-[#00A389]/30'
                                    : 'bg-slate-100 text-slate-600 border-slate-200'
                                }`}
                              >
                                {r.completion_percent}% ({r.completed_modules}/{r.total_modules})
                              </span>
                            </div>
                          </div>
                        );
                      })
                    )}
                  </div>
                </div>

                {/* Live Email Preview Card */}
                <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs space-y-3">
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                    <h4 className="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                      <Eye className="h-3.5 w-3.5 text-[#00A389]" />
                      <span>Live Render Preview</span>
                    </h4>
                    {previewRecipient && (
                      <span className="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">
                        Previewing for: <strong>{previewRecipient.name}</strong>
                      </span>
                    )}
                  </div>

                  <div className="bg-slate-50 rounded-xl p-3 border border-slate-200/80 space-y-2">
                    <div className="text-xs">
                      <span className="text-slate-400 font-medium">To: </span>
                      <strong className="text-slate-700">
                        {previewRecipient ? `${previewRecipient.name} <${previewRecipient.email}>` : 'No recipient selected'}
                      </strong>
                    </div>
                    <div className="text-xs">
                      <span className="text-slate-400 font-medium">Subject: </span>
                      <strong className="text-slate-800">{previewSubject || '(Empty subject)'}</strong>
                    </div>
                  </div>

                  {/* Rendered HTML snippet preview in sandboxed container */}
                  <div className="border border-slate-200 rounded-xl p-4 bg-white min-h-[140px] max-h-[320px] overflow-y-auto text-xs leading-relaxed text-slate-800">
                    <div 
                      className="[&_p]:mb-3 [&_p:last-child]:mb-0 font-normal"
                      dangerouslySetInnerHTML={{ __html: previewHtml || '<p class="text-slate-400 italic">No message content.</p>' }} 
                    />
                  </div>
                </div>

              </div>
            </div>

          </div>
        )}

        {/* ============================================================== */}
        {/* TAB 2: TEMPLATES MANAGEMENT */}
        {/* ============================================================== */}
        {activeTab === 'templates' && (
          <div className="space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-lg font-black text-slate-800">Notification Templates</h2>
                <p className="text-xs text-slate-500">
                  Pre-configured reminders and customizable templates for onboarding compliance.
                </p>
              </div>
              <Button
                onClick={() => setEditingTemplate({
                  name: '',
                  slug: `custom_${Date.now()}`,
                  subject: 'HexaLearn Onboarding Notice',
                  body_html: '<p>Hi {employee_name},</p><p>Please complete your onboarding modules.</p>',
                  description: 'Custom reminder'
                })}
                className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl gap-1.5 cursor-pointer"
              >
                <Plus className="h-3.5 w-3.5" />
                <span>Create New Template</span>
              </Button>
            </div>

            {/* Template Edit / Create Modal */}
            {editingTemplate && (
              <div className="bg-white border border-[#00A389]/40 rounded-2xl p-5 shadow-lg space-y-4">
                <div className="flex items-center justify-between border-b pb-3">
                  <h3 className="text-sm font-black text-slate-800">
                    {editingTemplate.id ? 'Edit Template' : 'Create Template'}
                  </h3>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setEditingTemplate(null)}
                    className="text-xs"
                  >
                    Cancel
                  </Button>
                </div>

                <form onSubmit={handleSaveTemplate} className="space-y-4">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="space-y-1">
                      <label className="text-xs font-bold text-slate-700">Template Name</label>
                      <Input
                        type="text"
                        value={editingTemplate.name || ''}
                        onChange={(e) => setEditingTemplate({ ...editingTemplate, name: e.target.value })}
                        required
                        className="text-xs rounded-xl"
                      />
                    </div>
                    <div className="space-y-1">
                      <label className="text-xs font-bold text-slate-700">Description</label>
                      <Input
                        type="text"
                        value={editingTemplate.description || ''}
                        onChange={(e) => setEditingTemplate({ ...editingTemplate, description: e.target.value })}
                        className="text-xs rounded-xl"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="text-xs font-bold text-slate-700">Subject</label>
                    <Input
                      type="text"
                      value={editingTemplate.subject || ''}
                      onChange={(e) => setEditingTemplate({ ...editingTemplate, subject: e.target.value })}
                      required
                      className="text-xs rounded-xl"
                    />
                  </div>

                  <div className="space-y-1">
                    <div className="flex items-center justify-between">
                      <label className="text-xs font-bold text-slate-700">HTML Template Body</label>
                      <span className="text-[10px] text-slate-400">
                        Supports {'{employee_name}'}, {'{completion_percent}'}, {'{deadline_day}'}, {'{onboard_url}'}
                      </span>
                    </div>
                    <textarea
                      value={editingTemplate.body_html || ''}
                      onChange={(e) => setEditingTemplate({ ...editingTemplate, body_html: e.target.value })}
                      rows={8}
                      className="w-full text-xs font-mono bg-slate-50 border border-slate-200 rounded-xl p-3 focus:bg-white"
                      required
                    />
                  </div>

                  <div className="flex justify-end gap-2 pt-2">
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => setEditingTemplate(null)}
                      className="text-xs rounded-xl cursor-pointer"
                    >
                      Cancel
                    </Button>
                    <Button
                      type="submit"
                      disabled={isSavingTemplate}
                      className="bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl cursor-pointer"
                    >
                      {isSavingTemplate ? 'Saving...' : 'Save Template'}
                    </Button>
                  </div>
                </form>
              </div>
            )}

            {/* Templates Grid Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {templates.map((t) => (
                <div
                  key={t.id}
                  className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-2xs hover:shadow-md transition-all flex flex-col justify-between gap-4"
                >
                  <div className="space-y-2">
                    <div className="flex items-start justify-between gap-2">
                      <h3 className="font-black text-slate-800 text-sm">{t.name}</h3>
                      <span className="text-[9px] font-mono px-2 py-0.5 rounded-md bg-slate-100 text-slate-500">
                        {t.slug}
                      </span>
                    </div>
                    <p className="text-xs text-slate-500 leading-snug">{t.description}</p>
                    <div className="bg-slate-50 rounded-xl p-2.5 border border-slate-100 space-y-1">
                      <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Subject</div>
                      <div className="text-xs font-semibold text-slate-700 truncate">{t.subject}</div>
                    </div>
                  </div>

                  <div className="flex items-center justify-between pt-3 border-t border-slate-100">
                    <button
                      onClick={() => {
                        setSelectedTemplateId(t.id);
                        setCampaignSubject(t.subject);
                        setCampaignBody(t.body_html);
                        setActiveTab('send');
                      }}
                      className="text-xs font-bold text-[#00A389] hover:underline flex items-center gap-1 cursor-pointer"
                    >
                      <span>Use in Send</span>
                      <ArrowRight className="h-3 w-3" />
                    </button>

                    <div className="flex items-center gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditingTemplate(t)}
                        className="h-8 w-8 p-0 text-slate-500 hover:text-slate-800 rounded-lg cursor-pointer"
                        title="Edit template"
                      >
                        <Edit3 className="h-3.5 w-3.5" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDeleteTemplate(t.id, t.name)}
                        className="h-8 w-8 p-0 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg cursor-pointer"
                        title="Delete template"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* ============================================================== */}
        {/* TAB 3: DELIVERY LOGS */}
        {/* ============================================================== */}
        {activeTab === 'logs' && (
          <div className="space-y-4">
            
            {/* Log Stats Counter Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Dispatches</div>
                <div className="text-2xl font-black text-slate-800 mt-1">{logStats.total}</div>
              </div>
              <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
                <div className="text-[10px] font-bold text-emerald-600 uppercase tracking-wider">Successfully Sent</div>
                <div className="text-2xl font-black text-emerald-600 mt-1">{logStats.sent}</div>
              </div>
              <div className="bg-white border border-slate-200/90 rounded-2xl p-4 shadow-2xs">
                <div className="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Failed Dispatches</div>
                <div className="text-2xl font-black text-rose-600 mt-1">{logStats.failed}</div>
              </div>
            </div>

            {/* Filter and Search Bar */}
            <div className="bg-white border border-slate-200/80 rounded-2xl px-5 py-3.5 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div className="flex items-center gap-2 flex-wrap">
                {/* View Mode Toggle: 1 Line Per Recipient vs All Dispatches */}
                <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200/80">
                  <button
                    type="button"
                    onClick={() => {
                      setLogViewMode('unique');
                      setLogPage(1);
                      loadLogs(1, logStatusFilter, logSearch, 'unique');
                    }}
                    className={`px-3 py-1 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                      logViewMode === 'unique'
                        ? 'bg-white text-[#00A389] shadow-2xs'
                        : 'text-slate-500 hover:text-slate-800'
                    }`}
                    title="Consolidated view: 1 line per employee recipient with latest status"
                  >
                    1 Line Per Recipient
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setLogViewMode('all');
                      setLogPage(1);
                      loadLogs(1, logStatusFilter, logSearch, 'all');
                    }}
                    className={`px-3 py-1 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                      logViewMode === 'all'
                        ? 'bg-white text-[#00A389] shadow-2xs'
                        : 'text-slate-500 hover:text-slate-800'
                    }`}
                    title="Detailed view: Full historical list of every single email dispatch"
                  >
                    All Dispatches
                  </button>
                </div>

                {/* Status Filter */}
                <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200/80">
                  {(['all', 'sent', 'failed'] as const).map((st) => (
                    <button
                      key={st}
                      onClick={() => {
                        setLogStatusFilter(st);
                        setLogPage(1);
                        loadLogs(1, st, logSearch, logViewMode);
                      }}
                      className={`px-3 py-1 rounded-lg text-xs font-bold uppercase transition-all cursor-pointer ${
                        logStatusFilter === st
                          ? 'bg-white text-[#00A389] shadow-2xs'
                          : 'text-slate-500 hover:text-slate-800'
                      }`}
                    >
                      {st}
                    </button>
                  ))}
                </div>

                <div className="relative">
                  <Search className="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                  <Input
                    type="text"
                    value={logSearch}
                    onChange={(e) => {
                      setLogSearch(e.target.value);
                      loadLogs(1, logStatusFilter, e.target.value, logViewMode);
                    }}
                    placeholder="Search logs..."
                    className="h-8 pl-8 pr-3 text-xs rounded-xl bg-slate-50 w-44"
                  />
                </div>
              </div>

              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => loadLogs(logPage, logStatusFilter, logSearch)}
                  className="h-8 text-xs font-bold rounded-xl cursor-pointer gap-1.5"
                >
                  <RefreshCw className="h-3 w-3" />
                  <span>Refresh</span>
                </Button>
                {logs.length > 0 && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleClearLogs}
                    disabled={isClearingLogs}
                    className="h-8 text-xs font-bold rounded-xl text-rose-600 hover:text-rose-700 hover:bg-rose-50 border-rose-200 cursor-pointer"
                  >
                    Clear History
                  </Button>
                )}
              </div>
            </div>

            {/* Logs Table */}
            <div className="bg-white border border-slate-200/90 rounded-2xl shadow-2xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="bg-slate-50/90 border-b border-slate-200/80 text-[11px] font-black uppercase tracking-wider text-slate-500">
                      <th className="py-3 px-4">Status</th>
                      <th className="py-3 px-4">Recipient</th>
                      <th className="py-3 px-4">Subject</th>
                      <th className="py-3 px-4">Sent At</th>
                      <th className="py-3 px-4">Details / Error</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {isLoadingLogs ? (
                      <tr>
                        <td colSpan={5} className="py-8 text-center text-slate-400">
                          <div className="flex items-center justify-center gap-2">
                            <Loader2 className="h-4 w-4 animate-spin text-[#00A389]" />
                            <span>Loading email history...</span>
                          </div>
                        </td>
                      </tr>
                    ) : logs.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="py-8 text-center text-slate-400">
                          No email delivery logs recorded yet.
                        </td>
                      </tr>
                    ) : (
                      logs.map((log) => (
                        <tr key={log.id} className="hover:bg-slate-50/70 transition-colors">
                          <td className="py-3 px-4 whitespace-nowrap">
                            {log.status === 'sent' ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <CheckCircle2 className="h-3 w-3 text-emerald-600" />
                                <span>Sent</span>
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                <AlertTriangle className="h-3 w-3 text-rose-600" />
                                <span>Failed</span>
                              </span>
                            )}
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-bold text-slate-800">{log.recipient_name}</div>
                            <div className="text-[11px] text-slate-400 font-mono">{log.recipient_email}</div>
                          </td>
                          <td className="py-3 px-4 font-medium text-slate-700 max-w-xs truncate">
                            {log.subject}
                          </td>
                          <td className="py-3 px-4 text-slate-500 whitespace-nowrap text-[11px]">
                            {log.sent_at_formatted}
                          </td>
                          <td className="py-3 px-4 text-slate-600 max-w-sm">
                            {log.error_message ? (
                              <span className="text-[11px] text-rose-600 font-medium break-words">
                                {log.error_message}
                              </span>
                            ) : (
                              <span className="text-[11px] text-slate-400 truncate block">
                                {log.body_snippet || '—'}
                              </span>
                            )}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {/* Pagination Footer */}
              {logTotalPages > 1 && (
                <div className="p-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                  <div>
                    Showing page {logPage} of {logTotalPages} ({logTotal} total logs)
                  </div>
                  <div className="flex items-center gap-1">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        const prev = Math.max(1, logPage - 1);
                        setLogPage(prev);
                        loadLogs(prev);
                      }}
                      disabled={logPage <= 1}
                      className="h-7 text-xs rounded-lg"
                    >
                      Prev
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        const next = Math.min(logTotalPages, logPage + 1);
                        setLogPage(next);
                        loadLogs(next);
                      }}
                      disabled={logPage >= logTotalPages}
                      className="h-7 text-xs rounded-lg"
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </div>

          </div>
        )}

        {/* ============================================================== */}
        {/* MODAL 1: BEAUTIFUL CONFIRMATION POPUP */}
        {/* ============================================================== */}
        {showConfirmModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div className="bg-white border border-slate-200 rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden animate-in zoom-in-95 duration-200">
              {/* Header with Emerald Gradient */}
              <div className="p-5 sm:p-6 pb-4 bg-gradient-to-r from-emerald-50 via-teal-50/40 to-white border-b border-slate-100 flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="h-11 w-11 rounded-2xl bg-gradient-to-tr from-[#00A389] to-emerald-500 flex items-center justify-center text-white shadow-lg shadow-[#00A389]/25 shrink-0">
                    <Send className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-base font-black text-slate-900">Confirm Email Dispatch</h3>
                    <p className="text-xs text-slate-500 font-medium mt-0.5">
                      Review campaign details before sending
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => !isSending && setShowConfirmModal(false)}
                  disabled={isSending}
                  className="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer"
                >
                  <X className="h-5 w-5" />
                </button>
              </div>

              {/* Body Content */}
              <div className="p-5 sm:p-6 space-y-4">
                {/* Target Audience Pill */}
                <div className="p-3.5 rounded-2xl bg-emerald-50/80 border border-emerald-200 flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Users className="h-4 w-4 text-[#00A389]" />
                    <span className="text-xs font-bold text-emerald-950">Target Audience</span>
                  </div>
                  <span className="text-xs font-black text-[#00A389] bg-white px-3 py-1 rounded-xl shadow-2xs border border-emerald-100">
                    {selectedUids.size} {selectedUids.size === 1 ? 'Employee' : 'Employees'} Selected
                  </span>
                </div>

                {/* Campaign Details Box */}
                <div className="space-y-3 bg-slate-50/80 rounded-2xl p-4 border border-slate-200/80 text-xs">
                  <div>
                    <span className="text-slate-400 font-medium block text-[11px]">Email Subject</span>
                    <strong className="text-slate-800 text-xs font-bold block mt-0.5 leading-snug">
                      {campaignSubject}
                    </strong>
                  </div>

                  <div className="pt-2 border-t border-slate-200/60 flex items-center justify-between gap-3">
                    <div>
                      <span className="text-slate-400 font-medium block text-[11px]">Primary Deadline</span>
                      <strong className="text-[#00A389] text-xs font-bold">{deadlineDay}</strong>
                    </div>
                    <div className="text-right">
                      <span className="text-slate-400 font-medium block text-[11px]">Outbound Provider</span>
                      <strong className="text-slate-700 text-xs font-bold">Zoho SMTP (contact-us@hexalearn.com)</strong>
                    </div>
                  </div>

                  {/* Recipient list snippet */}
                  <div className="pt-2 border-t border-slate-200/60">
                    <span className="text-slate-400 font-medium block text-[11px] mb-1.5">Recipient Roster:</span>
                    <div className="max-h-32 overflow-y-auto space-y-1 pr-1">
                      {selectedRecipientsList.map((r) => (
                        <div key={r.uid} className="flex items-center justify-between text-[11px] bg-white px-2.5 py-1.5 rounded-lg border border-slate-200/70">
                          <span className="font-bold text-slate-800 truncate">{r.name}</span>
                          <span className="text-slate-500 font-mono text-[10px] ml-2 truncate">{r.email}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2.5">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setShowConfirmModal(false)}
                  disabled={isSending}
                  className="h-10 px-5 text-xs font-bold rounded-xl border-slate-200 text-slate-700 hover:bg-slate-100 cursor-pointer"
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  onClick={executeSendCampaign}
                  disabled={isSending}
                  className="h-10 px-6 bg-gradient-to-r from-[#00A389] to-emerald-600 hover:from-[#008f77] hover:to-emerald-700 text-white text-xs font-bold rounded-xl shadow-md cursor-pointer flex items-center gap-2"
                >
                  {isSending ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin" />
                      <span>Sending {selectedUids.size} Email(s)...</span>
                    </>
                  ) : (
                    <>
                      <Send className="h-4 w-4" />
                      <span>Yes, Dispatch Emails</span>
                    </>
                  )}
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* ============================================================== */}
        {/* MODAL 2: BEAUTIFUL DISPATCH RESULT POPUP */}
        {/* ============================================================== */}
        {showResultModal && sendResult && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div className="bg-white border border-slate-200 rounded-3xl shadow-2xl max-w-md w-full overflow-hidden animate-in zoom-in-95 duration-200">
              <div className="p-6 text-center space-y-4">
                {/* Result Icon */}
                <div className="flex justify-center">
                  {sendResult.success ? (
                    <div className="h-16 w-16 rounded-full bg-emerald-100 text-[#00A389] flex items-center justify-center ring-8 ring-emerald-50 shadow-inner">
                      <CheckCircle2 className="h-9 w-9" />
                    </div>
                  ) : (
                    <div className="h-16 w-16 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center ring-8 ring-rose-50 shadow-inner">
                      <AlertTriangle className="h-9 w-9" />
                    </div>
                  )}
                </div>

                <div>
                  <h3 className="text-lg font-black text-slate-900">
                    {sendResult.success ? 'Emails Dispatched Successfully!' : 'Dispatch Completed with Warnings'}
                  </h3>
                  <p className="text-xs text-slate-500 font-medium mt-1 leading-relaxed">
                    {sendResult.message}
                  </p>
                </div>

                {/* Scorecard */}
                <div className="grid grid-cols-2 gap-3 pt-2">
                  <div className="p-3 rounded-2xl bg-emerald-50 border border-emerald-200/80 text-center">
                    <span className="text-[10px] font-bold text-emerald-800 uppercase tracking-wider block">Sent Successfully</span>
                    <span className="text-2xl font-black text-emerald-700 mt-0.5 block">{sendResult.sent_count}</span>
                  </div>
                  <div className={`p-3 rounded-2xl border text-center ${
                    sendResult.failed_count > 0 
                      ? 'bg-rose-50 border-rose-200/80 text-rose-700' 
                      : 'bg-slate-50 border-slate-200/80 text-slate-500'
                  }`}>
                    <span className="text-[10px] font-bold uppercase tracking-wider block">Failed</span>
                    <span className="text-2xl font-black mt-0.5 block">{sendResult.failed_count}</span>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-3">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setShowResultModal(false)}
                  className="h-10 px-4 text-xs font-bold rounded-xl border-slate-200 text-slate-700 hover:bg-slate-100 cursor-pointer flex-1"
                >
                  Close
                </Button>
                <Button
                  type="button"
                  onClick={() => {
                    setShowResultModal(false);
                    setActiveTab('logs');
                    loadLogs();
                  }}
                  className="h-10 px-4 bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl cursor-pointer flex-1 flex items-center justify-center gap-1.5"
                >
                  <span>View Delivery Logs</span>
                  <ArrowRight className="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* ============================================================== */}
        {/* MODAL 3: IN-APP NOTICE / ALERT MODAL (Replaces browser alert) */}
        {/* ============================================================== */}
        {noticeModal && noticeModal.isOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div className="bg-white border border-slate-200 rounded-3xl shadow-2xl max-w-sm w-full overflow-hidden animate-in zoom-in-95 duration-200 p-6 text-center space-y-4">
              <div className="flex justify-center">
                <div className={`h-14 w-14 rounded-full flex items-center justify-center ring-8 ${
                  noticeModal.type === 'error'
                    ? 'bg-rose-100 text-rose-600 ring-rose-50'
                    : 'bg-amber-100 text-amber-600 ring-amber-50'
                }`}>
                  <AlertTriangle className="h-7 w-7" />
                </div>
              </div>
              <div>
                <h3 className="text-base font-black text-slate-900">{noticeModal.title}</h3>
                <p className="text-xs text-slate-500 font-medium mt-1 leading-relaxed">
                  {noticeModal.message}
                </p>
              </div>
              <div className="pt-2">
                <Button
                  type="button"
                  onClick={() => setNoticeModal(null)}
                  className="w-full h-10 bg-[#00A389] hover:bg-[#008f77] text-white text-xs font-bold rounded-xl cursor-pointer"
                >
                  Understood
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* ============================================================== */}
        {/* MODAL 4: IN-APP CONFIRM ACTION MODAL (Replaces window.confirm) */}
        {/* ============================================================== */}
        {confirmActionModal && confirmActionModal.isOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div className="bg-white border border-slate-200 rounded-3xl shadow-2xl max-w-sm w-full overflow-hidden animate-in zoom-in-95 duration-200 p-6 space-y-4">
              <div className="flex items-center gap-3">
                <div className={`h-10 w-10 rounded-2xl flex items-center justify-center shrink-0 ${
                  confirmActionModal.confirmVariant === 'danger'
                    ? 'bg-rose-100 text-rose-600'
                    : 'bg-[#E6F7F4] text-[#00A389]'
                }`}>
                  <AlertTriangle className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="text-sm font-black text-slate-900">{confirmActionModal.title}</h3>
                  <p className="text-[11px] text-slate-500">Please confirm your action</p>
                </div>
              </div>

              <p className="text-xs text-slate-600 leading-relaxed">
                {confirmActionModal.message}
              </p>

              <div className="pt-2 flex items-center justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setConfirmActionModal(null)}
                  className="h-9 px-4 text-xs font-bold rounded-xl border-slate-200 text-slate-700 hover:bg-slate-100 cursor-pointer"
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  onClick={confirmActionModal.onConfirm}
                  className={`h-9 px-4 text-xs font-bold rounded-xl cursor-pointer ${
                    confirmActionModal.confirmVariant === 'danger'
                      ? 'bg-rose-600 hover:bg-rose-700 text-white'
                      : 'bg-[#00A389] hover:bg-[#008f77] text-white'
                  }`}
                >
                  {confirmActionModal.confirmLabel}
                </Button>
              </div>
            </div>
          </div>
        )}

      </div>
    </Layout>
  );
}

