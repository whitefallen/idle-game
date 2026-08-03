/**
 * Combat replay.
 *
 * This module renders a server-produced log. It contains no combat rules and
 * performs no simulation: the outcome was decided on the server, and the client
 * only plays it back. That is what removes any need for bit-exact PRNG parity
 * between PHP and TypeScript, and it means the client cannot learn future rolls.
 *
 * Playback is a pure fold over the event array, so any frame can be reached by
 * indexing rather than by replaying from the start — which is what makes
 * seeking and skip-to-end trivial, and lets the whole thing be tested without a
 * DOM.
 */

export interface LogParticipant {
  id: string;
  definitionId: string;
  name: string;
  team: 'players' | 'enemies';
  level: number;
  maxHealth: number;
  maxFocus: number;
}

export interface LogEvent {
  t: string;
  [key: string]: unknown;
}

export interface CombatLog {
  logVersion: number;
  rulesetVersion: string;
  seed: string;
  outcome: 'victory' | 'defeat' | 'draw';
  rounds: number;
  participants: LogParticipant[];
  events: LogEvent[];
}

/** Which battle plan rule an actor is currently executing. */
export interface ActiveRule {
  actorId: string;
  ruleNumber: number;
  abilityId: string;
}

export interface ReplayFrame {
  /** Index of the event this frame results from; -1 is the opening state. */
  eventIndex: number;
  round: number;
  health: Readonly<Record<string, number>>;
  effects: Readonly<Record<string, string[]>>;
  defeated: readonly string[];
  activeRule: ActiveRule | null;
  event: LogEvent | null;
}

function initialFrame(log: CombatLog): ReplayFrame {
  const health: Record<string, number> = {};

  for (const participant of log.participants) {
    health[participant.id] = participant.maxHealth;
  }

  return {
    eventIndex: -1,
    round: 0,
    health,
    effects: {},
    defeated: [],
    activeRule: null,
    event: null,
  };
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function asNumber(value: unknown): number | null {
  return typeof value === 'number' ? value : null;
}

/**
 * Applies one event. Pure: never mutates the frame it is given.
 */
export function applyEvent(frame: ReplayFrame, event: LogEvent, eventIndex: number): ReplayFrame {
  // Mutable working copies. The returned frame is readonly, so every frame
  // stays independent of its predecessor — which is what makes scrubbing
  // backwards show the right picture.
  const health: Record<string, number> = { ...frame.health };
  const effects: Record<string, string[]> = { ...frame.effects };

  let round = frame.round;
  let activeRule = frame.activeRule;
  let defeated = frame.defeated;

  switch (event.t) {
    case 'round.start': {
      round = asNumber(event.round) ?? frame.round;
      // A new round clears the highlighted rule so the previous round's
      // decision is not left looking current.
      activeRule = null;
      break;
    }

    case 'plan.matched': {
      activeRule = {
        actorId: asString(event.actor),
        ruleNumber: asNumber(event.rule) ?? 0,
        abilityId: asString(event.ability),
      };
      break;
    }

    case 'plan.exhausted': {
      activeRule = null;
      break;
    }

    case 'damage':
    case 'heal':
    case 'effect.ticked': {
      const target = asString(event.target);
      const resulting = asNumber(event.targetHealth);

      // Health comes from the log rather than being recomputed here. The
      // server already decided it; subtracting damage client-side would let
      // the two disagree.
      if (target !== '' && resulting !== null) {
        health[target] = resulting;
      }
      break;
    }

    case 'effect.applied': {
      const target = asString(event.target);
      const effect = asString(event.effect);
      const current = effects[target] ?? [];

      if (!current.includes(effect)) {
        effects[target] = [...current, effect];
      }
      break;
    }

    case 'effect.expired': {
      const target = asString(event.target);
      const effect = asString(event.effect);
      effects[target] = (effects[target] ?? []).filter((id) => id !== effect);
      break;
    }

    case 'died': {
      const participant = asString(event.participant);

      if (!defeated.includes(participant)) {
        defeated = [...defeated, participant];
      }

      health[participant] = 0;
      break;
    }

    default:
      // An unknown event type from a newer log version is skipped rather than
      // throwing, so an older client degrades instead of breaking.
      break;
  }

  return { eventIndex, round, health, effects, defeated, activeRule, event };
}

/**
 * Every frame of the fight, including the opening state at index 0.
 *
 * Computed once per log. Seeking is then an array lookup, so scrubbing is free
 * and playback speed cannot drift from the recorded truth.
 */
export function buildFrames(log: CombatLog): ReplayFrame[] {
  const frames: ReplayFrame[] = [initialFrame(log)];

  log.events.forEach((event, index) => {
    const previous = frames[frames.length - 1];

    if (previous) {
      frames.push(applyEvent(previous, event, index));
    }
  });

  return frames;
}

export function participantsByTeam(log: CombatLog, team: LogParticipant['team']): LogParticipant[] {
  return log.participants.filter((participant) => participant.team === team);
}
