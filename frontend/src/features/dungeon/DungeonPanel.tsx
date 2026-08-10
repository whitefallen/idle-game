import { useState } from 'react';
import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { Dungeon, DungeonRunResult, OfferedDiscipline } from '@/lib/types';
import { useDungeons, useEnterDungeon, usePickDungeonDiscipline } from './api';

/**
 * The discipline pick, when a clear offered one. No special-casing for a
 * single option — the same picker renders whether one or three were
 * offered, per docs/dungeons.md section 2: consistency of the ritual matters
 * more than marking the moment, and the significance is already carried by
 * the mechanic, not by the UI calling attention to itself.
 */
function DisciplinePicker({
  offered,
  onPick,
  busy,
}: {
  offered: OfferedDiscipline[];
  onPick: (disciplineId: string) => void;
  busy: boolean;
}) {
  return (
    <div className="mt-3 rounded-md border border-ember-500/50 bg-ash-800/60 p-3">
      <p className="mb-2 text-sm font-medium text-ember-400">{t('dungeon.disciplinePickTitle')}</p>
      <ul className="space-y-1.5">
        {offered.map((discipline) => (
          <li key={discipline.id} className="flex items-center justify-between gap-2">
            <span className="text-sm">{t(`ability.${discipline.ability_id}`)}</span>
            <Button variant="primary" busy={busy} onClick={() => onPick(discipline.id)}>
              {t('dungeon.pickDiscipline')}
            </Button>
          </li>
        ))}
      </ul>
    </div>
  );
}

function RunResult({
  run,
  onClose,
  onPickDiscipline,
  pickBusy,
}: {
  run: DungeonRunResult;
  onClose: () => void;
  onPickDiscipline: (disciplineId: string) => void;
  pickBusy: boolean;
}) {
  return (
    <div className="mt-3 rounded-md border border-ash-700 bg-ash-800/60 p-3">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-medium">
          {run.cleared
            ? t('dungeon.cleared')
            : t('dungeon.notCleared', { stage: run.stages.length })}
        </p>
        <Button variant="ghost" onClick={onClose}>
          ×
        </Button>
      </div>

      <ul className="mt-2 space-y-1">
        {run.stages.map((stage, index) => (
          <li key={index} className="flex items-center justify-between text-xs text-ash-400">
            <span>{t(`encounterName.${stage.encounterId}`)}</span>
            <span>
              {t(`outcome.${stage.outcome}`)}
              {' · '}
              {stage.experience} xp, {stage.gold} gold
            </span>
          </li>
        ))}
      </ul>

      {run.cleared && (
        <p className="mt-2 text-xs text-ember-400">
          {t('quest.rewards', { xp: run.rewards.experience, gold: run.rewards.gold })}
          {run.rewards.items > 0 && ` · +${run.rewards.items} items`}
        </p>
      )}

      {run.offered_disciplines && run.picked_discipline_id === null && (
        <DisciplinePicker offered={run.offered_disciplines} onPick={onPickDiscipline} busy={pickBusy} />
      )}

      {run.offered_disciplines && run.offered_disciplines.length === 0 && (
        <p className="mt-2 text-xs text-ash-400">{t('dungeon.poolExhausted')}</p>
      )}
    </div>
  );
}

function DungeonRow({
  dungeon,
  onEnter,
  busy,
}: {
  dungeon: Dungeon;
  onEnter: () => void;
  busy: boolean;
}) {
  if (!dungeon.unlocked) {
    return (
      <li className="rounded-md border border-dashed border-ash-700 p-3 text-xs text-ash-400">
        {t(`dungeonName.${dungeon.id}`)} — {t('dungeon.locked', { level: dungeon.required_level })}
      </li>
    );
  }

  const costLine = Object.entries(dungeon.cost)
    .map(([materialId, quantity]) => `${quantity}× ${t(`material.${materialId}`)}`)
    .join(', ');

  return (
    <li className="rounded-md border border-ash-700 bg-ash-800/60 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium">{t(`dungeonName.${dungeon.id}`)}</p>
          <p className="text-xs text-ash-400">
            {t('dungeon.stages', { count: dungeon.stages })}
            {' · '}
            {t('dungeon.completionBonus', { xp: dungeon.completion_bonus.xp, gold: dungeon.completion_bonus.gold })}
          </p>
          <p className="text-xs text-ash-400">{t('dungeon.cost', { cost: costLine })}</p>
        </div>

        {dungeon.cleared ? (
          <span className="text-xs text-ash-400">{t('dungeon.cleared')}</span>
        ) : (
          <Button variant="primary" busy={busy} disabled={!dungeon.affordable} onClick={onEnter}>
            {t('dungeon.enter')}
          </Button>
        )}
      </div>

      {!dungeon.cleared && !dungeon.affordable && (
        <p className="mt-1 text-xs text-ash-400">{t('dungeon.cannotAfford')}</p>
      )}
    </li>
  );
}

export function DungeonPanel({ characterId }: { characterId: string }) {
  const dungeons = useDungeons(characterId);
  const enter = useEnterDungeon(characterId);
  const pickDiscipline = usePickDungeonDiscipline(characterId);
  const [lastRun, setLastRun] = useState<DungeonRunResult | null>(null);

  if (dungeons.isError) {
    return (
      <Panel title={t('dungeon.title')}>
        <ErrorNotice error={dungeons.error} />
      </Panel>
    );
  }

  if (!dungeons.data) {
    return null;
  }

  return (
    <Panel title={t('dungeon.title')}>
      <ErrorNotice error={enter.error ?? pickDiscipline.error} />

      {dungeons.data.length === 0 && <p className="text-sm text-ash-400">{t('dungeon.none')}</p>}

      <ul className="space-y-2">
        {dungeons.data.map((dungeon) => (
          <DungeonRow
            key={dungeon.id}
            dungeon={dungeon}
            busy={enter.isPending}
            onEnter={() =>
              enter.mutate(dungeon.id, {
                onSuccess: (entered) => setLastRun(entered.run),
              })
            }
          />
        ))}
      </ul>

      {lastRun && (
        <RunResult
          run={lastRun}
          onClose={() => setLastRun(null)}
          pickBusy={pickDiscipline.isPending}
          onPickDiscipline={(disciplineId) =>
            pickDiscipline.mutate(
              { runId: lastRun.id, disciplineId },
              { onSuccess: (picked) => setLastRun(picked.run) },
            )
          }
        />
      )}
    </Panel>
  );
}
