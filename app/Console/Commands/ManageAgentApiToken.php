<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AgentApi\AgentCatalogScope;
use App\Services\AgentApi\AgentTokenService;
use Illuminate\Console\Command;

class ManageAgentApiToken extends Command
{
    protected $signature = 'agent:token
        {action : issue or revoke}
        {email : Email of the dedicated Agent admin account}
        {--name=production-agent : Token name}
        {--expires=90 : Expiry in days for newly issued tokens}
        {--scope=all : Catalog scope: all, stories, or products}
        {--rework : Allow selecting and correcting existing checkouts}
        {--identity-only : Restrict the token to the story identity queue only}';

    protected $description = 'Issue or revoke a scoped HeroKid Agent API token';

    public function handle(AgentTokenService $tokens): int
    {
        $action = strtolower((string) $this->argument('action'));
        if (! in_array($action, ['issue', 'revoke'], true)) {
            $this->error('Action must be issue or revoke.');

            return self::INVALID;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user || $user->role !== 'admin' || ! $user->is_active) {
            $this->error('Use a separate, active Admin account for this Agent.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name')) ?: 'production-agent';
        if ($action === 'revoke') {
            $tokensToRevoke = $user->tokens()->where('name', $name)->get()
                ->filter(fn ($token): bool => in_array('agent', $token->abilities ?? [], true));
            $count = $tokensToRevoke->count();
            $tokensToRevoke->each->delete();
            $this->info("Revoked {$count} token(s). The Agent account remains enabled.");

            return self::SUCCESS;
        }

        $days = (int) $this->option('expires');
        if ($days < 1 || $days > 365) {
            $this->error('--expires must be between 1 and 365 days.');

            return self::INVALID;
        }

        $scope = strtolower(trim((string) $this->option('scope')));
        if (! in_array($scope, [AgentCatalogScope::ALL, AgentCatalogScope::STORIES, AgentCatalogScope::PRODUCTS], true)) {
            $this->error('--scope must be all, stories, or products.');

            return self::INVALID;
        }

        $identityOnly = (bool) $this->option('identity-only');
        if ($identityOnly) {
            $scope = AgentCatalogScope::STORIES;
        }

        $token = $tokens->issue($user, $name, $days, $scope, (bool) $this->option('rework'), $identityOnly);

        $this->warn('Copy this token now. It will not be shown again:');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->info('Expires: '.$token->accessToken->expires_at?->toIso8601String());
        $this->info('Catalog scope: '.AgentCatalogScope::label($scope));
        $this->info('Existing-order rework: '.($this->option('rework') ? 'enabled' : 'disabled'));
        $this->info('Story identity only: '.($identityOnly ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }
}
