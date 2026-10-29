/**
 * REST client for the backend (schema/README.md §8). Same-origin session cookie; every
 * state-changing request carries the CSRF token ("nonce") from /api/session.
 */
import type { Globals } from '@/core/globals';
import type { PageDocument, ValidationIssue } from '@/core/types';

export interface SessionInfo {
  user: { id: number; name: string; email: string; role: string };
  capabilities: string[];
  csrfToken: string;
}

export interface PageSummary {
  id: number;
  title: string;
  slug: string;
  status: 'draft' | 'published';
  authorId: number;
  authorName: string | null;
  version: number;
  publishedVersion: number | null;
  hasUnpublishedChanges: boolean;
  createdAt: string;
  updatedAt: string;
  publishedAt: string | null;
  url: string;
}

export interface PageDetail extends PageSummary {
  document: PageDocument;
}

/** README §10. */
export interface ComponentSummary {
  id: number;
  name: string;
  authorId: number;
  authorName: string;
  version: number;
  publishedVersion: number;
  hasUnpublishedChanges: boolean;
  createdAt: string;
  updatedAt: string;
  publishedAt: string;
}

export interface ComponentDetail extends ComponentSummary {
  document: PageDocument;
  publishedDocument: PageDocument;
}

/** README §11. */
export interface TemplateSummary {
  id: number;
  name: string;
  kind: 'page' | 'section';
  authorId: number;
  authorName: string;
  createdAt: string;
  updatedAt: string;
}

export interface RevisionSummary {
  id: number;
  kind: 'draft' | 'published';
  version: number;
  authorId: number;
  authorName: string;
  createdAt: string;
  updatedAt: string;
  size: number;
}

export interface MediaItem {
  id: number;
  url: string;
  width: number | null;
  height: number | null;
  mime: string;
  size: number;
}

interface ErrorBody {
  error?: string;
  errors?: ValidationIssue[];
  currentVersion?: number;
  updatedAt?: string;
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly body: ErrorBody = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Network failures and 5xx are worth retrying; client errors are not. */
  get retryable(): boolean {
    return this.status === 0 || this.status >= 500 || this.status === 429;
  }
}

let session: Promise<SessionInfo> | null = null;

export function getSession(force = false): Promise<SessionInfo> {
  if (!session || force) {
    session = request<SessionInfo>('GET', '/api/session').catch((error: unknown) => {
      session = null;
      throw error;
    });
  }
  return session;
}

export const loginUrl = () => `/login?return=${encodeURIComponent(window.location.pathname + window.location.search)}`;

async function request<T>(method: string, path: string, body?: unknown, retriedCsrf = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  let payload: BodyInit | undefined;
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = (await getSession()).csrfToken;
    if (body instanceof FormData) payload = body;
    else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
  }

  let response: Response;
  try {
    response = await fetch(path, { method, headers, body: payload, credentials: 'same-origin' });
  } catch {
    throw new ApiError(0, 'Network error: the server could not be reached.');
  }

  if (response.status === 204) return undefined as T;
  const data = (await response.json().catch(() => ({}))) as unknown;
  if (response.ok) return data as T;

  const errorBody = (data && typeof data === 'object' ? data : {}) as ErrorBody;
  // An expired session rotates the CSRF token: refresh it once and replay.
  if (response.status === 422 && !errorBody.errors && method !== 'GET' && !retriedCsrf) {
    await getSession(true).catch(() => undefined);
    return request<T>(method, path, body, true);
  }
  throw new ApiError(response.status, errorBody.error ?? `Request failed (${response.status}).`, errorBody);
}

export const api = {
  listPages: (query = '') => request<{ items: PageSummary[] }>('GET', `/api/pages${query ? `?q=${encodeURIComponent(query)}` : ''}`),
  createPage: (title: string, slug?: string) => request<PageDetail>('POST', '/api/pages', { title, ...(slug ? { slug } : {}) }),
  getPage: (id: number) => request<PageDetail>('GET', `/api/pages/${id}`),
  updatePage: (id: number, changes: { title?: string; slug?: string }) => request<PageSummary>('PATCH', `/api/pages/${id}`, changes),
  saveDraft: (id: number, document: PageDocument, baseVersion: number) =>
    request<{ version: number; updatedAt: string }>('PUT', `/api/pages/${id}/draft`, { document, baseVersion }),
  publish: (id: number, baseVersion: number) => request<PageSummary>('POST', `/api/pages/${id}/publish`, { baseVersion }),
  unpublish: (id: number) => request<PageSummary>('POST', `/api/pages/${id}/unpublish`),
  deletePage: (id: number) => request<void>('DELETE', `/api/pages/${id}`),
  listComponents: () => request<{ items: ComponentSummary[] }>('GET', '/api/components'),
  getComponent: (id: number) => request<ComponentDetail>('GET', `/api/components/${id}`),
  createComponent: (name: string, document: PageDocument) => request<ComponentDetail>('POST', '/api/components', { name, document }),
  renameComponent: (id: number, name: string) => request<ComponentSummary>('PATCH', `/api/components/${id}`, { name }),
  saveComponentDraft: (id: number, document: PageDocument, baseVersion: number) =>
    request<{ version: number; updatedAt: string }>('PUT', `/api/components/${id}/draft`, { document, baseVersion }),
  publishComponent: (id: number, baseVersion: number) =>
    request<ComponentSummary & { rerendered: number }>('POST', `/api/components/${id}/publish`, { baseVersion }),
  deleteComponent: (id: number) => request<void>('DELETE', `/api/components/${id}`),
  listTemplates: () => request<{ items: TemplateSummary[] }>('GET', '/api/templates'),
  getTemplate: (id: number) => request<TemplateSummary & { document: PageDocument }>('GET', `/api/templates/${id}`),
  createTemplate: (name: string, kind: 'page' | 'section', document: PageDocument) =>
    request<TemplateSummary>('POST', '/api/templates', { name, kind, document }),
  renameTemplate: (id: number, name: string) => request<TemplateSummary>('PATCH', `/api/templates/${id}`, { name }),
  deleteTemplate: (id: number) => request<void>('DELETE', `/api/templates/${id}`),
  listRevisions: (pageId: number) => request<{ items: RevisionSummary[] }>('GET', `/api/pages/${pageId}/revisions`),
  getRevision: (pageId: number, id: number) =>
    request<RevisionSummary & { document: PageDocument }>('GET', `/api/pages/${pageId}/revisions/${id}`),
  getGlobals: () => request<{ globals: Globals; version: number; updatedAt: string | null }>('GET', '/api/globals'),
  saveGlobals: (globals: Globals, baseVersion: number) =>
    request<{ version: number; updatedAt: string; rerendered: number }>('PUT', '/api/globals', { globals, baseVersion }),
  uploadMedia: (file: File) => {
    const form = new FormData();
    form.append('file', file);
    return request<MediaItem>('POST', '/api/media', form);
  },
};
