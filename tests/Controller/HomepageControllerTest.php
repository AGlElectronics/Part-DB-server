<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HomepageControllerTest extends WebTestCase
{
    public function testLicenseNoticeIsNotRendered(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringNotContainsString(
            'GNU Affero General Public License',
            (string) $client->getResponse()->getContent()
        );
    }
}
