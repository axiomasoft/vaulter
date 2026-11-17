<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * What the caller may do on a node. `?explain=<ability>` adds why that ability is
 * allowed or not; explaining reveals the grants behind it, so it needs Manage
 * (403 permission_denied otherwise).
 */
#[Internal]
final readonly class ShowAbilitiesController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        Validator::make($request->query(), ['explain' => ['sometimes', 'string', Rule::in(array_map(static fn (Ability $ability): string => $ability->value, Ability::cases()))]])->validate();
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $abilities = $drive->nodes()->abilities($node);
        $explain = null;
        $ability = $request->query('explain');
        if (is_string($ability)) {
            $explanation = $drive->access()->explain($node, Ability::from($ability));
            $explain = [
                'ability' => $explanation->ability->value,
                'allowed' => $explanation->allowed,
                'reasons' => [$explanation->reason, ...$explanation->restrictions],
            ];
        }

        return new JsonResponse(['data' => ['abilities' => $abilities->toArray(), 'explain' => $explain]]);
    }
}
