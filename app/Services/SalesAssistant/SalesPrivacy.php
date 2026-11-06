<?php

namespace App\Services\SalesAssistant;

class SalesPrivacy
{
    public function redact(string $text, array $known = [], array $publicLabels = []): string
    {
        foreach ($known as $value) {
            if (is_string($value) && mb_strlen(trim($value)) > 1) {
                $text = preg_replace('/(?<![\pL\pN])'.preg_quote(trim($value), '/').'(?![\pL\pN])/iu', '[بيانات خاصة]', $text);
            }
        }
        $restore = [];
        foreach ($publicLabels as $label) {
            if (! is_string($label) || mb_strlen($label) < 3 || ! str_contains($text, $label)) {
                continue;
            }
            // Protect explicit public references, never a child's name/address equal to a title.
            $pattern = '/(?:قصة|قصه|حكاية|حكايه|كتاب|لعبة|لعبه|بازل|منتج|باقة|باقه)\s+(?:اسمها?|بعنوان)\s*[«"“]?'.preg_quote($label, '/').'[»"”]?/u';
            $text = preg_replace_callback($pattern, function ($match) use (&$restore) {
                $token = 'HKPUBLIC'.strtr(bin2hex(random_bytes(12)), '0123456789', 'klmnopqrst').'END';
                $restore[$token] = $match[0];

                return $token;
            }, $text);
        }
        $text = strip_tags($text);
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[بريد خاص]', $text);
        $text = preg_replace('~(?:https?://|www\.|s3://|file://|data:image/)\S+~iu', '[رابط خاص]', $text);
        $text = preg_replace('/[+\d٠-٩][\d٠-٩\s().\-]{6,}[\d٠-٩]/u', '[رقم خاص]', $text);
        $text = preg_replace('/(?<!\pL)(?:اسمه|اسمها|اسمي|اسم(?: الطفل| البنت| الولد)?|name\s*(?:is)?|عنوان(?:ي|ه|ها)?|address|ساكن|ساكنه|شارع|شقة|شقه|street|تليفوني|موبايلي|رقمي)(?!\pL)\s*[:：=]?\s*([^،,\n.!؟?]+)/iu', '[بيانات خاصة في النموذج]', $text);
        $text = preg_replace('/(?<!\pL)(?:أنا|انا|my name is)\s+(?!عايز|عاوز|محتاج|بدور|نفسي|بحب|حابب|بسأل|عندي|مساعد|المساعد|هنا|هساعدك|معاك|موجود)[^،,\n.!؟?]+/iu', '[بيانات خاصة في النموذج]', $text);

        return mb_substr(trim(strtr($text, $restore)), 0, 1500);
    }

    public function safeReply(string $text, array $publicLabels = []): string
    {
        $text = $this->redact($text, publicLabels: $publicLabels);
        if (preg_match('/(?:[0-9٠-٩]+\s*(?:جنيه|ج\.?م|EGP|USD|دولار)|[$€]\s*[0-9]+)/iu', $text)) {
            return 'الأسعار الحالية موجودة في البطاقات، والإجمالي النهائي هتراجعه قبل تأكيد الطلب.';
        }
        if (preg_match('/(?:علاج|يعالج|يشفي|مضمون|ADHD|اضطراب|تشخيص)/iu', $text)) {
            return 'نقدر نختار قصة أو نشاط يدوي مناسب لعمر طفلك واهتماماته. المنتجات للقراءة واللعب، ومش بديل لتقييم متخصص.';
        }

        return $this->displayText(mb_substr($text, 0, 1000));
    }

    public function displayText(string $text): string
    {
        $text = preg_replace('/\[(?:بيانات خاصة(?: في النموذج)?|بريد خاص|رابط خاص|رقم خاص)\]\s*[،,:]?\s*/u', '', $text);

        return preg_replace('/^[\s،,]+|[\s،,]+$/u', '', $text) ?: 'خلّينا نختار القصة هنا، وبيانات التواصل والصور مكانها النماذج الخاصة.';
    }
}
