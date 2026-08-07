import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { EquipmentSlotName, EquippedItem, Inventory, RefinedItem, SoldItem } from '@/lib/types';

export function useInventory(characterId: string) {
  return useQuery({
    queryKey: queryKeys.inventory(characterId),
    queryFn: () => request<Inventory>(`/characters/${characterId}/inventory`),
  });
}

/**
 * Equipping and unequipping share one hook because they share one problem.
 *
 * Either can move more than the item named: equipping displaces whatever held
 * the slot, and a two-handed weapon clears the off hand as well. The server
 * returns only the item acted on, so the rest of the inventory is refetched
 * rather than patched — reconstructing the displacement rules here would mean
 * a second copy of them, in the layer that is not allowed to own game rules.
 *
 * No idempotency key: neither grants nor consumes anything, and repeating
 * either is already a no-op server-side.
 */
function useEquipmentMutation(
  characterId: string,
  send: (variables: { itemId: string; slot?: EquipmentSlotName }) => Promise<EquippedItem>,
) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: send,

    onSuccess: (result) => {
      client.setQueryData(queryKeys.character(characterId), result.character);
      void client.invalidateQueries({ queryKey: queryKeys.inventory(characterId) });
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}

export function useEquipItem(characterId: string) {
  return useEquipmentMutation(characterId, ({ itemId, slot }) =>
    request<EquippedItem>(`/items/${itemId}/equip`, {
      method: 'POST',
      // Omitted for anything with one home; sent only where the player has a
      // genuine choice, which today means which ring finger.
      body: slot ? { slot } : {},
    }),
  );
}

export function useUnequipItem(characterId: string) {
  return useEquipmentMutation(characterId, ({ itemId }) =>
    request<EquippedItem>(`/items/${itemId}/unequip`, { method: 'POST' }),
  );
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

export function useSellItem(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (itemId: string) =>
      request<SoldItem>(`/items/${itemId}/sell`, {
        method: 'POST',
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (result, itemId) => {
      // The sold item is gone rather than replaced, so it is filtered out
      // in place instead of patched — the same reasoning useRefineItem's
      // onSuccess documents, just for removal instead of replacement.
      client.setQueryData<Inventory>(queryKeys.inventory(characterId), (current) => {
        if (!current) return current;

        return { ...current, carried: current.carried.filter((item) => item.id !== itemId) };
      });

      client.setQueryData(queryKeys.character(characterId), result.character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}
