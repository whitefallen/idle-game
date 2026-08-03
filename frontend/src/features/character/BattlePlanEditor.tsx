import { useMemo, useState } from 'react';
import { Button, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import type {
  AbilityMeta,
  BattlePlanGrammar,
  CharacterDetail,
  ConditionTerm,
  PlanIssue,
  PlanRule,
} from '@/lib/types';
import { planIssuesFrom, useBattlePlanGrammar, useUpdateBattlePlan } from './planApi';

/**
 * The battle plan editor.
 *
 * The plan being edited is an unsaved draft, which is the one case where local
 * state legitimately shadows server state: it belongs to the browser until the
 * player submits it. Nothing is written to the query cache until the server
 * accepts the plan.
 *
 * Validation is the server's job. This mirrors enough of the grammar to render
 * the right inputs — using the grammar the server publishes, not a copy — but
 * never decides whether a plan is legal.
 */

const SELECT_CLASS =
  'rounded-md border border-ash-700 bg-ash-950 px-2 py-1.5 text-sm text-ash-50 disabled:opacity-50';

function defaultTermFor(subject: string, grammar: BattlePlanGrammar, effects: string[]): ConditionTerm {
  const meta = grammar.subjects.find((candidate) => candidate.value === subject);

  if (!meta) return { subject };

  const term: ConditionTerm = { subject };

  if (meta.requires_operator) term.operator = grammar.operators[0] ?? 'lt';
  if (meta.requires_value) term.value = meta.is_percentage ? 50 : 1;
  if (meta.requires_effect) term.effectId = effects[0] ?? '';

  return term;
}

function TermRow({
  term,
  grammar,
  effects,
  isFirst,
  canRemove,
  onChange,
  onRemove,
}: {
  term: ConditionTerm;
  grammar: BattlePlanGrammar;
  effects: string[];
  isFirst: boolean;
  canRemove: boolean;
  onChange: (term: ConditionTerm) => void;
  onRemove: () => void;
}) {
  const meta = grammar.subjects.find((candidate) => candidate.value === term.subject);

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="w-8 shrink-0 text-xs text-ash-400">{t(isFirst ? 'plan.if' : 'plan.and')}</span>

      <select
        className={SELECT_CLASS}
        value={term.subject}
        aria-label={t('plan.if')}
        onChange={(event) => onChange(defaultTermFor(event.target.value, grammar, effects))}
      >
        {grammar.subjects.map((subject) => (
          <option key={subject.value} value={subject.value}>
            {t(`subject.${subject.value}`)}
          </option>
        ))}
      </select>

      {meta?.requires_operator && (
        <select
          className={SELECT_CLASS}
          value={term.operator ?? ''}
          aria-label={t('operator.lt')}
          onChange={(event) => onChange({ ...term, operator: event.target.value })}
        >
          {grammar.operators.map((operator) => (
            <option key={operator} value={operator}>
              {t(`operator.${operator}`)}
            </option>
          ))}
        </select>
      )}

      {meta?.requires_value && (
        <input
          type="number"
          className={`${SELECT_CLASS} w-24`}
          value={term.value ?? 0}
          min={0}
          max={meta.is_percentage ? 100 : undefined}
          aria-label={t(`subject.${term.subject}`)}
          onChange={(event) => onChange({ ...term, value: Number(event.target.value) })}
        />
      )}

      {meta?.requires_effect && (
        <select
          className={SELECT_CLASS}
          value={term.effectId ?? ''}
          aria-label={t(`subject.${term.subject}`)}
          onChange={(event) => onChange({ ...term, effectId: event.target.value })}
        >
          {effects.map((effect) => (
            <option key={effect} value={effect}>
              {t(`effect.${effect}`)}
            </option>
          ))}
        </select>
      )}

      {canRemove && (
        <Button variant="ghost" className="px-2 py-1" aria-label={t('plan.removeTerm')} onClick={onRemove}>
          ×
        </Button>
      )}
    </div>
  );
}

function RuleCard({
  rule,
  index,
  total,
  abilities,
  grammar,
  issues,
  onChange,
  onMove,
  onRemove,
}: {
  rule: PlanRule;
  index: number;
  total: number;
  abilities: AbilityMeta[];
  grammar: BattlePlanGrammar;
  issues: PlanIssue[];
  onChange: (rule: PlanRule) => void;
  onMove: (from: number, to: number) => void;
  onRemove: () => void;
}) {
  const isLast = index === total - 1;
  const isAlways = rule.condition.length === 1 && rule.condition[0]?.subject === 'always';
  const hasError = issues.length > 0;

  return (
    <li
      className={`rounded-md border p-3 ${hasError ? 'border-danger-500 bg-danger-500/5' : 'border-ash-700 bg-ash-800/40'}`}
    >
      <div className="mb-2 flex items-center justify-between gap-2">
        <span className="rounded bg-ash-700 px-1.5 py-0.5 text-[11px] tabular-nums text-ash-200">{index + 1}</span>

        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            className="px-2 py-1"
            aria-label={t('plan.moveUp')}
            disabled={index === 0}
            onClick={() => onMove(index, index - 1)}
          >
            ↑
          </Button>
          <Button
            variant="ghost"
            className="px-2 py-1"
            aria-label={t('plan.moveDown')}
            disabled={index === total - 1}
            onClick={() => onMove(index, index + 1)}
          >
            ↓
          </Button>
          <Button
            variant="ghost"
            className="px-2 py-1"
            aria-label={t('plan.removeRule')}
            disabled={total === 1}
            onClick={onRemove}
          >
            ×
          </Button>
        </div>
      </div>

      {!isAlways && (
        <div className="mb-2 space-y-1.5">
          {rule.condition.map((term, termIndex) => (
            <TermRow
              key={termIndex}
              term={term}
              grammar={grammar}
              effects={grammar.effects}
              isFirst={termIndex === 0}
              canRemove={rule.condition.length > 1}
              onChange={(updated) =>
                onChange({
                  ...rule,
                  condition: rule.condition.map((existing, i) => (i === termIndex ? updated : existing)),
                })
              }
              onRemove={() =>
                onChange({ ...rule, condition: rule.condition.filter((_, i) => i !== termIndex) })
              }
            />
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        {!isAlways && rule.condition.length < grammar.limits.max_terms_per_condition && (
          <Button
            variant="ghost"
            className="px-2 py-1 text-xs"
            onClick={() =>
              onChange({
                ...rule,
                condition: [
                  ...rule.condition,
                  defaultTermFor('self.focus', grammar, grammar.effects),
                ],
              })
            }
          >
            + {t('plan.addTerm')}
          </Button>
        )}

        <span className="text-xs text-ash-400">{t('plan.then')}</span>

        <select
          className={SELECT_CLASS}
          value={rule.abilityId}
          aria-label={t('plan.then')}
          onChange={(event) => onChange({ ...rule, abilityId: event.target.value })}
        >
          {abilities.map((ability) => (
            <option key={ability.id} value={ability.id}>
              {t(`ability.${ability.id}`)}
              {ability.focus_cost > 0 ? ` · ${ability.focus_cost} Focus` : ''}
              {ability.cooldown_rounds > 0 ? ` · ${ability.cooldown_rounds}r` : ''}
            </option>
          ))}
        </select>

        {/* Only the last rule must be unconditional, so only it gets the note. */}
        {isLast && <span className="text-xs text-ash-400">{t('plan.fallbackNote')}</span>}
      </div>

      {issues.map((issue, i) => (
        <p key={i} role="alert" className="mt-2 text-xs text-danger-500">
          {t(`planIssue.${issue.code}`)}
        </p>
      ))}
    </li>
  );
}

export function BattlePlanEditor({ character, onClose }: { character: CharacterDetail; onClose: () => void }) {
  const grammarQuery = useBattlePlanGrammar();
  const save = useUpdateBattlePlan(character.id);

  const [draft, setDraft] = useState<PlanRule[]>(() => structuredClone(character.battle_plan));
  const [warnings, setWarnings] = useState<PlanIssue[]>([]);
  const [saved, setSaved] = useState(false);

  const issues = planIssuesFrom(save.error);

  const dirty = useMemo(
    () => JSON.stringify(draft) !== JSON.stringify(character.battle_plan),
    [draft, character.battle_plan],
  );

  if (grammarQuery.isPending) {
    return (
      <Panel title={t('plan.title')}>
        <p className="text-sm text-ash-400">…</p>
      </Panel>
    );
  }

  const grammar = grammarQuery.data;

  if (!grammar) {
    return (
      <Panel title={t('plan.title')}>
        <p className="text-sm text-danger-500">{t('error.INTERNAL_ERROR')}</p>
      </Panel>
    );
  }

  function update(index: number, rule: PlanRule) {
    setDraft((rules) => rules.map((existing, i) => (i === index ? rule : existing)));
    setSaved(false);
  }

  function move(from: number, to: number) {
    setDraft((rules) => {
      const next = [...rules];
      const [moved] = next.splice(from, 1);

      if (moved) next.splice(to, 0, moved);

      return next;
    });
    setSaved(false);
  }

  async function submit() {
    try {
      const result = await save.mutateAsync(draft);
      setWarnings(result.warnings);
      setSaved(true);
    } catch {
      // A rejected plan is expected input from an editor. The mutation already
      // holds the error, which is read back as per-rule issues; swallowing the
      // rejection here just stops it surfacing as an unhandled promise.
      setWarnings([]);
      setSaved(false);
    }
  }

  const issuesFor = (index: number) => issues.filter((issue) => issue.rule === index);
  const planWideIssues = issues.filter((issue) => issue.rule === undefined);

  return (
    <Panel
      title={t('plan.title')}
      actions={
        <Button variant="ghost" onClick={onClose}>
          {t('plan.done')}
        </Button>
      }
    >
      <p className="mb-3 text-xs text-ash-400">
        {t('plan.ruleCount', { count: draft.length, max: grammar.limits.max_rules })}
        {dirty && <span className="ml-2 text-ember-400">{t('plan.unsaved')}</span>}
      </p>

      <ol className="space-y-2">
        {draft.map((rule, index) => (
          <RuleCard
            key={index}
            rule={rule}
            index={index}
            total={draft.length}
            abilities={character.abilities}
            grammar={grammar}
            issues={issuesFor(index)}
            onChange={(updated) => update(index, updated)}
            onMove={move}
            onRemove={() => {
              setDraft((rules) => rules.filter((_, i) => i !== index));
              setSaved(false);
            }}
          />
        ))}
      </ol>

      {planWideIssues.map((issue, i) => (
        <p key={i} role="alert" className="mt-2 text-sm text-danger-500">
          {t(`planIssue.${issue.code}`)}
        </p>
      ))}

      {saved && warnings.length === 0 && <p className="mt-3 text-sm text-blight-500">{t('plan.saved')}</p>}

      {/* Warnings accompany a successful save: worth flagging, not worth refusing. */}
      {saved &&
        warnings.map((warning, i) => (
          <p key={i} className="mt-2 text-sm text-ember-400">
            {warning.rule !== undefined && `${warning.rule + 1}. `}
            {t(`planIssue.${warning.code}`)}
          </p>
        ))}

      <div className="mt-4 flex flex-wrap items-center gap-2">
        <Button
          onClick={() => {
            const fallback = character.abilities.find((ability) => ability.can_be_fallback);

            setDraft((rules) => [
              ...rules.slice(0, -1),
              {
                condition: [defaultTermFor('self.health_percent', grammar, grammar.effects)],
                abilityId: fallback?.id ?? character.abilities[0]?.id ?? '',
              },
              ...rules.slice(-1),
            ]);
            setSaved(false);
          }}
          disabled={draft.length >= grammar.limits.max_rules}
        >
          + {t('plan.addRule')}
        </Button>

        <Button
          variant="ghost"
          onClick={() => {
            setDraft(structuredClone(character.battle_plan));
            save.reset();
            setSaved(false);
          }}
          disabled={!dirty}
        >
          {t('plan.revert')}
        </Button>

        <Button variant="primary" className="ml-auto" onClick={submit} busy={save.isPending} disabled={!dirty}>
          {save.isPending ? t('plan.saving') : t('plan.save')}
        </Button>
      </div>
    </Panel>
  );
}
