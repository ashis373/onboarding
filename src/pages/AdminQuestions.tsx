import { useEffect, useState } from "react";
import { Layout } from "@/components/Layout";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { apiGet, apiPost } from "@/lib/api";
import { Plus, Trash2 } from "lucide-react";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useToast } from "@/components/ui/use-toast";

const AdminQuestions = () => {
  const [items, setItems] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      const data = await apiGet<{ success: boolean; items: any[] }>(`/questions/list.php`);
      if (data.success) setItems(data.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const [openCreate, setOpenCreate] = useState(false);
  const [qType, setQType] = useState('single');
  const [qPrompt, setQPrompt] = useState('');
  const [qOptions, setQOptions] = useState<string>('');
  const [qPairs, setQPairs] = useState<string>('');
  const [qItems, setQItems] = useState<string>('');
  const { toast } = useToast();

  const submitCreate = async () => {
    if (qPrompt.trim().length < 3) { toast({ description: 'Prompt must be at least 3 characters' }); return; }
    const payload:any = { type: qType, prompt: qPrompt.trim() };
    if (qType === 'single' || qType === 'boolean' || qType === 'multiple') {
      const parts = qOptions.split(',').map(s=>s.trim()).filter(Boolean);
      if (parts.length < 2) { toast({ description: 'Provide at least 2 options' }); return; }
      const opts = parts.map(t => ({ label: t.replace(/^\*/, ''), is_correct: t.startsWith('*') }));
      if (!opts.some(o=>o.is_correct)) { toast({ description: 'Mark at least one correct option with *' }); return; }
      if ((qType === 'single' || qType === 'boolean') && opts.filter(o=>o.is_correct).length !== 1) { toast({ description: 'Single/Boolean requires exactly one correct option' }); return; }
      payload.options = opts;
    } else if (qType === 'matching') {
      const pairs = qPairs.split('\n').map(s=>s.trim()).filter(Boolean).map(line => {
        const [l, r] = line.split('=').map(s=>s.trim());
        return { left: l, right: r };
      });
      if (pairs.length === 0 || pairs.some(p=>!p.left || !p.right)) { toast({ description: 'Provide pairs as left=right, one per line' }); return; }
      payload.pairs = pairs;
    } else if (qType === 'reorder') {
      const items = qItems.split(',').map(s=>s.trim()).filter(Boolean);
      if (items.length < 2) { toast({ description: 'Provide at least 2 items' }); return; }
      payload.items = items;
    }
    try { const res = await apiPost(`/questions/create.php`, payload); if ((res as any).success) { setOpenCreate(false); setQPrompt(''); setQOptions(''); setQPairs(''); setQItems(''); load(); } }
    catch (e:any) { toast({ description: e?.message || 'Failed' }); }
  };

  return (
    <Layout isAdmin>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold">Questions</h1>
            <p className="text-muted-foreground">Manage question bank</p>
          </div>
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <DialogTrigger asChild>
              <Button className="gap-2"><Plus className="h-4 w-4"/>Create</Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Create Question</DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <div className="grid grid-cols-3 gap-4">
                  <div className="space-y-2">
                    <Label>Type</Label>
                    <Select value={qType} onValueChange={setQType}>
                      <SelectTrigger><SelectValue placeholder="Select type"/></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="single">Single</SelectItem>
                        <SelectItem value="multiple">Multiple</SelectItem>
                        <SelectItem value="boolean">Boolean</SelectItem>
                        <SelectItem value="text">Text</SelectItem>
                        <SelectItem value="matching">Matching</SelectItem>
                        <SelectItem value="reorder">Reorder</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-2 space-y-2">
                    <Label>Prompt</Label>
                    <Input value={qPrompt} onChange={e=>setQPrompt(e.target.value)} placeholder="Enter the question prompt" />
                  </div>
                </div>

                {(qType === 'single' || qType === 'boolean' || qType === 'multiple') && (
                  <div className="space-y-2">
                    <Label>Options (comma separated, prefix * for correct)</Label>
                    <Input value={qOptions} onChange={e=>setQOptions(e.target.value)} placeholder="e.g. *Yes,No" />
                  </div>
                )}

                {qType === 'matching' && (
                  <div className="space-y-2">
                    <Label>Pairs (one per line, left=right)</Label>
                    <Textarea value={qPairs} onChange={e=>setQPairs(e.target.value)} placeholder={"Term A=Definition A\nTerm B=Definition B"} />
                  </div>
                )}

                {qType === 'reorder' && (
                  <div className="space-y-2">
                    <Label>Items (comma separated, correct order)</Label>
                    <Input value={qItems} onChange={e=>setQItems(e.target.value)} placeholder="Step 1, Step 2, Step 3" />
                  </div>
                )}
              </div>
              <DialogFooter>
                <Button variant="ghost" onClick={()=> setOpenCreate(false)}>Cancel</Button>
                <Button onClick={submitCreate}>Create</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Question Bank</CardTitle>
            <CardDescription>{items.length} items</CardDescription>
          </CardHeader>
          <CardContent>
            {loading ? (
              <div className="flex items-center justify-center min-h-[30vh]">
                <div className="h-10 w-10 rounded-full border-4 border-muted border-t-brand animate-spin" />
              </div>
            ) : (
              <div className="space-y-3">
                {items.map((it) => (
                  <div key={it.id} className="p-3 border rounded-lg">
                    <div className="flex items-start justify-between">
                      <div>
                        <div className="font-medium">[{it.type}] {it.prompt}</div>
                        {Array.isArray(it.tags) && it.tags.length > 0 && (
                          <div className="text-xs text-muted-foreground">tags: {it.tags.join(', ')}</div>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <Button size="sm" variant="destructive" onClick={async () => {
                          if (!confirm('Delete question?')) return;
                          try { const ok = await apiGet(`/questions/delete.php?id=${it.id}`); if ((ok as any).success) load(); } catch {}
                        }}><Trash2 className="h-4 w-4"/></Button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </Layout>
  );
};

export default AdminQuestions;



