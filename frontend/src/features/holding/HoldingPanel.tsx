import { Button, ErrorNotice, Panel } from '@/components/ui';
import { duration, t } from '@/lib/i18n';
import type { Holding, ProductionSlot } from '@/lib/types';
import { useAssignSlot, useClaimHolding, useHolding } from './api';

/**
 * The idle layer.
 *
 * Two things the UI must state plainly, because both are rules a player would
 * otherwise discover by losing something:
 *
 * - a slot **at its cap** has stopped producing, and every further hour away is
 *   lost rather than banked;
 * - reassigning a slot **discards** whatever it has pending.
 */
function SlotRow({
  slot,
  holding,
  onAssign,
  busy,
}: {
  slot: ProductionSlot;
  holding: Holding;
  onAssign: (index: number, materialId: string | null) => void;
  busy: boolean;
}) {
  if (!slot.unlocked) {
    return (
      <li className="rounded-md border border-dashed border-ash-700 p-3 text-xs text-ash-400">
        {t('holding.slotLocked', { level: slot.unlocks_at_level })}
      </li>
    );
  }

  return (
    <li className="rounded-md border border-ash-700 bg-ash-800/60 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <label className="flex items-center gap-2 text-xs text-ash-400">
          {t('holding.slot', { index: slot.index + 1 })}
          <select
            className="rounded-md border border-ash-700 bg-ash-950 px-2 py-1 text-sm text-ash-50"
            value={slot.material_id ?? ''}
            disabled={busy}
            onChange={(event) => onAssign(slot.index, event.target.value === '' ? null : event.target.value)}
          >
            <option value="">{t('holding.idle')}</option>
            {holding.lines.map((line) => (
              <option key={line.material_id} value={line.material_id} disabled={!line.unlocked}>
                {t(`material.${line.material_id}`)}
                {line.unlocked
                  ? ` · ${t('holding.perHour', { rate: line.rate_per_hour })}`
                  : ` · ${t('holding.unlocksAt', { level: line.unlock_level })}`}
              </option>
            ))}
          </select>
        </label>

        <span className="text-sm tabular-nums">
          {slot.material_id ? `+${slot.pending}` : '—'}
        </span>
      </div>

      {slot.material_id && (
        <p className="mt-1 text-xs text-ash-400">
          {slot.at_cap ? (
            <span className="text-danger-500">{t('holding.atCap')}</span>
          ) : (
            t('holding.nextIn', { time: duration(slot.seconds_until_next) })
          )}
          {slot.pending > 0 && <span className="ml-2">{t('holding.reassignWarning')}</span>}
        </p>
      )}
    </li>
  );
}

export function HoldingPanel({ characterId }: { characterId: string }) {
  const holding = useHolding(characterId);
  const claim = useClaimHolding(characterId);
  const assign = useAssignSlot(characterId);

  if (holding.isError) {
    return (
      <Panel title={t('holding.title')}>
        <ErrorNotice error={holding.error} />
      </Panel>
    );
  }

  if (!holding.data) {
    return null;
  }

  const data = holding.data;
  const pendingMaterials = Object.values(data.pending.materials).reduce((total, value) => total + value, 0);
  const nothingPending = pendingMaterials === 0 && data.pending.gold === 0;

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Panel
        title={t('holding.title')}
        actions={
          <Button
            variant="primary"
            onClick={() => claim.mutate()}
            busy={claim.isPending}
            // Claiming nothing is harmless, but a button that does nothing
            // visible reads as broken. The reason is stated below instead.
            disabled={nothingPending}
          >
            {t('holding.claim')}
          </Button>
        }
      >
        <ErrorNotice error={claim.error ?? assign.error} />

        <p className="mb-3 text-xs text-ash-400">
          {nothingPending
            ? t('holding.nothingPending')
            : t('holding.pending', { gold: data.pending.gold, materials: pendingMaterials })}
          <span className="block">{t('holding.cap', { time: duration(data.cap_seconds) })}</span>
        </p>

        {/*
          Locked slots are in this list too, sent by the server with the level
          that unlocks them — the next few levels should be legible, for the
          same reason the discipline catalogue ships its locked entries.
        */}
        <ul className="space-y-2">
          {data.slots.map((slot) => (
            <SlotRow
              key={slot.index}
              slot={slot}
              holding={data}
              busy={assign.isPending || claim.isPending}
              onAssign={(index, materialId) => assign.mutate({ index, materialId })}
            />
          ))}
        </ul>

        <p className="mt-3 text-xs text-ash-400">
          {t('holding.tithe', { gold: data.tithe.gold_per_hour })}
          {data.tithe.pending > 0 && <span className="ml-1">(+{data.tithe.pending})</span>}
        </p>
      </Panel>

      <Panel title={t('holding.stash')}>
        {data.stash.length === 0 && <p className="text-sm text-ash-400">{t('holding.stashEmpty')}</p>}

        <ul>
          {data.stash.map((stack) => (
            <li
              key={stack.material_id}
              className="flex items-baseline justify-between gap-3 border-b border-ash-800 py-1.5 last:border-0"
            >
              <span className="text-sm">{t(`material.${stack.material_id}`)}</span>
              <span className="text-sm font-medium tabular-nums">{stack.quantity}</span>
            </li>
          ))}
        </ul>

        <p className="mt-3 text-xs text-ash-400">{t('holding.stashNote')}</p>
      </Panel>
    </div>
  );
}
