<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function log(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $changes = [],
        ?Request $request = null,
    ): void {
        $user = Auth::guard('admin')->user();

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => $this->redact($changes),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function redact(array $changes): array
    {
        $blocked = ['password', 'password_confirmation', 'current_password', 'token', 'secret', 'code'];

        foreach ($changes as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $changes[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $changes[$key] = $this->redact($value);
            }
        }

        return $changes;
    }
}
