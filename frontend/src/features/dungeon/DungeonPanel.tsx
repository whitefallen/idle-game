import { useState } from 'react';
import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { Dungeon, DungeonRunResult } from '@/lib/types';
import { useDungeons, useEnterDungeon } from './api';

/**
 * A dungeon result: the per-stage outcome, not a full interactive replay
 * (that lives in encounter/ReplayView for a single fight). A dungeon is
 * several fights at once, so this is a compact summary of each stage rather
 * than a step-through of any one of them.
 */
function RunResult({ run, onClose }: { run: DungeonRunResult; onClose: () => void }) {
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

  const hasKey = dungeon.keys_held > 0;

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
          <p className="text-xs text-ash-400">{t('dungeon.keysHeld', { count: dungeon.keys_held })}</p>
        </div>

        <Button variant="primary" busy={busy} disabled={!hasKey} onClick={onEnter}>
          {t('dungeon.enter')}
        </Button>
      </div>

      {!hasKey && <p className="mt-1 text-xs text-ash-400">{t('dungeon.noKey')}</p>}
    </li>
  );
}

export function DungeonPanel({ characterId }: { characterId: string }) {
  const dungeons = useDungeons(characterId);
  const enter = useEnterDungeon(characterId);
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
      <ErrorNotice error={enter.error} />

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

      {lastRun && <RunResult run={lastRun} onClose={() => setLastRun(null)} />}
    </Panel>
  );
}
