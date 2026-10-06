<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LogSystem\PartStockChangedLogEntry;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Purchasing\PurchaseOrder;
use App\Entity\Purchasing\PurchaseOrderLine;
use App\Entity\UserSystem\User;
use App\Services\Purchasing\OrderCheckIn;
use App\Services\Purchasing\OrderCheckInException;
use App\Services\Purchasing\OrderCheckInLine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

#[Group('DB')]
#[Group('slow')]
final class PurchaseOrderControllerTest extends WebTestCase
{
    public function testCreateRenameAddRemoveAndDelete(): void
    {
        $client = $this->client();
        $part = $this->anyPart($client);
        $name = 'Shelf-'.bin2hex(random_bytes(4));

        $crawler = $client->request('GET', '/en/orders');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/en/orders', [
            '_token' => $this->formToken($crawler, '#/orders$#'),
            'name' => $name,
        ]);
        self::assertResponseRedirects();
        $orderId = $this->orderIdFromRedirect($client);

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($name, (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('a.btn-outline-light[href$="/orders"]'));

        $renamed = $name.'-renamed';
        $client->request('POST', '/en/orders/'.$orderId, [
            '_token' => $this->formToken($crawler, '#/orders/'.$orderId.'$#'),
            'name' => $renamed,
            'add_part' => (string) $part->getID(),
        ]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($renamed, $content);
        self::assertStringContainsString($part->getName(), $content);
        self::assertCount(1, $crawler->filter('input[name^="remove["]'));

        $client->request('POST', '/en/orders/'.$orderId, [
            '_token' => $this->formToken($crawler, '#/orders/'.$orderId.'$#'),
            'name' => $renamed,
            'add_part' => (string) $part->getID(),
        ]);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('That part is already on this order.', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('input[name^="remove["]'));

        $remove = $crawler->filter('input[name^="remove["]')->attr('name');
        self::assertIsString($remove);
        self::assertSame(1, preg_match('/remove\[(\d+)\]/', $remove, $matches));
        $client->request('POST', '/en/orders/'.$orderId, [
            '_token' => $this->formToken($crawler, '#/orders/'.$orderId.'$#'),
            'name' => $renamed,
            'remove' => [$matches[1] => '1'],
        ]);
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('a[href*="/part/'.$part->getID().'/"]'));
        self::assertCount(0, $crawler->filter('input[name^="remove["]'));

        $client->request('POST', '/en/orders/'.$orderId.'/delete', [
            '_token' => $this->formToken($crawler, '#/delete$#'),
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($renamed, (string) $client->getResponse()->getContent());

        $orders = $client->getContainer()->get(EntityManagerInterface::class)->getRepository(PurchaseOrder::class);
        self::assertNull($orders->find($orderId));
    }

    public function testNameIsRequiredAndPoNumberMatchesTheOrderKind(): void
    {
        $client = $this->client();
        $em = $this->em($client);
        $repository = $em->getRepository(PurchaseOrder::class);
        $before = $repository->count([]);
        $crawler = $client->request('GET', '/en/orders');

        $client->request('POST', '/en/orders', [
            '_token' => $this->formToken($crawler, '#/orders$#'),
            'name' => '   ',
            'kind' => 'mech',
        ]);
        $client->followRedirect();
        self::assertSame($before, $repository->count([]));
        self::assertStringContainsString('Enter a name for the order.', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/en/orders');
        $client->request('POST', '/en/orders', [
            '_token' => $this->formToken($crawler, '#/orders$#'),
            'name' => 'Mechanical PO',
            'kind' => 'mech',
        ]);
        $orderId = $this->orderIdFromRedirect($client);
        $order = $repository->find($orderId);
        self::assertInstanceOf(PurchaseOrder::class, $order);
        self::assertSame(PurchaseOrder::KIND_MECH, $order->getKind());
        self::assertMatchesRegularExpression('/^Mech-'.date('Y').'\d{4}$/', $order->getNumber());
    }

    public function testExternalOrderLineCanBeTurnedIntoADatabasePart(): void
    {
        $client = $this->client();
        $em = $this->em($client);
        $name = 'External-order-'.bin2hex(random_bytes(3));
        $crawler = $client->request('GET', '/en/orders');
        $client->request('POST', '/en/orders', [
            '_token' => $this->formToken($crawler, '#/orders$#'),
            'name' => $name,
            'kind' => 'mech',
        ]);
        $orderId = $this->orderIdFromRedirect($client);
        $crawler = $client->followRedirect();

        $client->request('POST', '/en/orders/'.$orderId, [
            '_token' => $this->formToken($crawler, '#/orders/'.$orderId.'$#'),
            'name' => $name,
            'external_name' => 'DIN 912 M5x20',
            'external_quantity' => '12',
        ]);
        $client->followRedirect();
        $em->clear();
        $order = $em->find(PurchaseOrder::class, $orderId);
        self::assertInstanceOf(PurchaseOrder::class, $order);
        self::assertCount(1, $order->getLines());
        $line = $order->getLines()->first();
        self::assertInstanceOf(PurchaseOrderLine::class, $line);
        self::assertFalse($line->isInDatabase());
        self::assertSame(12, $line->getQuantity());

        $category = $em->find(Category::class, 1);
        if (!$category instanceof Category) {
            self::markTestSkipped('Fixture category was not found.');
        }
        $crawler = $client->request('GET', '/en/orders/'.$orderId);
        $client->request('POST', '/en/orders/'.$orderId.'/lines/'.$line->getId().'/create-part', [
            '_token' => $this->formToken($crawler, '#/create-part$#'),
            'adopt_name' => 'DIN 912 M5 x 20',
            'category' => (string) $category->getID(),
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $em->clear();
        $order = $em->find(PurchaseOrder::class, $orderId);
        self::assertInstanceOf(PurchaseOrder::class, $order);
        $line = $order->getLines()->first();
        self::assertInstanceOf(PurchaseOrderLine::class, $line);
        self::assertTrue($line->isInDatabase());
        self::assertSame('DIN 912 M5 x 20', $line->getPart()?->getName());
    }

    public function testPartToolsButtonAddsThePartToANewOrExistingOrder(): void
    {
        $client = $this->client();
        $part = $this->anyPart($client);
        $name = 'From-part-'.bin2hex(random_bytes(4));

        $client->request('GET', '/en/part/'.$part->getID());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="/orders/add?parts='.$part->getID().'"]');

        $crawler = $client->request('GET', '/en/orders/add?parts='.$part->getID());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($part->getName(), (string) $client->getResponse()->getContent());

        $client->request('POST', '/en/orders/add', [
            '_token' => $this->formToken($crawler, '#/orders/add$#'),
            'parts' => (string) $part->getID(),
            'order' => '',
            'name' => $name,
        ]);
        self::assertResponseRedirects();
        $orderId = $this->orderIdFromRedirect($client);
        $crawler = $client->followRedirect();
        self::assertStringContainsString($name, (string) $client->getResponse()->getContent());
        self::assertStringContainsString($part->getName(), (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/en/orders/add?parts='.$part->getID());
        $client->request('POST', '/en/orders/add', [
            '_token' => $this->formToken($crawler, '#/orders/add$#'),
            'parts' => (string) $part->getID(),
            'order' => (string) $orderId,
            'name' => '',
        ]);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('That part is already on this order.', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('input[name^="remove["]'));
    }

    public function testOrderedButtonUsesTheServerDate(): void
    {
        $client = $this->client();
        $name = 'Dated-'.bin2hex(random_bytes(3));
        $crawler = $client->request('GET', '/en/orders');
        $client->request('POST', '/en/orders', [
            '_token' => $this->formToken($crawler, '#/orders$#'),
            'name' => $name,
        ]);
        $orderId = $this->orderIdFromRedirect($client);
        $client->followRedirect();

        $crawler = $client->request('GET', '/en/orders');
        $row = $this->orderRow($crawler, $name);
        self::assertStringContainsString('Not ordered', $row);
        self::assertStringContainsString('Open', $row);

        $crawler = $client->request('GET', '/en/orders/'.$orderId);
        $client->request('POST', '/en/orders/'.$orderId, [
            '_token' => $this->formToken($crawler, '#/orders/'.$orderId.'$#'),
            'name' => $name,
            'mark_ordered' => '1',
        ]);
        $client->followRedirect();
        self::assertStringContainsString((new \DateTimeImmutable('today'))->format('Y-m-d'), (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/en/orders');
        self::assertStringContainsString((new \DateTimeImmutable('today'))->format('Y-m-d'), $this->orderRow($crawler, $name));
    }

    public function testCheckInAddsStockAcrossDeliveriesAndRecordsTheComment(): void
    {
        $client = $this->client();
        $created = $this->partWithSingleLot($client, 4.0);
        $part = $created['part'];
        $lotId = (int) $created['lot']->getID();
        $name = 'Arrive-'.bin2hex(random_bytes(3));
        $orderId = $this->createOrderWithParts($client, $name, [$part]);
        $lineId = $this->lineIdForPart($client->getCrawler(), (int) $part->getID());
        $this->setLineQuantity($client, $client->getCrawler(), $orderId, $name, $lineId, 10);

        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.btn-outline-light[href$="/orders/'.$orderId.'"]'));
        self::assertSame((string) $lotId, $this->namedField($crawler, 'lot['.$lineId.']')?->attr('value'));
        self::assertStringContainsString($created['location']->getName(), (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('form[data-controller~="pages--purchase-order-check-in"]'));
        self::assertCount(1, $crawler->filter('[data-check-in-toolbar]'));
        self::assertCount(5, $crawler->filter('[data-check-in-filter]'));
        self::assertCount(1, $crawler->filter('[data-check-in-filter="missing"]'));
        self::assertCount(1, $crawler->filter('[data-check-in-set-visible="arrived"]'));
        self::assertCount(1, $crawler->filter('tr[data-check-in-row][data-check-in-state="missing"]'));
        self::assertCount(1, $crawler->filter('input[data-check-in][name="check['.$lineId.']"].d-none'));
        self::assertCount(1, $crawler->filter('button[data-check-in-choice="missing"]'));
        self::assertCount(1, $crawler->filter('button[data-check-in-choice="arrived"]'));

        $comment = 'Checked in from order '.$name;
        $crawler = $this->postCheckIn($client, $crawler, $orderId, [
            'step' => 'review',
            'comment' => $comment,
            'check' => [$lineId => '1'],
            'qty' => [$lineId => '4'],
            'lot' => [$lineId => (string) $lotId],
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing has changed yet.', (string) $client->getResponse()->getContent());
        $em = $this->em($client);
        $em->clear();
        self::assertSame(4.0, $this->lotAmount($em, $lotId));

        $client->request('POST', '/en/orders/'.$orderId.'/check-in', [
            '_token' => $this->formToken($crawler, '#/check-in$#'),
            'step' => 'confirm',
            'comment' => $comment,
            'check' => [$lineId => '1'],
            'qty' => [$lineId => '4'],
            'lot' => [$lineId => (string) $lotId],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Stock was updated.', $page);
        self::assertStringContainsString('Partial', $page);
        self::assertStringContainsString($comment, $page);
        $em->clear();
        self::assertSame(8.0, $this->lotAmount($em, $lotId));
        self::assertTrue($this->stockCommentExists($em, $lotId, $comment));

        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        $client->request('POST', '/en/orders/'.$orderId.'/check-in', [
            '_token' => $this->formToken($crawler, '#/check-in$#'),
            'step' => 'confirm',
            'comment' => $comment,
            'check' => [$lineId => '1'],
            'qty' => [$lineId => '6'],
            'lot' => [$lineId => (string) $lotId],
        ]);
        $client->followRedirect();
        $em->clear();
        $order = $em->find(PurchaseOrder::class, $orderId);
        self::assertInstanceOf(PurchaseOrder::class, $order);
        self::assertSame('received', $order->getFulfillment());
        self::assertSame(14.0, $this->lotAmount($em, $lotId));
        self::assertCount(2, $order->getReceipts());

        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        self::assertCount(1, $crawler->filter('tr[data-check-in-row][data-check-in-state="completed"]'));
        self::assertCount(0, $crawler->filter('tr[data-check-in-state="completed"] input[data-check-in]'));
        self::assertStringContainsString('Already received', $crawler->filter('tr[data-check-in-state="completed"]')->text());

        $crawler = $client->request('GET', '/en/orders?view=history');
        self::assertStringContainsString('Received', $this->orderRow($crawler, $name));
    }

    public function testCheckInCreatesALotWhenThePartHasNone(): void
    {
        $client = $this->client();
        $em = $this->em($client);
        $part = $em->getRepository(Part::class)->findOneBy(['name' => 'Part 1']);
        if (!$part instanceof Part || $part->getPartLots()->count() > 0) {
            self::markTestSkipped('Fixture part Part 1 without lots was not found.');
        }
        $name = 'New-lot-'.bin2hex(random_bytes(3));
        $orderId = $this->createOrderWithParts($client, $name, [$part]);
        $lineId = $this->lineIdForPart($client->getCrawler(), (int) $part->getID());
        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        $locationSelect = $this->namedField($crawler, 'location['.$lineId.']');
        self::assertInstanceOf(Crawler::class, $locationSelect);
        $locationId = 0;
        $locationSelect->filter('option')->each(function (Crawler $option) use (&$locationId): void {
            $value = (string) $option->attr('value');
            if ($locationId === 0 && $value !== '') {
                $locationId = (int) $value;
            }
        });
        self::assertGreaterThan(0, $locationId);
        $comment = 'Checked in from order '.$name;
        $crawler = $this->postCheckIn($client, $crawler, $orderId, [
            'step' => 'review',
            'comment' => $comment,
            'check' => [$lineId => '1'],
            'qty' => [$lineId => '3'],
            'location' => [$lineId => (string) $locationId],
        ]);
        self::assertStringContainsString('New lot at', (string) $client->getResponse()->getContent());
        $client->request('POST', '/en/orders/'.$orderId.'/check-in', [
            '_token' => $this->formToken($crawler, '#/check-in$#'),
            'step' => 'confirm',
            'comment' => $comment,
            'check' => [$lineId => '1'],
            'qty' => [$lineId => '3'],
            'location' => [$lineId => (string) $locationId],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        $em->clear();
        $reloaded = $em->find(Part::class, $part->getID());
        self::assertInstanceOf(Part::class, $reloaded);
        self::assertCount(1, $reloaded->getPartLots());
        $lot = $reloaded->getPartLots()->first();
        self::assertInstanceOf(PartLot::class, $lot);
        self::assertSame(3.0, $lot->getAmount());
        self::assertSame($locationId, $lot->getStorageLocation()?->getID());
        self::assertTrue($this->stockCommentExists($em, (int) $lot->getID(), $comment));
    }

    public function testCheckInMovesCompletedLinesBelowOutstandingLines(): void
    {
        $client = $this->client();
        $first = $this->partWithSingleLot($client, 0.0);
        $second = $this->partWithSingleLot($client, 0.0);
        $orderId = $this->createOrderWithParts(
            $client,
            'Sort-check-in-'.bin2hex(random_bytes(3)),
            [$first['part'], $second['part']]
        );

        $em = $this->em($client);
        $order = $em->find(PurchaseOrder::class, $orderId);
        self::assertInstanceOf(PurchaseOrder::class, $order);
        $lines = $order->getLines()->toArray();
        self::assertCount(2, $lines);
        $lines[0]->setQuantity(2);
        $lines[0]->addQuantityReceived(2);
        $lines[1]->setQuantity(2);
        $em->flush();

        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        $states = $crawler->filter('tr[data-check-in-row]')->each(
            static fn (Crawler $row): ?string => $row->attr('data-check-in-state')
        );

        self::assertSame(['missing', 'completed'], $states);
    }

    public function testCheckInOfAMissingLocationLeavesStockUnchanged(): void
    {
        $client = $this->client();
        $created = $this->partWithSingleLot($client, 4.0);
        $em = $this->em($client);
        $bare = $em->getRepository(Part::class)->findOneBy(['name' => 'Part 1']);
        if (!$bare instanceof Part || $bare->getPartLots()->count() > 0) {
            self::markTestSkipped('Fixture part Part 1 without lots was not found.');
        }
        $name = 'Hold-'.bin2hex(random_bytes(3));
        $orderId = $this->createOrderWithParts($client, $name, [$created['part'], $bare]);
        $goodLine = $this->lineIdForPart($client->getCrawler(), (int) $created['part']->getID());
        $bareLine = $this->lineIdForPart($client->getCrawler(), (int) $bare->getID());
        $lotId = (int) $created['lot']->getID();
        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        $client->request('POST', '/en/orders/'.$orderId.'/check-in', [
            '_token' => $this->formToken($crawler, '#/check-in$#'),
            'step' => 'confirm',
            'comment' => 'should not stick',
            'check' => [$goodLine => '1', $bareLine => '1'],
            'qty' => [$goodLine => '2', $bareLine => '2'],
            'lot' => [$goodLine => (string) $lotId],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Choose a storage location for Part 1.', (string) $client->getResponse()->getContent());
        $em->clear();
        self::assertSame(4.0, $this->lotAmount($em, $lotId));
        $order = $em->find(PurchaseOrder::class, $orderId);
        self::assertInstanceOf(PurchaseOrder::class, $order);
        self::assertCount(0, $order->getReceipts());
        self::assertSame(0, $order->findLineForPart($em->find(Part::class, $created['part']->getID()) ?? $created['part'])?->getQuantityReceived());
    }

    public function testCheckInAsksForALotWhenThePartHasSeveral(): void
    {
        $client = $this->client();
        $part = $this->em($client)->getRepository(Part::class)->findOneBy(['name' => 'Part 3']);
        if (!$part instanceof Part || $part->getPartLots()->count() < 2) {
            self::markTestSkipped('Fixture part Part 3 with two lots was not found.');
        }
        $orderId = $this->createOrderWithParts($client, 'Multi-'.bin2hex(random_bytes(3)), [$part]);
        $lineId = $this->lineIdForPart($client->getCrawler(), (int) $part->getID());
        $crawler = $client->request('GET', '/en/orders/'.$orderId.'/check-in');
        $lotSelect = $this->namedField($crawler, 'lot['.$lineId.']');
        self::assertInstanceOf(Crawler::class, $lotSelect);
        self::assertGreaterThan(2, $lotSelect->filter('option')->count());

        $client->request('POST', '/en/orders/'.$orderId.'/check-in', [
            '_token' => $this->formToken($crawler, '#/check-in$#'),
            'step' => 'review',
            'check' => [$lineId => '1'],
            'qty' => [$lineId => '1'],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Choose a stock lot for Part 3.', (string) $client->getResponse()->getContent());
    }

    public function testAFailedLineRollsBackTheWholeCheckIn(): void
    {
        $client = $this->client();
        $em = $this->em($client);
        $first = $this->partWithSingleLot($client, 5.0);
        $second = $this->partWithSingleLot($client, 7.0);
        $second['lot']->setInstockUnknown(true);
        $em->flush();

        $order = new PurchaseOrder();
        $order->setName('Rollback-'.bin2hex(random_bytes(3)));
        $line1 = new PurchaseOrderLine();
        $line1->setPart($first['part']);
        $line1->setQuantity(5);
        $order->addLine($line1);
        $line2 = new PurchaseOrderLine();
        $line2->setPart($second['part']);
        $line2->setQuantity(5);
        $order->addLine($line2);
        $em->persist($order);
        $em->flush();

        $service = static::getContainer()->get(OrderCheckIn::class);
        self::assertInstanceOf(OrderCheckIn::class, $service);
        try {
            $service->apply($order, [
                new OrderCheckInLine($line1, 2, $first['lot'], false, null),
                new OrderCheckInLine($line2, 2, $second['lot'], false, null),
            ], 'should not stick');
            self::fail('The check-in should have been rejected.');
        } catch (OrderCheckInException $exception) {
            self::assertSame('purchase_order.check_in.lot_rejected', $exception->translationKey);
        }

        $lotId = (int) $first['lot']->getID();
        $orderId = (int) $order->getId();
        $partId = (int) $first['part']->getID();
        $em->clear();
        self::assertSame(5.0, $this->lotAmount($em, $lotId));
        $reloaded = $em->find(PurchaseOrder::class, $orderId);
        self::assertInstanceOf(PurchaseOrder::class, $reloaded);
        self::assertCount(0, $reloaded->getReceipts());
        $part = $em->find(Part::class, $partId);
        self::assertInstanceOf(Part::class, $part);
        self::assertSame(0, $reloaded->findLineForPart($part)?->getQuantityReceived());
    }

    public function testAddPageWithoutAPartReturnsToTheList(): void
    {
        $client = $this->client();
        $client->request('GET', '/en/orders/add');
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('Choose a part to add.', (string) $client->getResponse()->getContent());
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $users = $client->getContainer()->get(EntityManagerInterface::class)->getRepository(User::class);
        $user = $users->findOneBy(['name' => 'admin']);
        if (!$user instanceof User) {
            self::markTestSkipped('Fixture user admin not found.');
        }
        $client->loginUser($user);

        return $client;
    }

    private function anyPart(KernelBrowser $client): Part
    {
        $part = $client->getContainer()->get(EntityManagerInterface::class)->getRepository(Part::class)->findOneBy([]);
        if (!$part instanceof Part) {
            self::markTestSkipped('No fixture parts found.');
        }

        return $part;
    }

    private function formToken(Crawler $crawler, string $actionPattern): string
    {
        $token = null;
        $crawler->filter('form')->each(function (Crawler $node) use ($actionPattern, &$token): void {
            if ($token !== null || !preg_match($actionPattern, (string) $node->attr('action'))) {
                return;
            }
            $token = $node->filter('input[name="_token"]')->attr('value');
        });
        self::assertIsString($token);

        return $token;
    }

    private function orderIdFromRedirect(KernelBrowser $client): int
    {
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/orders/(\d+)#', $location);
        preg_match('#/orders/(\d+)#', $location, $matches);

        return (int) $matches[1];
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @return array{part: Part, lot: PartLot, location: StorageLocation}
     */
    private function partWithSingleLot(KernelBrowser $client, float $amount): array
    {
        $em = $this->em($client);
        $category = $em->find(Category::class, 1);
        $location = $em->find(StorageLocation::class, 1);
        if (!$category instanceof Category || !$location instanceof StorageLocation) {
            self::markTestSkipped('Fixture category or storage location was not found.');
        }
        $part = new Part();
        $part->setName('Checkin-'.bin2hex(random_bytes(3)));
        $part->setCategory($category);
        $lot = new PartLot();
        $lot->setAmount($amount);
        $lot->setStorageLocation($location);
        $part->addPartLot($lot);
        $em->persist($part);
        $em->flush();

        return ['part' => $part, 'lot' => $lot, 'location' => $location];
    }

    /**
     * @param list<Part> $parts
     */
    private function createOrderWithParts(KernelBrowser $client, string $name, array $parts): int
    {
        $ids = array_map(static fn (Part $part): string => (string) $part->getID(), $parts);
        $crawler = $client->request('GET', '/en/orders/add?parts='.implode(',', $ids));
        self::assertResponseIsSuccessful();
        $client->request('POST', '/en/orders/add', [
            '_token' => $this->formToken($crawler, '#/orders/add$#'),
            'parts' => implode(',', $ids),
            'order' => '',
            'name' => $name,
        ]);
        self::assertResponseRedirects();
        $orderId = $this->orderIdFromRedirect($client);
        $client->followRedirect();

        return $orderId;
    }

    private function lineIdForPart(Crawler $crawler, int $partId): int
    {
        $id = null;
        $crawler->filter('tbody tr')->each(function (Crawler $row) use ($partId, &$id): void {
            if ($id !== null || $row->filter('a[href*="/part/'.$partId.'/"]')->count() === 0) {
                return;
            }
            $name = $row->filter('input[data-order-qty]')->attr('name');
            if (is_string($name) && preg_match('/lines\[(\d+)\]\[quantity\]/', $name, $matches) === 1) {
                $id = (int) $matches[1];
            }
        });
        self::assertIsInt($id);

        return $id;
    }

    private function setLineQuantity(KernelBrowser $client, Crawler $crawler, int $orderId, string $name, int $lineId, int $quantity): void
    {
        $client->request('POST', '/en/orders/'.$orderId, [
            '_token' => $this->formToken($crawler, '#/orders/'.$orderId.'$#'),
            'name' => $name,
            'lines' => [$lineId => ['target' => '0', 'quantity' => (string) $quantity]],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function postCheckIn(KernelBrowser $client, Crawler $crawler, int $orderId, array $fields): Crawler
    {
        $fields['_token'] = $this->formToken($crawler, '#/check-in$#');
        $client->request('POST', '/en/orders/'.$orderId.'/check-in', $fields);

        return $client->getCrawler();
    }

    private function namedField(Crawler $crawler, string $name): ?Crawler
    {
        $found = null;
        $crawler->filter('input, select, textarea')->each(function (Crawler $node) use ($name, &$found): void {
            if ($found instanceof Crawler || $node->attr('name') !== $name) {
                return;
            }
            $found = $node;
        });

        return $found;
    }

    private function orderRow(Crawler $crawler, string $name): string
    {
        $text = null;
        $crawler->filter('table tbody tr')->each(function (Crawler $row) use ($name, &$text): void {
            if ($text === null && str_contains($row->text(), $name)) {
                $text = $row->text();
            }
        });
        self::assertIsString($text);

        return $text;
    }

    private function lotAmount(EntityManagerInterface $em, int $lotId): float
    {
        $lot = $em->find(PartLot::class, $lotId);
        self::assertInstanceOf(PartLot::class, $lot);

        return $lot->getAmount();
    }

    private function stockCommentExists(EntityManagerInterface $em, int $lotId, string $comment): bool
    {
        $logs = $em->getRepository(PartStockChangedLogEntry::class)->findBy(['target_id' => $lotId]);
        foreach ($logs as $log) {
            if ($log->getComment() === $comment) {
                return true;
            }
        }

        return false;
    }
}
