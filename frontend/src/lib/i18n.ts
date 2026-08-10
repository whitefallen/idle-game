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
  'character.respec': 'Respec ({gold}g)',
  'character.respecConfirm':
    'Reset every allocated point for {gold} gold? Gear you no longer qualify for will be unequipped.',
  'character.respecConfirmYes': 'Reset attributes',
  'character.respecCancel': 'Cancel',
  'character.respecUnaffordable': 'Not enough gold to respec yet.',
  'character.respecUnequipped': 'Unequipped, no longer qualified: {items}',

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

  'discipline.title': 'Disciplines',
  'discipline.slotsUsed': '{used} of {max} slots used',
  'discipline.unlocksAt': 'unlocks at level {level}',
  'discipline.slot': 'Slot',
  'discipline.slotted': 'Slotted',
  'discipline.save': 'Save loadout',
  'discipline.saving': 'Saving…',
  'discipline.revert': 'Revert',
  'discipline.planNote':
    'To unslot an ability your battle plan uses, change the plan first — the plan is never rewritten for you.',

  'plan.title': 'Battle plan',
  'plan.edit': 'Edit plan',
  'plan.done': 'Done',
  'plan.save': 'Save plan',
  'plan.saving': 'Saving…',
  'plan.saved': 'Plan saved.',
  'plan.revert': 'Revert changes',
  'plan.addRule': 'Add rule',
  'plan.removeRule': 'Remove rule',
  'plan.moveUp': 'Move up',
  'plan.moveDown': 'Move down',
  'plan.if': 'If',
  'plan.and': 'and',
  'plan.then': 'then',
  'plan.addTerm': 'Add condition',
  'plan.removeTerm': 'Remove condition',
  'plan.fallbackNote': 'The last rule runs when nothing above it can. It needs an ability with no cost and no cooldown.',
  'plan.ruleCount': '{count} of {max} rules',
  'plan.unsaved': 'Unsaved changes',

  'subject.always': 'Always',
  'subject.self.health_percent': 'My health %',
  'subject.self.focus': 'My Focus',
  'subject.enemy.count': 'Enemies alive',
  'subject.target.health_percent': "Target's health %",
  'subject.round': 'Round',
  'subject.self.has_effect': 'I have effect',
  'subject.target.has_effect': 'Target has effect',

  'operator.lt': 'is below',
  'operator.lte': 'is at most',
  'operator.gt': 'is above',
  'operator.gte': 'is at least',
  'operator.eq': 'equals',

  'planIssue.PLAN_EMPTY': 'A plan needs at least one rule.',
  'planIssue.PLAN_TOO_MANY_RULES': 'Too many rules.',
  'planIssue.RULE_MALFORMED': 'This rule is not valid.',
  'planIssue.RULE_UNKNOWN_ABILITY': 'No such ability.',
  'planIssue.RULE_ABILITY_NOT_LEARNED': 'This character has not learned that ability.',
  'planIssue.PLAN_FINAL_RULE_NOT_UNCONDITIONAL': 'The last rule must have no condition.',
  'planIssue.PLAN_FINAL_RULE_NOT_ALWAYS_AVAILABLE': 'The last rule needs an ability with no cost and no cooldown.',
  'planIssue.CONDITION_EMPTY': 'This rule needs a condition.',
  'planIssue.CONDITION_TOO_MANY_TERMS': 'Too many conditions on one rule.',
  'planIssue.TERM_MALFORMED': 'This condition is not valid.',
  'planIssue.TERM_UNKNOWN_EFFECT': 'Nothing can apply that effect.',
  'planIssue.TERM_PERCENT_OUT_OF_RANGE': 'A percentage must be between 0 and 100.',
  'planIssue.RULE_UNREACHABLE': 'An earlier rule always runs first, so this one never will.',

  'encounter.available': 'Beacon-line',
  'encounter.fight': 'Fight',
  'encounter.fighting': 'Resolving…',
  'encounter.cost': '{cost} Vigor',
  'encounter.locked': 'Requires level {level}',
  'encounter.notEnoughVigor': 'Not enough Vigor',
  'encounter.activityResolving': 'Still resolving — ready in {seconds}s',
  'encounter.history': 'Recent encounters',
  'encounter.noHistory': 'No encounters yet.',

  'quest.title': 'Quests',
  'quest.accept': 'Accept',
  'quest.claim': 'Claim',
  'quest.locked': 'Requires level {level}',
  'quest.active': 'Under way — ready in {time}',
  'quest.readyToClaim': 'Ready to claim',
  'quest.failed': 'Last attempt failed. Accept again to retry.',
  'quest.claimed': 'Completed',
  'quest.rewards': '{xp} xp, {gold} gold',
  'quest.contentChanged': 'This quest changed while it was under way. Accept it again.',
  'quest.none': 'No quests available yet.',

  'dungeon.title': 'Dungeons',
  'dungeon.enter': 'Enter',
  'dungeon.locked': 'Requires level {level}',
  'dungeon.keysHeld': '{count} key(s) held',
  'dungeon.noKey': 'No key — complete a kill quest that rewards one first.',
  'dungeon.stages': '{count} stages',
  'dungeon.completionBonus': '+{xp} xp, +{gold} gold on a full clear',
  'dungeon.cleared': 'Cleared',
  'dungeon.notCleared': 'Not cleared — stopped at stage {stage}',
  'dungeon.none': 'No dungeons available yet.',

  'holding.title': 'The Holding',
  'holding.claim': 'Claim',
  'holding.slot': 'Slot {index}',
  'holding.slotLocked': 'Slot unlocks at level {level}',
  'holding.idle': 'Idle — producing nothing',
  'holding.perHour': '{rate}/h',
  'holding.unlocksAt': 'unlocks at level {level}',
  'holding.nextIn': 'Next in {time}',
  'holding.atCap': 'Full — production has stopped',
  'holding.reassignWarning': 'Switching lines discards this.',
  'holding.pending': 'Ready to claim: {materials} materials, {gold} gold',
  'holding.nothingPending': 'Nothing ready yet.',
  'holding.cap': 'Accrues for up to {time} away.',
  'holding.tithe': 'Tithe: {gold} gold an hour',
  'holding.stash': 'Materials',
  'holding.stashEmpty': 'No materials yet. Assign a line, or fight for them.',
  'holding.stashNote': 'Materials are the refinement input. They are never bought or sold.',

  'item.inventory': 'Inventory',
  'item.empty': 'Nothing carried or worn yet.',
  'item.level': 'ilvl {level}',
  'item.refine': 'Refine ({gold}g, {material})',
  'item.refineMaxed': 'Fully refined.',
  'item.noMaterial': 'No matching material',
  'item.sell': 'Sell ({gold}g)',
  'item.equip': 'Equip',
  'item.unequip': 'Unequip',
  'item.worn': 'Worn',
  'item.carried': 'Carried',
  'item.nothingWorn': 'Nothing worn.',
  'item.nothingCarried': 'Nothing carried.',
  'item.requires': 'Requires {requirements}',
  'item.requiresLevel': 'level {level}',
  'item.requiresAttribute': 'Requires {required} {attribute}; you have {current}.',
  'item.chooseRingSlot': 'Choose ring slot',

  'vendor.title': 'Vendor',
  'vendor.empty': 'Nothing in stock today.',
  'vendor.buy': 'Buy ({gold}g)',
  'vendor.requiresLevel': 'Requires level {level}',

  'rarity.common': 'Common',
  'rarity.uncommon': 'Uncommon',
  'rarity.rare': 'Rare',
  'rarity.epic': 'Epic',
  'rarity.legendary': 'Legendary',

  'slot.Head': 'Head',
  'slot.Chest': 'Chest',
  'slot.Legs': 'Legs',
  'slot.Hands': 'Hands',
  'slot.Feet': 'Feet',
  'slot.MainHand': 'Main hand',
  'slot.OffHand': 'Off hand',
  'slot.Amulet': 'Amulet',
  'slot.Ring1': 'Ring 1',
  'slot.Ring2': 'Ring 2',

  'item.item.wardens_halberd': "Warden's Halberd",
  'item.item.tempered_hauberk': 'Tempered Hauberk',
  'item.item.ember_band': 'Ember Band',

  'material.material.emberash': 'Emberash',
  'material.material.slagiron': 'Slagiron',
  'material.material.verdigris': 'Verdigris',
  'material.material.cinderglass': 'Cinderglass',
  'material.material.blightcore': 'Blightcore',
  'material.material.dungeon_key': 'Dungeon Key',

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
  'ability.ability.mending_tide': 'Mending Tide',
  'ability.ability.reaping_blow': 'Reaping Blow',
  'ability.ability.crushing_blow': 'Crushing Blow',
  'ability.ability.rot_chant': 'Rot Chant',
  'ability.ability.rending_claw': 'Rending Claw',
  'ability.ability.blood_frenzy': 'Blood Frenzy',
  'ability.ability.shattering_arc': 'Shattering Arc',
  'ability.ability.cinder_volley': 'Cinder Volley',
  'ability.ability.ash_shroud': 'Ash Shroud',
  'ability.ability.ember_reave': 'Ember Reave',
  'ability.ability.wrath_of_ash': 'Wrath of Ash',
  'ability.ability.vault_mend': 'Vault Mend',

  'effect.effect.burning': 'Burning',
  'effect.effect.bleeding': 'Bleeding',
  'effect.effect.resolve': 'Resolve',
  'effect.effect.sundered': 'Sundered',
  'effect.effect.mending': 'Mending',
  'effect.effect.corroded': 'Corroded',
  'effect.effect.frenzy': 'Frenzy',
  'effect.effect.emberbrand': 'Emberbrand',
  'effect.effect.ash_choked': 'Ash-choked',
  'effect.effect.warden_wrath': "Warden's Wrath",

  'monster.monster.blightling': 'Blightling',
  'monster.monster.blight_stalker': 'Blight Stalker',
  'monster.monster.causeway_husk': 'Causeway Husk',
  'monster.monster.rot_chanter': 'Rot Chanter',
  'monster.monster.marsh_lurker': 'Marsh Lurker',
  'monster.monster.causeway_ravager': 'Causeway Ravager',
  'monster.monster.vault_sentinel': 'Vault Sentinel',
  'monster.monster.ash_revenant': 'Ash Revenant',
  'monster.monster.emberbound_thrall': 'Emberbound Thrall',
  'monster.monster.warden_of_ash': 'The Warden of Ash',

  'encounterName.encounter.stretch1.patrol': 'Beacon Patrol',
  'encounterName.encounter.stretch1.swarm': 'Blight Swarm',
  'encounterName.encounter.stretch1.stalker': 'The Stalker',
  'encounterName.encounter.stretch2.causeway': 'Causeway Watch',
  'encounterName.encounter.stretch2.chanters': 'Chanter Vigil',
  'encounterName.encounter.stretch2.lurkers': 'Marsh Crossing',
  'encounterName.encounter.stretch2.warren': 'The Rot Warren',
  'encounterName.encounter.stretch2.ravager': 'The Ravager',
  'encounterName.encounter.stretch3.vault_watch': 'Vault Watch',
  'encounterName.encounter.stretch3.revenants': 'Ashen Procession',
  'encounterName.encounter.stretch3.sentinels': 'The Sealed Door',
  'encounterName.encounter.stretch3.warden_of_ash': 'The Warden of Ash',

  'questName.quest.stretch1.blightling_watch': 'Blightling Watch',
  'questName.quest.stretch1.stalker_hunt': 'Stalker Hunt',

  'dungeonName.dungeon.stretch1.blight_hollow': 'The Blight Hollow',

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
  'error.ACTIVITY_IN_PROGRESS': 'Another activity is still resolving.',
  'error.INSUFFICIENT_GOLD': 'Not enough gold.',
  'error.INSUFFICIENT_MATERIAL': 'Not enough of that material.',
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
