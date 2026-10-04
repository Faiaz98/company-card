const API_BASE_URL = 'http://localhost:8000/api';

type ApiResponse<T> = {
  success: boolean;
  data?: T;
  message?: string;
};

export type User = {
  id: number;
  email: string;
  role: 'ADMIN' | 'STORE_OPERATOR' | 'EMPLOYEE';
};

let csrfToken: string | null = null;

async function fetchCsrfToken(): Promise<string> {
  const response = await fetch(`${API_BASE_URL}/auth/csrf`, {
    credentials: 'include',
  });

  const data = await response.json();

  if (!response.ok || !data.success || !data.data?.token) {
    throw new Error('Unable to initialize security token.');
  }

  csrfToken = data.data.token;

  return csrfToken;
}

export async function getCsrfToken(): Promise<string> {
  if (csrfToken) {
    return csrfToken;
  }

  return fetchCsrfToken();
}

async function request<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<ApiResponse<T>> {
  const method = (options.method || 'GET').toUpperCase();

  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    ...options.headers,
  };

  /*
   * Session-authenticated state-changing requests require CSRF.
   *
   * Login is intentionally excluded because no authenticated
   * session exists yet.
   */
  if (
    method !== 'GET' &&
    endpoint !== '/auth/login' &&
    endpoint !== '/auth/csrf'
  ) {
    const token = await getCsrfToken();

    (headers as Record<string, string>)['X-CSRF-Token'] = token;
  }

  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    credentials: 'include',
    headers,
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'Request failed.');
  }

  return data;
}

export async function login(
  email: string,
  password: string
): Promise<User> {
  const response = await request<{ user: User }>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({
      email,
      password,
    }),
  });

  /*
   * Initialize the CSRF token immediately after authentication.
   */
  csrfToken = null;
  await fetchCsrfToken();

  return response.data!.user;
}

export async function getCurrentUser(): Promise<User | null> {
  try {
    const response = await request<{ user: User }>('/auth/me');

    /*
     * If the browser already has an authenticated session,
     * make sure a CSRF token exists.
     */
    await getCsrfToken();

    return response.data!.user;
  } catch {
    csrfToken = null;
    return null;
  }
}

export async function logout(): Promise<void> {
  await request('/auth/logout', {
    method: 'POST',
  });

  csrfToken = null;
}