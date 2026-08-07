<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Inventory\Domain\Entity\ItemInstance;
use App\Feature\Inventory\Domain\Model\ItemRarity;
use App\Feature\Inventory\Domain\Service\VendorRules;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class VendorFlowTest extends ApiTestCase
{
    /** item.tempered_hauberk: ilvl 5, vendorValue 95, needs level 3 + 10 CON. */
    private const string ITEM_DEFINITION = 'item.tempered_hauberk';

    private const int ITEM_LEVEL = 5;

    private const int VENDOR_VALUE = 95;

    private function grantItem(string $characterId): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $item = new ItemInstance(
            Uuid::v7(),
            Uuid::fromString($characterId),
            self::ITEM_DEFINITION,
            self::ITEM_LEVEL,
            ItemRarity::Common,
            [],
            new DateTimeImmutable(),
        );

        $entityManager->persist($item);
        $entityManager->flush();

        return $item->id()->toRfc4122();
    }

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

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function stock(string $characterId): array
    {
        return $this->getJson('/api/v1/characters/' . $characterId . '/vendor');
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function buy(string $characterId, int $offerIndex): array
    {
        return $this->postJson('/api/v1/characters/' . $characterId . '/vendor/buy', ['offer_index' => $offerIndex]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function sell(string $itemId): array
    {
        return $this->postJson('/api/v1/items/' . $itemId . '/sell', []);
    }

    public function testStockReturnsAFullDaysOffersEachWithAPriceAndDefinition(): void
    {
        $this->registerAndLogin('shopper@example.com');
        $character = $this->createCharacter('Shopper');

        $response = $this->stock($character['id']);

        self::assertSame(200, $response['status'], self::describe($response['body']));

        $offers = $response['body']['data']['offers'];
        self::assertCount(VendorRules::STOCK_SIZE, $offers);

        foreach ($offers as $index => $offer) {
            self::assertSame($index, $offer['offer_index']);
            self::assertNotSame('', $offer['definition_id']);
            self::assertGreaterThan(0, $offer['price']);
            self::assertContains($offer['rarity'], ['common', 'uncommon', 'rare']);
        }
    }

    public function testStockIsStableAcrossRequestsOnTheSameDay(): void
    {
        $this->registerAndLogin('returning@example.com');
        $character = $this->createCharacter('Returning');

        $first = $this->stock($character['id']);
        $second = $this->stock($character['id']);

        self::assertSame($first['body']['data']['offers'], $second['body']['data']['offers']);
    }

    /**
     * The reroll exploit, closed.
     *
     * Seeding on (character, day) alone was not enough: the roll also consumed
     * the character's live level, Luck and average equipped item level, so
     * changing any of them re-rolled the day's eight offers on the next read.
     * Levelling and spending an attribute point move both of those inputs, and
     * the stock must not notice. See docs/vendor.md section 2.
     */
    public function testChangingLevelOrAttributesDoesNotRerollTheDaysStock(): void
    {
        $this->registerAndLogin('rerollhunter@example.com');
        $character = $this->createCharacter('Rerollhunter');

        $before = $this->stock($character['id'])['body']['data']['offers'];

        $this->levelTo($character['id'], 12);
        $allocated = $this->postJson(
            '/api/v1/characters/' . $character['id'] . '/attributes',
            ['allocation' => ['LUK' => 5]],
        );
        self::assertSame(200, $allocated['status'], self::describe($allocated['body']));

        $after = $this->stock($character['id'])['body']['data']['offers'];

        self::assertSame($before, $after);
    }

    /**
     * The same guarantee for a client that never calls the read endpoint.
     *
     * A rejected buy must still freeze the day, or failing a purchase, changing
     * gear and retrying becomes the reroll loop by another route — which is why
     * BuyVendorItemHandler freezes before it opens its transaction rather than
     * inside it.
     */
    public function testAFailedBuyStillFreezesTheDaysStock(): void
    {
        $this->registerAndLogin('failedbuyer@example.com');
        $character = $this->createCharacter('Failedbuyer');

        $rejected = $this->buy($character['id'], 0);
        self::assertSame('INSUFFICIENT_GOLD', $rejected['body']['error']['code']);
        $pricedAt = $rejected['body']['error']['details']['required'];

        // A rejected buy rolls its transaction back, and Doctrine closes the
        // EntityManager when it does. A real client's next request gets a fresh
        // one; this test reaches into the container directly, so it has to ask.
        static::getContainer()->get('doctrine')->resetManager();

        $this->levelTo($character['id'], 12);

        $offers = $this->stock($character['id'])['body']['data']['offers'];

        self::assertSame($pricedAt, $offers[0]['price']);
    }

    public function testBuyingAnOfferDebitsExactPriceAndGrantsTheItem(): void
    {
        $this->registerAndLogin('buyer@example.com');
        $character = $this->createCharacter('Buyer');

        $offer = $this->stock($character['id'])['body']['data']['offers'][0];
        $this->grantGold($character['id'], $offer['price']);

        $response = $this->buy($character['id'], 0);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame($offer['definition_id'], $response['body']['data']['item']['definition_id']);
        self::assertSame($offer['item_level'], $response['body']['data']['item']['item_level']);
        self::assertSame($offer['rarity'], $response['body']['data']['item']['rarity']);
        self::assertSame(0, $response['body']['data']['character']['gold']);
    }

    public function testBuyingWithoutEnoughGoldFails(): void
    {
        $this->registerAndLogin('brokeshopper@example.com');
        $character = $this->createCharacter('Brokeshopper');

        $offer = $this->stock($character['id'])['body']['data']['offers'][0];

        $response = $this->buy($character['id'], 0);

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_GOLD', $response['body']['error']['code']);
        self::assertSame($offer['price'], $response['body']['error']['details']['required']);
        self::assertSame(0, $response['body']['error']['details']['available']);
    }

    public function testBuyingAnOutOfRangeOfferIndexFails(): void
    {
        $this->registerAndLogin('outofrange@example.com');
        $character = $this->createCharacter('Outofrange');

        $response = $this->buy($character['id'], 999);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
    }

    public function testCannotBuyForAnotherAccountsCharacter(): void
    {
        $this->registerAndLogin('vendorowner@example.com');
        $character = $this->createCharacter('Vendorowner');

        $this->registerAndLogin('vendorintruder@example.com');

        self::assertSame(404, $this->buy($character['id'], 0)['status']);
    }

    /**
     * A retried request with the same idempotency key must replay the first
     * outcome rather than mint the item and charge gold twice — the same
     * guarantee RefineItemHandler's endpoint gives, for the same reason.
     */
    public function testRepeatingTheSameIdempotencyKeyOnBuyDoesNotDoubleCharge(): void
    {
        $this->registerAndLogin('buyretrier@example.com');
        $character = $this->createCharacter('Buyretrier');

        $offer = $this->stock($character['id'])['body']['data']['offers'][0];
        $this->grantGold($character['id'], $offer['price']);

        $this->client->request(
            'POST',
            '/api/v1/characters/' . $character['id'] . '/vendor/buy',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'buy-once'],
            content: json_encode(['offer_index' => 0], JSON_THROW_ON_ERROR),
        );
        $first = $this->decode();
        self::assertSame(200, $first['status'], self::describe($first['body']));

        $this->client->request(
            'POST',
            '/api/v1/characters/' . $character['id'] . '/vendor/buy',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'buy-once'],
            content: json_encode(['offer_index' => 0], JSON_THROW_ON_ERROR),
        );
        $second = $this->decode();

        self::assertSame(200, $second['status']);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame($first['body'], $second['body']);
        self::assertSame(0, $second['body']['data']['character']['gold']);
    }

    public function testSellingAnOwnedUnequippedItemAwardsVendorValue(): void
    {
        $this->registerAndLogin('seller@example.com');
        $character = $this->createCharacter('Seller');
        $itemId = $this->grantItem($character['id']);

        $response = $this->sell($itemId);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame(self::ITEM_DEFINITION, $response['body']['data']['sold']['definition_id']);
        self::assertSame(self::VENDOR_VALUE, $response['body']['data']['sold']['gold_awarded']);
        self::assertSame(self::VENDOR_VALUE, $response['body']['data']['character']['gold']);

        $inventory = $this->getJson('/api/v1/characters/' . $character['id'] . '/inventory');
        $carriedIds = array_column($inventory['body']['data']['carried'], 'id');
        self::assertNotContains($itemId, $carriedIds);
    }

    public function testCannotSellAnEquippedItem(): void
    {
        $this->registerAndLogin('equippedseller@example.com');
        $character = $this->createCharacter('Equippedseller');

        $this->levelTo($character['id'], 4);
        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', ['allocation' => ['CON' => 5]]);

        $itemId = $this->grantItem($character['id']);
        $equipped = $this->postJson('/api/v1/items/' . $itemId . '/equip', []);
        self::assertSame(200, $equipped['status'], self::describe($equipped['body']));

        $response = $this->sell($itemId);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
    }

    public function testCannotSellAnotherAccountsItem(): void
    {
        $this->registerAndLogin('itemowner@example.com');
        $character = $this->createCharacter('Itemowner');
        $itemId = $this->grantItem($character['id']);

        $this->registerAndLogin('itemthief@example.com');

        self::assertSame(404, $this->sell($itemId)['status']);
    }

    /**
     * A retried sell must not award gold twice — the same guarantee buy gives.
     */
    public function testRepeatingTheSameIdempotencyKeyOnSellDoesNotDoubleAward(): void
    {
        $this->registerAndLogin('sellretrier@example.com');
        $character = $this->createCharacter('Sellretrier');
        $itemId = $this->grantItem($character['id']);

        $this->client->request(
            'POST',
            '/api/v1/items/' . $itemId . '/sell',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'sell-once'],
            content: json_encode([], JSON_THROW_ON_ERROR),
        );
        $first = $this->decode();
        self::assertSame(200, $first['status'], self::describe($first['body']));

        $this->client->request(
            'POST',
            '/api/v1/items/' . $itemId . '/sell',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'sell-once'],
            content: json_encode([], JSON_THROW_ON_ERROR),
        );
        $second = $this->decode();

        self::assertSame(200, $second['status']);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertSame($first['body'], $second['body']);
        self::assertSame(self::VENDOR_VALUE, $second['body']['data']['character']['gold']);
    }
}
