<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\Clock\Clock;
use App\Platform\Uid\IdentifierGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class DoctrineAuditLogger implements AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        /**
         * Salts the address hash so the same address does not produce the same
         * digest across deployments, which would make the hashes correlatable
         * between environments and precomputable.
         */
        private readonly string $hashSalt,
    ) {
    }

    public function record(
        AuditAction $action,
        array $context = [],
        ?Uuid $accountId = null,
        ?Uuid $characterId = null,
    ): void {
        $this->entityManager->persist($this->build($action, $context, $accountId, $characterId));
    }

    public function recordNow(
        AuditAction $action,
        array $context = [],
        ?Uuid $accountId = null,
        ?Uuid $characterId = null,
    ): void {
        $entry = $this->build($action, $context, $accountId, $characterId);

        try {
            // A dedicated entity manager transaction, so writing a security
            // record neither joins nor disturbs whatever the request was doing.
            $this->entityManager->wrapInTransaction(function () use ($entry): void {
                $this->entityManager->persist($entry);
            });
        } catch (Throwable $e) {
            // Failing to audit must never fail the request that triggered it.
            // A login attempt that cannot be recorded is still a login attempt,
            // and turning it into a 500 would hand an attacker a denial of
            // service. The failure is surfaced through the application log
            // instead, where monitoring can act on it.
            $this->logger->critical('Failed to write audit record', [
                'action' => $action->value,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function build(
        AuditAction $action,
        array $context,
        ?Uuid $accountId,
        ?Uuid $characterId,
    ): AuditEntry {
        return new AuditEntry(
            $this->identifiers->generate(),
            $action,
            $accountId,
            $characterId,
            $context,
            $this->currentIpHash(),
            $this->clock->now(),
        );
    }

    private function currentIpHash(): ?string
    {
        $address = $this->requests->getCurrentRequest()?->getClientIp();

        return $address === null ? null : hash('sha256', $this->hashSalt . $address);
    }
}
