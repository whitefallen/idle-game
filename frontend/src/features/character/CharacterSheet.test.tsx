import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CharacterDetail, ItemDetail } from '@/lib/types';
import { CharacterSheet } from './CharacterSheet';

/**
 * Respec is the one control on this screen that spends gold irreversibly and
 * can take gear off without being asked to. These tests cover the two things
 * that would make it a bug report rather than a feature: firing without
 * confirmation, and stripping gear without saying so.
 *
 * The mutation is exercised through a mocked fetch rather than a mocked hook,
 * so the request the server would actually receive is what is asserted on.
 */

const character: CharacterDetail = {
  id: 'char-1',
  name: 'Warden',
  level: 4,
  power_score: 120,
  vigor: {
    current: 10,
    max: 10,
    seconds_per_point: 300,
    ticked_at: '2026-08-07T12:00:00+00:00',
    full_at: '2026-08-07T12:00:00+00:00',
  },
  experience: 0,
  experience_to_next_level: 100,
  gold: 1000,
  emberdust: 0,
  unspent_points: 0,
  attributes: { STR: 30, DEX: 5, INT: 5, CON: 5, LUK: 5 },
  derived_stats: {
    maxHealth: 100,
    initiative: 10,
    maxFocus: 40,
    focusPerTurn: 5,
    weaponBaseDamage: 14,
    flatDamageBonus: 0,
    scalingBp: 10000,
    critChanceBp: 500,
    critPowerBp: 15000,
    dodgeChanceBp: 300,
    accuracyBp: 10000,
    armourRating: 0,
    resistanceRatings: {},
  },
  loadout_slots: 2,
  respec_cost: 680,
  ability_ids: [],
  abilities: [],
  disciplines: [],
  battle_plan: [],
};

const halberd = {
  id: 'item-1',
  definition_id: 'item.wardens_halberd',
  equipped_slot: null,
} as unknown as ItemDetail;

function renderSheet(detail: CharacterDetail = character): void {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });

  render(
    <QueryClientProvider client={client}>
      <CharacterSheet character={detail} onEditPlan={() => {}} />
    </QueryClientProvider>,
  );
}

/** The envelope every endpoint replies in. See docs/api.md section 2. */
function respond(data: unknown) {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify({ data, meta: { server_time: '2026-08-07T12:00:00+00:00' } }),
  } as Response;
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('respec', () => {
  it('asks before spending anything', async () => {
    renderSheet();

    await userEvent.click(screen.getByRole('button', { name: /respec/i }));

    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByText(/reset every allocated point for 680 gold/i)).toBeInTheDocument();
  });

  it('does nothing when the confirmation is dismissed', async () => {
    renderSheet();

    await userEvent.click(screen.getByRole('button', { name: /respec/i }));
    await userEvent.click(screen.getByRole('button', { name: /cancel/i }));

    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.queryByText(/reset every allocated point/i)).not.toBeInTheDocument();
  });

  it('sends no body and an idempotency key once confirmed', async () => {
    fetchMock.mockResolvedValue(respond({ character, gold_spent: 680, unequipped: [] }));

    renderSheet();

    await userEvent.click(screen.getByRole('button', { name: /respec/i }));
    await userEvent.click(screen.getByRole('button', { name: /reset attributes/i }));

    expect(fetchMock).toHaveBeenCalledTimes(1);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

    expect(url).toBe('/api/v1/characters/char-1/respec');
    expect(init.method).toBe('POST');
    // The cost is the server's to decide; a client that names a price is a
    // client that can be made to name the wrong one.
    expect(init.body).toBeUndefined();
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
  });

  it('reports gear the reset took off', async () => {
    fetchMock.mockResolvedValue(respond({ character, gold_spent: 680, unequipped: [halberd] }));

    renderSheet();

    await userEvent.click(screen.getByRole('button', { name: /respec/i }));
    await userEvent.click(screen.getByRole('button', { name: /reset attributes/i }));

    expect(await screen.findByRole('status')).toHaveTextContent(/Warden's Halberd/);
  });

  it('is offered but refused when the gold is not there', async () => {
    renderSheet({ ...character, gold: 10 });

    // Shown rather than hidden: a player needs to know the option exists and
    // what it would cost, or the price is a secret until they can afford it.
    expect(screen.getByRole('button', { name: /respec/i })).toBeDisabled();
    expect(screen.getByText(/not enough gold to respec yet/i)).toBeInTheDocument();
  });
});
