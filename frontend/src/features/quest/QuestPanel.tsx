import { Button, ErrorNotice, Panel } from '@/components/ui';
import { duration, t } from '@/lib/i18n';
import type { Quest } from '@/lib/types';
import { useAcceptQuest, useClaimQuest, useQuests } from './api';

/**
 * Kill quests: an expedition, not a live fight. Accepting freezes the
 * character as a combat snapshot and starts a timer; claiming, once that
 * timer elapses, resolves the one fight it was frozen for. Nothing here is
 * tracked live — there is no progress bar to poll mid-fight, only a
 * countdown to the moment a claim becomes possible. See
 * docs/adr/0008-quest-snapshot-resolution.md.
 */
function QuestRow({
  quest,
  onAccept,
  onClaim,
  busy,
}: {
  quest: Quest;
  onAccept: () => void;
  onClaim: () => void;
  busy: boolean;
}) {
  const rewardsLine = t('quest.rewards', { xp: quest.rewards.xp, gold: quest.rewards.gold });

  if (!quest.unlocked) {
    return (
      <li className="rounded-md border border-dashed border-ash-700 p-3 text-xs text-ash-400">
        {t(`questName.${quest.id}`)} — {t('quest.locked', { level: quest.required_level })}
      </li>
    );
  }

  const secondsRemaining =
    quest.completes_at !== null ? Math.max(0, (new Date(quest.completes_at).getTime() - Date.now()) / 1000) : 0;

  return (
    <li className="rounded-md border border-ash-700 bg-ash-800/60 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium">{t(`questName.${quest.id}`)}</p>
          <p className="text-xs text-ash-400">{rewardsLine}</p>
        </div>

        {quest.status === 'active' && !quest.ready_to_claim && (
          <span className="text-xs text-ash-400">{t('quest.active', { time: duration(secondsRemaining) })}</span>
        )}

        {quest.status === 'active' && quest.ready_to_claim && (
          <Button variant="primary" busy={busy} onClick={onClaim}>
            {t('quest.claim')}
          </Button>
        )}

        {(quest.status === 'available' || quest.status === 'failed') && (
          <Button variant="primary" busy={busy} onClick={onAccept}>
            {t('quest.accept')}
          </Button>
        )}

        {quest.status === 'claimed' && <span className="text-xs text-ash-400">{t('quest.claimed')}</span>}
      </div>

      {quest.status === 'failed' && <p className="mt-1 text-xs text-danger-500">{t('quest.failed')}</p>}
    </li>
  );
}

export function QuestPanel({ characterId }: { characterId: string }) {
  const quests = useQuests(characterId);
  const accept = useAcceptQuest(characterId);
  const claim = useClaimQuest(characterId);

  if (quests.isError) {
    return (
      <Panel title={t('quest.title')}>
        <ErrorNotice error={quests.error} />
      </Panel>
    );
  }

  if (!quests.data) {
    return null;
  }

  return (
    <Panel title={t('quest.title')}>
      <ErrorNotice error={accept.error ?? claim.error} />

      {quests.data.length === 0 && <p className="text-sm text-ash-400">{t('quest.none')}</p>}

      <ul className="space-y-2">
        {quests.data.map((quest) => (
          <QuestRow
            key={quest.id}
            quest={quest}
            busy={accept.isPending || claim.isPending}
            onAccept={() => accept.mutate(quest.id)}
            onClaim={() => claim.mutate(quest.id)}
          />
        ))}
      </ul>
    </Panel>
  );
}
