import { useEffect, useMemo } from 'react';
import { Bar, Button, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { EncounterDetail } from '@/lib/types';
import { BASE_FRAME_DELAY_MS, REPLAY_SPEEDS, useReplayStore } from '@/stores/useReplayStore';
import { buildFrames, participantsByTeam, type CombatLog, type LogEvent, type ReplayFrame } from './replay';

/**
 * The name to show for a participant.
 *
 * A monster's `name` is its localisation key, because the server never sends
 * player-facing prose — so it must be resolved here. A character's name is the
 * one its player chose and is shown verbatim. Both the roster and the
 * narration go through this, or the two disagree on screen.
 */
function displayName(log: CombatLog, id: string): string {
  const participant = log.participants.find((candidate) => candidate.id === id);

  if (!participant) return id;

  return participant.team === 'enemies' ? t(`monster.${participant.definitionId}`) : participant.name;
}

/**
 * Renders one log event as a sentence.
 *
 * Events carry ids and numbers only, so all text is resolved here from
 * localisation keys — which is what lets a stored log stay renderable in any
 * language added later.
 */
function narrate(event: LogEvent, nameOf: (id: string) => string): string {
  const source = nameOf(String(event.source ?? ''));
  const target = nameOf(String(event.target ?? ''));

  switch (event.t) {
    case 'round.start':
      return t('event.round.start', { round: Number(event.round) });
    case 'damage':
      return t(event.crit === true ? 'event.damage.crit' : 'event.damage', {
        source,
        target,
        amount: Number(event.amount),
      });
    case 'miss':
      return t('event.miss', { source, target });
    case 'heal':
      return t('event.heal', { source, target, amount: Number(event.amount) });
    case 'effect.applied':
      return t('event.effect.applied', { target, effect: t(`effect.${String(event.effect)}`) });
    case 'effect.expired':
      return t('event.effect.expired', { target, effect: t(`effect.${String(event.effect)}`) });
    case 'effect.ticked':
      return t('event.effect.ticked', {
        target,
        effect: t(`effect.${String(event.effect)}`),
        amount: Number(event.amount),
      });
    case 'died':
      return t('event.died', { participant: nameOf(String(event.participant ?? '')) });
    case 'plan.exhausted':
      return t('event.plan.exhausted', { actor: nameOf(String(event.actor ?? '')) });
    case 'encounter.end':
      return t('event.encounter.end');
    default:
      return '';
  }
}

function CombatantRow({
  name,
  health,
  maxHealth,
  effects,
  isActor,
  defeated,
}: {
  name: string;
  health: number;
  maxHealth: number;
  effects: string[];
  isActor: boolean;
  defeated: boolean;
}) {
  return (
    <div className={`rounded-md px-2 py-2 ${isActor ? 'bg-ash-800' : ''} ${defeated ? 'opacity-40' : ''}`}>
      <div className="mb-1 flex items-baseline justify-between gap-2">
        <span className="truncate text-sm font-medium">{name}</span>
        <span className="text-xs tabular-nums text-ash-400">
          {health} / {maxHealth}
        </span>
      </div>
      <Bar value={health} max={maxHealth} tone={defeated ? 'danger' : 'blight'} label={name} />
      {effects.length > 0 && (
        <ul className="mt-1.5 flex flex-wrap gap-1">
          {effects.map((effect) => (
            <li key={effect} className="rounded bg-ash-700 px-1.5 py-0.5 text-[11px] text-ash-200">
              {t(`effect.${effect}`)}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function ReplayView({ encounter, onClose }: { encounter: EncounterDetail; onClose: () => void }) {
  const log = encounter.log;

  // Computed once per log; seeking is then an array lookup.
  const frames = useMemo(() => buildFrames(log), [log]);

  const { frameIndex, playing, speed, setFrame, toggle, restart, skipTo, setSpeed, reset } = useReplayStore();

  // A new encounter always starts from the beginning at normal speed.
  useEffect(() => {
    reset();
  }, [encounter.id, reset]);

  const lastIndex = frames.length - 1;

  useEffect(() => {
    if (!playing || frameIndex >= lastIndex) return;

    const timer = window.setTimeout(() => {
      setFrame(Math.min(frameIndex + 1, lastIndex));
    }, BASE_FRAME_DELAY_MS / speed);

    return () => window.clearTimeout(timer);
  }, [playing, frameIndex, lastIndex, speed, setFrame]);

  const frame: ReplayFrame = frames[frameIndex] ?? frames[0]!;
  const nameOf = (id: string) => displayName(log, id);

  const players = participantsByTeam(log, 'players');
  const enemies = participantsByTeam(log, 'enemies');

  const rule = frame.activeRule;
  const finished = frameIndex >= lastIndex;

  return (
    <Panel
      title={t('replay.title')}
      actions={
        <Button variant="ghost" onClick={onClose}>
          {t('replay.close')}
        </Button>
      }
    >
      <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
        <span className="font-semibold text-ember-400">{t(`outcome.${encounter.outcome}`)}</span>
        <span className="text-ash-400">{t('replay.round', { round: frame.round })}</span>
        {encounter.outcome === 'victory' && (
          <span className="text-ash-200">
            {t('replay.rewards', {
              experience: encounter.rewards.experience,
              gold: encounter.rewards.gold,
            })}
          </span>
        )}
        {encounter.rewards.vigorRefunded > 0 && (
          <span className="text-ash-200">{t('replay.vigorRefunded', { vigor: encounter.rewards.vigorRefunded })}</span>
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1">
          {players.map((participant) => (
            <CombatantRow
              key={participant.id}
              name={participant.name}
              health={frame.health[participant.id] ?? participant.maxHealth}
              maxHealth={participant.maxHealth}
              effects={frame.effects[participant.id] ?? []}
              isActor={rule?.actorId === participant.id}
              defeated={frame.defeated.includes(participant.id)}
            />
          ))}
        </div>
        <div className="space-y-1">
          {enemies.map((participant) => (
            <CombatantRow
              key={participant.id}
              name={displayName(log, participant.id)}
              health={frame.health[participant.id] ?? participant.maxHealth}
              maxHealth={participant.maxHealth}
              effects={frame.effects[participant.id] ?? []}
              isActor={rule?.actorId === participant.id}
              defeated={frame.defeated.includes(participant.id)}
            />
          ))}
        </div>
      </div>

      {/*
        The signature affordance: every action shows which battle plan rule
        fired. Without it the plan is a black box and a loss teaches nothing.
        See docs/combat.md section 5.3.
      */}
      <div className="mt-4 min-h-16 rounded-md border border-ash-700 bg-ash-950 p-3">
        {rule ? (
          <p className="text-sm">
            <span className="mr-2 rounded bg-ember-500/20 px-1.5 py-0.5 text-xs font-semibold text-ember-300">
              {t('replay.rule', { rule: rule.ruleNumber })}
            </span>
            <span className="font-medium">{nameOf(rule.actorId)}</span>
            <span className="text-ash-400"> → </span>
            <span>{t(`ability.${rule.abilityId}`)}</span>
          </p>
        ) : (
          <p className="text-sm text-ash-400">{t('replay.round', { round: frame.round })}</p>
        )}
        {frame.event && <p className="mt-1 text-sm text-ash-200">{narrate(frame.event, nameOf)}</p>}
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2">
        <Button variant="primary" onClick={toggle} disabled={finished}>
          {playing ? t('replay.pause') : t('replay.play')}
        </Button>
        <Button onClick={restart}>{t('replay.restart')}</Button>
        {/*
          Skip is required, not optional: players run many encounters and will
          not watch a full animation each time.
        */}
        <Button onClick={() => skipTo(lastIndex)} disabled={finished}>
          {t('replay.skip')}
        </Button>

        <div className="ml-auto flex items-center gap-1" role="group" aria-label={t('replay.speed')}>
          {REPLAY_SPEEDS.map((option) => (
            <Button
              key={option}
              variant={speed === option ? 'primary' : 'ghost'}
              aria-pressed={speed === option}
              onClick={() => setSpeed(option)}
            >
              {option}×
            </Button>
          ))}
        </div>
      </div>

      <label className="mt-3 block">
        <span className="sr-only">{t('replay.title')}</span>
        <input
          type="range"
          min={0}
          max={lastIndex}
          value={frameIndex}
          onChange={(event) => skipTo(Number(event.target.value))}
          className="w-full accent-ember-500"
        />
      </label>
    </Panel>
  );
}
