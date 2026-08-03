import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { AvailableEncounter, EncounterSummary, ResolvedEncounter } from '@/lib/types';

export function useAvailableEncounters(characterId: string) {
  return useQuery({
    queryKey: queryKeys.availableEncounters(characterId),
    queryFn: async () =>
      (await request<{ encounters: AvailableEncounter[] }>(`/characters/${characterId}/encounters/available`))
        .encounters,
  });
}

export function useEncounterHistory(characterId: string) {
  return useQuery({
    queryKey: queryKeys.encounterHistory(characterId),
    queryFn: async () =>
      (await request<{ encounters: EncounterSummary[] }>(`/characters/${characterId}/encounters`)).encounters,
  });
}

export function useResolveEncounter(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (encounterId: string) =>
      request<ResolvedEncounter>('/encounters', {
        method: 'POST',
        body: { character_id: characterId, encounter_id: encounterId },
        // A fresh key per intent. If the request is retried, the same key is
        // not reused — a genuine second click is a second intent — but a
        // network-level retry of this call replays safely.
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (resolved) => {
      // The response already carries the post-fight character, so it is written
      // straight into the cache rather than triggering a refetch the user would
      // see as a flicker.
      client.setQueryData(queryKeys.character(characterId), resolved.character);

      void client.invalidateQueries({ queryKey: queryKeys.characters });
      void client.invalidateQueries({ queryKey: queryKeys.availableEncounters(characterId) });
      void client.invalidateQueries({ queryKey: queryKeys.encounterHistory(characterId) });
    },
  });
}
