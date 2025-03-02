<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\Factory\BookFactory;
use App\DataFixtures\Factory\BookmarkFactory;
use App\DataFixtures\Factory\UserFactory;
use App\Entity\Bookmark;
use App\Repository\BookmarkRepository;
use App\Tests\Api\Trait\SecurityTrait;
use App\Tests\Api\Trait\SerializerTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BookmarkTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
    use SecurityTrait;
    use SerializerTrait;

    private Client $client;

    protected function setup(): void
    {
        $this->client = self::createClient();
    }

    public function testAsAnonymousICannotGetACollectionOfBookmarks(): void
    {
        BookmarkFactory::createMany(10);

        $this->client->request('GET', '/bookmarks');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'hydra:Error',
            'hydra:title' => 'An error occurred',
            'hydra:description' => 'Full authentication is required to access this resource.',
        ]);
    }

    /**
     * Filters are disabled on /bookmarks.
     */
    public function testAsAUserICanGetACollectionOfMyBookmarksWithoutFilters(): void
    {
        BookmarkFactory::createMany(10);
        $user = UserFactory::createOne();
        BookmarkFactory::createMany(35, ['user' => $user]);

        $token = $this->generateToken([
            'email' => $user->email,
        ]);

        $response = $this->client->request('GET', '/bookmarks', ['auth_bearer' => $token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertJsonContains([
            'hydra:totalItems' => 35,
        ]);
        self::assertCount(30, $response->toArray()['hydra:member']);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__.'/schemas/Bookmark/collection.json'));
    }

    public function testAsAnonymousICannotCreateABookmark(): void
    {
        $book = BookFactory::createOne(['book' => 'https://openlibrary.org/books/OL2055137M.json']);

        $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/'.$book->getId(),
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'hydra:Error',
            'hydra:title' => 'An error occurred',
            'hydra:description' => 'Full authentication is required to access this resource.',
        ]);
    }

    public function testAsAUserICannotCreateABookmarkWithInvalidData(): void
    {
        $token = $this->generateToken([
            'email' => UserFactory::createOne()->email,
        ]);

        $uuid = Uuid::v7()->__toString();

        $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/'.$uuid,
            ],
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        // todo waiting for https://github.com/api-platform/core/pull/5844
//        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'hydra:Error',
            'hydra:title' => 'An error occurred',
            'hydra:description' => 'Item not found for "/books/'.$uuid.'".',
        ]);
        // todo waiting for https://github.com/api-platform/core/pull/5844
//        self::assertJsonContains([
//            '@type' => 'ConstraintViolationList',
//            'hydra:title' => 'An error occurred',
//            'hydra:description' => 'book: This value should be of type '.Book::class.'.',
//            'violations' => [
//                [
//                    'propertyPath' => 'book',
//                    'hint' => 'Item not found for "/books/'.$uuid.'".',
//                ],
//            ],
//        ]);
    }

    /**
     * @group mercure
     */
    public function testAsAUserICanCreateABookmark(): void
    {
        $book = BookFactory::createOne(['book' => 'https://openlibrary.org/books/OL2055137M.json']);
        $user = UserFactory::createOne();
        self::getMercureHub()->reset();

        $token = $this->generateToken([
            'email' => $user->email,
        ]);

        $response = $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/'.$book->getId(),
            ],
            'auth_bearer' => $token,
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertJsonContains([
            'book' => [
                '@id' => '/books/'.$book->getId(),
            ],
        ]);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__.'/schemas/Bookmark/item.json'));
        $id = preg_replace('/^.*\/(.+)$/', '$1', $response->toArray()['@id']);
        $object = self::getContainer()->get(BookmarkRepository::class)->find($id);
        self::assertCount(1, self::getMercureMessages());
        self::assertEquals(
            self::getMercureMessage(),
            new Update(
                topics: ['http://localhost/bookmarks/'.$id],
                data: self::serialize(
                    $object,
                    'jsonld',
                    self::getOperationNormalizationContext(Bookmark::class, '/bookmarks/{id}{._format}')
                )
            )
        );
    }

    public function testAsAUserICannotCreateADuplicateBookmark(): void
    {
        $book = BookFactory::createOne(['book' => 'https://openlibrary.org/books/OL2055137M.json']);
        $user = UserFactory::createOne();
        BookmarkFactory::createOne(['book' => $book, 'user' => $user]);

        $token = $this->generateToken([
            'email' => $user->email,
        ]);

        $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/'.$book->getId(),
            ],
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'ConstraintViolationList',
            'hydra:title' => 'An error occurred',
            'hydra:description' => 'You have already bookmarked this book.',
        ]);
    }

    public function testAsAnonymousICannotDeleteABookmark(): void
    {
        $bookmark = BookmarkFactory::createOne();

        $this->client->request('DELETE', '/bookmarks/'.$bookmark->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'hydra:Error',
            'hydra:title' => 'An error occurred',
            'hydra:description' => 'Full authentication is required to access this resource.',
        ]);
    }

    public function testAsAUserICannotDeleteABookmarkOfAnotherUser(): void
    {
        $bookmark = BookmarkFactory::createOne(['user' => UserFactory::createOne()]);

        $token = $this->generateToken([
            'email' => UserFactory::createOne()->email,
        ]);

        $this->client->request('DELETE', '/bookmarks/'.$bookmark->getId(), [
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'hydra:Error',
            'hydra:title' => 'An error occurred',
            'hydra:description' => 'Access Denied.',
        ]);
    }

    public function testAsAUserICannotDeleteAnInvalidBookmark(): void
    {
        $token = $this->generateToken([
            'email' => UserFactory::createOne()->email,
        ]);

        $this->client->request('DELETE', '/bookmarks/invalid', [
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @group mercure
     */
    public function testAsAUserICanDeleteMyBookmark(): void
    {
        $book = BookFactory::createOne(['title' => 'Hyperion']);
        $bookmark = BookmarkFactory::createOne(['book' => $book]);
        self::getMercureHub()->reset();

        $id = $bookmark->getId();

        $token = $this->generateToken([
            'email' => $bookmark->user->email,
        ]);

        $response = $this->client->request('DELETE', '/bookmarks/'.$bookmark->getId(), [
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmpty($response->getContent());
        BookmarkFactory::assert()->notExists(['book' => $book]);
        self::assertCount(1, self::getMercureMessages());
        // todo how to ensure it's a delete update
        self::assertEquals(
            new Update(
                topics: ['http://localhost/bookmarks/'.$id],
                data: json_encode(['@id' => '/bookmarks/'.$id])
            ),
            self::getMercureMessage()
        );
    }
}
