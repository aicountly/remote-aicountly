<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Verified company/product context handed over by another AICOUNTLY product
 * when it launches Remote (§6C).
 *
 * An instance of this class only ever exists because
 * {@see SourceContextVerifier} checked a signature, an issuer, an audience, an
 * expiry and a one-time `jti`. A raw `?company_id=481` never produces one.
 */
final class SourceContext
{
    public function __construct(
        public readonly string $subjectUuid,
        public readonly ?int $companyId,
        public readonly ?int $branchId,
        public readonly ?int $financialYearId,
        public readonly string $product,
        public readonly ?string $route,
        public readonly ?string $supportTicketId,
        public readonly ?string $sourceAgent,
        public readonly ?string $sourceConversationId,
        public readonly ?string $issueSummary,
        public readonly ?string $sourceReference,
        public readonly string $jti,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'companyId'       => $this->companyId,
            'branchId'        => $this->branchId,
            'financialYearId' => $this->financialYearId,
            'product'         => $this->product,
            'route'           => $this->route,
            'supportTicketId' => $this->supportTicketId,
            'sourceAgent'     => $this->sourceAgent,
            'issueSummary'    => $this->issueSummary,
        ];
    }
}
