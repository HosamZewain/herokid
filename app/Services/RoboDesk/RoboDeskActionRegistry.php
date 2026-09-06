<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskActionSetting;
use App\Models\User;
use App\Services\RoboDesk\Actions\ConfirmIdentityAction;
use App\Services\RoboDesk\Actions\ConfirmItemAction;
use App\Services\RoboDesk\Actions\ConfirmOrderAction;
use App\Services\RoboDesk\Actions\ReceivePaymentAction;
use App\Services\RoboDesk\Actions\RequestCsatAction;
use App\Services\RoboDesk\Actions\RoboDeskAction;
use Illuminate\Support\Collection;

/**
 * The catalogue of configurable RoboDesk actions. The admin screen renders
 * itself from this list plus each action's param schema, so adding an action
 * later needs one class here and no new UI.
 */
class RoboDeskActionRegistry
{
    private const ACTIONS = [
        ConfirmOrderAction::class,
        ConfirmIdentityAction::class,
        ConfirmItemAction::class,
        ReceivePaymentAction::class,
        RequestCsatAction::class,
    ];

    /** @return Collection<string, RoboDeskAction> */
    public function all(): Collection
    {
        return collect(self::ACTIONS)
            ->map(fn (string $class): RoboDeskAction => app($class))
            ->keyBy(fn (RoboDeskAction $action): string => $action->key());
    }

    public function keys(): array
    {
        return $this->all()->keys()->all();
    }

    public function find(string $key): ?RoboDeskAction
    {
        return $this->all()->get($key);
    }

    public function get(string $key): RoboDeskAction
    {
        $action = $this->find($key);

        abort_if($action === null, 404, 'Unknown RoboDesk action.');

        return $action;
    }

    public function enabled(string $key): bool
    {
        return $this->find($key)?->enabled() ?? false;
    }

    public function save(string $key, bool $isEnabled, array $params, ?User $user = null): RoboDeskActionSetting
    {
        $action = $this->get($key);
        $allowed = array_keys($action->defaults());

        return RoboDeskActionSetting::query()->updateOrCreate(
            ['action_key' => $key],
            [
                'is_enabled' => $isEnabled,
                'params' => array_intersect_key($params, array_flip($allowed)),
                'updated_by_user_id' => $user?->id,
            ],
        );
    }

    /** @return Collection<string, RoboDeskActionSetting> */
    public function settings(): Collection
    {
        return RoboDeskActionSetting::query()
            ->whereIn('action_key', $this->keys())
            ->get()
            ->keyBy('action_key');
    }
}
