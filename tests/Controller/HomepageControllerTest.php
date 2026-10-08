<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('DB')]
#[Group('slow')]
final class HomepageControllerTest extends WebTestCase
{
    public function testHomepageShowsUpstreamVersionAndForkBuildNumber(): void
    {
        $previousBuildVersion = getenv('PARTDB_BUILD_VERSION');
        putenv('PARTDB_BUILD_VERSION=2.19.2-5');

        try {
            $client = self::createClient();
            $client->request('GET', '/en/');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-build-identity]', 'Version v2.19.2');
            self::assertSelectorTextContains('[data-build-identity] .badge', 'Build 5');
            self::assertSelectorNotExists('[data-build-identity] code');
        } finally {
            putenv(false === $previousBuildVersion
                ? 'PARTDB_BUILD_VERSION'
                : 'PARTDB_BUILD_VERSION='.$previousBuildVersion);
        }
    }
}
