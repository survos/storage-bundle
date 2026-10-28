<?php

declare(strict_types=1);

namespace Survos\StorageBundle\Controller;

use Survos\StorageBundle\Tracking\StorageJournal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class StorageChangesController
{
    public function __construct(private StorageJournal $journal, private ?AuthorizationCheckerInterface $authorization = null)
    {
    }

    #[Route('/changes', name: 'survos_storage_changes', methods: ['GET'], priority: 100)]
    public function __invoke(#[MapQueryParameter] int $after = 0, #[MapQueryParameter] int $limit = 100): JsonResponse
    {
        if (null === $this->authorization || !$this->authorization->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }
        if ($after < 0 || $limit < 1 || $limit > 1000) {
            return new JsonResponse(['error' => 'Invalid cursor or limit'], 400);
        }
        $events = $this->journal->since($after, $limit);

        return new JsonResponse(['events' => $events, 'next' => [] === $events ? $after : $events[array_key_last($events)]['id']], headers: ['Cache-Control' => 'private, no-store']);
    }
}
