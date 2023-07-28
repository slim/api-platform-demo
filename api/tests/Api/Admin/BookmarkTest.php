<?php

declare(strict_types=1);

namespace App\Tests\Api\Admin;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\Factory\BookmarkFactory;
use App\DataFixtures\Factory\UserFactory;
use App\Security\OidcTokenGenerator;
use App\Tests\Api\Admin\Trait\UsersDataProviderTrait;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BookmarkTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
    use UsersDataProviderTrait;

    private Client $client;

    protected function setup(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @dataProvider getNonAdminUsers
     */
    public function testAsNonAdminUserICannotGetACollectionOfBookmarks(int $expectedCode, string $hydraDescription, ?UserFactory $userFactory): void
    {
        BookmarkFactory::createMany(10, ['user' => UserFactory::createOne()]);

        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(OidcTokenGenerator::class)->generate([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('GET', '/admin/bookmarks', $options);

        self::assertResponseStatusCodeSame($expectedCode);
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertJsonContains([
            '@context' => '/contexts/Error',
            '@type' => 'hydra:Error',
            'hydra:title' => 'An error occurred',
            'hydra:description' => $hydraDescription,
        ]);
    }

    public function testAsAdminUserICanGetACollectionOfBookmarks(): void
    {
        BookmarkFactory::createMany(100, ['user' => UserFactory::createOne()]);

        $token = self::getContainer()->get(OidcTokenGenerator::class)->generate([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('GET', '/admin/bookmarks', ['auth_bearer' => $token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertJsonContains([
            'hydra:totalItems' => 100,
        ]);
        self::assertCount(30, $response->toArray()['hydra:member']);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__.'/schemas/Bookmark/collection.json'));
    }
}
