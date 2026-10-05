<?php

/*
| Back office: the audit log (spec 021, US3). Read-only: no export, edit or delete.
| Every key here exists in ../en/audit.php.
*/

return [
    'title' => 'سجل التدقيق',
    'subtitle' => 'من فعل ماذا، الأحدث أولًا. لا تُعدَّل المدخلات أبدًا.',
    'note' => 'فتح هذه الصفحة أو تصفيتها يُسجَّل كـ audit_read. المدخلات للإضافة فقط.',
    'filters' => [
        'label' => 'عوامل تصفية سجل التدقيق',
        'event' => 'الحدث',
        'actor_type' => 'نوع الفاعل',
        'actor_id' => 'معرّف الفاعل',
        'subject_type' => 'نوع الموضوع',
        'subject_id' => 'معرّف الموضوع',
        'from' => 'من',
        'to' => 'إلى',
        'any' => 'أي',
        'apply' => 'تطبيق',
        'reset' => 'إعادة ضبط',
    ],
    'columns' => [
        'when' => 'الوقت (:zone)',
        'event' => 'الحدث',
        'actor' => 'الفاعل',
        'subject' => 'الموضوع',
        'origin' => 'المصدر',
    ],
    'actor' => [
        'system' => 'النظام',
        'operator' => 'مشغّل',
        'user' => 'شخص',
    ],
    'subject' => [
        'user' => 'حساب',
        'wallet' => 'محفظة',
        'session' => 'جلسة',
        'subscription' => 'اشتراك',
        'operator' => 'مشغّل',
        'payment_claim' => 'مطالبة',
        'plan' => 'خطة',
    ],
    'detail' => [
        'request_id' => 'معرّف الطلب',
        'entry_id' => 'معرّف المدخل',
        'origin' => 'المصدر',
    ],
    'expand' => 'إظهار أو إخفاء تفاصيل :event',
    'range' => 'الأحدث أولًا · :from ← :to',
    'empty' => 'لا توجد مدخلات مطابقة.',
    'fix_filters' => 'صحّح عوامل التصفية أعلاه لعرض المدخلات.',
    'errors' => [
        'range_too_long' => 'اختر نطاقًا لا يزيد على 400 يوم.',
        'range_inverted' => 'يجب ألا يسبق تاريخ النهاية تاريخ البداية.',
        'id' => 'أدخل المعرّف كاملًا.',
        'date' => 'أدخل تاريخًا مثل 2026-10-05.',
        'unknown' => 'اختر إحدى القيم المعروضة.',
    ],
];
