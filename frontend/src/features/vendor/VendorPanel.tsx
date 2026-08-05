import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { VendorOffer } from '@/lib/types';
import { useBuyVendorItem, useVendorStock } from './api';

/**
 * The gold sink that replaced durability/repair (docs/items.md section 6):
 * offers are priced against how far above the character's current gear they
 * sit, so the best of today's eight offers are real upgrades worth saving
 * for, not just a lateral option. Stock is rolled once per character per day
 * and never stored — see docs/items.md section 5.
 */
function OfferCard({ offer, onBuy, busy }: { offer: VendorOffer; onBuy: (offerIndex: number) => void; busy: boolean }) {
  return (
    <li className="rounded-md border border-ash-700 bg-ash-800/60 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium">{t(`item.${offer.definition_id}`)}</p>
          <p className="text-xs text-ash-400">
            {t('item.level', { level: offer.item_level })} · {t(`rarity.${offer.rarity}`)}
            {offer.slot && ` · ${t(`slot.${offer.slot}`)}`}
          </p>
        </div>
      </div>

      <div className="mt-2 flex items-center justify-between gap-2">
        <span className="text-xs text-ash-400">{t('vendor.requiresLevel', { level: offer.requirements.level })}</span>
        <Button variant="primary" busy={busy} onClick={() => onBuy(offer.offer_index)}>
          {t('vendor.buy', { gold: offer.price })}
        </Button>
      </div>
    </li>
  );
}

export function VendorPanel({ characterId }: { characterId: string }) {
  const stock = useVendorStock(characterId);
  const buy = useBuyVendorItem(characterId);

  if (stock.isError) {
    return (
      <Panel title={t('vendor.title')}>
        <ErrorNotice error={stock.error} />
      </Panel>
    );
  }

  if (!stock.data) {
    return null;
  }

  return (
    <Panel title={t('vendor.title')}>
      <ErrorNotice error={buy.error} />

      {stock.data.offers.length === 0 ? (
        <p className="text-sm text-ash-400">{t('vendor.empty')}</p>
      ) : (
        <ul className="grid gap-2 sm:grid-cols-2">
          {stock.data.offers.map((offer) => (
            <OfferCard
              key={offer.offer_index}
              offer={offer}
              busy={buy.isPending}
              onBuy={(offerIndex) => buy.mutate(offerIndex)}
            />
          ))}
        </ul>
      )}
    </Panel>
  );
}
