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
      // Order matters, and the session query must be updated in place.
      //
      // Seeding it first transitions the UI immediately and avoids a redundant
      // /auth/me round trip. Removing it instead — as an earlier version did —
      // strands the mounted useSession observer on a deleted query: a later
      // setQueryData creates a *new* cache entry the observer never re-binds
      // to, so login succeeds and the app sits on the sign-in screen.
      client.setQueryData(queryKeys.session, data.account);

      // Everything else belongs to the previous account and is dropped rather
      // than invalidated, so their characters cannot flash on screen while a
      // refetch is in flight.
      client.removeQueries({ predicate: (query) => query.queryKey[0] !== 'session' });
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
