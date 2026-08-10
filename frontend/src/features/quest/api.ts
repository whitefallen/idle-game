import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { AcceptedQuest, ClaimedQuest, Quest } from '@/lib/types';

export function useQuests(characterId: string) {
  return useQuery({
    queryKey: queryKeys.quests(characterId),
    queryFn: async () => (await request<{ quests: Quest[] }>(`/characters/${characterId}/quests`)).quests,
    // An active quest's `ready_to_claim` flips on the server's clock, not the
    // client's — refetching is what notices, the same reason the encounter
    // activity gate refetches while it is closed.
    refetchInterval: (query) => (query.state.data?.some((quest) => quest.status === 'active') ? 5000 : false),
  });
}

export function useAcceptQuest(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (questId: string) =>
      request<AcceptedQuest>(`/characters/${characterId}/quests/${questId}/accept`, {
        method: 'POST',
        body: {},
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: () => {
      void client.invalidateQueries({ queryKey: queryKeys.quests(characterId) });
    },
  });
}

export function useClaimQuest(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (questId: string) =>
      request<ClaimedQuest>(`/characters/${characterId}/quests/${questId}/claim`, {
        method: 'POST',
        body: {},
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (claimed) => {
      // The response already carries the post-claim character, so it is
      // written straight into the cache rather than triggering a refetch.
      client.setQueryData(queryKeys.character(characterId), claimed.character);

      void client.invalidateQueries({ queryKey: queryKeys.characters });
      void client.invalidateQueries({ queryKey: queryKeys.quests(characterId) });
    },
  });
}
