/**
 * API payload types.
 *
 * These are hand-written for now, which is a known deviation from
 * docs/architecture.md section 7: they should be generated from the backend's
 * OpenAPI schema, because a hand-maintained duplicate of a contract drifts and
 * the drift is discovered by players. Generating them is scheduled work, not a
 * decision to maintain them by hand.
 */

import type { CombatLog } from '@/features/encounter/replay';

export interface Account {
  id: string;
  email: string;
  created_at?: string;
}

export interface Vigor {
  current: number;
  max: number;
  seconds_per_point: number;
  ticked_at: string;
  full_at: string;
}

export interface CharacterSummary {
  id: string;
  name: string;
  level: number;
  power_score: number;
  vigor: Vigor;
}

export type AttributeCode = 'STR' | 'DEX' | 'INT' | 'CON' | 'LUK';

export interface DerivedStats {
  maxHealth: number;
  initiative: number;
  maxFocus: number;
  focusPerTurn: number;
  weaponBaseDamage: number;
  flatDamageBonus: number;
  scalingBp: number;
  critChanceBp: number;
  critPowerBp: number;
  dodgeChanceBp: number;
  accuracyBp: number;
  armourRating: number;
  resistanceRatings: Record<string, number>;
}

export interface ConditionTerm {
  subject: string;
  operator?: string;
  value?: number;
  effectId?: string;
}

export interface PlanRule {
  condition: ConditionTerm[];
  abilityId: string;
}

export interface AbilityMeta {
  id: string;
  localisation_key: string;
  focus_cost: number;
  cooldown_rounds: number;
  school: string;
  selector: string;
  effect_id: string | null;
  /** Whether this ability is free and off cooldown, so it may end a plan. */
  can_be_fallback: boolean;
}

export interface GrammarSubject {
  value: string;
  requires_operator: boolean;
  requires_value: boolean;
  requires_effect: boolean;
  is_percentage: boolean;
}

/**
 * Published by the server rather than duplicated here. The grammar is what the
 * engine evaluates and the validator enforces, so a local copy would drift and
 * the editor would start offering plans the server rejects.
 */
export interface BattlePlanGrammar {
  limits: { max_rules: number; max_terms_per_condition: number };
  subjects: GrammarSubject[];
  operators: string[];
  effects: string[];
}

export interface PlanIssue {
  code: string;
  message: string;
  /** 0-based index of the rule at fault, when the fault is a specific rule. */
  rule?: number;
  term?: number;
}

export interface CharacterDetail extends CharacterSummary {
  experience: number;
  experience_to_next_level: number;
  gold: number;
  emberdust: number;
  unspent_points: number;
  attributes: Record<AttributeCode, number>;
  derived_stats: DerivedStats;
  loadout_slots: number;
  respec_cost: number;
  ability_ids: string[];
  abilities: AbilityMeta[];
  battle_plan: PlanRule[];
}

export interface AvailableEncounter {
  id: string;
  localisation_key: string;
  level: number;
  tier: 'patrol' | 'elite' | 'boss';
  vigor_cost: number;
  required_level: number;
  monsters: string[];
  unlocked: boolean;
  affordable: boolean;
}

/**
 * The Vigor activity gate. A character runs one Vigor-spending activity at a
 * time, so this belongs to the character rather than to any one encounter and
 * is reported once alongside the list.
 */
export interface ActivityGate {
  ready: boolean;
  seconds_remaining: number;
  ready_at: string;
  gate_seconds: number;
}

export interface AvailableEncounters {
  encounters: AvailableEncounter[];
  activity: ActivityGate;
}

export interface EncounterRewards {
  experience: number;
  gold: number;
  levelsGained: number;
  vigorRefunded: number;
}

export interface EncounterSummary {
  id: string;
  definition_id: string;
  outcome: 'victory' | 'defeat' | 'draw';
  rounds: number;
  rewards: EncounterRewards;
  created_at: string;
}

export interface EncounterDetail extends EncounterSummary {
  seed: string;
  ruleset_version: string;
  log: CombatLog;
}

export interface ResolvedEncounter {
  encounter: EncounterDetail;
  character: CharacterDetail;
}
