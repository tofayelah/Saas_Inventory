<?php

namespace App\Traits;

use App\Models\Team;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

trait HasTenantApiTokens
{
    /**
     * Create a new tenant-bound personal access token for the user.
     *
     * @param  int  $teamId
     * @param  string  $name
     * @param  array  $abilities
     * @param  \DateTimeInterface|null  $expiresAt
     * @return \Laravel\Sanctum\NewAccessToken
     *
     * @throws \InvalidArgumentException
     */
    public function createTenantToken(int $teamId, string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $team = Team::withoutGlobalScopes()->find($teamId);

        if (!$team) {
            throw new InvalidArgumentException("Tenant [ID: {$teamId}] does not exist.");
        }

        if ($team->isSuspended()) {
            throw new InvalidArgumentException("Tenant [{$team->name}] is currently suspended.");
        }

        if (!$this->isSuperAdmin() && !$this->belongsToTeam($team)) {
            throw new InvalidArgumentException("User does not have membership in tenant [{$team->name}].");
        }

        $plainTextToken = sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $tokenEntropy = Str::random(40),
            hash('crc32b', $tokenEntropy)
        );

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'team_id' => $team->id,
        ]);

        return new NewAccessToken($token, $token->getKey() . '|' . $plainTextToken);
    }
}
