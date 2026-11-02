<?php

namespace App\Policies;

use App\Models\Scan;
use App\Models\User;

/** Ownership rules (IDOR protection). Admins manage platform data but do not read farmers' private photos. */
class ScanPolicy
{
    public function view(User $user, Scan $scan): bool
    {
        return $scan->user_id === $user->id;
    }

    public function delete(User $user, Scan $scan): bool
    {
        return $scan->user_id === $user->id;
    }
}
