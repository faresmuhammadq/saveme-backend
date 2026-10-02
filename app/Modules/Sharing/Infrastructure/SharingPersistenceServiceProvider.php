<?php

namespace App\Modules\Sharing\Infrastructure;

use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Sharing\Infrastructure\Repositories\EloquentInvitationRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Sharing's Domain ports to their persistence (P-02). Migrations and
 * factories are found by ModuleServiceProvider.
 */
class SharingPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        InvitationRepository::class => EloquentInvitationRepository::class,
    ];
}
