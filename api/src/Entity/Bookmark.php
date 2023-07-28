<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\BookmarkRepository;
use App\Serializer\IriTransformerNormalizer;
use App\State\Processor\BookmarkPersistProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An agent bookmarks/flags/labels/tags/marks an object.
 *
 * @see https://schema.org/BookmarkAction
 *
 * todo remove admin (useless)
 */
#[ApiResource(
    types: ['https://schema.org/BookmarkAction'],
    operations: [
        new GetCollection(
            uriTemplate: '/admin/bookmarks{._format}',
            itemUriTemplate: '/admin/bookmarks/{id}{._format}',
            security: 'is_granted("ROLE_ADMIN")',
            normalizationContext: [
                'groups' => ['Bookmark:read:admin'],
                IriTransformerNormalizer::CONTEXT_KEY => [
                    'book' => '/admin/books/{id}{._format}',
                    'user' => '/admin/users/{id}{._format}',
                ],
            ],
            filters: ['app.filter.bookmark.admin.search']
        ),
        new Get(
            uriTemplate: '/admin/bookmarks/{id}{._format}',
            security: 'is_granted("ROLE_ADMIN")',
            normalizationContext: [
                'groups' => ['Bookmark:read:admin'],
                IriTransformerNormalizer::CONTEXT_KEY => [
                    'book' => '/admin/books/{id}{._format}',
                    'user' => '/admin/users/{id}{._format}',
                ],
            ]
        ),
        new GetCollection(
            itemUriTemplate: '/bookmarks/{id}{._format}',
            filters: ['app.filter.bookmark.search']
        ),
        new Get(),
        new Post(
            processor: BookmarkPersistProcessor::class,
            itemUriTemplate: '/bookmarks/{id}{._format}'
        ),
    ],
    normalizationContext: [
        'groups' => ['Bookmark:read'],
        IriTransformerNormalizer::CONTEXT_KEY => [
            'book' => '/books/{id}{._format}',
        ],
    ],
    denormalizationContext: ['groups' => ['Bookmark:write']],
    mercure: true,
    security: 'is_granted("ROLE_USER")'
)]
#[ORM\Entity(repositoryClass: BookmarkRepository::class)]
class Bookmark
{
    /**
     * @see https://schema.org/identifier
     */
    #[ApiProperty(identifier: true, types: ['https://schema.org/identifier'])]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    private ?Uuid $id = null;

    /**
     * @see https://schema.org/agent
     */
    #[ApiProperty(types: ['https://schema.org/agent'])]
    #[Groups(groups: ['Bookmark:read:admin'])]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $user = null;

    /**
     * @see https://schema.org/object
     */
    #[ApiProperty(types: ['https://schema.org/object'])]
    #[Assert\NotNull]
    #[Groups(groups: ['Bookmark:read', 'Bookmark:read:admin', 'Bookmark:write'])]
    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Book $book = null;

    /**
     * @see https://schema.org/startTime
     */
    #[ApiProperty(types: ['https://schema.org/startTime'])]
    #[Groups(groups: ['Bookmark:read', 'Bookmark:read:admin'])]
    #[ORM\Column(type: 'datetime_immutable')]
    public ?\DateTimeInterface $bookmarkedAt = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }
}
