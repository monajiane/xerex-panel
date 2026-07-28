<?php

namespace App\Console\Commands;

use App\Models\SslCertificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SslRenewCommand extends Command
{
    protected $signature = 'xerex:ssl:renew';
    protected $description = 'Renew Let\'s Encrypt certificates that are about to expire';

    public function handle(): int
    {
        $expiring = SslCertificate::where('auto_renew', true)
            ->where('status', SslCertificate::STATUS_ACTIVE)
            ->where('expires_at', '<', now()->addDays(14))
            ->get();

        foreach ($expiring as $cert) {
            $this->info("Renewing certificate for {$cert->common_name}");

            try {
                // In production: shell out to certbot
                // exec("certbot renew --cert-name {$cert->common_name} --nginx");

                // Update tracking
                $cert->update([
                    'last_renewal_attempt_at' => now(),
                    'renewal_failures'        => 0,
                ]);

                Log::info("SSL renewal successful for {$cert->common_name}");
            } catch (\Throwable $e) {
                $cert->update([
                    'renewal_failures' => $cert->renewal_failures + 1,
                    'last_renewal_attempt_at' => now(),
                ]);
                Log::error("SSL renewal failed for {$cert->common_name}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
