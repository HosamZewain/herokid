<?php

namespace App\Services\SalesAssistant;

class SalesPrivacy
{
    public function redact(string $text, array $known = []): string
    {
        foreach ($known as $value) {
            if (is_string($value) && mb_strlen(trim($value)) > 1) {
                $text = str_ireplace($value, '[بيانات خاصة]', $text);
            }
        }
        $text = strip_tags($text);
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[بريد خاص]', $text);
        $text = preg_replace('~(?:https?://|www\.|s3://|file://|data:image/)\S+~iu', '[رابط خاص]', $text);
        $text = preg_replace('/[+\d٠-٩][\d٠-٩\s().\-]{6,}[\d٠-٩]/u', '[رقم خاص]', $text);
        $text = preg_replace('/(?:اسم(?:ه|ها|ي| الطفل| البنت)?|اسمه|اسمها|اسمي|name\s*(?:is)?|عنوان(?:ي|ه|ها)?|address|ساكن|ساكنه|شارع|شقة|شقه|street|تليفوني|موبايلي|رقمي)\s*[:：=]?\s*[^،,\n.!؟?]+/iu', '[بيانات خاصة في النموذج]', $text);

        $text = preg_replace('/(?:أنا|انا|my name is)\s+(?!عايز|عاوز|محتاج|بدور|نفسي|بحب|حابب|بسأل|عندي)[^،,\n.!؟?]+/iu', '[بيانات خاصة في النموذج]', $text);

        return mb_substr(trim($text), 0, 1500);
    }

    public function safeReply(string $text): string
    {
        $text = $this->redact($text);
        if (preg_match('/(?:[0-9٠-٩]+\s*(?:جنيه|ج\.?م|EGP|USD|دولار)|[$€]\s*[0-9]+)/iu', $text)) {
            return 'الأسعار الحالية موجودة في البطاقات، والإجمالي النهائي هتراجعه قبل تأكيد الطلب.';
        }
        // Never display claimed cures or treatment recommendations from the model.
        if (preg_match('/(?:علاج|يعالج|يشفي|مضمون|ADHD|اضطراب|تشخيص)/iu', $text)) {
            return 'نقدر نختار قصة أو نشاط يدوي مناسب لعمر طفلك واهتماماته. المنتجات للقراءة واللعب، ومش بديل لتقييم متخصص.';
        }

        return mb_substr($text, 0, 1000);
    }
}
