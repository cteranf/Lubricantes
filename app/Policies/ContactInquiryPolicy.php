<?php

namespace App\Policies;

use App\Models\ContactInquiry;
use App\Models\User;

class ContactInquiryPolicy
{
    public function viewAny(User $user): bool { return $user->isAdmin(); }
    public function view(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
    public function update(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
    public function assign(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
    public function addNote(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
    public function archive(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
    public function restore(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
    public function externalAction(User $user, ContactInquiry $inquiry): bool { return $user->isAdmin(); }
}
