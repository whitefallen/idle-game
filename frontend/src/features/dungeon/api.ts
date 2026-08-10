import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { Dungeon, EnteredDungeon } from '@/lib/types';

export function useDungeons(characterId: string) {
  return useQuery({
    queryKey: queryKeys.dungeons(characterId),
    queryFn: async () => (await request<{ dungeons: Dungeon[] }>(`/characters/${characterId}/dungeons`)).dungeons,
  });
}

export function useEnterDungeon(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (dungeonId: string) =>
      request<EnteredDungeon>(`/characters/${characterId}/dungeons/${dungeonId}/enter`, {
        method: 'POST',
        body: {},
        // A fresh key per intent. Entering consumes a key material and fights
        // every stage — a retried double-tap without this would consume a
        // second key and fight the whole dungeon twice.
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (entered) => {
      client.setQueryData(queryKeys.character(characterId), entered.character);

      void client.invalidateQueries({ queryKey: queryKeys.characters });
      // The entry cost was consumed and quest-granted materials feed this
      // dungeon list's affordability, so both need to reflect the new balance.
      void client.invalidateQueries({ queryKey: queryKeys.dungeons(characterId) });
      void client.invalidateQueries({ queryKey: queryKeys.quests(characterId) });
    },
  });
}

/**
 * Confirms a discipline pick offered by a prior clear. A separate mutation
 * from useEnterDungeon on purpose — see docs/dungeons.md section 2: the
 * offer and the confirmation are deliberately two requests, always, even
 * when only one option was offered.
 */
export function usePickDungeonDiscipline(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ runId, disciplineId }: { runId: string; disciplineId: string }) =>
      request<EnteredDungeon>(`/characters/${characterId}/dungeons/runs/${runId}/discipline`, {
        method: 'POST',
        body: { discipline_id: disciplineId },
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (picked) => {
      client.setQueryData(queryKeys.character(characterId), picked.character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}
