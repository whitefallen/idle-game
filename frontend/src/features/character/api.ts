import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { AttributeCode, CharacterDetail, CharacterSummary } from '@/lib/types';

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
