<?php

namespace App\Console\Commands;

use App\Models\CodexSceneRun;
use App\Models\User;
use App\Services\CodexScenes\BookPageRecovery;
use App\Services\Orders\OrderAccess;
use App\Support\AdminActivityLogger;
use Illuminate\Console\Command;

class RecoverMissingBookPages extends Command
{
    protected $signature = 'books:recover-missing {run} {--actor=} {--apply}';

    protected $description = 'Review or explicitly request missing coloring pages only; never replay the original request';

    public function handle(BookPageRecovery $recovery): int
    {
        $run = CodexSceneRun::findOrFail($this->argument('run'));
        $actor = User::findOrFail($this->option('actor'));
        abort_unless($run->production_kind === 'coloring_book' && $actor->is_active
            && app(OrderAccess::class)->controls($run->order, $actor)
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
        if ($this->option('apply')) {
            $recovery->advance($run, true, $actor);
            AdminActivityLogger::log('order.book_missing_pages_requested', 'طلب استكمال الصفحات الناقصة فقط بواسطة المالك؛ دون إعادة التوليد الكامل أو إرسال رسائل.', $run->order,
                ['run_id' => $run->id], $actor);
        }
        $meta = $run->fresh()->book_pdf['recovery'] ?? [];
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'read_only', 'run_id' => $run->id,
            'saved' => count($run->scenes ?? []), 'total' => $run->scene_count, 'recovery' => $meta], JSON_UNESCAPED_UNICODE));

        return ($meta['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
