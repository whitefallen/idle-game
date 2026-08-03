/**
 * Localisation.
 *
 * Every player-facing string is resolved from a key, from the first component
 * onward. Retrofitting localisation onto a UI is far more expensive than
 * carrying keys from the start, even while there is only one language — and
 * the API deliberately returns keys and error codes rather than prose so the
 * server never has to know the player's language.
 */

const strings: Record<string, string> = {
  'app.title': 'Emberwatch',
  'app.tagline': 'Hold the beacon-line.',

  'auth.signIn': 'Sign in',
  'auth.register': 'Create account',
  'auth.email': 'Email',
  'auth.password': 'Password',
  'auth.signOut': 'Sign out',
  'auth.needAccount': 'Need an account?',
  'auth.haveAccount': 'Already have an account?',
  'auth.working': 'Working…',

  'character.create': 'Create character',
  'character.name': 'Name',
  'character.yours': 'Your wardens',
  'character.none': 'No wardens yet. Create one to begin.',
  'character.level': 'Level',
  'character.experience': 'Experience',
  'character.gold': 'Gold',
  'character.vigor': 'Vigor',
  'character.power': 'Power',
  'character.unspentPoints': 'Unspent points',
  'character.attributes': 'Attributes',
  'character.derived': 'Derived',
  'character.battlePlan': 'Battle plan',
  'character.allocate': 'Allocate',
  'character.back': 'Back to wardens',
  'character.vigorFull': 'Full',
  'character.vigorFullIn': 'Full in {time}',

  'attribute.STR': 'Strength',
  'attribute.DEX': 'Dexterity',
  'attribute.INT': 'Intelligence',
  'attribute.CON': 'Constitution',
  'attribute.LUK': 'Luck',

  'stat.maxHealth': 'Health',
  'stat.initiative': 'Initiative',
  'stat.maxFocus': 'Focus',
  'stat.focusPerTurn': 'Focus / turn',
  'stat.weaponBaseDamage': 'Weapon damage',
  'stat.scalingBp': 'Attribute scaling',
  'stat.critChanceBp': 'Critical chance',
  'stat.critPowerBp': 'Critical power',
  'stat.dodgeChanceBp': 'Dodge',
  'stat.armourRating': 'Armour',

  'encounter.available': 'Beacon-line',
  'encounter.fight': 'Fight',
  'encounter.fighting': 'Resolving…',
  'encounter.cost': '{cost} Vigor',
  'encounter.locked': 'Requires level {level}',
  'encounter.notEnoughVigor': 'Not enough Vigor',
  'encounter.history': 'Recent encounters',
  'encounter.noHistory': 'No encounters yet.',

  'outcome.victory': 'Victory',
  'outcome.defeat': 'Defeat',
  'outcome.draw': 'Draw',

  'replay.title': 'Combat replay',
  'replay.play': 'Play',
  'replay.pause': 'Pause',
  'replay.restart': 'Restart',
  'replay.skip': 'Skip to end',
  'replay.speed': 'Speed',
  'replay.round': 'Round {round}',
  'replay.rule': 'Rule {rule}',
  'replay.close': 'Close',
  'replay.rewards': '+{experience} XP, +{gold} gold',
  'replay.vigorRefunded': '{vigor} Vigor refunded',

  'ability.ability.measured_strike': 'Measured Strike',
  'ability.ability.sweeping_arc': 'Sweeping Arc',
  'ability.ability.rupture': 'Rupture',
  'ability.ability.ignite': 'Ignite',
  'ability.ability.emberdraught': 'Emberdraught',
  'ability.ability.sunder': 'Sunder',
  'ability.ability.blight_lash': 'Blight Lash',
  'ability.ability.corrosive_spray': 'Corrosive Spray',

  'effect.effect.burning': 'Burning',
  'effect.effect.bleeding': 'Bleeding',
  'effect.effect.resolve': 'Resolve',
  'effect.effect.sundered': 'Sundered',
  'effect.effect.mending': 'Mending',

  'monster.monster.blightling': 'Blightling',
  'monster.monster.blight_stalker': 'Blight Stalker',

  'encounterName.encounter.stretch1.patrol': 'Beacon Patrol',
  'encounterName.encounter.stretch1.swarm': 'Blight Swarm',
  'encounterName.encounter.stretch1.stalker': 'The Stalker',

  'event.damage': '{source} hits {target} for {amount}',
  'event.damage.crit': '{source} critically hits {target} for {amount}',
  'event.miss': '{source} misses {target}',
  'event.heal': '{source} restores {amount} to {target}',
  'event.effect.applied': '{target} is afflicted with {effect}',
  'event.effect.expired': '{effect} fades from {target}',
  'event.effect.ticked': '{effect} deals {amount} to {target}',
  'event.died': '{participant} falls',
  'event.plan.exhausted': '{actor} has no usable action',
  'event.round.start': 'Round {round}',
  'event.encounter.end': 'The encounter ends',

  // Error codes map to keys, so the server never sends player-facing prose.
  'error.VALIDATION_FAILED': 'That does not look right.',
  'error.MALFORMED_REQUEST': 'That request could not be understood.',
  'error.AUTHENTICATION_REQUIRED': 'Please sign in.',
  'error.INVALID_CREDENTIALS': 'Those credentials are not valid.',
  'error.FORBIDDEN': 'You do not have permission to do that.',
  'error.NOT_FOUND': 'Not found.',
  'error.CONFLICT': 'That conflicts with the current state.',
  'error.EMAIL_ALREADY_REGISTERED': 'That email address is already registered.',
  'error.CHARACTER_NAME_TAKEN': 'That name is already taken.',
  'error.CHARACTER_LIMIT_REACHED': 'You have reached the character limit.',
  'error.INSUFFICIENT_VIGOR': 'Not enough Vigor.',
  'error.INSUFFICIENT_GOLD': 'Not enough gold.',
  'error.INSUFFICIENT_POINTS': 'Not enough unspent points.',
  'error.REQUIREMENT_NOT_MET': 'You do not meet the requirements yet.',
  'error.IDEMPOTENCY_CONFLICT': 'That action was already submitted differently.',
  'error.RATE_LIMITED': 'Too many requests. Try again shortly.',
  'error.INTERNAL_ERROR': 'Something went wrong.',
};

/**
 * Resolves a key, interpolating {placeholders}.
 *
 * An unknown key returns the key itself rather than throwing or rendering
 * blank: a missing translation should be visible in review, never a crash or
 * an empty label in front of a player.
 */
export function t(key: string, values: Record<string, string | number> = {}): string {
  const template = strings[key] ?? key;

  return template.replace(/\{(\w+)\}/g, (match, name: string) => {
    const value = values[name];

    return value === undefined ? match : String(value);
  });
}

/** Basis points rendered as a percentage, e.g. 2500 -> "25%". */
export function bp(value: number): string {
  return `${(value / 100).toFixed(value % 100 === 0 ? 0 : 1)}%`;
}

export function duration(seconds: number): string {
  if (seconds <= 0) return '0m';

  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}
