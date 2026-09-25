<?php

/*
| Back office: primitives gallery (spec 006, US4). Every key here exists in
| ../en/gallery.php. All figures, names and addresses are invented samples.
*/

return [
    'title' => 'معرض المكوّنات',
    'subtitle' => 'كل مكوّن بكل حالاته، لمراجعة التصميم. محلي فقط.',
    'breadcrumb_root' => 'نظام التصميم',
    'sample_notice_title' => 'محتوى نموذجي.',
    'sample_notice' => 'الأسماء والعناوين والأرقام هنا مختلَقة. لا يُقرأ شيء من قاعدة البيانات.',
    'sample_operator' => 'مريم س.',
    'sample_operator_initial' => 'م',
    'sections_label' => 'أقسام المعرض',
    'reason_demo' => 'يشغّل نافذة السبب الحقيقية: لا يُنفَّذ الإجراء النموذجي إلا بعد ذكر سبب.',
    'sections' => [
        'tokens' => 'الرموز', 'actions' => 'الإجراءات', 'forms' => 'النماذج', 'data' => 'البيانات',
        'feedback' => 'التنبيهات', 'navigation' => 'التنقل', 'values' => 'القيم', 'reason' => 'نافذة السبب',
    ],
    'states' => [
        'default' => 'افتراضي', 'hover' => 'تمرير', 'focus' => 'تركيز', 'pressed' => 'ضغط', 'loading' => 'تحميل', 'disabled' => 'معطّل',
    ],
    'variants' => ['primary' => 'أساسي', 'secondary' => 'ثانوي', 'danger' => 'خطر', 'ghost' => 'شفاف'],
    'sizes' => ['sm' => 'صغير · 32', 'md' => 'متوسط · 40', 'lg' => 'كبير · 48'],
    'nav' => [
        'overview' => 'نظرة عامة', 'claims' => 'مطالبات إنستاباي', 'users' => 'المستخدمون',
        'subscriptions' => 'الاشتراكات', 'plans' => 'كتالوج الخطط', 'audit' => 'سجل التدقيق',
    ],
    'plans' => ['free' => 'مجانية', 'plus_monthly' => 'Plus · شهري', 'plus_yearly' => 'Plus · سنوي', 'lifetime' => 'مدى الحياة'],
    'roles' => ['owner' => 'مالك', 'editor' => 'محرّر'],
    'subscription_states' => [
        'active' => 'نشط', 'trial' => 'تجريبي', 'in_grace' => 'في فترة السماح', 'on_hold' => 'معلّق',
        'paused' => 'متوقف مؤقتًا', 'canceled' => 'ملغى · حتى 2026-10-12', 'expired' => 'منتهٍ', 'revoked' => 'مسحوب',
    ],
    'claim_states' => ['pending' => 'معلّقة', 'approved' => 'مقبولة', 'rejected' => 'مرفوضة', 'expired' => 'انتهت دون مراجعة'],
    'samples' => [
        'h1' => 'نظرة عامة على المنصة', 'h2' => 'مطالبات تنتظر المراجعة', 'h3' => 'سجل الاشتراك',
        'body' => 'الموافقة تُنشئ اشتراك إنستاباي وترسل رمز تفعيل صالحًا لمرة واحدة.',
        'label' => 'الرقم المرجعي لإنستاباي', 'overline' => 'إيرادات هذا الشهر',
        'button' => ['primary' => 'موافقة', 'secondary' => 'تصدير', 'danger' => 'سحب', 'ghost' => 'إلغاء'],
        'approve_claim' => 'الموافقة على المطالبة', 'export' => 'تصدير CSV', 'view_user' => 'عرض المستخدم',
        'day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر', 'grant' => 'منح اشتراك', 'more_grant_options' => 'خيارات منح أخرى', 'extend' => 'تمديد',
        'icon' => ['ghost' => 'إجراءات أخرى', 'outline' => 'نسخ المعرّف', 'filled' => 'إضافة خطة', 'danger' => 'سحب'],
        'filter' => 'تصفية',
        'link' => 'رابط افتراضي', 'link_quiet' => 'رابط هادئ',
        'claim_actions' => 'إجراءات المطالبة', 'approve' => 'موافقة', 'open_user' => 'فتح المستخدم', 'copy_claim_id' => 'نسخ معرّف المطالبة',
        'resend_code' => 'إعادة إرسال رمز التفعيل', 'after_approval' => 'بعد الموافقة', 'reject' => 'رفض',
        'yearly' => 'سنوي', 'monthly' => 'شهري', 'save_pricing' => 'حفظ الأسعار…',
        'source' => 'المصدر', 'instapay_claim' => 'مطالبة إنستاباي', 'period' => 'الفترة',
        'new_notifications' => '3 إشعارات جديدة', 'no_match' => 'لا يوجد مستخدم مطابق لهذا البحث',
        'no_match_body' => 'يحتاج البحث إلى بريد كامل أو معرّف مستخدم أو معرّف مطالبة.',
        'wallets' => 'المحافظ', 'wallets_value' => '3 · 1 مشتركة', 'open_profile' => 'فتح الملف الكامل',
        'claim' => 'المطالبة', 'range' => 'المدة', 'billing' => 'الفوترة',
    ],
    'fields' => [
        'email' => 'البريد الإلكتروني', 'email_hint' => 'يظهر التلميح أسفل الحقل.', 'reference' => 'الرقم المرجعي لإنستاباي',
        'reference_error' => 'يجب أن يتكوّن الرقم المرجعي من 12 حرفًا.', 'user_id' => 'معرّف المستخدم', 'search_placeholder' => 'البريد أو معرّف المستخدم أو المطالبة',
        'monthly_price' => 'السعر الشهري', 'members' => 'الأعضاء لكل محفظة', 'reason' => 'السبب',
        'reason_sample' => 'الرقم المرجعي مطابق لكشف البنك والمبلغ مطابق لـ Plus السنوي.', 'reason_hint' => 'يُحفظ في سجل التدقيق باسمك.',
        'plan' => 'الخطة', 'email_code' => 'إرسال رمز التفعيل بالبريد', 'notify_push' => 'إشعار المستخدم',
        'select_page' => 'تحديد كل ما في الصفحة', 'refund' => 'استرداد تلقائي (ليس ضمن النسخة الأولى)',
        'sharing' => 'المشاركة', 'export_csv' => 'التصدير إلى CSV', 'bank_sync' => 'مزامنة البنك', 'feature_flag' => 'ميزة في الخطة', 'out_of_scope' => 'معطّل · خارج النطاق',
        'period' => 'مدة الخطة', 'period_start' => 'بداية الفترة', 'revoke_at' => 'السحب في',
        'pricing' => 'الأسعار', 'pricing_description' => 'الأسعار بيانات. أي تغيير يسري على المشتريات الجديدة فقط.',
        'monthly' => 'شهري', 'yearly' => 'سنوي', 'sku' => 'معرّف منتج Google Play',
        'sku_required' => 'معرّف منتج Google Play مطلوب', 'yearly_above_monthly' => 'يجب أن يكون السعر السنوي أعلى من الشهري',
    ],
    'stats' => [
        'revenue' => 'الإيرادات · سبتمبر', 'revenue_delta' => 'أعلى بـ 9% من أغسطس في النقطة نفسها',
        'signups' => 'التسجيلات الجديدة · 30 يومًا', 'signups_delta' => '12% مقارنة بالفترة السابقة',
        'churned' => 'المشتركون المغادرون', 'churned_delta' => 'أكثر بـ 21%', 'churned_caption' => 'معدل مغادرة شهري 2.4%',
        'lifetime_assiut' => 'مشترو مدى الحياة · أسيوط', 'mau' => 'المستخدمون النشطون شهريًا',
        'card_title' => 'بطاقة', 'card_subtitle' => 'عنوان وعنوان فرعي ومحتوى',
    ],
    'table' => [
        'caption' => 'مطالبات إنستاباي نموذجية', 'claim' => 'المطالبة', 'email' => 'البريد', 'plan' => 'الخطة', 'paid' => 'المدفوع',
        'submitted' => 'تاريخ التقديم', 'status' => 'الحالة', 'search' => 'البحث في المطالبات', 'filter_pending' => 'الحالة: معلّقة',
        'summary' => 'عرض 4 · الأحدث أولًا', 'empty_title' => 'لا توجد مطالبات معلّقة',
        'empty_body' => 'لا توجد مطالبات بانتظار المراجعة. تظهر الجديدة هنا خلال دقيقة.',
    ],
    'alerts' => [
        'info_title' => 'إذن الدعم يمنحه المستخدم.', 'info' => 'ترى الفوترة والبنية، ولا ترى المبالغ أو الملاحظات أبدًا.',
        'success_title' => 'تمت الموافقة على المطالبة.', 'success' => 'أُرسل رمز التفعيل بالبريد.', 'success_action' => 'عرض الاشتراك',
        'warning_title' => 'المبلغ يختلف عن سعر الخطة.', 'warning' => 'المطالب به 140 ج.م والخطة 149 ج.م.',
        'error_title' => 'تعذّر الوصول إلى واجهة Google Play.', 'error' => 'الإشعارات في قائمة الانتظار وسيُعاد المحاولة.',
    ],
    'toasts' => [
        'explainer' => 'تتراكم الإشعارات في الركن السفلي. تبقى رسائل الخطأ حتى إغلاقها.',
        'show_success' => 'نجاح مع تراجع', 'show_info' => 'معلومة', 'show_error' => 'خطأ',
        'saved' => 'حُفظ السعر للمشتريات الجديدة', 'copied' => 'نُسخ معرّف المستخدم', 'failed' => 'تعذّرت الموافقة على المطالبة',
        'approved' => 'تمت الموافقة النموذجية: :reason',
    ],
    'dialogs' => [
        'modal_title' => 'نافذة حوار', 'modal_description' => 'عنوان ووصف ومحتوى وأزرار في الأسفل.',
        'revoke_title' => 'سحب هذا الاشتراك؟', 'revoke_description' => 'تتوقف مزايا Plus فورًا. لا يمكن التراجع عن ذلك.',
        'revoke' => 'سحب…',
    ],
    'tabs' => [
        'details' => 'التفاصيل', 'receipt' => 'الإيصال', 'account' => 'الحساب', 'history' => 'السجل',
        'days_7' => '7 أيام', 'days_30' => '30 يومًا', 'months_12' => '12 شهرًا',
    ],
    'values' => [
        'money' => 'المبالغ', 'compact' => 'المبالغ · مختصرة', 'date' => 'تاريخ تقويمي', 'instant' => 'لحظة زمنية',
        'relative' => 'وقت نسبي', 'identifier' => 'معرّف', 'user_id' => 'معرّف المستخدم',
    ],
];
