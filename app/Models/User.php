<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_ENGINEER = 'engineer';

    public const ROLE_USER = 'user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_director',
        'department',
        'job_title',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_director' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function isEngineer(): bool
    {
        return $this->role === self::ROLE_ENGINEER;
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_SUPERADMIN, self::ROLE_ENGINEER], true);
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isDirector(): bool
    {
        return (bool) $this->is_director
            && in_array($this->role, [self::ROLE_SUPERADMIN, self::ROLE_USER], true);
    }

    public function canApproveEngineeringRequests(): bool
    {
        return $this->isDirector() || $this->isSuperAdmin();
    }

    /**
     * Tickets submitted by this user.
     *
     * @return HasMany<Ticket, $this>
     */
    public function submittedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'requester_id');
    }

    /**
     * Tickets where this user is assigned as lead engineer.
     *
     * @return HasMany<Ticket, $this>
     */
    public function ledTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'lead_engineer_id');
    }

    /**
     * Tickets where this user is added as a collaborating engineer.
     *
     * @return BelongsToMany<Ticket, $this>
     */
    public function collaboratedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_collaborators', 'user_id', 'ticket_id')
            ->withPivot('added_by_id')
            ->withTimestamps();
    }

    /**
     * Subtasks assigned to this engineer.
     *
     * @return HasMany<TicketSubtask, $this>
     */
    public function assignedSubtasks(): HasMany
    {
        return $this->hasMany(TicketSubtask::class, 'assigned_engineer_id');
    }

    /**
     * In-app notifications belonging to this user.
     *
     * @return HasMany<UserNotification, $this>
     */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'user_id');
    }
}
