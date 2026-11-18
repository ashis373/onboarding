const isLocalCorHis = typeof window !== 'undefined' && window.location.pathname.startsWith('/internal/corhis');
const defaultApiBase = isLocalCorHis ? '/internal/corhis/onboard/api' : '/onboard/api';
export const API_BASE = (import.meta.env.VITE_API_BASE as string) || defaultApiBase;

export async function apiGet<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    credentials: 'include',
    headers: {
      'Accept': 'application/json',
      ...(init.headers || {}),
    },
  });
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json() as Promise<T>;
}

export async function apiPost<T>(path: string, body: any, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    body: body instanceof FormData ? body : JSON.stringify(body),
    credentials: 'include',
    headers: {
      'Accept': 'application/json',
      ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...(init.headers || {}),
    },
    ...init,
  });
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json() as Promise<T>;
}

