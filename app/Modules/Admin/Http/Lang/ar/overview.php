<?php

/*
| Back office: the platform overview (spec 021, US5). Every key here exists in ../en/overview.php.
*/

return [
    'title' => 'نظرة عامة على المنصة',
    'subtitle' => 'أعداد فقط. الأعداد الأقل من 10 أشخاص تظهر على أنها «أقل من 10».',
    'fewer_than_10' => 'أقل من 10',
    'figures' => [
        'accounts' => 'الحسابات',
        'verified' => 'موثّقة',
        'pending_deletion' => 'بانتظار الحذف',
        'new_7_days' => 'جديدة · 7 أيام',
        'new_30_days' => 'جديدة · 30 يومًا',
        'paid_google_play' => 'مدفوع · جوجل بلاي',
        'test_google_play' => 'تجريبي · جوجل بلاي',
        'paid_instapay' => 'مدفوع · إنستا باي',
        'granted_manual' => 'ممنوح · يدويًا',
        'in_grace' => 'في فترة السماح',
        'shared_wallets' => 'المحافظ المشتركة',
    ],
    'push' => [
        'title' => 'الإشعارات، آخر 24 ساعة',
        'hint' => 'أعداد فقط: لا أشخاص ولا أجهزة ولا ما قاله أي إشعار.',
        'created' => 'أُنشئت',
        'sent' => 'أُرسلت',
        'skipped' => 'تم تخطيها',
        'failed' => 'فشلت',
        'pruned' => 'تسجيلات أُزيلت',
    ],
    'play' => [
        'title' => 'جوجل بلاي، آخر 24 ساعة',
        'hint' => 'أعداد فقط: لا عمليات شراء ولا طلبات ولا أشخاص.',
        'received' => 'إشعارات مستلمة',
        'processed' => 'تمت معالجتها',
        'ignored' => 'تم تجاهلها',
        'failed' => 'فشلت',
        'rejected' => 'رسائل مرفوضة',
        'verified' => 'عمليات شراء تم التحقق منها',
        'unacknowledged' => 'بلا إقرار لأكثر من 24 ساعة',
        'reasons' => [
            'malformed' => 'رمز تالف',
            'algorithm' => 'خوارزمية غير صحيحة',
            'signature' => 'التوقيع',
            'issuer' => 'الجهة المُصدِرة',
            'audience' => 'الجمهور المقصود',
            'email' => 'المرسل',
            'email_unverified' => 'مرسل غير موثّق',
            'time' => 'نافذة وقت الإصدار',
            'expired' => 'منتهي الصلاحية',
        ],
    ],
    'attention' => [
        'title' => 'يحتاج إلى انتباه',
        'nothing' => 'لا شيء يحتاج إلى انتباه',
        'claims' => '{1} :count مطالبة بانتظار المراجعة|[2,*] :count مطالبات بانتظار المراجعة',
        'oldest' => 'الأقدم',
        'reenrolments' => '{1} :count طلب إعادة تسجيل مفتوح|[2,*] :count طلبات إعادة تسجيل مفتوحة',
    ],
];
