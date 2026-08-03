import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, request } from '@/lib/api';
import { queryKeys } from '@/lib/queryClient';
import type { BattlePlanGrammar, CharacterDetail, PlanIssue, PlanRule } from '@/lib/types';

export function useBattlePlanGrammar() {
  return useQuery({
    queryKey: ['battle-plan', 'grammar'],
    queryFn: async () => (await request<{ grammar: BattlePlanGrammar }>('/battle-plan/grammar')).grammar,
    // Changes only with a release, so it is fetched once per session rather
    // than revalidated alongside gameplay data.
    staleTime: Infinity,
  });
}

interface SaveResult {
  character: CharacterDetail;
  warnings: PlanIssue[];
}

/**
 * Extracts the per-rule issues from a rejected save.
 *
 * The server returns every problem at once and points each at its rule, which
 * is what lets the editor annotate the offending rule instead of showing one
 * message at the top and leaving the player to hunt.
 */
export function planIssuesFrom(error: unknown): PlanIssue[] {
  if (!(error instanceof ApiError)) return [];

  const issues = error.details.issues;

  return Array.isArray(issues) ? (issues as PlanIssue[]) : [];
}

export function useUpdateBattlePlan(characterId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (rules: PlanRule[]) =>
      request<SaveResult>(`/characters/${characterId}/battle-plan`, { method: 'PUT', body: { rules } }),

    onSuccess: (result) => {
      // The response carries the saved character, so the cache is updated from
      // it rather than triggering a refetch the player would see as a flicker.
      client.setQueryData(queryKeys.character(characterId), result.character);
    },
  });
}
