<?php

declare(strict_types=1);

namespace App\Tests\Functional\Character;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Event\CharacterRespecced;
use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class RespecTest extends ApiTestCase
{
    private function grantGold(string $characterId, int $amount): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        /** @var Character $character */
        $character = $entityManager->find(Character::class, Uuid::fromString($characterId));

        $character->awardGold($amount, new DateTimeImmutable());

        $entityManager->flush();
        $entityManager->clear();
    }

    private function grantItem(string $characterId, string $definitionId, int $itemLevel): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $item = new ItemInstance(
            Uuid::v7(),
            Uuid::fromString($characterId),
            $definitionId,
            $itemLevel,
            ItemRarity::Common,
            [],
            new DateTimeImmutable(),
        );

        $entityManager->persist($item);
        $entityManager->flush();

        return $item->id()->toRfc4122();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function respec(string $characterId): array
    {
        return $this->postJson('/api/v1/characters/' . $characterId . '/respec', []);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function postWithKey(string $uri, string $idempotencyKey): array
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey],
            content: json_encode([], JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    public function testRespecReturnsEveryAllocatedPointAndChargesGold(): void
    {
        $this->registerAndLogin('respec@example.com');
        $character = $this->createCharacter('Respec');

        $this->levelTo($character['id'], 4);

        // Level 4: 10 starting points plus 3 levels * 5 = 25 allocatable.
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['STR' => 25],
        ]);

        $cost = ProgressionRules::respecCost(4);
        $this->grantGold($character['id'], $cost);

        $response = $this->respec($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));

        $after = $response['body']['data']['character'];

        self::assertSame($cost, $response['body']['data']['gold_spent']);
        self::assertSame(0, $after['gold'], 'The cost is charged in full.');
        self::assertSame(25, $after['unspent_points'], 'Every allocated point comes back.');
        self::assertSame(5, $after['attributes']['STR'], 'Attributes return to their base value.');
    }

    /**
     * The cost comes from the character's level, never from the request, and is
     * the same figure the character sheet advertises.
     */
    public function testCostMatchesTheAdvertisedRespecCost(): void
    {
        $this->registerAndLogin('quoted@example.com');
        $character = $this->createCharacter('Quoted');

        $this->levelTo($character['id'], 10);
        $quoted = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character']['respec_cost'];

        $this->grantGold($character['id'], $quoted);

        $response = $this->respec($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame($quoted, $response['body']['data']['gold_spent']);
    }

    public function testRespecIsRefusedWithoutEnoughGold(): void
    {
        $this->registerAndLogin('broke@example.com');
        $character = $this->createCharacter('Broke');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['STR' => 25],
        ]);

        $response = $this->respec($character['id']);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_GOLD', $response['body']['error']['code']);
        self::assertSame(ProgressionRules::respecCost(4), $response['body']['error']['details']['required']);

        // Nothing moved: a refused respec must not reallocate either.
        $after = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];
        self::assertSame(30, $after['attributes']['STR']);
        self::assertSame(0, $after['unspent_points']);
    }

    /**
     * The loophole this endpoint would otherwise open: allocate into a
     * requirement, equip, respec into a different build, keep the gear.
     */
    public function testGearTheNewAllocationCannotSupportComesOff(): void
    {
        $this->registerAndLogin('stripped@example.com');
        $character = $this->createCharacter('Stripped');

        // The halberd needs level 4 and 12 Strength.
        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['STR' => 7],
        ]);

        $itemId = $this->grantItem($character['id'], 'item.wardens_halberd', 6);

        self::assertSame(200, $this->postJson('/api/v1/items/' . $itemId . '/equip', [])['status']);

        $this->grantGold($character['id'], ProgressionRules::respecCost(4));

        $response = $this->respec($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));

        $unequipped = $response['body']['data']['unequipped'];

        self::assertCount(1, $unequipped, 'The respec reports what it took off.');
        self::assertSame($itemId, $unequipped[0]['id']);

        $inventory = $this->getJson('/api/v1/characters/' . $character['id'] . '/inventory')['body']['data'];

        self::assertCount(0, $inventory['equipped']);
        self::assertCount(1, $inventory['carried'], 'The item is kept, just not worn.');

        // The advisory score has to follow the gear off, or leaderboards rank
        // the player by a weapon they are no longer wearing. See ADR-0006.
        $after = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];
        self::assertSame(14, $after['derived_stats']['weaponBaseDamage'], 'Back to the unarmed baseline.');
    }

    /**
     * Only gear the new allocation actually invalidates comes off. A respec
     * that stripped everything would be a far worse surprise than the one this
     * endpoint already carries.
     */
    public function testGearWithNoAttributeRequirementStaysOn(): void
    {
        $this->registerAndLogin('kept@example.com');
        $character = $this->createCharacter('Kept');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['CON' => 5],
        ]);

        // The hauberk needs level 3 and 10 Constitution — base Constitution is
        // 5, so this one does come off. The band is the control: no attribute
        // requirement at all.
        $hauberk = $this->grantItem($character['id'], 'item.tempered_hauberk', 5);
        $band = $this->grantItem($character['id'], 'item.ember_band', 5);

        $this->postJson('/api/v1/items/' . $hauberk . '/equip', []);
        $this->postJson('/api/v1/items/' . $band . '/equip', ['slot' => 'Ring1']);

        $this->grantGold($character['id'], ProgressionRules::respecCost(4));

        $unequipped = $this->respec($character['id'])['body']['data']['unequipped'];

        self::assertCount(1, $unequipped);
        self::assertSame($hauberk, $unequipped[0]['id'], 'Only the item whose requirement failed.');

        $inventory = $this->getJson('/api/v1/characters/' . $character['id'] . '/inventory')['body']['data'];

        self::assertCount(1, $inventory['equipped']);
        self::assertSame($band, $inventory['equipped'][0]['id']);
    }

    /**
     * No cooldown and no frequency limit: the gold cost is the only brake.
     * See docs/progression.md section 3.
     */
    public function testRespecMayBeRepeatedImmediately(): void
    {
        $this->registerAndLogin('repeat@example.com');
        $character = $this->createCharacter('Repeat');

        $this->levelTo($character['id'], 4);
        $this->grantGold($character['id'], ProgressionRules::respecCost(4) * 2);

        self::assertSame(200, $this->respec($character['id'])['status']);

        $second = $this->respec($character['id']);

        self::assertSame(200, $second['status'], self::describe($second['body']));
        self::assertSame(0, $second['body']['data']['character']['gold']);
    }

    /**
     * It spends real gold, so a retried request must not charge twice.
     */
    public function testReplayingAnIdempotencyKeyDoesNotChargeTwice(): void
    {
        $this->registerAndLogin('replayed@example.com');
        $character = $this->createCharacter('Replayed');

        $this->levelTo($character['id'], 4);

        $cost = ProgressionRules::respecCost(4);
        $this->grantGold($character['id'], $cost * 2);

        $uri = '/api/v1/characters/' . $character['id'] . '/respec';

        $first = $this->postWithKey($uri, 'respec-once');
        $second = $this->postWithKey($uri, 'respec-once');

        self::assertSame(200, $first['status'], self::describe($first['body']));
        self::assertSame(200, $second['status'], self::describe($second['body']));
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));

        $after = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];

        self::assertSame($cost, $after['gold'], 'Charged exactly once.');
    }

    /**
     * The guarantee that makes the synchronous path worth having: a subscriber
     * that fails takes the whole respec with it. Charging a player for a
     * reallocation that left illegal gear on would be worse than refusing the
     * reallocation. See ADR-0007.
     */
    public function testAFailingSubscriberRollsTheWholeRespecBack(): void
    {
        $this->registerAndLogin('rollback@example.com');
        $character = $this->createCharacter('Rollback');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['STR' => 25],
        ]);

        $cost = ProgressionRules::respecCost(4);
        $this->grantGold($character['id'], $cost);

        // The client reboots the kernel between requests by default, which
        // would discard a listener registered here before the request that has
        // to see it.
        $this->client->disableReboot();

        /** @var EventDispatcherInterface $events */
        $events = static::getContainer()->get('event_dispatcher');
        $events->addListener(CharacterRespecced::class, static function (): never {
            throw new RuntimeException('Subscriber failed.');
        });

        $response = $this->respec($character['id']);

        self::assertSame(500, $response['status'], 'The failure is not swallowed.');

        // Nothing committed: not the gold, not the reallocation.
        $after = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];

        self::assertSame($cost, $after['gold'], 'The gold was not spent.');
        self::assertSame(30, $after['attributes']['STR'], 'The allocation still stands.');
        self::assertSame(0, $after['unspent_points']);
    }

    public function testCannotRespecAnotherAccountsCharacter(): void
    {
        $this->registerAndLogin('owner@example.com');
        $character = $this->createCharacter('Owner');
        $this->grantGold($character['id'], 100000);

        $this->registerAndLogin('intruder@example.com');

        self::assertSame(404, $this->respec($character['id'])['status']);
    }
}
