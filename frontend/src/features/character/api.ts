import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { AttributeCode, CharacterDetail, CharacterSummary, RespecResult } from '@/lib/types';

export function useCharacters(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.characters,
    queryFn: async () => (await request<{ characters: CharacterSummary[] }>('/characters')).characters,
    enabled,
  });
}

export function useCharacter(id: string | null) {
  return useQuery({
    queryKey: queryKeys.character(id ?? ''),
    queryFn: async () => (await request<{ character: CharacterDetail }>(`/characters/${id}`)).character,
    enabled: id !== null,
  });
}

export function useCreateCharacter() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (name: string) =>
      (await request<{ character: CharacterDetail }>('/characters', { method: 'POST', body: { name } })).character,

    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}

/**
 * Replaces the slotted loadout wholesale.
 *
 * The whole set is sent because which abilities are slotted *together* is the
 * decision — the same reason the battle plan is saved as a whole plan rather
 * than rule by rule.
 */
export function useSaveLoadout(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (abilityIds: string[]) =>
      (
        await request<{ character: CharacterDetail }>(`/characters/${characterId}/loadout`, {
          method: 'PUT',
          body: { ability_ids: abilityIds },
        })
      ).character,

    onSuccess: (character) => {
      client.setQueryData(queryKeys.character(characterId), character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}

/**
 * Resets every allocated attribute and returns the points, for gold.
 *
 * Sends no body — the cost comes from the character's level server-side and
 * the reset is total, so there is nothing for the client to say. The cost is
 * never sent either: a client that names a price is a client that can be made
 * to name the wrong one.
 *
 * The inventory is invalidated rather than patched because a respec can take
 * gear off (see RespecResult.unequipped), and which items moved is the server's
 * answer to give, not one worth reconstructing here.
 */
export function useRespec(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: () =>
      request<RespecResult>(`/characters/${characterId}/respec`, {
        method: 'POST',
        // Spends real gold, so a network-level retry must replay rather than
        // charge a second time.
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (result) => {
      client.setQueryData(queryKeys.character(characterId), result.character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });

      if (result.unequipped.length > 0) {
        void client.invalidateQueries({ queryKey: queryKeys.inventory(characterId) });
      }
    },
  });
}

export function useAllocatePoints(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (allocation: Partial<Record<AttributeCode, number>>) =>
      (
        await request<{ character: CharacterDetail }>(`/characters/${characterId}/attributes`, {
          method: 'POST',
          body: { allocation },
        })
      ).character,

    // No optimistic update. Showing points as spent before the server agrees
    // teaches players not to trust the display, and a 200ms wait is cheaper
    // than that. See docs/frontend-architecture.md section 6.
    onSuccess: (character) => {
      client.setQueryData(queryKeys.character(characterId), character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}
