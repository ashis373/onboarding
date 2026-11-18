import { useEffect, useMemo, useState } from "react";
import { Layout } from "@/components/Layout";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { apiGet, apiPost } from "@/lib/api";
import { Plus, Settings, ListChecks, Trash2, Download, Search, ArrowUp, ArrowDown, Save } from "lucide-react";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { useToast } from "@/components/ui/use-toast";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

const AdminQuizzes = () => {
  const [quizzes, setQuizzes] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const { toast } = useToast();

  // Dialog state
  const [openCreate, setOpenCreate] = useState(false);
  const [openEdit, setOpenEdit] = useState<null | number>(null);
  const [openPools, setOpenPools] = useState<null | number>(null);

  // Form state
  const [form, setForm] = useState<any>({ title: '', passing_score: '', time_limit_minutes: '', attempt_limit: '', randomize_questions: true });
  const editItem = useMemo(() => quizzes.find(q => q.id === openEdit) || null, [openEdit, quizzes]);
  const poolsItem = useMemo(() => quizzes.find(q => q.id === openPools) || null, [openPools, quizzes]);

  // Assign Pools enhanced UI state
  const [poolSearch, setPoolSearch] = useState('');
  const [poolLoading, setPoolLoading] = useState(false);
  const [pools, setPools] = useState<any[]>([]);
  const [selectedDraws, setSelectedDraws] = useState<Record<number, number>>({});
  // Assigned view state
  const [assignedLoading, setAssignedLoading] = useState(false);
  const [assignedPools, setAssignedPools] = useState<any[]>([]);

  const loadAssigned = async (assessmentId: number) => {
    setAssignedLoading(true);
    try {
      const data = await apiGet<{ success: boolean; pools: any[] }>(`/assessments/assigned.php?assessment_id=${assessmentId}`);
      if (data.success) setAssignedPools(data.pools || []);
    } catch {}
    setAssignedLoading(false);
  };
  const [quickPoolName, setQuickPoolName] = useState('');
  const [quickPoolDesc, setQuickPoolDesc] = useState('');

  // Quick Question (single choice) minimal creator
  const [qqPrompt, setQqPrompt] = useState('');
  const [qqPoolId, setQqPoolId] = useState<number | ''>('');
  const [qqType, setQqType] = useState<'single'|'multiple'|'boolean'|'text'|'matching'|'reorder'>('single');
  const [qqOptions, setQqOptions] = useState<{ label: string, is_correct: boolean }[]>([
    { label: '', is_correct: true },
    { label: '', is_correct: false },
  ]);
  const [qqPairs, setQqPairs] = useState<{ left: string, right: string }[]>([{ left: '', right: '' }]);
  const [qqItems, setQqItems] = useState<string[]>(['', '']);
  const [qqExpanded, setQqExpanded] = useState(false);
  const [qSearch, setQSearch] = useState('');
  const [qType, setQType] = useState('');
  const [qLoading, setQLoading] = useState(false);
  const [qItems, setQItems] = useState<any[]>([]);
  const [qSelected, setQSelected] = useState<Record<number, boolean>>({});

  const load = async () => {
    setLoading(true);
    try {
      const data = await apiGet<{ success: boolean; assessments: any[] }>(`/assessments/list.php`);
      if (data.success) setQuizzes(data.assessments || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const resetForm = () => setForm({ title: '', passing_score: '', time_limit_minutes: '', attempt_limit: '', randomize_questions: true });
  const validate = () => {
    if (!form.title || String(form.title).trim().length < 3) { toast({ description: 'Title must be at least 3 characters' }); return false; }
    const numOrBlank = (v: any) => v === '' || v === null || v === undefined || (!isNaN(Number(v)) && Number(v) >= 0);
    if (!numOrBlank(form.passing_score) || Number(form.passing_score) > 100) { toast({ description: 'Passing score must be 0-100' }); return false; }
    if (!numOrBlank(form.time_limit_minutes)) { toast({ description: 'Time limit must be a non-negative number' }); return false; }
    if (!numOrBlank(form.attempt_limit)) { toast({ description: 'Attempt limit must be a non-negative number' }); return false; }
    return true;
  };

  // Load pools on open
  useEffect(() => {
    const fetchPools = async () => {
      if (!openPools) return;
      setPoolLoading(true);
      try {
        const data = await apiGet<{ success: boolean; items: any[] }>(`/pools/list.php?q=${encodeURIComponent(poolSearch)}&limit=50`);
        if (data.success) setPools(data.items || []);
      } catch {}
      setPoolLoading(false);
    };
    fetchPools();
    if (openPools) { loadAssigned(openPools); }
  }, [openPools, poolSearch]);

  useEffect(() => {
    const fetchQuestions = async () => {
      if (!openPools) return;
      setQLoading(true);
      try {
        const data = await apiGet<{ success: boolean; items: any[] }>(`/questions/list.php?q=${encodeURIComponent(qSearch)}&type=${encodeURIComponent(qType)}&limit=100`);
        if (data.success) setQItems(data.items || []);
      } catch {}
      setQLoading(false);
    };
    fetchQuestions();
  }, [openPools, qSearch, qType]);

  const toggleDraw = (poolId: number, value: number) => {
    setSelectedDraws((prev) => ({ ...prev, [poolId]: value }));
  };

  const quickCreatePool = async () => {
    const name = quickPoolName.trim();
    if (!name) { toast({ description: 'Pool name required' }); return; }
    try {
      const res = await apiPost<{ success: boolean; id: number }>(`/pools/create.php`, { name, description: quickPoolDesc });
      if (res.success) {
        setQuickPoolName(''); setQuickPoolDesc('');
        setPoolSearch('');
        const data = await apiGet<{ success: boolean; items: any[] }>(`/pools/list.php?limit=50`);
        if (data.success) setPools(data.items || []);
        toast({ description: 'Pool created' });
      }
    } catch (e: any) { toast({ description: e?.message || 'Failed to create pool' }); }
  };

  const quickCreateQuestion = async () => {
    if (!qqPrompt.trim()) { toast({ description: 'Question prompt required' }); return; }
    const pid = Number(qqPoolId);
    if (!pid) { toast({ description: 'Select a pool' }); return; }

    let payload: any = { type: qqType, prompt: qqPrompt };
    if (qqType === 'single' || qqType === 'multiple' || qqType === 'boolean') {
      const options = qqOptions.filter(o => o.label.trim() !== '');
      if (qqType === 'boolean') {
        // Force True/False options
        payload.options = [
          { label: 'True', is_correct: options.find(o=>o.label.toLowerCase()==='true' && o.is_correct) ? 1 : 0 },
          { label: 'False', is_correct: options.find(o=>o.label.toLowerCase()==='false' && o.is_correct) ? 1 : 0 },
        ];
        if (payload.options.filter((o:any)=>o.is_correct).length !== 1) { toast({ description: 'Mark exactly one of True/False as correct' }); return; }
      } else {
        if (options.length < 2) { toast({ description: 'Provide at least 2 options' }); return; }
        if (qqType === 'single' && options.filter(o => o.is_correct).length !== 1) { toast({ description: 'Select exactly 1 correct option' }); return; }
        if (qqType === 'multiple' && options.filter(o => o.is_correct).length < 1) { toast({ description: 'Select at least 1 correct option' }); return; }
        payload.options = options.map(o=> ({ label: o.label, is_correct: o.is_correct ? 1 : 0 }));
      }
    } else if (qqType === 'matching') {
      const pairs = qqPairs.filter(p => p.left.trim() !== '' && p.right.trim() !== '');
      if (pairs.length < 1) { toast({ description: 'Add at least 1 matching pair' }); return; }
      payload.pairs = pairs.map(p => ({ left: p.left.trim(), right: p.right.trim() }));
    } else if (qqType === 'reorder') {
      const items = qqItems.map(s => s.trim()).filter(Boolean);
      if (items.length < 2) { toast({ description: 'Add at least 2 items to reorder' }); return; }
      payload.items = items;
    }

    try {
      const created = await apiPost<{ success: boolean; id: number }>(`/questions/create.php`, payload);
      if (created.success) {
        await apiPost(`/pools/assign-questions.php`, { pool_id: pid, question_ids: [created.id] });
        setQqPrompt(''); setQqPoolId(''); setQqType('single'); setQqOptions([{ label: '', is_correct: true }, { label: '', is_correct: false }]); setQqPairs([{ left: '', right: '' }]); setQqItems(['', '']);
        toast({ description: 'Question created and added to pool' });
      }
    } catch (e: any) { toast({ description: e?.message || 'Failed to create question' }); }
  };

  return (
    <Layout isAdmin>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold">Assessments</h1>
            <p className="text-muted-foreground">Manage assessments</p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" className="gap-2" onClick={() => {
              window.open(`${API_BASE}/results/export.php`, '_blank');
            }}>
              <Download className="h-4 w-4" />
              Export CSV
            </Button>
            <Dialog open={openCreate} onOpenChange={(o)=>{ setOpenCreate(o); if (!o) resetForm(); }}>
              <DialogTrigger asChild>
                <Button className="gap-2"><Plus className="h-4 w-4"/>Create</Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>Create Assessment</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="title">Title</Label>
                    <Input id="title" value={form.title} onChange={e=>setForm((f:any)=>({...f, title: e.target.value}))} placeholder="e.g. Security Awareness Test" />
                  </div>
                  <div className="grid grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="passing">Passing %</Label>
                      <Input id="passing" inputMode="numeric" value={form.passing_score} onChange={e=>setForm((f:any)=>({...f, passing_score: e.target.value}))} placeholder="e.g. 75" />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="time">Time limit (min)</Label>
                      <Input id="time" inputMode="numeric" value={form.time_limit_minutes} onChange={e=>setForm((f:any)=>({...f, time_limit_minutes: e.target.value}))} placeholder="e.g. 30" />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="attempts">Attempt limit</Label>
                      <Input id="attempts" inputMode="numeric" value={form.attempt_limit} onChange={e=>setForm((f:any)=>({...f, attempt_limit: e.target.value}))} placeholder="blank = unlimited" />
                    </div>
                  </div>
                  <div className="flex items-center justify-between border rounded-md p-3">
                    <div>
                      <div className="font-medium">Randomize questions</div>
                      <div className="text-xs text-muted-foreground">Shuffle the selected questions</div>
                    </div>
                    <Switch checked={!!form.randomize_questions} onCheckedChange={(v)=>setForm((f:any)=>({...f, randomize_questions: v}))} />
                  </div>
                </div>
                <DialogFooter>
                  <Button variant="ghost" onClick={()=>{ setOpenCreate(false); resetForm(); }}>Cancel</Button>
                  <Button onClick={async ()=>{
                    if (!validate()) return;
                    const payload:any = { title: form.title, randomize_questions: form.randomize_questions ? 1 : 0 };
                    if (String(form.passing_score).trim() !== '') payload.passing_score = Number(form.passing_score);
                    if (String(form.time_limit_minutes).trim() !== '') payload.time_limit_minutes = Number(form.time_limit_minutes);
                    if (String(form.attempt_limit).trim() !== '') payload.attempt_limit = Number(form.attempt_limit);
                    try { const res = await apiPost(`/assessments/create.php`, payload); if ((res as any).success) { setOpenCreate(false); resetForm(); load(); } } catch (e:any) { toast({ description: e?.message || 'Failed' }); }
                  }}>Create</Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          </div>
        </div>

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
            <CardTitle>Assessments</CardTitle>
            <CardDescription>{quizzes.length} items</CardDescription>
              </div>
              <Button variant="outline" className="gap-2" onClick={async ()=>{
                const order = quizzes.map(q=> q.id);
                try { await apiPost(`/assessments/update-order.php`, { order }); toast({ description: 'Order saved' }); }
                catch (e:any) { toast({ description: e?.message || 'Failed to save order' }); }
              }}><Save className="h-4 w-4"/>Save Order</Button>
            </div>
          </CardHeader>
          <CardContent>
            {loading ? (
              <div className="flex items-center justify-center min-h-[30vh]">
                <div className="h-10 w-10 rounded-full border-4 border-muted border-t-brand animate-spin" />
              </div>
            ) : (
              <div className="space-y-3">
                {quizzes.map((q, idx) => (
                  <div key={q.id} className="flex items-center justify-between p-3 border rounded-lg">
                    <div className="flex items-center gap-3">
                      <div className="flex flex-col gap-1">
                        <Button variant="outline" size="icon" onClick={()=>{
                          if (idx === 0) return;
                          const next = quizzes.slice();
                          [next[idx-1], next[idx]] = [next[idx], next[idx-1]];
                          setQuizzes(next);
                        }} title="Move Up"><ArrowUp className="h-4 w-4"/></Button>
                        <Button variant="outline" size="icon" onClick={()=>{
                          if (idx === quizzes.length-1) return;
                          const next = quizzes.slice();
                          [next[idx+1], next[idx]] = [next[idx], next[idx+1]];
                          setQuizzes(next);
                        }} title="Move Down"><ArrowDown className="h-4 w-4"/></Button>
                      </div>
                    <div>
                      <div className="font-medium">{q.title}</div>
                      <div className="text-xs text-muted-foreground flex gap-3">
                        <span>{q.questions || 0} questions</span>
                        {q.passing_score != null && <span>Pass: {q.passing_score}%</span>}
                        {q.time_limit_minutes != null && <span>Time: {q.time_limit_minutes}m</span>}
                        {q.attempt_limit != null && <span>Attempts: {q.attempt_limit}</span>}
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Button size="sm" variant="outline" onClick={()=>{ setOpenEdit(q.id); setForm({ title: q.title, passing_score: q.passing_score ?? '', time_limit_minutes: q.time_limit_minutes ?? '', attempt_limit: q.attempt_limit ?? '', randomize_questions: !!q.randomize_questions }); }} title="Settings"><Settings className="h-4 w-4" /></Button>
                      <Button size="sm" variant="outline" onClick={()=> { setOpenPools(q.id); setSelectedDraws({}); setPoolSearch(''); }} title="Manage Pools"><ListChecks className="h-4 w-4" /></Button>
                      <Button size="sm" variant="destructive" onClick={async () => {
                        if (!confirm('Delete assessment?')) return;
                        try { const ok = await apiGet(`/assessments/delete.php?id=${q.id}`); if ((ok as any).success) load(); } catch {}
                      }}><Trash2 className="h-4 w-4" /></Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

      {/* Edit / Settings Dialog */}
      <Dialog open={!!openEdit} onOpenChange={(o)=>{ if (!o) { setOpenEdit(null); } }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Assessment</DialogTitle>
          </DialogHeader>
          {editItem && (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="etitle">Title</Label>
                <Input id="etitle" value={form.title} onChange={e=>setForm((f:any)=>({...f, title: e.target.value}))} />
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="epassing">Passing %</Label>
                  <Input id="epassing" inputMode="numeric" value={form.passing_score} onChange={e=>setForm((f:any)=>({...f, passing_score: e.target.value}))} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="etime">Time limit (min)</Label>
                  <Input id="etime" inputMode="numeric" value={form.time_limit_minutes} onChange={e=>setForm((f:any)=>({...f, time_limit_minutes: e.target.value}))} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="eattempts">Attempt limit</Label>
                  <Input id="eattempts" inputMode="numeric" value={form.attempt_limit} onChange={e=>setForm((f:any)=>({...f, attempt_limit: e.target.value}))} />
                </div>
              </div>
              <div className="flex items-center justify-between border rounded-md p-3">
                <div>
                  <div className="font-medium">Randomize questions</div>
                  <div className="text-xs text-muted-foreground">Shuffle the selected questions</div>
                </div>
                <Switch checked={!!form.randomize_questions} onCheckedChange={(v)=>setForm((f:any)=>({...f, randomize_questions: v}))} />
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="ghost" onClick={()=> setOpenEdit(null)}>Close</Button>
            <Button onClick={async ()=>{
              if (!validate()) return;
              const payload:any = { id: openEdit, title: form.title, randomize_questions: form.randomize_questions ? 1 : 0 };
              if (String(form.passing_score).trim() !== '') payload.passing_score = Number(form.passing_score); else payload.passing_score = null;
              if (String(form.time_limit_minutes).trim() !== '') payload.time_limit_minutes = Number(form.time_limit_minutes); else payload.time_limit_minutes = null;
              if (String(form.attempt_limit).trim() !== '') payload.attempt_limit = Number(form.attempt_limit); else payload.attempt_limit = null;
              try { await apiPost(`/assessments/update.php`, payload); setOpenEdit(null); load(); } catch (e:any) { toast({ description: e?.message || 'Failed' }); }
            }}>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Pools assignment dialog (Enhanced) */}
      <Dialog open={!!openPools} onOpenChange={(o)=>{ if (!o) setOpenPools(null); }}>
        <DialogContent className="max-w-5xl">
          <DialogHeader>
            <DialogTitle>Assign Pools</DialogTitle>
          </DialogHeader>
          {poolsItem && (
            <Tabs defaultValue="pools" className="space-y-6">
              <TabsList>
                <TabsTrigger value="pools">Pools</TabsTrigger>
                <TabsTrigger value="questions">Questions</TabsTrigger>
                <TabsTrigger value="assigned">Assigned</TabsTrigger>
              </TabsList>
              <TabsContent value="assigned" className="space-y-4">
                {assignedLoading ? (
                  <div className="p-4 text-sm text-muted-foreground">Loading assigned…</div>
                ) : assignedPools.length === 0 ? (
                  <div className="p-4 text-sm text-muted-foreground">No pools assigned yet.</div>
                ) : (
            <div className="space-y-3">
                    {assignedPools.map((p:any)=> (
                      <div key={p.pool_id} className="border rounded-md">
                        <div className="flex items-center justify-between p-3">
                          <div>
                            <div className="font-medium">{p.name}</div>
                            {p.description && <div className="text-xs text-muted-foreground">{p.description}</div>}
                          </div>
                          <div className="flex items-center gap-2">
                            <div className="text-xs text-muted-foreground">Draw: {p.questions_to_draw}</div>
                            <Button variant="outline" size="sm" onClick={async()=>{
                              if (!confirm('Unassign this pool from assessment?')) return;
                              try { await apiPost(`/assessments/unassign-pool.php`, { assessment_id: poolsItem.id, pool_id: p.pool_id }); loadAssigned(poolsItem.id); toast({ description: 'Pool unassigned' }); } catch (e:any) { toast({ description: e?.message || 'Failed to unassign' }); }
                            }}>Unassign</Button>
                          </div>
                        </div>
                        <div className="border-t">
                          {Array.isArray(p.questions) && p.questions.length > 0 ? (
                            <div className="divide-y max-h-56 overflow-auto">
                              {p.questions.map((q:any)=> (
                                <div key={q.id} className="flex items-center justify-between p-3">
                                  <div className="text-sm">[{q.type}] {q.prompt}</div>
                                  <Button variant="ghost" size="sm" onClick={async()=>{
                                    if (!confirm('Remove this question from pool?')) return;
                                    try { await apiPost(`/pools/unassign-question.php`, { pool_id: p.pool_id, question_id: q.id }); loadAssigned(poolsItem.id); toast({ description: 'Question removed from pool' }); } catch (e:any) { toast({ description: e?.message || 'Failed to remove question' }); }
                                  }}>Remove</Button>
                                </div>
                              ))}
                            </div>
                          ) : (
                            <div className="p-3 text-xs text-muted-foreground">No questions in this pool.</div>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </TabsContent>
              <TabsContent value="pools" className="space-y-6">
                {/* Quick create pool */}
                <div className="border rounded-md p-3">
                  <div className="font-medium mb-2">Quick Create Pool</div>
                  <div className="grid md:grid-cols-3 gap-2">
                    <Input placeholder="Pool name" value={quickPoolName} onChange={(e)=>setQuickPoolName(e.target.value)} />
                    <Input placeholder="Description (optional)" value={quickPoolDesc} onChange={(e)=>setQuickPoolDesc(e.target.value)} />
                    <Button onClick={quickCreatePool}>Create Pool</Button>
                  </div>
                </div>

                {/* Search and list pools */}
                <div>
                  <div className="flex items-center gap-2 mb-2">
                    <Search className="h-4 w-4 text-muted-foreground" />
                    <Input placeholder="Search pools" value={poolSearch} onChange={(e)=>setPoolSearch(e.target.value)} />
                  </div>
                  <div className="border rounded-md divide-y max-h-64 overflow-auto">
                    {poolLoading ? (
                      <div className="p-4 text-sm text-muted-foreground">Loading pools…</div>
                    ) : pools.length === 0 ? (
                      <div className="p-4 text-sm text-muted-foreground">No pools found. Create one above.</div>
                    ) : (
                      pools.map((p:any) => (
                        <div key={p.id} className="flex items-center justify-between p-3">
                          <div>
                            <div className="font-medium">{p.name}</div>
                            {p.description && <div className="text-xs text-muted-foreground">{p.description}</div>}
                          </div>
                          <div className="flex items-center gap-2">
                            <Label htmlFor={`draw-${p.id}`} className="text-xs text-muted-foreground">Draw</Label>
                            <Input id={`draw-${p.id}`} inputMode="numeric" className="w-20 h-8" value={selectedDraws[p.id] ?? ''} onChange={(e)=> toggleDraw(p.id, Number(e.target.value) || 0)} placeholder="0" />
                          </div>
                        </div>
                      ))
                    )}
                  </div>
                </div>

                {/* Quick create question (collapsed by default) */}
                <div className="border rounded-md p-3">
                  <button className="text-sm text-brand" onClick={()=> setQqExpanded(!qqExpanded)}>
                    {qqExpanded ? '− Hide new question' : '+ Add new question'}
                  </button>
                  {qqExpanded && (
                    <div className="grid gap-2 mt-3">
                      <Input placeholder="Question prompt" value={qqPrompt} onChange={(e)=>setQqPrompt(e.target.value)} />
                      <div className="grid md:grid-cols-3 gap-2">
                        <select className="border rounded-md h-9 px-2 bg-background" value={qqPoolId} onChange={(e)=> setQqPoolId(Number(e.target.value) || '')}>
                          <option value="">Select pool</option>
                          {pools.map((p:any)=> (
                            <option key={p.id} value={p.id}>{p.name}</option>
                          ))}
                        </select>
                        <select className="border rounded-md h-9 px-2 bg-background" value={qqType} onChange={(e)=> setQqType(e.target.value as any)}>
                          <option value="single">Single choice</option>
                          <option value="multiple">Multiple choice</option>
                          <option value="boolean">True/False</option>
                          <option value="text">Short text</option>
                          <option value="matching">Matching</option>
                          <option value="reorder">Reorder</option>
                        </select>
                        <div className="text-xs text-muted-foreground self-center">Question will be added to selected pool</div>
                      </div>
                      {qqType !== 'text' && qqType !== 'matching' && qqType !== 'reorder' && (
                        <div className="grid md:grid-cols-2 gap-2">
                          {qqOptions.map((opt, idx) => (
                            <div key={idx} className="flex items-center gap-2">
                              {qqType === 'single' && (<input type="radio" name="qq-correct" checked={!!opt.is_correct} onChange={()=> setQqOptions(qqOptions.map((o,i)=> ({ ...o, is_correct: i===idx })))} />)}
                              {qqType === 'multiple' && (<input type="checkbox" checked={!!opt.is_correct} onChange={(e)=> setQqOptions(qqOptions.map((o,i)=> i===idx ? { ...o, is_correct: e.target.checked } : o))} />)}
                              {qqType === 'boolean' && (<span className="text-xs text-muted-foreground w-12">{idx===0?'True':'False'}</span>)}
                              <Input placeholder={`Option ${idx+1}`} value={qqType === 'boolean' ? (idx===0?'True':'False') : opt.label} onChange={(e)=> {
                                if (qqType === 'boolean') return; setQqOptions(qqOptions.map((o,i)=> i===idx ? { ...o, label: e.target.value } : o));
                              }} />
                            </div>
                          ))}
                        </div>
                      )}
                      {qqType === 'matching' && (
                        <div className="space-y-2">
                          {qqPairs.map((pair, idx) => (
                            <div key={idx} className="grid md:grid-cols-2 gap-2">
                              <Input placeholder={`Left ${idx+1}`} value={pair.left} onChange={(e)=> setQqPairs(qqPairs.map((p,i)=> i===idx ? { ...p, left: e.target.value } : p))} />
                              <div className="flex gap-2">
                                <Input placeholder={`Right ${idx+1}`} value={pair.right} onChange={(e)=> setQqPairs(qqPairs.map((p,i)=> i===idx ? { ...p, right: e.target.value } : p))} />
                                <Button variant="outline" onClick={()=> setQqPairs(qqPairs.filter((_,i)=> i!==idx))}>Remove</Button>
                              </div>
                            </div>
                          ))}
                          <Button variant="outline" onClick={()=> setQqPairs([...qqPairs, { left: '', right: '' }])}>Add Pair</Button>
                        </div>
                      )}
                      {qqType === 'reorder' && (
                        <div className="space-y-2">
                          {qqItems.map((text, idx) => (
                            <div key={idx} className="flex gap-2">
                              <Input placeholder={`Item ${idx+1}`} value={text} onChange={(e)=> setQqItems(qqItems.map((t,i)=> i===idx ? e.target.value : t))} />
                              <Button variant="outline" onClick={()=> setQqItems(qqItems.filter((_,i)=> i!==idx))}>Remove</Button>
                            </div>
                          ))}
                          <Button variant="outline" onClick={()=> setQqItems([...qqItems, ''])}>Add Item</Button>
                        </div>
                      )}
                      <div className="flex gap-2">
                        {qqType === 'single' || qqType === 'multiple' ? (
                          <Button variant="outline" onClick={()=> setQqOptions([...qqOptions, { label: '', is_correct: false }])}>Add Option</Button>
                        ) : null}
                        <Button onClick={quickCreateQuestion}>Create Question</Button>
                      </div>
                    </div>
                  )}
                </div>
              </TabsContent>
              <TabsContent value="questions" className="space-y-4">
                <div className="grid md:grid-cols-3 gap-2 items-center">
                  <Input placeholder="Search questions" value={qSearch} onChange={(e)=> setQSearch(e.target.value)} />
                  <select className="border rounded-md h-9 px-2 bg-background" value={qType} onChange={(e)=> setQType(e.target.value)}>
                    <option value="">All types</option>
                    <option value="single">Single choice</option>
                    <option value="multiple">Multiple choice</option>
                    <option value="boolean">True/False</option>
                    <option value="matching">Matching</option>
                    <option value="reorder">Reorder</option>
                    <option value="text">Text</option>
                  </select>
                  <div className="text-xs text-muted-foreground">Select questions below to add to a pool</div>
                </div>
                <div className="border rounded-md max-h-64 overflow-auto">
                  {qLoading ? (
                    <div className="p-4 text-sm text-muted-foreground">Loading questions…</div>
                  ) : qItems.length === 0 ? (
                    <div className="p-4 text-sm text-muted-foreground">No questions found.</div>
                  ) : (
                    <div className="divide-y">
                      {qItems.map((q:any) => (
                        <label key={q.id} className="flex items-start gap-3 p-3 cursor-pointer">
                          <input type="checkbox" checked={!!qSelected[q.id]} onChange={(e)=> setQSelected((prev)=> ({ ...prev, [q.id]: e.target.checked }))} />
                          <div>
                            <div className="text-sm font-medium">[{q.type}] {q.prompt}</div>
                            {Array.isArray(q.tags) && q.tags.length > 0 && (
                              <div className="text-xs text-muted-foreground">Tags: {q.tags.join(', ')}</div>
                            )}
                          </div>
                        </label>
                      ))}
            </div>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <select className="border rounded-md h-9 px-2 bg-background" value={qqPoolId} onChange={(e)=> setQqPoolId(Number(e.target.value) || '')}>
                    <option value="">Select pool to add</option>
                    {pools.map((p:any)=> (
                      <option key={p.id} value={p.id}>{p.name}</option>
                    ))}
                  </select>
                  <Button onClick={async ()=>{
                    const pid = Number(qqPoolId);
                    if (!pid) { toast({ description: 'Select a pool' }); return; }
                    const ids = Object.entries(qSelected).filter(([,v])=> v).map(([id])=> Number(id));
                    if (ids.length === 0) { toast({ description: 'Select at least one question' }); return; }
                    try { await apiPost(`/pools/assign-questions.php`, { pool_id: pid, question_ids: ids }); toast({ description: 'Questions added to pool' }); setQSelected({}); }
                    catch (e:any) { toast({ description: e?.message || 'Failed to add questions' }); }
                  }}>Add to Pool</Button>
                </div>
              </TabsContent>
            </Tabs>
          )}
          <DialogFooter>
            <Button variant="ghost" onClick={()=> setOpenPools(null)}>Close</Button>
            <Button onClick={async ()=>{
              // Build pools payload from selected draws, fallback to textarea if none
              const entries = Object.entries(selectedDraws).filter(([,v])=> Number(v) > 0) as [string, number][];
              let poolsPayload = entries.map(([id, draw], idx)=> ({ pool_id: Number(id), questions_to_draw: Number(draw), position: idx }));
              // Fallback to textarea legacy if user used it earlier
              if ((!poolsPayload || poolsPayload.length === 0) && form._poolsText) {
              const text = String(form._poolsText || '').trim();
              const lines = text ? text.split('\n').map(l=>l.trim()).filter(Boolean) : [];
                poolsPayload = lines.map(l=>{ const [id, draw] = l.split(',').map(s=>s.trim()); return { pool_id: Number(id), questions_to_draw: Number(draw) }; }).filter(p=>p.pool_id>0 && p.questions_to_draw>0).map((p,idx)=>({...p, position: idx}));
              }
              try { await apiPost(`/assessments/assign-pools.php`, { assessment_id: openPools, pools: poolsPayload }); setOpenPools(null); load(); } catch (e:any) { toast({ description: e?.message || 'Failed' }); }
            }}>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      </div>
    </Layout>
  );
};

export default AdminQuizzes;


