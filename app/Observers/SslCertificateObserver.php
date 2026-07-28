<?php

namespace App\Observers;

use App\Events\SslCertificateUpdated;
use App\Models\SslCertificate;

class SslCertificateObserver
{
    public function created(SslCertificate $cert): void
    {
        SslCertificateUpdated::dispatch($cert, SslCertificateUpdated::ACTION_ISSUED);
    }

    public function updated(SslCertificate $cert): void
    {
        if ($cert->isDirty('status')) {
            $action = match ($cert->status) {
                SslCertificate::STATUS_ACTIVE,
                SslCertificate::STATUS_EXPIRING => SslCertificateUpdated::ACTION_RENEWED,
                SslCertificate::STATUS_ERROR    => SslCertificateUpdated::ACTION_RENEWED,
                default                         => SslCertificateUpdated::ACTION_RENEWED,
            };
            SslCertificateUpdated::dispatch($cert, $action);
        }
    }
}
