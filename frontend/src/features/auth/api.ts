import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { Account } from '@/lib/types';

export function useSession() {
  return useQuery({
    queryKey: queryKeys.session,
    queryFn: async (): Promise<Account | null> => {
      try {
        const data = await request<{ account: Account }>('/auth/me');

        return data.account;
      } catch (error) {
        // Not being signed in is a normal state, not a failure. Treating it as
        // an error would leave the login screen behind an error boundary.
        if (error instanceof ApiError && error.isUnauthenticated) {
          return null;
        }

        throw error;
      }
    },
  });
}

export function useLogin() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      request<{ account: Account }>('/auth/login', { method: 'POST', body: credentials }),

    onSuccess: (data) => {
      // Order matters. Everything cached belongs to the previous session, so it
      // is removed first — otherwise the previous account's characters could
      // flash on screen. The new session is then seeded directly from the login
      // response, which both transitions the UI immediately and avoids a
      // redundant /auth/me round trip.
      //
      // Invalidating after removing would be a no-op: there is nothing left to
      // invalidate, so no refetch is scheduled and the app stays on the login
      // screen despite a successful sign-in.
      client.removeQueries();
      client.setQueryData(queryKeys.session, data.account);
    },
  });
}

export function useRegister() {
  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      request<{ account: Account }>('/auth/register', { method: 'POST', body: credentials }),
  });
}

export function useLogout() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: () => request<void>('/auth/logout', { method: 'POST' }),
    onSettled: () => {
      client.clear();
    },
  });
}
