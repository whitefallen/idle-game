import { useState } from 'react';
import { Button, ErrorNotice, Tabs } from '@/components/ui';
import { Navigation, type Section } from '@/components/Navigation';
import { VitalsBar } from '@/components/VitalsBar';
import { AuthScreen } from '@/features/auth/AuthScreen';
import { useLogout, useSession } from '@/features/auth/api';
import { BattlePlanEditor } from '@/features/character/BattlePlanEditor';
import { CharacterList } from '@/features/character/CharacterList';
import { CharacterSheet } from '@/features/character/CharacterSheet';
import { useCharacter } from '@/features/character/api';
import { DungeonPanel } from '@/features/dungeon/DungeonPanel';
import { EncounterPanel } from '@/features/encounter/EncounterPanel';
import { ReplayView } from '@/features/encounter/ReplayView';
import { HoldingPanel } from '@/features/holding/HoldingPanel';
import { InventoryPanel } from '@/features/inventory/InventoryPanel';
import { QuestPanel } from '@/features/quest/QuestPanel';
import { VendorPanel } from '@/features/vendor/VendorPanel';
import { t } from '@/lib/i18n';
import type { EncounterDetail } from '@/lib/types';

type AdventureTab = 'beacon' | 'quests' | 'dungeons';
type GearTab = 'inventory' | 'vendor';

export function App() {
  const session = useSession();
  const logout = useLogout();

  const [characterId, setCharacterId] = useState<string | null>(null);
  const [replay, setReplay] = useState<EncounterDetail | null>(null);
  const [editingPlan, setEditingPlan] = useState(false);
  const [section, setSection] = useState<Section>('character');
  const [adventureTab, setAdventureTab] = useState<AdventureTab>('beacon');
  const [gearTab, setGearTab] = useState<GearTab>('inventory');

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
    <div className="min-h-dvh">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-ash-700 px-4 py-3 sm:px-5">
        <div>
          <h1 className="font-display text-xl font-bold text-ember-400">{t('app.title')}</h1>
          <p className="text-xs text-ash-400">{session.data.email}</p>
        </div>

        <div className="flex items-center gap-2">
          {characterId && (
            <Button
              variant="ghost"
              onClick={() => {
                setCharacterId(null);
                setReplay(null);
                setEditingPlan(false);
                setSection('character');
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

      {!characterId && (
        <main className="mx-auto max-w-5xl p-4">
          <CharacterList onSelect={setCharacterId} />
        </main>
      )}

      {characterId && character.isError && (
        <main className="mx-auto max-w-5xl p-4">
          <ErrorNotice error={character.error} />
        </main>
      )}

      {characterId && character.data && (
        <>
          <VitalsBar character={character.data} />

          {editingPlan ? (
            <main className="mx-auto max-w-5xl p-4">
              <BattlePlanEditor character={character.data} onClose={() => setEditingPlan(false)} />
            </main>
          ) : replay ? (
            <main className="mx-auto max-w-5xl p-4">
              <ReplayView encounter={replay} onClose={() => setReplay(null)} />
            </main>
          ) : (
            <div className="flex flex-col sm:flex-row">
              <Navigation section={section} onSelect={setSection} />

              <main className="min-w-0 flex-1 space-y-4 p-4 sm:p-5">
                {section === 'character' && (
                  <CharacterSheet
                    character={character.data}
                    onEditPlan={() => {
                      setEditingPlan(true);
                      setReplay(null);
                    }}
                  />
                )}

                {section === 'adventure' && (
                  <>
                    <Tabs
                      tabs={[
                        { key: 'beacon', label: t('encounter.available') },
                        { key: 'quests', label: t('quest.title') },
                        { key: 'dungeons', label: t('dungeon.title') },
                      ]}
                      active={adventureTab}
                      onChange={setAdventureTab}
                    />
                    {adventureTab === 'beacon' && <EncounterPanel characterId={characterId} onResolved={setReplay} />}
                    {adventureTab === 'quests' && <QuestPanel characterId={characterId} />}
                    {adventureTab === 'dungeons' && <DungeonPanel characterId={characterId} />}
                  </>
                )}

                {section === 'holding' && <HoldingPanel characterId={characterId} />}

                {section === 'gear' && (
                  <>
                    <Tabs
                      tabs={[
                        { key: 'inventory', label: t('item.inventory') },
                        { key: 'vendor', label: t('vendor.title') },
                      ]}
                      active={gearTab}
                      onChange={setGearTab}
                    />
                    {gearTab === 'inventory' && <InventoryPanel characterId={characterId} />}
                    {gearTab === 'vendor' && <VendorPanel characterId={characterId} />}
                  </>
                )}
              </main>
            </div>
          )}
        </>
      )}
    </div>
  );
}
