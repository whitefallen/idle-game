import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { EncounterDetail } from '@/lib/types';
import { useAvailableEncounters, useEncounterHistory, useResolveEncounter } from './api';

export function EncounterPanel({
  characterId,
  onResolved,
}: {
  characterId: string;
  onResolved: (encounter: EncounterDetail) => void;
}) {
  const available = useAvailableEncounters(characterId);
  const history = useEncounterHistory(characterId);
  const resolve = useResolveEncounter(characterId);
  const gate = available.data?.activity;

  async function fight(encounterId: string) {
    const result = await resolve.mutateAsync(encounterId);
    onResolved(result.encounter);
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Panel title={t('encounter.available')}>
        <ErrorNotice error={resolve.error} />

        {/*
          The activity gate belongs to the character, so it is stated once at
          the top rather than repeated on every row — and stated at all, because
          a disabled button with no reason is a bug. See docs/progression.md §5.
        */}
        {gate && !gate.ready && (
          <p className="mb-2 text-xs text-ash-400">
            {t('encounter.activityResolving', { seconds: gate.seconds_remaining })}
          </p>
        )}

        <ul className="space-y-2">
          {available.data?.encounters.map((encounter) => {
            const blocked = !encounter.unlocked || !encounter.affordable || !gate?.ready;

            return (
              <li
                key={encounter.id}
                className="flex items-center justify-between gap-3 rounded-md border border-ash-700 bg-ash-800/60 p-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{t(`encounterName.${encounter.id}`)}</p>
                  <p className="text-xs text-ash-400">
                    {t('character.level')} {encounter.level} · {t('encounter.cost', { cost: encounter.vigor_cost })}
                  </p>
                  {/*
                    A gate always says what is missing. "You cannot do this yet"
                    without a reason is a bug. See docs/progression.md §5.
                  */}
                  {!encounter.unlocked && (
                    <p className="text-xs text-danger-500">
                      {t('encounter.locked', { level: encounter.required_level })}
                    </p>
                  )}
                  {encounter.unlocked && !encounter.affordable && (
                    <p className="text-xs text-danger-500">{t('encounter.notEnoughVigor')}</p>
                  )}
                </div>

                <Button
                  variant="primary"
                  onClick={() => fight(encounter.id)}
                  disabled={blocked}
                  busy={resolve.isPending && resolve.variables === encounter.id}
                >
                  {resolve.isPending && resolve.variables === encounter.id
                    ? t('encounter.fighting')
                    : t('encounter.fight')}
                </Button>
              </li>
            );
          })}
        </ul>
      </Panel>

      <Panel title={t('encounter.history')}>
        {history.data?.length === 0 && <p className="text-sm text-ash-400">{t('encounter.noHistory')}</p>}

        <ul className="space-y-1">
          {history.data?.map((encounter) => (
            <li
              key={encounter.id}
              className="flex items-baseline justify-between gap-2 border-b border-ash-800 py-2 last:border-0"
            >
              <span className="truncate text-sm">{t(`encounterName.${encounter.definition_id}`)}</span>
              <span
                className={`text-xs font-medium ${
                  encounter.outcome === 'victory' ? 'text-blight-500' : 'text-ash-400'
                }`}
              >
                {t(`outcome.${encounter.outcome}`)}
                {encounter.rewards.experience > 0 && (
                  <span className="ml-2 text-ash-400">+{encounter.rewards.experience} XP</span>
                )}
              </span>
            </li>
          ))}
        </ul>
      </Panel>
    </div>
  );
}
