import { PagesApp } from '@/dashboard/PagesApp';
import '@/editor/editor.css';
import '@/dashboard/dashboard.css';

export const metadata = { title: 'Pages — Content Visual Builder' };

export default function Home() {
  return <PagesApp />;
}
