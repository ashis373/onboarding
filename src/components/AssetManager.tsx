import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { apiGet } from "@/lib/api";

type Props = { onPick: (url: string) => void };

export function AssetManager({ onPick }: Props) {
  const [items, setItems] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const load = async () => {
    setLoading(true);
    try { const res = await apiGet<{ success: boolean; items: any[] }>(`/assets/list.php`); if (res.success) setItems(res.items || []); } finally { setLoading(false); }
  };
  useEffect(()=>{ load(); }, []);

  return (
    <Card>
      <CardContent className="p-3">
        {loading ? (
          <div className="text-sm text-muted-foreground">Loading...</div>
        ) : (
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            {items.map(it => (
              <div key={it.id} className="border rounded-md p-2">
                <div className="text-xs truncate" title={it.filename}>{it.filename}</div>
                <div className="text-[10px] text-muted-foreground">{it.mime}</div>
                <Button size="sm" className="mt-2 w-full" onClick={()=> onPick(it.url)}>Insert</Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}


