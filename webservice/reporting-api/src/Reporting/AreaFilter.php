<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use YapReporting\Auth\Client;
use YapReporting\Http\HttpException;

/**
 * The set of service bodies a request may see. Built ONLY from the authenticated client's
 * scope intersected with what was requested — a client-supplied id outside the scope is refused.
 */
final class AreaFilter
{
    /** @param int[]|null $ids null = every service body (only for all-access clients) */
    public function __construct(public readonly ?array $ids, public readonly bool $includeUnassigned)
    {
    }

    /** @param int[]|null $requested */
    public static function forClient(Client $client, ?array $requested, ?bool $includeUnassigned): self
    {
        if ($includeUnassigned === true && !$client->allServiceBodies && !$client->allowUnassigned) {
            throw new HttpException(403, 'area_not_authorized', 'This client may not view unassigned activity.');
        }
        if ($requested !== null) {
            foreach ($requested as $id) {
                if (!$client->canAccessServiceBody($id)) {
                    throw new HttpException(403, 'area_not_authorized', 'One or more requested service bodies are not authorized for this client.');
                }
            }
            return new self($requested, (bool) $includeUnassigned);
        }
        if ($client->allServiceBodies) {
            return new self(null, $includeUnassigned ?? true);
        }
        if ($client->serviceBodyIds === []) {
            throw new HttpException(403, 'area_not_authorized', 'This client has no authorized service bodies.');
        }
        return new self($client->serviceBodyIds, $includeUnassigned ?? false);
    }

    public function allows(?int $serviceBodyId): bool
    {
        if ($serviceBodyId === null || $serviceBodyId <= 0) {
            return $this->includeUnassigned;
        }
        return $this->ids === null || in_array($serviceBodyId, $this->ids, true);
    }

    public function key(): string
    {
        $ids = $this->ids;
        if ($ids !== null) {
            sort($ids);
        }
        return json_encode([$ids, $this->includeUnassigned]) ?: '';
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['service_body_ids' => $this->ids ?? 'all', 'include_unassigned' => $this->includeUnassigned];
    }
}
