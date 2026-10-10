<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcileOrderPayments extends Command
{
    protected $signature = 'payments:reconcile {--json : Aggregate output without customer data} {--limit=20 : Largest differences, maximum 100}';

    protected $description = 'Read-only reconciliation of legacy balances, payment movements and current checkout balances';

    public function handle(PaymentReconciliationService $payments): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 0 || $limit > 100) {
            $this->error('Limit must be an integer between 0 and 100.');

            return self::INVALID;
        }
        $r = $payments->report();
        $output = [];
        foreach (['opening_cents', 'recorded_net_cents', 'opening_plus_net_cents', 'non_cash_adjustments_cents',
            'merge_transfers_cents', 'merged_source_copies_cents', 'expected_balance_cents', 'current_balance_cents', 'unreconciled_cents'] as $key) {
            $output[substr($key, 0, -6)] = round($r[$key] / 100, 2);
        }
        $output['historical_recovered_net'] = $r['history']['recovered_net_cents'] / 100;
        $output['opening_snapshot'] = $r['opening_snapshot_cents'] / 100;
        $output['historical_baseline_correction'] = $r['historical_baseline_correction_cents'] / 100;
        $output['historical_baseline_corrections'] = $r['baseline_corrections']->map(fn (array $row): array => [
            'reference' => $row['reference'], 'order_id' => $row['order_id'],
            'baseline_event_id' => $row['baseline_event_id'], 'discarded_order_id' => $row['discarded_order_id'],
            'snapshot_balance' => $row['snapshot_cents'] / 100, 'corrected_balance' => $row['corrected_cents'] / 100,
            'correction' => $row['delta_cents'] / 100, 'last_payment_log_id' => $row['last_payment_log_id'],
            'last_payment_log_at' => $row['last_payment_log_at'],
            'proof' => $row['proof'], 'post_baseline_anchor_event_id' => $row['post_baseline_anchor_event_id'],
        ])->all();
        $output['historical_undated_balance'] = $r['history']['undated_cents'] / 100;
        $output['historical_problem_groups'] = $r['history']['issues']->count();
        $output['different_groups'] = $r['differences']->count();
        $output['largest_differences'] = $r['differences']->take($limit)->map(fn (array $row): array => [
            'order_id' => $row['order_id'], 'reference' => $row['reference'], 'missing_order' => $row['missing_order'],
            'current_balance' => $row['current_cents'] / 100, 'expected_balance' => $row['expected_cents'] / 100,
            'difference' => $row['difference_cents'] / 100,
        ])->all();
        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->info('Read only. No orders, payments or activity logs were changed. Amounts in EGP.');
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
