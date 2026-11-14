<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthUser
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    /**
     * Maker-checker: the person who made a request normally may not approve it. A business with a single owner
     * can let its Super Admin approve their own requests (Settings → Security); every such decision stays audited.
     */
    public function maySelfApprove(): bool
    {
        return in_array('super_admin', $this->roles, true) && \App\Services\Settings::get('owner_self_approval') === '1';
    }

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?int $branchId,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly int $tokenId,
        public readonly bool $mfaEnabled = false,
        public readonly bool $mfaSetupRequired = false,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * How much customer data this user may see, widest scope wins:
     * all > branch > assigned (agents) > own (customer portal) > none.
     */
    public function customerScope(): string
    {
        foreach (['all', 'branch', 'assigned', 'own'] as $scope) {
            if ($this->can('customers.scope.' . $scope)) {
                return $scope;
            }
        }
        return 'none';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'branch_id' => $this->branchId,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'customer_scope' => $this->customerScope(),
            'mfa_enabled' => $this->mfaEnabled,
            'mfa_setup_required' => $this->mfaSetupRequired,
        ];
    }
}
