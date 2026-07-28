<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\SslCertificate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * CertbotService - wraps the `certbot` CLI to issue / renew Let's Encrypt certs.
 *
 * In production this is invoked on the master (where DNS-01 / HTTP-01 challenges
 * are reachable) or via the panel API when running on edge servers.
 *
 * Staging flag is enabled by default during development to avoid rate limits.
 */
class CertbotService
{
    public function __construct(
        protected string $email,
        protected bool $staging = false,
        protected string $webroot = '/var/www/letsencrypt',
    ) {}

    public static function make(): self
    {
        return new self(
            email:  (string) config('xerex.certbot.email'),
            staging: (bool) config('xerex.certbot.staging', false),
            webroot: (string) config('xerex.certbot.webroot', '/var/www/letsencrypt'),
        );
    }

    /**
     * Issue a new Let's Encrypt certificate for the given domain.
     * Optionally include SANs for wildcard subdomains.
     */
    public function issue(Domain $domain, bool $wildcard = false): SslCertificate
    {
        $domains = $wildcard ? [$domain->domain, "*." . $domain->domain] : [$domain->domain];

        $stagingFlag = $this->staging ? '--staging' : '';
        $args = [
            'certbot', 'certonly',
            '--non-interactive', '--agree-tos',
            '-m', $this->email,
            '--webroot', '-w', $this->webroot,
            $stagingFlag,
        ];
        foreach ($domains as $d) {
            $args[] = '-d';
            $args[] = $d;
        }

        Log::info("Issuing SSL cert for {$domain->domain}", ['args' => $args]);

        $cert = SslCertificate::create([
            'domain_id'   => $domain->id,
            'common_name' => $domain->domain,
            'subject_alt_names' => $wildcard ? ["*." . $domain->domain] : null,
            'provider'    => 'letsencrypt',
            'status'      => SslCertificate::STATUS_PROVISIONING,
        ]);

        try {
            $process = new Process($args);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $info = $this->inspectCertificate($domain->domain);

            $cert->update([
                'status'        => SslCertificate::STATUS_ACTIVE,
                'issuer'        => "Let's Encrypt",
                'cert_path'     => "/etc/letsencrypt/live/{$domain->domain}/fullchain.pem",
                'key_path'      => "/etc/letsencrypt/live/{$domain->domain}/privkey.pem",
                'chain_path'    => "/etc/letsencrypt/live/{$domain->domain}/chain.pem",
                'serial_number' => $info['serial'] ?? null,
                'fingerprint_sha256' => $info['fingerprint_sha256'] ?? null,
                'issued_at'     => $info['issued_at'] ?? now(),
                'expires_at'    => $info['expires_at'] ?? now()->addDays(90),
            ]);

            $domain->update([
                'ssl_status'      => SslCertificate::STATUS_ACTIVE,
                'ssl_provider'    => 'letsencrypt',
                'ssl_issued_at'   => $cert->issued_at,
                'ssl_expires_at'  => $cert->expires_at,
                'ssl_fingerprint' => $cert->fingerprint_sha256,
            ]);

            return $cert->fresh();
        } catch (\Throwable $e) {
            $cert->update([
                'status' => SslCertificate::STATUS_ERROR,
                'error'  => $e->getMessage(),
            ]);
            $domain->update(['ssl_status' => SslCertificate::STATUS_ERROR]);
            throw $e;
        }
    }

    /**
     * Renew an existing certificate via `certbot renew`.
     */
    public function renew(SslCertificate $cert): SslCertificate
    {
        $args = ['certbot', 'renew', '--non-interactive', '--cert-name', $cert->common_name];
        if ($this->staging) {
            $args[] = '--staging';
        }

        Log::info("Renewing SSL cert for {$cert->common_name}");

        try {
            $process = new Process($args);
            $process->setTimeout(180);
            $process->run();

            $cert->update([
                'last_renewal_attempt_at' => now(),
                'renewal_failures'        => $process->isSuccessful() ? 0 : $cert->renewal_failures + 1,
            ]);

            if ($process->isSuccessful()) {
                $info = $this->inspectCertificate($cert->common_name);
                $cert->update([
                    'status'        => SslCertificate::STATUS_ACTIVE,
                    'issued_at'     => $info['issued_at'] ?? now(),
                    'expires_at'    => $info['expires_at'] ?? now()->addDays(90),
                    'fingerprint_sha256' => $info['fingerprint_sha256'] ?? $cert->fingerprint_sha256,
                    'error'         => null,
                ]);
            } else {
                $cert->update(['error' => $process->getOutput() . $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $cert->update([
                'renewal_failures'        => $cert->renewal_failures + 1,
                'last_renewal_attempt_at' => now(),
                'error'                   => $e->getMessage(),
            ]);
            Log::error("Cert renewal failed for {$cert->common_name}: " . $e->getMessage());
        }

        return $cert->fresh();
    }

    public function revoke(SslCertificate $cert): void
    {
        $process = new Process(['certbot', 'revoke', '--cert-name', $cert->common_name, '--non-interactive']);
        $process->setTimeout(60);
        $process->run();

        $cert->update(['status' => SslCertificate::STATUS_EXPIRED]);
    }

    /**
     * Read the issued cert from the filesystem and parse its metadata.
     */
    protected function inspectCertificate(string $commonName): array
    {
        $certPath = "/etc/letsencrypt/live/{$commonName}/fullchain.pem";

        if (! is_readable($certPath)) {
            return [];
        }

        $certContent = file_get_contents($certPath);
        $parsed = openssl_x509_parse($certContent);

        if (! $parsed) {
            return [];
        }

        $fingerprint = openssl_x509_fingerprint($certContent, 'sha256');

        return [
            'issuer'             => $parsed['issuer']['CN'] ?? 'Unknown',
            'serial'             => $parsed['serialNumber'] ?? null,
            'fingerprint_sha256' => $fingerprint ? bin2hex($fingerprint) : null,
            'issued_at'          => isset($parsed['validFrom_time_t']) ? \Carbon\Carbon::createFromTimestamp($parsed['validFrom_time_t']) : null,
            'expires_at'         => isset($parsed['validTo_time_t']) ? \Carbon\Carbon::createFromTimestamp($parsed['validTo_time_t']) : null,
        ];
    }
}
