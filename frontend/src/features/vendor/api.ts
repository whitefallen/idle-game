import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { newIdempotencyKey, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { Inventory, PurchasedItem, VendorStock } from '@/lib/types';

export function useVendorStock(characterId: string) {
  return useQuery({
    queryKey: queryKeys.vendorStock(characterId),
    queryFn: () => request<VendorStock>(`/characters/${characterId}/vendor`),
  });
}

export function useBuyVendorItem(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (offerIndex: number) =>
      request<PurchasedItem>(`/characters/${characterId}/vendor/buy`, {
        method: 'POST',
        body: { offer_index: offerIndex },
        // A fresh key per intent, so a network-level retry of this call
        // replays safely rather than spending the gold and minting the item
        // twice.
        idempotencyKey: newIdempotencyKey(),
      }),

    onSuccess: (result) => {
      // The bought item joins the carried list directly rather than
      // refetching inventory — the response already has everything the
      // presenter needs to render it.
      client.setQueryData<Inventory>(queryKeys.inventory(characterId), (current) => {
        if (!current) return current;

        return { ...current, carried: [...current.carried, result.item] };
      });

      client.setQueryData(queryKeys.character(characterId), result.character);
      void client.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}
