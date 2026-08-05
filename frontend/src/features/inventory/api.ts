import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { Inventory, RefinedItem } from '@/lib/types';

export function useInventory(characterId: string) {
  return useQuery({
    queryKey: queryKeys.inventory(characterId),
    queryFn: () => request<Inventory>(`/characters/${characterId}/inventory`),
  });
}

export function useRefineItem(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ itemId, materialId }: { itemId: string; materialId: string }) =>
      request<RefinedItem>(`/items/${itemId}/refine`, {
        method: 'POST',
        body: { material_id: materialId },
        // A fresh key per intent, so a network-level retry of this call
        // replays safely rather than spending the gold and material twice.
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (result) => {
      // The refined item and the post-spend character both come back with the
      // result, so neither is refetched.
      client.setQueryData<Inventory>(queryKeys.inventory(characterId), (current) => {
        if (!current) return current;

        const replace = (items: typeof current.equipped) =>
          items.map((item) => (item.id === result.item.id ? result.item : item));

        return { ...current, equipped: replace(current.equipped), carried: replace(current.carried) };
      });

      client.setQueryData(queryKeys.character(characterId), result.character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}
