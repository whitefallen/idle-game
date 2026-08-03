import { useState } from 'react';
import { Bar, Button, ErrorNotice, Panel, Stat } from '@/components/ui';
import { bp, duration, t } from '@/lib/i18n';
import type { AttributeCode, CharacterDetail } from '@/lib/types';
import { useAllocatePoints } from './api';

const ATTRIBUTES: AttributeCode[] = ['STR', 'DEX', 'INT', 'CON', 'LUK'];

/**
 * Vigor accrues continuously, so the sheet shows when it will next be full
 * rather than only its current value. The projection is computed from the
 * server's own timestamps; the client never consults its own clock for
 * anything authoritative. See docs/api.md section 7.
 */
function VigorPanel({ character }: { character: CharacterDetail }) {
  const { current, max, full_at: fullAt } = character.vigor;
  const secondsUntilFull = Math.max(0, (new Date(fullAt).getTime() - Date.now()) / 1000);

  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between">
        <span className="text-xs text-ash-400">{t('character.vigor')}</span>
        <span className="text-sm tabular-nums">
          {current} / {max}
        </span>
      </div>
      <Bar value={current} max={max} label={t('character.vigor')} />
      <p className="mt-1 text-xs text-ash-400">
        {current >= max ? t('character.vigorFull') : t('character.vigorFullIn', { time: duration(secondsUntilFull) })}
      </p>
    </div>
  );
}

function AttributeAllocator({ character }: { character: CharacterDetail }) {
  const allocate = useAllocatePoints(character.id);
  const [pending, setPending] = useState<Partial<Record<AttributeCode, number>>>({});

  const spent = Object.values(pending).reduce<number>((total, value) => total + (value ?? 0), 0);
  const remaining = character.unspent_points - spent;

  function adjust(attribute: AttributeCode, delta: number) {
    setPending((current) => {
      const next = Math.max(0, (current[attribute] ?? 0) + delta);

      return { ...current, [attribute]: next };
    });
  }

  async function commit() {
    const allocation = Object.fromEntries(Object.entries(pending).filter(([, value]) => (value ?? 0) > 0));

    await allocate.mutateAsync(allocation as Partial<Record<AttributeCode, number>>);
    setPending({});
  }

  return (
    <div>
      <div className="mb-2 flex items-baseline justify-between">
        <span className="text-xs text-ash-400">{t('character.unspentPoints')}</span>
        <span className="text-sm font-medium tabular-nums">{remaining}</span>
      </div>

      <ul className="space-y-1">
        {ATTRIBUTES.map((attribute) => {
          const staged = pending[attribute] ?? 0;

          return (
            <li key={attribute} className="flex items-center justify-between gap-2 border-b border-ash-800 py-1.5">
              <span className="text-sm">{t(`attribute.${attribute}`)}</span>
              <div className="flex items-center gap-2">
                <span className="text-sm tabular-nums">
                  {character.attributes[attribute]}
                  {staged > 0 && <span className="text-ember-400"> +{staged}</span>}
                </span>
                <Button
                  aria-label={`-1 ${t(`attribute.${attribute}`)}`}
                  onClick={() => adjust(attribute, -1)}
                  disabled={staged === 0}
                  className="px-2 py-1"
                >
                  −
                </Button>
                <Button
                  aria-label={`+1 ${t(`attribute.${attribute}`)}`}
                  onClick={() => adjust(attribute, 1)}
                  disabled={remaining <= 0}
                  className="px-2 py-1"
                >
                  +
                </Button>
              </div>
            </li>
          );
        })}
      </ul>

      <ErrorNotice error={allocate.error} />

      {/*
        Staged locally until submitted — an unsaved draft is genuinely
        browser-only state. Nothing is shown as spent until the server agrees.
      */}
      <Button variant="primary" className="mt-3 w-full" onClick={commit} busy={allocate.isPending} disabled={spent === 0}>
        {t('character.allocate')}
      </Button>
    </div>
  );
}

export function CharacterSheet({
  character,
  onEditPlan,
}: {
  character: CharacterDetail;
  onEditPlan: () => void;
}) {
  const stats = character.derived_stats;

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <Panel title={character.name}>
        <div className="space-y-3">
          <Stat label={t('character.level')} value={character.level} />
          <div>
            <div className="mb-1 flex items-baseline justify-between">
              <span className="text-xs text-ash-400">{t('character.experience')}</span>
              <span className="text-sm tabular-nums">
                {character.experience} / {character.experience_to_next_level}
              </span>
            </div>
            <Bar
              value={character.experience}
              max={Math.max(1, character.experience_to_next_level)}
              label={t('character.experience')}
            />
          </div>
          <VigorPanel character={character} />
          <Stat label={t('character.gold')} value={character.gold} />
          <Stat label={t('character.power')} value={character.power_score} />
        </div>
      </Panel>

      <Panel title={t('character.attributes')}>
        <AttributeAllocator character={character} />
      </Panel>

      <Panel title={t('character.derived')}>
        <div>
          <Stat label={t('stat.maxHealth')} value={stats.maxHealth} />
          <Stat label={t('stat.initiative')} value={stats.initiative} />
          <Stat label={t('stat.maxFocus')} value={`${stats.maxFocus} (+${stats.focusPerTurn})`} />
          <Stat label={t('stat.weaponBaseDamage')} value={stats.weaponBaseDamage} />
          <Stat label={t('stat.scalingBp')} value={bp(stats.scalingBp)} />
          <Stat label={t('stat.critChanceBp')} value={bp(stats.critChanceBp)} />
          <Stat label={t('stat.dodgeChanceBp')} value={bp(stats.dodgeChanceBp)} />
          <Stat label={t('stat.armourRating')} value={stats.armourRating} />
        </div>

        <div className="mt-4 mb-2 flex items-center justify-between gap-2">
          <h3 className="text-xs font-semibold tracking-wide text-ash-400 uppercase">
            {t('character.battlePlan')}
          </h3>
          <Button variant="ghost" className="px-2 py-1 text-xs" onClick={onEditPlan}>
            {t('plan.edit')}
          </Button>
        </div>
        <ol className="space-y-1">
          {character.battle_plan.map((rule, index) => (
            <li key={index} className="flex items-baseline gap-2 text-sm">
              <span className="rounded bg-ash-700 px-1.5 py-0.5 text-[11px] tabular-nums text-ash-200">
                {index + 1}
              </span>
              <span>{t(`ability.${rule.abilityId}`)}</span>
            </li>
          ))}
        </ol>
      </Panel>
    </div>
  );
}
