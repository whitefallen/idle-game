import { useState } from 'react';
import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { ItemDetail, MaterialStack } from '@/lib/types';
import { useInventory, useRefineItem, useSellItem } from './api';

/**
 * The refinement sink, laid over the whole inventory rather than a screen of
 * its own: refining is something a player does to an item they already see,
 * not a destination with nothing else in it. Equipping and unequipping keep
 * their existing endpoints but have no UI yet (docs/items.md section 9.4) —
 * out of scope here, since this panel exists to make refinement usable, not
 * to build the inventory screen in full.
 */
function ItemRow({
  item,
  materials,
  onRefine,
  busy,
  onSell,
  sellBusy,
}: {
  item: ItemDetail;
  materials: MaterialStack[];
  onRefine: (itemId: string, materialId: string) => void;
  busy: boolean;
  onSell: (itemId: string) => void;
  sellBusy: boolean;
}) {
  const { refinement } = item;

  const candidates =
    refinement.next_material_tier === null
      ? []
      : materials.filter((material) => material.tier === refinement.next_material_tier);

  const [chosenId, setChosenId] = useState<string | null>(null);
  const selected = candidates.find((material) => material.material_id === chosenId) ?? candidates[0] ?? null;

  return (
    <li className="rounded-md border border-ash-700 bg-ash-800/60 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium">{t(`item.${item.definition_id}`)}</p>
          <p className="text-xs text-ash-400">
            {t('item.level', { level: item.item_level })} · {t(`rarity.${item.rarity}`)}
            {item.equipped_slot && ` · ${t(`slot.${item.equipped_slot}`)}`}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-sm tabular-nums">
            +{refinement.level}/{refinement.max_level}
          </span>
          {!item.equipped_slot && (
            <Button variant="ghost" busy={sellBusy} onClick={() => onSell(item.id)}>
              {t('item.sell', { gold: item.vendor_value })}
            </Button>
          )}
        </div>
      </div>

      {refinement.at_cap ? (
        <p className="mt-2 text-xs text-ash-400">{t('item.refineMaxed')}</p>
      ) : (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <select
            className="rounded-md border border-ash-700 bg-ash-950 px-2 py-1 text-xs text-ash-50"
            value={selected?.material_id ?? ''}
            disabled={busy || candidates.length === 0}
            onChange={(event) => setChosenId(event.target.value)}
          >
            {candidates.length === 0 && <option value="">{t('item.noMaterial')}</option>}
            {candidates.map((material) => (
              <option key={material.material_id} value={material.material_id}>
                {t(`material.${material.material_id}`)} ({material.quantity})
              </option>
            ))}
          </select>

          <Button
            variant="primary"
            busy={busy}
            disabled={!selected}
            onClick={() => selected && onRefine(item.id, selected.material_id)}
          >
            {t('item.refine', {
              gold: refinement.next_gold_cost ?? 0,
              material: refinement.next_material_cost ?? 0,
            })}
          </Button>
        </div>
      )}
    </li>
  );
}

export function InventoryPanel({ characterId }: { characterId: string }) {
  const inventory = useInventory(characterId);
  const refine = useRefineItem(characterId);
  const sell = useSellItem(characterId);

  if (inventory.isError) {
    return (
      <Panel title={t('item.inventory')}>
        <ErrorNotice error={inventory.error} />
      </Panel>
    );
  }

  if (!inventory.data) {
    return null;
  }

  const items = [...inventory.data.equipped, ...inventory.data.carried];

  return (
    <Panel title={t('item.inventory')}>
      <ErrorNotice error={refine.error} />
      <ErrorNotice error={sell.error} />

      {items.length === 0 ? (
        <p className="text-sm text-ash-400">{t('item.empty')}</p>
      ) : (
        <ul className="space-y-2">
          {items.map((item) => (
            <ItemRow
              key={item.id}
              item={item}
              materials={inventory.data.materials}
              busy={refine.isPending}
              onRefine={(itemId, materialId) => refine.mutate({ itemId, materialId })}
              sellBusy={sell.isPending}
              onSell={(itemId) => sell.mutate(itemId)}
            />
          ))}
        </ul>
      )}
    </Panel>
  );
}
