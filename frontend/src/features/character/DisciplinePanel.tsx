import { useState } from 'react';
import { Button, ErrorNotice, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { CharacterDetail, DisciplineEntry } from '@/lib/types';
import { useSaveLoadout } from './api';

/**
 * The discipline catalogue and the slotted loadout.
 *
 * Owning a discipline is permanent; slotting is the limited resource. Locked
 * entries are shown greyed with the level that grants them, because a
 * progression axis the player cannot see ahead of is one they cannot plan
 * around — and a disabled control with no stated reason is treated as a bug.
 *
 * Changes are staged locally and saved as a set: the server replaces the whole
 * loadout, so sending one toggle at a time would let two edits race.
 */
export function DisciplinePanel({ character }: { character: CharacterDetail }) {
  const save = useSaveLoadout(character.id);
  const [staged, setStaged] = useState<string[] | null>(null);

  const slotted = staged ?? character.ability_ids;
  const dirty = staged !== null && !sameSet(staged, character.ability_ids);
  const slots = character.loadout_slots;

  function toggle(entry: DisciplineEntry) {
    if (!entry.unlocked) return;

    const next = slotted.includes(entry.ability_id)
      ? slotted.filter((id) => id !== entry.ability_id)
      : [...slotted, entry.ability_id];

    setStaged(next);
  }

  async function commit() {
    if (staged === null) return;

    await save.mutateAsync(staged);
    setStaged(null);
  }

  return (
    <Panel title={t('discipline.title')}>
      <ErrorNotice error={save.error} />

      <p className="mb-3 text-xs text-ash-400">
        {t('discipline.slotsUsed', { used: slotted.length, max: slots })}
      </p>

      <ul className="space-y-1">
        {character.disciplines.map((entry) => {
          const isSlotted = slotted.includes(entry.ability_id);
          const full = !isSlotted && slotted.length >= slots;

          return (
            <li key={entry.id} className="flex items-center justify-between gap-3 py-1">
              <span className={`truncate text-sm ${entry.unlocked ? '' : 'text-ash-500'}`}>
                {t(`ability.${entry.ability_id}`)}
                {!entry.unlocked && entry.unlock_level !== null && (
                  <span className="ml-2 text-xs text-ash-500">
                    {t('discipline.unlocksAt', { level: entry.unlock_level })}
                  </span>
                )}
              </span>

              <Button
                variant={isSlotted ? 'primary' : 'ghost'}
                className="shrink-0 px-2 py-1 text-xs"
                onClick={() => toggle(entry)}
                disabled={!entry.unlocked || full}
              >
                {isSlotted ? t('discipline.slotted') : t('discipline.slot')}
              </Button>
            </li>
          );
        })}
      </ul>

      {dirty && (
        <div className="mt-3 flex items-center gap-2">
          <Button variant="primary" onClick={commit} busy={save.isPending}>
            {save.isPending ? t('discipline.saving') : t('discipline.save')}
          </Button>
          <Button variant="ghost" onClick={() => setStaged(null)} disabled={save.isPending}>
            {t('discipline.revert')}
          </Button>
        </div>
      )}

      {/*
        Unslotting an ability the plan still uses is refused by the server
        rather than silently rewriting the plan, so the order of operations is
        stated up front instead of being discovered through an error.
      */}
      <p className="mt-3 text-xs text-ash-500">{t('discipline.planNote')}</p>
    </Panel>
  );
}

function sameSet(a: string[], b: string[]): boolean {
  return a.length === b.length && [...a].sort().join() === [...b].sort().join();
}
