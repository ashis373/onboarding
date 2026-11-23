import { useEffect, useState } from "react";
import { Layout } from "@/components/Layout";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { apiGet, apiPost } from "@/lib/api";
import { Plus, ListChecks, Trash2 } from "lucide-react";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useToast } from "@/components/ui/use-toast";

const AdminPools = () => {
  const [items, setItems] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      const data = await apiGet<{ success: boolean; items: any[] }>(`/pools/list.php`);
      if (data.success) setItems(data.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const [openCreate, setOpenCreate] = useState(false);
  const [poolName, setPoolName] = useState('');
  const { toast } = useToast();
  const submitCreate = async () => {
    if (poolName.trim().length < 3) { toast({ description: 'Name must be at least 3 characters' }); return; }
    try { const res = await apiPost(`/pools/create.php`, { name: poolName.trim() }); if ((res as any).success) { setOpenCreate(false); setPoolName(''); load(); } }
    catch (e: any) { toast({ description: e?.message || 'Failed' }); }
  };

  const assignQuick = async (poolId: number) => {
    const raw = prompt('Question IDs CSV to add');
    if (!raw) return;
    const question_ids = raw.split(',').map(s => parseInt(s.trim(), 10)).filter(n => n > 0);
    try { await apiPost(`/pools/assign-questions.php`, { pool_id: poolId, question_ids }); load(); } catch { }
  };

  return (
    <Layout isAdmin>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold">Pools</h1>
            <p className="text-muted-foreground">Group questions for randomization</p>
          </div>
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <DialogTrigger asChild>
              <Button className="gap-2"><Plus className="h-4 w-4" />Create</Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Create Pool</DialogTitle>
              </DialogHeader>
              <div className="space-y-2">
                <Label>Name</Label>
                <Input value={poolName} onChange={e => setPoolName(e.target.value)} placeholder="e.g. Security Basics" />
              </div>
              <DialogFooter>
                <Button variant="ghost" onClick={() => setOpenCreate(false)}>Cancel</Button>
                <Button onClick={submitCreate}>Create</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Question Pools</CardTitle>
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
                  <div key={it.id} className="p-3 border rounded-lg flex items-center justify-between">
                    <div>
                      <div className="font-medium">{it.name}</div>
                      <div className="text-xs text-muted-foreground">{it.description || ''}</div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Button size="sm" variant="outline" onClick={() => assignQuick(it.id)} title="Assign Questions"><ListChecks className="h-4 w-4" /></Button>
                      <Button size="sm" variant="destructive" onClick={async () => {
                        if (!confirm('Delete pool?')) return;
                        try { const ok = await apiGet(`/pools/delete.php?id=${it.id}`); if ((ok as any).success) load(); } catch { }
                      }}><Trash2 className="h-4 w-4" /></Button>
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

export default AdminPools;



