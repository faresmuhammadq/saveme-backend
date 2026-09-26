<?php

namespace App\Modules\Admin\Domain\Notifications;

/**
 * One operator notification, ready to render: identifiers and scalars only
 * (P-18 rules 5 and 8). Text comes from the translation keys.
 */
final readonly class OperatorNotificationView
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $subjectUserId,
        public string $occurredAt,
        public string $titleKey,
        public string $bodyKey,
        public array $params = [],
    ) {}

    /**
     * @return array{id: string, kind: string, subject_user_id: string, occurred_at: string, title_key: string, body_key: string, params: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'subject_user_id' => $this->subjectUserId,
            'occurred_at' => $this->occurredAt,
            'title_key' => $this->titleKey,
            'body_key' => $this->bodyKey,
            'params' => $this->params,
        ];
    }
}
