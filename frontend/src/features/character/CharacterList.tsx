import { useState, type FormEvent } from 'react';
import { Bar, Button, ErrorNotice, Field, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import { useCharacters, useCreateCharacter } from './api';

export function CharacterList({ onSelect }: { onSelect: (id: string) => void }) {
  const characters = useCharacters(true);
  const create = useCreateCharacter();
  const [name, setName] = useState('');

  async function onCreate(event: FormEvent) {
    event.preventDefault();

    const character = await create.mutateAsync(name.trim());
    setName('');
    onSelect(character.id);
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Panel title={t('character.yours')}>
        {characters.isPending && <p className="text-sm text-ash-400">…</p>}
        {characters.isError && <ErrorNotice error={characters.error} />}

        {characters.data?.length === 0 && <p className="text-sm text-ash-400">{t('character.none')}</p>}

        <ul className="space-y-2">
          {characters.data?.map((character) => (
            <li key={character.id}>
              <button
                type="button"
                onClick={() => onSelect(character.id)}
                className="w-full rounded-md border border-ash-700 bg-ash-800/60 p-3 text-left transition-colors hover:border-ember-500"
              >
                <div className="flex items-baseline justify-between gap-2">
                  <span className="font-medium">{character.name}</span>
                  <span className="text-xs text-ash-400">
                    {t('character.level')} {character.level}
                  </span>
                </div>
                <div className="mt-2 flex items-center gap-2">
                  <span className="text-xs text-ash-400">{t('character.vigor')}</span>
                  <Bar
                    value={character.vigor.current}
                    max={character.vigor.max}
                    label={t('character.vigor')}
                  />
                  <span className="text-xs tabular-nums text-ash-400">
                    {character.vigor.current}/{character.vigor.max}
                  </span>
                </div>
              </button>
            </li>
          ))}
        </ul>
      </Panel>

      <Panel title={t('character.create')}>
        <form onSubmit={onCreate} className="flex flex-col gap-4">
          <Field
            id="character-name"
            label={t('character.name')}
            required
            minLength={3}
            maxLength={24}
            value={name}
            onChange={(event) => setName(event.target.value)}
          />

          <ErrorNotice error={create.error} />

          <Button type="submit" variant="primary" busy={create.isPending} disabled={name.trim().length < 3}>
            {t('character.create')}
          </Button>
        </form>
      </Panel>
    </div>
  );
}
