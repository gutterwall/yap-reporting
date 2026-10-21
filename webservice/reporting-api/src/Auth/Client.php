<?php

declare(strict_types=1);

namespace YapReporting\Auth;

/**
 * An API client (one per WordPress site, typically). Holds its authorization scope.
 * Token verification material is held only by ClientStore, never in this object.
 */
final class Client
{
    public const SCOPE_SUMMARY = 'summary';
    public const SCOPE_CALL_DETAILS = 'call_details';
    public const SCOPE_VOLUNTEER_STATS = 'volunteer_stats';
    public const SCOPE_VOLUNTEER_NAMES = 'volunteer_names';

    public const ALL_SCOPES = [
        self::SCOPE_SUMMARY,
        self::SCOPE_CALL_DETAILS,
        self::SCOPE_VOLUNTEER_STATS,
        self::SCOPE_VOLUNTEER_NAMES,
    ];

    /**
     * @param int[] $serviceBodyIds
     * @param string[] $scopes
     * @param string[] $ipAllowlist
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $allServiceBodies,
        public readonly array $serviceBodyIds,
        public readonly bool $allowUnassigned,
        public readonly array $scopes,
        public readonly array $ipAllowlist,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function canAccessServiceBody(int $id): bool
    {
        return $this->allServiceBodies || in_array($id, $this->serviceBodyIds, true);
    }

    /** Stable fingerprint of the authorization scope, used in cache keys. */
    public function scopeFingerprint(): string
    {
        $ids = $this->serviceBodyIds;
        sort($ids);
        $scopes = $this->scopes;
        sort($scopes);
        return hash('sha256', json_encode([$this->id, $this->allServiceBodies, $ids, $this->allowUnassigned, $scopes]) ?: '');
    }

    /** @return array<string, mixed> Safe description (no secrets). */
    public function describe(): array
    {
        return [
            'client_id' => $this->id,
            'name' => $this->name,
            'all_service_bodies' => $this->allServiceBodies,
            'service_body_ids' => $this->serviceBodyIds,
            'allow_unassigned' => $this->allowUnassigned,
            'scopes' => $this->scopes,
            'ip_allowlist_enabled' => $this->ipAllowlist !== [],
        ];
    }
}
