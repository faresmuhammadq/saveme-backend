<?php

/*
| Back office: primitives gallery (spec 006, US4). Every key here exists in
| ../ar/gallery.php. All figures, names and addresses are invented samples.
*/

return [
    'title' => 'Primitives gallery',
    'subtitle' => 'Every primitive in every state, for design review. Local only.',
    'breadcrumb_root' => 'Design system',
    'sample_notice_title' => 'Sample content.',
    'sample_notice' => 'Names, emails and figures here are invented. Nothing is read from the database.',
    'sample_operator' => 'Mariam S.',
    'sample_operator_initial' => 'M',
    'sections_label' => 'Gallery sections',
    'reason_demo' => 'Runs the real reason prompt: the sample action only runs after a reason is given.',
    'sections' => [
        'tokens' => 'Tokens', 'actions' => 'Actions', 'forms' => 'Forms', 'data' => 'Data',
        'feedback' => 'Feedback', 'navigation' => 'Navigation', 'values' => 'Values', 'reason' => 'Reason prompt',
    ],
    'states' => [
        'default' => 'Default', 'hover' => 'Hover', 'focus' => 'Focus', 'pressed' => 'Pressed', 'loading' => 'Loading', 'disabled' => 'Disabled',
    ],
    'variants' => ['primary' => 'Primary', 'secondary' => 'Secondary', 'danger' => 'Danger', 'ghost' => 'Ghost'],
    'sizes' => ['sm' => 'Small · 32', 'md' => 'Medium · 40', 'lg' => 'Large · 48'],
    'nav' => [
        'overview' => 'Overview', 'claims' => 'InstaPay claims', 'users' => 'Users',
        'subscriptions' => 'Subscriptions', 'plans' => 'Plan catalog', 'audit' => 'Audit log',
    ],
    'plans' => ['free' => 'Free', 'plus_monthly' => 'Plus · Monthly', 'plus_yearly' => 'Plus · Yearly', 'lifetime' => 'Lifetime'],
    'roles' => ['owner' => 'Owner', 'editor' => 'Editor'],
    'subscription_states' => [
        'active' => 'Active', 'trial' => 'Trial', 'in_grace' => 'In grace', 'on_hold' => 'On hold',
        'paused' => 'Paused', 'canceled' => 'Canceled · until 2026-10-12', 'expired' => 'Expired', 'revoked' => 'Revoked',
    ],
    'claim_states' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'expired' => 'Expired unreviewed'],
    'samples' => [
        'h1' => 'Platform overview', 'h2' => 'Claims awaiting review', 'h3' => 'Subscription history',
        'body' => 'Approving creates an InstaPay subscription and emails a single-use redeem code.',
        'label' => 'InstaPay reference', 'overline' => 'Revenue this month',
        'button' => ['primary' => 'Approve', 'secondary' => 'Export', 'danger' => 'Revoke', 'ghost' => 'Cancel'],
        'approve_claim' => 'Approve claim', 'export' => 'Export CSV', 'view_user' => 'View user',
        'day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'grant' => 'Grant subscription', 'more_grant_options' => 'More grant options', 'extend' => 'Extend',
        'icon' => ['ghost' => 'More actions', 'outline' => 'Copy identifier', 'filled' => 'Add plan', 'danger' => 'Revoke'],
        'filter' => 'Filter',
        'link' => 'Default link', 'link_quiet' => 'Quiet link',
        'claim_actions' => 'Claim actions', 'approve' => 'Approve', 'open_user' => 'Open user', 'copy_claim_id' => 'Copy claim ID',
        'resend_code' => 'Resend redeem code', 'after_approval' => 'after approval', 'reject' => 'Reject',
        'yearly' => 'Yearly', 'monthly' => 'Monthly', 'save_pricing' => 'Save pricing…',
        'source' => 'Source', 'instapay_claim' => 'InstaPay claim', 'period' => 'Period',
        'new_notifications' => '3 new notifications', 'no_match' => 'No user matches that search',
        'no_match_body' => 'Search needs a full email, a user ID or a claim ID.',
        'wallets' => 'Wallets', 'wallets_value' => '3 · 1 shared', 'open_profile' => 'Open full profile',
        'claim' => 'Claim', 'range' => 'Range', 'billing' => 'Billing',
    ],
    'fields' => [
        'email' => 'Email', 'email_hint' => 'Hint text sits under the field.', 'reference' => 'InstaPay reference',
        'reference_error' => 'Reference must be 12 characters.', 'user_id' => 'User ID', 'search_placeholder' => 'Email, user ID or claim ID',
        'monthly_price' => 'Monthly price', 'members' => 'Members per wallet', 'reason' => 'Reason',
        'reason_sample' => 'Reference matches bank statement; amount matches Plus yearly.', 'reason_hint' => 'Stored in the audit log with your name.',
        'plan' => 'Plan', 'email_code' => 'Email the redeem code', 'notify_push' => 'Notify user by push',
        'select_page' => 'Select all on page', 'refund' => 'Refund automatically (not in MVP)',
        'sharing' => 'Sharing', 'export_csv' => 'Export to CSV', 'bank_sync' => 'Bank sync', 'feature_flag' => 'Plan feature flag', 'out_of_scope' => 'Disabled · out of scope',
        'period' => 'Plan period', 'period_start' => 'Period start', 'revoke_at' => 'Revoke at',
        'pricing' => 'Pricing', 'pricing_description' => 'Prices are data. A change applies to new purchases only.',
        'monthly' => 'Monthly', 'yearly' => 'Yearly', 'sku' => 'Google Play product ID',
        'sku_required' => 'Google Play product ID is required', 'yearly_above_monthly' => 'Yearly price must be above monthly',
    ],
    'stats' => [
        'revenue' => 'Revenue · September', 'revenue_delta' => '9% ahead of August at this point',
        'signups' => 'New sign-ups · 30d', 'signups_delta' => '12% vs prior 30d',
        'churned' => 'Churned subscribers', 'churned_delta' => '21% more', 'churned_caption' => '2.4% monthly churn rate',
        'lifetime_assiut' => 'Lifetime buyers · Assiut', 'mau' => 'Monthly active users',
        'card_title' => 'Card', 'card_subtitle' => 'Title, subtitle and body',
    ],
    'table' => [
        'caption' => 'Sample InstaPay claims', 'claim' => 'Claim', 'email' => 'Email', 'plan' => 'Plan', 'paid' => 'Paid',
        'submitted' => 'Submitted', 'status' => 'Status', 'search' => 'Search claims', 'filter_pending' => 'Status: Pending',
        'summary' => 'Showing 4 · newest first', 'empty_title' => 'Queue is clear',
        'empty_body' => 'No claims are waiting. New ones appear here within a minute.',
    ],
    'alerts' => [
        'info_title' => 'Support access is user-granted.', 'info' => 'You see billing and structure, never amounts or notes.',
        'success_title' => 'Claim approved.', 'success' => 'Redeem code emailed.', 'success_action' => 'View subscription',
        'warning_title' => 'Amount differs from plan price.', 'warning' => 'Claimed EGP 140, the plan is EGP 149.',
        'error_title' => 'Play Developer API unreachable.', 'error' => 'Notifications are queued and will retry.',
    ],
    'toasts' => [
        'explainer' => 'Toasts stack in the bottom-end corner. Errors stay until dismissed.',
        'show_success' => 'Success with undo', 'show_info' => 'Info', 'show_error' => 'Error',
        'saved' => 'Price saved for new purchases', 'copied' => 'User ID copied', 'failed' => 'Couldn’t approve claim',
        'approved' => 'Sample approved: :reason',
    ],
    'dialogs' => [
        'modal_title' => 'A modal dialog', 'modal_description' => 'Title, description, body and footer actions.',
        'revoke_title' => 'Revoke this subscription?', 'revoke_description' => 'Plus features end immediately. This can’t be undone.',
        'revoke' => 'Revoke…',
    ],
    'tabs' => [
        'details' => 'Details', 'receipt' => 'Receipt', 'account' => 'Account', 'history' => 'History',
        'days_7' => '7 days', 'days_30' => '30 days', 'months_12' => '12 months',
    ],
    'values' => [
        'money' => 'Money', 'compact' => 'Money · compact', 'date' => 'Calendar date', 'instant' => 'Instant',
        'relative' => 'Relative time', 'identifier' => 'Identifier', 'user_id' => 'user ID',
    ],
];
