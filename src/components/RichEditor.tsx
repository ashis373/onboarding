import { useEffect, useRef } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

type Props = {
  value: string;
  onChange: (html: string) => void;
  onUploadAsset?: (file: File) => Promise<string>;
};

export function RichEditor({ value, onChange, onUploadAsset }: Props) {
  const ref = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (ref.current && ref.current.innerHTML !== value) {
      ref.current.innerHTML = value || '';
    }
  }, [value]);

  const exec = (cmd: string, val?: string) => {
    document.execCommand(cmd, false, val);
    if (ref.current) onChange(ref.current.innerHTML);
  };

  return (
    <Card>
      <div className="p-2 border-b flex flex-wrap gap-2">
        <Button size="sm" variant="outline" onClick={()=> exec('bold')}>B</Button>
        <Button size="sm" variant="outline" onClick={()=> exec('italic')}>I</Button>
        <Button size="sm" variant="outline" onClick={()=> exec('underline')}>U</Button>
        <Button size="sm" variant="outline" onClick={()=> exec('insertUnorderedList')}>• List</Button>
        <Button size="sm" variant="outline" onClick={()=> exec('insertOrderedList')}>1. List</Button>
        <Button size="sm" variant="outline" onClick={()=> {
          const url = prompt('Embed URL (image/video/pdf)');
          if (!url) return;
          exec('insertHTML', `<a href="${url}" target="_blank">${url}</a>`);
        }}>Link</Button>
        <input type="file" className="hidden" id="re-upload" onChange={async (e)=>{
          const f = e.target.files?.[0]; if (!f || !onUploadAsset) return;
          const url = await onUploadAsset(f);
          if (!url) return;
          exec('insertImage', url);
          e.currentTarget.value = '';
        }}/>
        <label htmlFor="re-upload">
          <Button size="sm" variant="outline" asChild>
            <span>Upload</span>
          </Button>
        </label>
      </div>
      <CardContent>
        <div
          ref={ref}
          className="min-h-[200px] p-2 prose prose-invert border rounded-md"
          contentEditable
          onInput={(e)=> onChange((e.target as HTMLDivElement).innerHTML)}
          suppressContentEditableWarning
        />
      </CardContent>
    </Card>
  );
}


