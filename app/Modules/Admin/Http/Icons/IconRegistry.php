<?php

namespace App\Modules\Admin\Http\Icons;

/**
 * The back-office icon set, drawn on a 24-unit grid with rounded strokes
 * (design source, Foundations board). Paths are drawn for left-to-right;
 * icons that indicate direction are flagged to mirror in right-to-left
 * (spec 006, FR-032).
 */
class IconRegistry
{
    /**
     * @var array<string, array{paths: string, mirrors: bool}>
     */
    private const ICONS = [
        'overview' => ['paths' => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>', 'mirrors' => false],
        'users' => ['paths' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.5a6 6 0 0 1 3.5 5.5"/>', 'mirrors' => false],
        'claims' => ['paths' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>', 'mirrors' => false],
        'plans' => ['paths' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>', 'mirrors' => false],
        'audit' => ['paths' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/>', 'mirrors' => false],
        'wallet' => ['paths' => '<path d="M4 7h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4z"/><path d="M4 7V6a2 2 0 0 1 2-2h10"/><circle cx="16" cy="13.5" r="1.2"/>', 'mirrors' => false],
        'search' => ['paths' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>', 'mirrors' => false],
        'filter' => ['paths' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>', 'mirrors' => false],
        'bell' => ['paths' => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>', 'mirrors' => false],
        'lock' => ['paths' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>', 'mirrors' => false],
        'check' => ['paths' => '<path d="M5 12.5l4.5 4.5L19 7"/>', 'mirrors' => false],
        'close' => ['paths' => '<path d="M6 6l12 12M18 6L6 18"/>', 'mirrors' => false],
        'add' => ['paths' => '<path d="M12 5v14M5 12h14"/>', 'mirrors' => false],
        'minus' => ['paths' => '<path d="M6 12h12"/>', 'mirrors' => false],
        'more' => ['paths' => '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>', 'mirrors' => false],
        'copy' => ['paths' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>', 'mirrors' => false],
        'date' => ['paths' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>', 'mirrors' => false],
        'instant' => ['paths' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 'mirrors' => false],
        'trend-up' => ['paths' => '<path d="M4 17l6-6 4 4 6-7"/><path d="M15 8h5v5"/>', 'mirrors' => true],
        'trend-down' => ['paths' => '<path d="M4 7l6 6 4-4 6 7"/><path d="M15 16h5v-5"/>', 'mirrors' => true],
        'private' => ['paths' => '<path d="M3 3l18 18M10.6 6.1A9.8 9.8 0 0 1 12 6c5 0 9 6 9 6a15 15 0 0 1-2.6 3.2M6.5 7.6C4.3 9.2 3 12 3 12s4 6 9 6a8.6 8.6 0 0 0 4-1"/>', 'mirrors' => false],
        'export' => ['paths' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>', 'mirrors' => false],
        'chevron-start' => ['paths' => '<path d="M15 6l-6 6 6 6"/>', 'mirrors' => true],
        'chevron-end' => ['paths' => '<path d="M9 6l6 6-6 6"/>', 'mirrors' => true],
        'chevron-up' => ['paths' => '<path d="M6 15l6-6 6 6"/>', 'mirrors' => false],
        'chevron-down' => ['paths' => '<path d="M6 9l6 6 6-6"/>', 'mirrors' => false],
        'sign-out' => ['paths' => '<path d="M10 4H5v16h5M15 8l4 4-4 4M19 12H9"/>', 'mirrors' => true],
        'info' => ['paths' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>', 'mirrors' => false],
        'warning' => ['paths' => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>', 'mirrors' => false],
        'error' => ['paths' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>', 'mirrors' => false],
        'success' => ['paths' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>', 'mirrors' => false],
        'image' => ['paths' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>', 'mirrors' => false],
        'key' => ['paths' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>', 'mirrors' => false],
        'device' => ['paths' => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>', 'mirrors' => false],
        'refresh' => ['paths' => '<path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/>', 'mirrors' => true],
        'trash' => ['paths' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>', 'mirrors' => false],
        'external' => ['paths' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>', 'mirrors' => true],
        'edit' => ['paths' => '<path d="M4 20h4L19 9l-4-4L4 16z"/>', 'mirrors' => false],
        'columns' => ['paths' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>', 'mirrors' => false],
        'mail' => ['paths' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>', 'mirrors' => false],
        'sort-asc' => ['paths' => '<path d="M12 19V5M6 11l6-6 6 6"/>', 'mirrors' => false],
        'sort-desc' => ['paths' => '<path d="M12 5v14M6 13l6 6 6-6"/>', 'mirrors' => false],
    ];

    public function has(string $name): bool
    {
        return array_key_exists($name, self::ICONS);
    }

    /**
     * @return array{paths: string, mirrors: bool}
     *
     * @throws UnknownIcon
     */
    public function get(string $name): array
    {
        return self::ICONS[$name] ?? throw UnknownIcon::named($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(self::ICONS);
    }
}
