<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Ticket extends Model
{
    use HasFactory;

    public const TYPE_ISSUE = 'issue';

    public const TYPE_ENGINEERING_REQUEST = 'engineering_request';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WAITING_ON_USER = 'waiting_on_user';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const APPROVAL_STATUS_PENDING = 'pending';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const PENDING_APPROVALS_CACHE_KEY = 'tickets.pending_approvals_count';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'type',
        'internal_system_id',
        'requester_id',
        'lead_engineer_id',
        'title',
        'description',
        'priority',
        'status',
        'approval_status',
        'approval_decided_by_id',
        'approval_decided_at',
        'environment',
        'steps_to_reproduce',
        'expected_behavior',
        'actual_behavior',
        'impact_scope',
        'business_justification',
        'proposed_functionality',
        'expected_benefits',
        'requested_deadline',
        'review_decision_notes',
        'target_due_date',
        'first_responded_at',
        'resolved_at',
        'closed_at',
        'resolution_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approval_decided_at' => 'datetime',
            'requested_deadline' => 'date',
            'target_due_date' => 'date',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::forget(self::PENDING_APPROVALS_CACHE_KEY);
        });

        static::deleted(function (): void {
            Cache::forget(self::PENDING_APPROVALS_CACHE_KEY);
        });
    }

    public static function cachedPendingApprovalsCount(): int
    {
        return (int) Cache::rememberForever(self::PENDING_APPROVALS_CACHE_KEY, function (): int {
            return self::query()
                ->where('status', self::STATUS_PENDING_APPROVAL)
                ->count();
        });
    }

    /**
     * @return BelongsTo<InternalSystem, $this>
     */
    public function internalSystem(): BelongsTo
    {
        return $this->belongsTo(InternalSystem::class, 'internal_system_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function leadEngineer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_engineer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvalDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_decided_by_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_collaborators', 'ticket_id', 'user_id')
            ->withPivot('added_by_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TicketCollaborator, $this>
     */
    public function collaboratorEntries(): HasMany
    {
        return $this->hasMany(TicketCollaborator::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketSubtask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(TicketSubtask::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'ticket_id');
    }

    /**
     * @return HasMany<UserNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'ticket_id');
    }

    /**
     * Determine whether the given user is assigned as lead or collaborator on this ticket.
     */
    public function isAssignedTo(User $user): bool
    {
        if ((int) $this->lead_engineer_id === (int) $user->id) {
            return true;
        }

        if ($this->relationLoaded('collaborators')) {
            return $this->collaborators->contains('id', $user->id);
        }

        return $this->collaborators()->where('users.id', $user->id)->exists();
    }

    /**
     * Determine whether the given staff user can work on this ticket.
     */
    public function canBeWorkedOnBy(User $user): bool
    {
        return $user->isSuperAdmin() || ($user->isEngineer() && $this->isAssignedTo($user));
    }

    public function isEngineeringRequest(): bool
    {
        return $this->type === self::TYPE_ENGINEERING_REQUEST;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * Scope tickets visible to the given user based on their role.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $directColumn = $this->qualifyColumn($user->isEngineer() ? 'lead_engineer_id' : 'requester_id');

        return $query->where(function (Builder $subQuery) use ($user, $directColumn): void {
            $subQuery->where($directColumn, $user->id)
                ->orWhereHas('collaborators', function (Builder $collabQuery) use ($user): void {
                    $collabQuery->where('users.id', $user->id);
                })
                ->when($user->isDirector(), function (Builder $directorQuery): void {
                    $directorQuery->orWhere($this->qualifyColumn('type'), self::TYPE_ENGINEERING_REQUEST);
                });
        });
    }
}
