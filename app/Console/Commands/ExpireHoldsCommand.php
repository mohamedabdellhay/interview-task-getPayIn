<?php

namespace App\Console\Commands;

use App\Models\Hold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireHoldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'holds:expire';

    /**
     * The console command description.
     */
    protected $description = 'Expire holds that have passed their expiry time and release stock';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting hold expiry process...');

        try {
            // Search for expired holds
            $expiredHolds = Hold::where('status', 'active')
                ->where('expires_at', '<=', now())
                ->get();

            if ($expiredHolds->isEmpty()) {
                $this->info('No expired holds found.');
                return Command::SUCCESS;
            }

            $expiredCount = 0;
            $errors = 0;

            // Process each expired hold
            foreach ($expiredHolds as $hold) {
                try {
                    DB::transaction(function () use ($hold) {
                        // Lock the hold to ensure it is not processed twice
                        $lockedHold = Hold::lockForUpdate()->find($hold->id);

                        // Check the status again (it might have changed)
                        if ($lockedHold && $lockedHold->status === 'active') {
                            $lockedHold->markAsExpired();

                            Log::info('Hold expired successfully', [
                                'hold_id' => $lockedHold->id,
                                'product_id' => $lockedHold->product_id,
                                'quantity' => $lockedHold->quantity,
                                'expired_at' => now(),
                            ]);
                        }
                    });

                    $expiredCount++;
                    $this->info("Expired hold #{$hold->id}");
                } catch (\Exception $e) {
                    $errors++;
                    $this->error("Failed to expire hold #{$hold->id}: {$e->getMessage()}");

                    Log::error('Hold expiry failed', [
                        'hold_id' => $hold->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Summary
            $this->info("─────────────────────────────────");
            $this->info("✓ Expired: {$expiredCount} holds");
            if ($errors > 0) {
                $this->warn("✗ Errors: {$errors} holds");
            }
            $this->info("─────────────────────────────────");

            Log::info('Hold expiry process completed', [
                'expired_count' => $expiredCount,
                'errors' => $errors,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Critical error during hold expiry: {$e->getMessage()}");

            Log::error('Hold expiry process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
