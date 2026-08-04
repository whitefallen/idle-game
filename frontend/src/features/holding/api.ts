import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { ClaimedHolding, Holding } from '@/lib/types';

export function useHolding(characterId: string) {
  return useQuery({
    queryKey: queryKeys.holding(characterId),
    queryFn: async () => (await request<{ holding: Holding }>(`/characters/${characterId}/holding`)).holding,
    // Output accrues in whole units on a schedule the server owns. The panel
    // counts down locally between refetches; this only corrects the drift and
    // moves the pending figure when a unit actually completes. A minute is far
    // below the fastest line's five-minute cadence, so a player never sees a
    // number that is more than one unit stale.
    refetchInterval: 60_000,
  });
}

export function useClaimHolding(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: () =>
      request<ClaimedHolding>(`/characters/${characterId}/holding/claim`, {
        method: 'POST',
        body: {},
        // A fresh key per intent, so a network-level retry of this call replays
        // safely rather than reporting an empty claim for output the first
        // delivery already granted.
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (result) => {
      // Both payloads come back with the claim, so neither is refetched — the
      // gold and the emptied Holding land in one render rather than two.
      client.setQueryData(queryKeys.holding(characterId), result.holding);
      client.setQueryData(queryKeys.character(characterId), result.character);

      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}

export function useAssignSlot(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ index, materialId }: { index: number; materialId: string | null }) =>
      request<{ holding: Holding }>(`/characters/${characterId}/holding/slots/${index}`, {
        method: 'PUT',
        body: { material_id: materialId },
      }),

    onSuccess: (result) => {
      client.setQueryData(queryKeys.holding(characterId), result.holding);
    },
  });
}
