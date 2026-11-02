<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CrmAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/** Works out who should hear about something, and tells them. */
class Notify
{
    /**
     * @param  iterable<User>|User|null  $to
     */
    public function send(iterable|User|null $to, string $type, string $title, string $body, string $link, bool $email = false, ?User $actor = null): void
    {
        $users = collect($to instanceof User ? [$to] : ($to ?? []))
            ->filter(fn ($u) => $u && $u->status === User::STATUS_ACTIVE && $u->id !== $actor?->id)
            ->unique('id');
        if ($users->isNotEmpty()) {
            Notification::send($users, new CrmAlert($type, $title, $body, $link, $email));
        }
    }

    /** Active staff who hold a permission (admins always). */
    public function staffWith(string $permission): Collection
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->where('status', User::STATUS_ACTIVE)->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))->values();
    }

    public function admins(): Collection
    {
        return User::where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->get();
    }

    /** The portal logins of a client company. */
    public function clientUsers(?int $clientId): Collection
    {
        return $clientId ? User::where('role', User::ROLE_CLIENT)->where('client_id', $clientId)->where('status', User::STATUS_ACTIVE)->get() : collect();
    }
}
