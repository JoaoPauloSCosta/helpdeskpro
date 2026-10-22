<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Registros administráveis com eventos em `audit_logs` (RF-19). */
trait HasAuditLogs
{
    /** Eventos administrativos sobre este registro. */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
