<?php

declare(strict_types=1);

namespace App\Feature\Holding\Application;

use App\Feature\Inventory\Domain\Repository\MaterialRepository;

/**
 * The base hourly rates the Holding produces at, read from the material
 * catalogue.
 *
 * The Holding aggregate takes rates as a plain map rather than reaching into
 * Inventory's content repository itself. That is what keeps the accrual maths
 * testable with three lines of setup and no content library — and it is also
 * the boundary docs/architecture.md section 3.1 draws: the Holding consumes
 * Inventory's published read model, it does not call into its services.
 */
final class ProductionRates
{
    public function __construct(private readonly MaterialRepository $materials)
    {
    }

    /**
     * @return array<string, int> Base units per hour, keyed by material id.
     *                            Drop-only materials are absent, so a slot
     *                            somehow assigned to one produces nothing.
     */
    public function perHour(): array
    {
        $rates = [];

        foreach ($this->materials->all() as $material) {
            if ($material->isProducible()) {
                $rates[$material->id] = (int) $material->ratePerHour;
            }
        }

        return $rates;
    }
}
