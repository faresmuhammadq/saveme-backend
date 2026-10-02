<?php

namespace App\Modules\Wallets\Infrastructure;

use App\Modules\Wallets\Domain\Categories\Ports\CategoryReferenceRepository;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence as UserScopeSequencePort;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentCategoryReferenceRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentCategoryRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentMembershipRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentWalletRepository;
use App\Modules\Wallets\Infrastructure\Sync\UserScopeSequence;
use App\Modules\Wallets\Infrastructure\Sync\WalletScopeSequence;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Wallets' Domain ports to their persistence (P-02). Migrations and
 * factories are found by ModuleServiceProvider.
 */
class WalletsPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        WalletRepository::class => EloquentWalletRepository::class,
        MembershipRepository::class => EloquentMembershipRepository::class,
        ScopeSequence::class => WalletScopeSequence::class,
        CategoryRepository::class => EloquentCategoryRepository::class,
        CategoryReferenceRepository::class => EloquentCategoryReferenceRepository::class,
        UserScopeSequencePort::class => UserScopeSequence::class,
    ];
}
