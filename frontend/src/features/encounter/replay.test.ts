import { describe, expect, it } from 'vitest';
import { applyEvent, buildFrames, type CombatLog, type LogEvent } from './replay';

/**
 * The replay fold is pure, so it is tested without a DOM, a network, or a
 * component. These tests are the reason the reducer was written as a fold in
 * the first place.
 */

const log: CombatLog = {
  logVersion: 1,
  rulesetVersion: '1.0.0',
  seed: '12345',
  outcome: 'victory',
  rounds: 2,
  participants: [
    {
      id: 'player-1',
      definitionId: 'character',
      name: 'Warden',
      team: 'players',
      level: 1,
      maxHealth: 100,
      maxFocus: 40,
    },
    {
      id: 'enemy-1',
      definitionId: 'monster.blightling',
      name: 'monster.blightling',
      team: 'enemies',
      level: 2,
      maxHealth: 70,
      maxFocus: 20,
    },
  ],
  events: [
    { t: 'round.start', round: 1 },
    { t: 'plan.matched', actor: 'player-1', rule: 1, ability: 'ability.rupture' },
    { t: 'damage', source: 'player-1', target: 'enemy-1', amount: 10, crit: false, school: 'physical', targetHealth: 60 },
    { t: 'effect.applied', source: 'player-1', target: 'enemy-1', effect: 'effect.bleeding', rounds: 4 },
    { t: 'round.start', round: 2 },
    { t: 'effect.ticked', target: 'enemy-1', effect: 'effect.bleeding', amount: 6, targetHealth: 54 },
    { t: 'plan.matched', actor: 'player-1', rule: 2, ability: 'ability.measured_strike' },
    { t: 'damage', source: 'player-1', target: 'enemy-1', amount: 54, crit: true, school: 'physical', targetHealth: 0 },
    { t: 'died', participant: 'enemy-1' },
    { t: 'encounter.end', outcome: 'victory', rounds: 2 },
  ],
};

describe('buildFrames', () => {
  it('produces one frame per event plus the opening state', () => {
    expect(buildFrames(log)).toHaveLength(log.events.length + 1);
  });

  it('starts everyone at full health before any event', () => {
    const [opening] = buildFrames(log);

    expect(opening?.health).toEqual({ 'player-1': 100, 'enemy-1': 70 });
    expect(opening?.round).toBe(0);
    expect(opening?.activeRule).toBeNull();
  });

  it('tracks health from the log rather than recomputing it', () => {
    const frames = buildFrames(log);

    // The server sends the resulting health with each event; the client must
    // use it rather than subtracting damage itself, or the two can disagree.
    expect(frames[3]?.health['enemy-1']).toBe(60);
    expect(frames[6]?.health['enemy-1']).toBe(54);
    expect(frames[8]?.health['enemy-1']).toBe(0);
  });

  it('exposes which battle plan rule is firing', () => {
    const frames = buildFrames(log);

    expect(frames[2]?.activeRule).toEqual({
      actorId: 'player-1',
      ruleNumber: 1,
      abilityId: 'ability.rupture',
    });

    expect(frames[7]?.activeRule?.ruleNumber).toBe(2);
  });

  it('clears the active rule at the start of a round', () => {
    const frames = buildFrames(log);

    // Frame 5 is the second round.start; the previous round's decision must
    // not be left looking current.
    expect(frames[5]?.activeRule).toBeNull();
    expect(frames[5]?.round).toBe(2);
  });

  it('tracks effects being applied and expiring', () => {
    const frames = buildFrames(log);

    expect(frames[4]?.effects['enemy-1']).toEqual(['effect.bleeding']);

    const expired = applyEvent(frames[4]!, { t: 'effect.expired', target: 'enemy-1', effect: 'effect.bleeding' }, 99);

    expect(expired.effects['enemy-1']).toEqual([]);
  });

  it('records the defeated', () => {
    const frames = buildFrames(log);

    // Frame index is event index + 1, because frame 0 is the opening state.
    // "died" is event 8, so it lands in frame 9.
    expect(frames[9]?.defeated).toEqual(['enemy-1']);
    expect(frames[9]?.health['enemy-1']).toBe(0);

    // The killing blow arrives one frame earlier, and nobody is marked
    // defeated until the log says so.
    expect(frames[8]?.health['enemy-1']).toBe(0);
    expect(frames[8]?.defeated).toEqual([]);
  });

  /**
   * Seeking is an array lookup precisely because the fold never mutates. If a
   * frame shared state with its predecessor, scrubbing backwards would show
   * the wrong picture.
   */
  it('never mutates earlier frames', () => {
    const frames = buildFrames(log);

    expect(frames[0]?.health['enemy-1']).toBe(70);
    expect(frames[frames.length - 1]?.health['enemy-1']).toBe(0);
  });

  it('ignores unknown event types rather than throwing', () => {
    const frame = buildFrames(log)[0]!;
    const unknown: LogEvent = { t: 'something.new.in.a.later.version', payload: 1 };

    expect(() => applyEvent(frame, unknown, 0)).not.toThrow();
    expect(applyEvent(frame, unknown, 0).health).toEqual(frame.health);
  });

  it('handles a log with no events', () => {
    const frames = buildFrames({ ...log, events: [] });

    expect(frames).toHaveLength(1);
    expect(frames[0]?.eventIndex).toBe(-1);
  });
});
