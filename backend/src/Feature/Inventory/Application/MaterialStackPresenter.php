<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Application;

use App\Feature\Inventory\Domain\Entity\MaterialStack;
use App\Feature\Inventory\Domain\Model\MaterialDefinition;
use App\Feature\Inventory\Domain\Repository\MaterialRepository;

/**
 * Presents a character's material stash.
 *
 * The single rendering of a material balance, shared by every feature that
 * shows one — the Holding stash and the Inventory's refinement materials
 * today. One place means a retired material definition degrades the same way
 * everywhere: the balance still renders, with the id standing in for the name,
 * rather than disappearing because a designer retired the material.
 */
final class MaterialStackPresenter
{
    public function __construct(private readonly MaterialRepository $materials)
    {
    }

    /**
     * @param list<MaterialStack> $stacks
     *
     * @return list<array<string, mixed>>
     */
    public function collection(array $stacks): array
    {
        $definitions = $this->materials->all();

        return array_map(
            fn (MaterialStack $stack): array => $this->one($stack, $definitions),
            $stacks,
        );
    }

    /**
     * @param array<string, MaterialDefinition> $definitions
     *
     * @return array<string, mixed>
     */
    private function one(MaterialStack $stack, array $definitions): array
    {
        // A stack whose definition has been withdrawn from content still
        // renders, with the id standing in for the name. The balance is the
        // player's; it does not disappear because a designer retired the
        // material.
        if (!isset($definitions[$stack->materialId()])) {
            return [
                'material_id' => $stack->materialId(),
                'localisation_key' => $stack->materialId(),
                'tier' => 0,
                'icon' => 'material_unknown',
                'quantity' => $stack->quantity(),
            ];
        }

        $definition = $definitions[$stack->materialId()];

        return [
            'material_id' => $stack->materialId(),
            'localisation_key' => $definition->localisationKey,
            'tier' => $definition->tier,
            'icon' => $definition->icon,
            'quantity' => $stack->quantity(),
        ];
    }
}
