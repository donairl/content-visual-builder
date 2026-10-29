import { Suspense } from 'react';
import { EditorApp } from '@/editor/EditorApp';
import '@/editor/editor.css';

export const metadata = { title: 'Editing — Content Visual Builder' };

export default function EditPage() {
  return (
    <Suspense fallback={<div className="ed-center">Loading editor…</div>}>
      <EditorApp />
    </Suspense>
  );
}
