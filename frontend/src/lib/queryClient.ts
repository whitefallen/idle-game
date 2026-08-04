import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './api';

/**
 * Server state lives here and nowhere else.
 *
 * The rule that prevents most bugs in this stack: anything the server knows is
 * owned by TanStack Query; anything only the browser knows is owned by Zustand.
 * Copying server data into a store creates two sources of truth with
 * independent staleness, and every later bug becomes a synchronisation bug.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 15_000,
      // This game is played in a tab left open for hours, so refetching on
      // focus is how a returning player sees current Vigor without a reload.
      refetchOnWindowFocus: true,

      retry: (failureCount, error) => {
        // Retrying an authentication failure just delays the login prompt, and
        // retrying a rejected action can only fail the same way.
        if (error instanceof ApiError && error.status < 500) {
          return false;
        }

        return failureCount < 2;
      },
    },

    mutations: {
      // Gameplay actions are never retried automatically. A retry without the
      // original idempotency key risks a second charge, and the endpoints that
      // grant resources are exactly the ones worth being careful with.
      retry: false,
    },
  },
});

export const queryKeys = {
  session: ['session'] as const,
  characters: ['characters'] as const,
  character: (id: string) => ['characters', id] as const,
  availableEncounters: (characterId: string) => ['characters', characterId, 'encounters', 'available'] as const,
  encounterHistory: (characterId: string) => ['characters', characterId, 'encounters'] as const,
  holding: (characterId: string) => ['characters', characterId, 'holding'] as const,
};
