<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Parts\Part;
use App\Entity\Purchasing\PurchaseOrder;
use App\Entity\UserSystem\User;
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
}
