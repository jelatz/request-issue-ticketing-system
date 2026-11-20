<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class InternalSystem extends Model
{
    use HasFactory;

    public const ACTIVE_CACHE_KEY = 'internal_systems.active';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'description',
        'is_active',
    ];

    /**
     * Register model lifecycle hooks for cache invalidation.
     */
    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::forget(self::ACTIVE_CACHE_KEY);
        });

        static::deleted(function (): void {
            Cache::forget(self::ACTIVE_CACHE_KEY);
        });
    }

    /**
     * Return the cached list of active internal systems ordered case-insensitively by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function cachedActiveList(): array
    {
        return Cache::rememberForever(self::ACTIVE_CACHE_KEY, function (): array {
            return self::query()
                ->where('is_active', true)
                ->orderByRaw('LOWER(name) ASC')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (self $system): array => [
                    'id' => (int) $system->id,
                    'name' => (string) $system->name,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'internal_system_id');
    }
}
