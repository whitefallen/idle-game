import { Bar } from '@/components/ui';
import { duration, t } from '@/lib/i18n';
import type { CharacterDetail } from '@/lib/types';

/**
 * Level, XP, Gold and Vigor, always visible regardless of which section is
 * open. Vigor gates both Encounter and Dungeon (docs/game-bible.md section 9's
 * "cost without completion" rule), so burying it inside one section's panel
 * would force checking a different tab just to see whether an action is
 * affordable.
 */
export function VitalsBar({ character }: { character: CharacterDetail }) {
  const { current, max, full_at: fullAt } = character.vigor;
  const secondsUntilFull = Math.max(0, (new Date(fullAt).getTime() - Date.now()) / 1000);

  return (
    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-ash-700 bg-ash-900 px-4 py-2.5 text-xs sm:px-5">
      <div className="flex items-center gap-2">
        <span className="font-bold tracking-wide text-ash-400 uppercase">{t('character.level')}</span>
        <span className="font-semibold tabular-nums">{character.level}</span>
      </div>

      <div className="flex min-w-0 items-center gap-2">
        <span className="font-bold tracking-wide text-ash-400 uppercase">{t('character.experience')}</span>
        <div className="w-24">
          <Bar value={character.experience} max={character.experience_to_next_level} label={t('character.experience')} />
        </div>
        <span className="tabular-nums">
          {character.experience} / {character.experience_to_next_level}
        </span>
      </div>

      <div className="flex items-center gap-2">
        <span className="font-bold tracking-wide text-ash-400 uppercase">{t('character.gold')}</span>
        <span className="font-semibold tabular-nums">{character.gold}</span>
      </div>

      <div className="flex min-w-0 items-center gap-2">
        <span className="font-bold tracking-wide text-ash-400 uppercase">{t('character.vigor')}</span>
        <div className="w-24">
          <Bar value={current} max={max} tone="blight" label={t('character.vigor')} />
        </div>
        <span className="tabular-nums text-ash-200">
          {current} / {max}
          {current < max && <span className="text-ash-400"> · {duration(secondsUntilFull)}</span>}
        </span>
      </div>
    </div>
  );
}
