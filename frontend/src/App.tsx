import { useState } from 'react';
import { Button, ErrorNotice } from '@/components/ui';
import { AuthScreen } from '@/features/auth/AuthScreen';
import { useLogout, useSession } from '@/features/auth/api';
import { CharacterList } from '@/features/character/CharacterList';
import { CharacterSheet } from '@/features/character/CharacterSheet';
import { useCharacter } from '@/features/character/api';
import { EncounterPanel } from '@/features/encounter/EncounterPanel';
import { ReplayView } from '@/features/encounter/ReplayView';
import { t } from '@/lib/i18n';
import type { EncounterDetail } from '@/lib/types';

export function App() {
  const session = useSession();
  const logout = useLogout();

  const [characterId, setCharacterId] = useState<string | null>(null);
  const [replay, setReplay] = useState<EncounterDetail | null>(null);

  const character = useCharacter(characterId);

  if (session.isPending) {
    return <main className="grid min-h-dvh place-items-center text-ash-400">…</main>;
  }

  if (session.isError) {
    return (
      <main className="mx-auto max-w-md p-4">
        <ErrorNotice error={session.error} />
      </main>
    );
  }

  if (!session.data) {
    return <AuthScreen />;
  }

  return (
    <div className="mx-auto min-h-dvh max-w-5xl p-4">
      <header className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ember-400">{t('app.title')}</h1>
          <p className="text-xs text-ash-400">{session.data.email}</p>
        </div>

        <div className="flex items-center gap-2">
          {characterId && (
            <Button
              variant="ghost"
              onClick={() => {
                setCharacterId(null);
                setReplay(null);
              }}
            >
              {t('character.back')}
            </Button>
          )}
          <Button onClick={() => logout.mutate()} busy={logout.isPending}>
            {t('auth.signOut')}
          </Button>
        </div>
      </header>

      <main className="space-y-4">
        {!characterId && <CharacterList onSelect={setCharacterId} />}

        {characterId && character.isError && <ErrorNotice error={character.error} />}

        {characterId && character.data && (
          <>
            <CharacterSheet character={character.data} />

            {replay ? (
              <ReplayView encounter={replay} onClose={() => setReplay(null)} />
            ) : (
              <EncounterPanel characterId={characterId} onResolved={setReplay} />
            )}
          </>
        )}
      </main>
    </div>
  );
}
