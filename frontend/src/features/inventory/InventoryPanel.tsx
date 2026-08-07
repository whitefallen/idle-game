import { useState } from 'react';
import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { AttributeCode, EquipmentSlotName, ItemDetail, MaterialStack } from '@/lib/types';
import { useEquipItem, useInventory, useRefineItem, useSellItem, useUnequipItem } from './api';

/**
 * The inventory: what is worn, what is carried, and the four things a player
 * can do to an item — equip, unequip, refine, sell.
 *
 * Requirements are shown but never enforced here. The server refuses an item
 * the character cannot wear and says which requirement failed; duplicating that
 * check to grey out a button would put a game rule in the layer that is not
 * allowed to own one, and the two copies would drift. Same reasoning the Vendor
 * panel applies to its offers.
 */
function requirementSummary(item: ItemDetail): string | null {
  const attributes = Object.entries(item.requirements.attributes) as [AttributeCode, number][];

  const parts = [
    ...(item.requirements.level > 1 ? [t('item.requiresLevel', { level: item.requirements.level })] : []),
    ...attributes.map(([code, value]) => `${value} ${t(`attribute.${code}`)}`),
  ];

  return parts.length === 0 ? null : parts.join(' · ');
}

/**
 * Rings are the only slot a player chooses between, so they are the only case
 * that gets a picker. Everything else has exactly one home and the server
 * derives it — asking would be a question with one answer.
 */
function EquipControl({
  item,
  onEquip,
  busy,
}: {
  item: ItemDetail;
  onEquip: (itemId: string, slot?: EquipmentSlotName) => void;
  busy: boolean;
}) {
  const isRing = item.slot === 'Ring1' || item.slot === 'Ring2';
  const [ring, setRing] = useState<EquipmentSlotName>('Ring1');

  if (!isRing) {
    return (
      <Button variant="primary" busy={busy} onClick={() => onEquip(item.id)}>
        {t('item.equip')}
      </Button>
    );
  }

  return (
    <div className="flex items-center gap-2">
      <select
        aria-label={t('item.chooseRingSlot')}
        className="rounded-md border border-ash-700 bg-ash-950 px-2 py-1 text-xs text-ash-50"
        value={ring}
        disabled={busy}
        onChange={(event) => setRing(event.target.value as EquipmentSlotName)}
      >
        <option value="Ring1">{t('slot.Ring1')}</option>
        <option value="Ring2">{t('slot.Ring2')}</option>
      </select>
      <Button variant="primary" busy={busy} onClick={() => onEquip(item.id, ring)}>
        {t('item.equip')}
      </Button>
    </div>
  );
}

function ItemRow({
  item,
  materials,
  onRefine,
  busy,
  onSell,
  sellBusy,
  onEquip,
  onUnequip,
  equipBusy,
}: {
  item: ItemDetail;
  materials: MaterialStack[];
  onRefine: (itemId: string, materialId: string) => void;
  busy: boolean;
  onSell: (itemId: string) => void;
  sellBusy: boolean;
  onEquip: (itemId: string, slot?: EquipmentSlotName) => void;
  onUnequip: (itemId: string) => void;
  equipBusy: boolean;
}) {
  const { refinement } = item;
  const requirements = requirementSummary(item);

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
          {requirements && !item.equipped_slot && (
            <p className="text-xs text-ash-400">{t('item.requires', { requirements })}</p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm tabular-nums">
            +{refinement.level}/{refinement.max_level}
          </span>

          {item.equipped_slot ? (
            <Button variant="ghost" busy={equipBusy} onClick={() => onUnequip(item.id)}>
              {t('item.unequip')}
            </Button>
          ) : (
            <>
              <EquipControl item={item} onEquip={onEquip} busy={equipBusy} />
              {/*
                Selling is offered only for what is not worn, matching the
                server: an equipped item cannot be sold out from under the
                character.
              */}
              <Button variant="ghost" busy={sellBusy} onClick={() => onSell(item.id)}>
                {t('item.sell', { gold: item.vendor_value })}
              </Button>
            </>
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
  const equip = useEquipItem(characterId);
  const unequip = useUnequipItem(characterId);

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

  const { equipped, carried, materials } = inventory.data;
  const equipBusy = equip.isPending || unequip.isPending;

  const row = (item: ItemDetail) => (
    <ItemRow
      key={item.id}
      item={item}
      materials={materials}
      busy={refine.isPending}
      onRefine={(itemId, materialId) => refine.mutate({ itemId, materialId })}
      sellBusy={sell.isPending}
      onSell={(itemId) => sell.mutate(itemId)}
      equipBusy={equipBusy}
      onEquip={(itemId, slot) => equip.mutate(slot ? { itemId, slot } : { itemId })}
      onUnequip={(itemId) => unequip.mutate({ itemId })}
    />
  );

  return (
    <Panel title={t('item.inventory')}>
      <ErrorNotice error={refine.error} />
      <ErrorNotice error={sell.error} />
      <ErrorNotice error={equip.error} />
      <ErrorNotice error={unequip.error} />

      {equipped.length === 0 && carried.length === 0 ? (
        <p className="text-sm text-ash-400">{t('item.empty')}</p>
      ) : (
        <div className="space-y-4">
          {/*
            Worn and carried are separated rather than listed together: with
            equipping available, which of the two an item is in is the thing
            the player is reading the list to find out.
          */}
          <section>
            <h3 className="mb-2 text-xs font-semibold tracking-wide text-ash-400 uppercase">{t('item.worn')}</h3>
            {equipped.length === 0 ? (
              <p className="text-sm text-ash-400">{t('item.nothingWorn')}</p>
            ) : (
              <ul className="space-y-2">{equipped.map(row)}</ul>
            )}
          </section>

          <section>
            <h3 className="mb-2 text-xs font-semibold tracking-wide text-ash-400 uppercase">{t('item.carried')}</h3>
            {carried.length === 0 ? (
              <p className="text-sm text-ash-400">{t('item.nothingCarried')}</p>
            ) : (
              <ul className="space-y-2">{carried.map(row)}</ul>
            )}
          </section>
        </div>
      )}
    </Panel>
  );
}
