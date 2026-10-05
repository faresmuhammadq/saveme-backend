<?php

/*
| Back office: the reason prompt (spec 006, FR-017). Every key here exists in ../en/reason.php.
*/

return [
    'question' => 'لماذا تتخذ هذا الإجراء؟',
    'attribution' => 'يُسجَّل في سجل التدقيق باسم :who',
    'confirm' => 'تأكيد',
    'errors' => [
        'blank' => 'اذكر سببًا قبل التأكيد.',
        'too_long' => 'اجعل السبب في حدود :max حرف.',
    ],
    'presets' => [
        'reference_verified' => 'تم التحقق من الرقم المرجعي',
        'amount_matches' => 'المبلغ مطابق',
        'screenshot_checked' => 'تمت مراجعة لقطة الشاشة',
    ],
    'actions' => [
        'claim' => [
            'revoke' => [
                'title' => 'إلغاء رمز الاسترداد هذا',
                'consequence' => 'يُبطل الرمز غير المستخدم ويغلق المطالبة. لا يُرسل بريد؛ تواصل مع الدافع بنفسك. لا تذكر بيانات شخصية في السبب.',
            ],
        ],
        'reenrolment' => [
            'approve' => [
                'title' => 'الموافقة على إعادة التسجيل',
                'consequence' => 'يسجّل موافقتك. عندما يوافق مشغّل ثانٍ يتوقف تطبيق المصادقة ورموز الاستعادة للمشغّل وتنتهي جلساته.',
            ],
            'decline' => [
                'title' => 'رفض إعادة التسجيل',
                'consequence' => 'يغلق الطلب. يمكن للمشغّل أن يطلب مرة أخرى.',
            ],
        ],
        'gallery' => [
            'approve' => [
                'title' => 'الموافقة على النموذج',
                'consequence' => 'يشغّل إجراءً نموذجيًا. لا يُحفظ شيء.',
            ],
        ],
        'fixture' => [
            'approve' => [
                'title' => 'الموافقة على النموذج',
                'consequence' => 'يسجّل موافقة نموذجية.',
            ],
        ],
    ],
];
