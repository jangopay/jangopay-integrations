<?php

namespace Azuriom\Plugin\JangoPay\Commands;

use Azuriom\Plugin\JangoPay\Models\Attempt;
use Azuriom\Plugin\Shop\Models\Gateway;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePayments extends Command
{
    protected $signature = 'jangopay:reconcile {--limit=20 : Max attempts to query in one invocation}';
    protected $description = 'Reconcile pending JangoPay invoices using read-only merchant queries';

    public function handle(): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        // Fair rotation: NULL (never checked) first, then oldest last_checked_at.
        // This avoids permanent starvation when old pending invoices stay pending.
        $attempts = Attempt::whereIn('state', ['quoted', 'awaiting_payment', 'verified', 'quote_unknown'])
            ->whereNotNull('invoice_id')
            ->orderByRaw('CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_checked_at')->orderBy('id')->limit($limit)->get();
        foreach ($attempts as $attempt) {
            try {
                // Stamp before remote I/O. Failures also yield their slot to later orders.
                Attempt::whereKey($attempt->id)->update(['last_checked_at' => now()]);
                $gateway = Gateway::find($attempt->gateway_id);
                if ($gateway?->type !== 'jangopay') continue;
                $status = $gateway->paymentMethod()->reconcile($attempt);
                $this->line('Attempt #'.$attempt->id.': '.$status);
            } catch (Throwable $e) {
                $this->warn('Attempt #'.$attempt->id.': upstream or reconciliation error (details redacted)');
            }
        }
        return self::SUCCESS;
    }
}
