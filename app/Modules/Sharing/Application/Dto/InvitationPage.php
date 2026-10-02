<?php

namespace App\Modules\Sharing\Application\Dto;

/**
 * One page of pending invitations and the cursor position after it.
 */
final readonly class InvitationPage
{
    /**
     * @param  list<InvitationView>  $items
     * @param  array{value: string, id: string}|null  $next
     */
    public function __construct(
        public array $items,
        public ?array $next,
    ) {}
}
