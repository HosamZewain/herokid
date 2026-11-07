<?php

namespace App\Services\SalesAssistant;

use App\Models\SalesConversation;
use App\Models\SalesTurn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Private draft only. Never a provider tool, order mutation or implicit child reuse. */
class SalesIntake
{
    public const FIELDS = ['child_name', 'child_age', 'child_gender', 'parent_name', 'phone', 'street'];

    public function read(Request $request): array
    {
        try {
            $draft = json_decode(Crypt::decryptString($request->session()->get('sales_assistant.intake', '')), true, 16, JSON_THROW_ON_ERROR);
            if (($draft['conversation'] ?? '') === app(SalesSessions::class)->own($request)->id && ($draft['expires'] ?? 0) > now()->timestamp) {
                return array_intersect_key($draft['fields'], array_flip(self::FIELDS));
            }
        } catch (\Throwable) {
            // An expired/replaced session has no reusable private draft.
        }

        return [];
    }

    /** Conservative extraction: ambiguous unlabeled names/addresses are never guessed. */
    public function extract(string $text, ?string $expected = null): array
    {
        $text = trim(strip_tags($text));
        $numbers = strtr($text, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), range(0, 9)));
        $words = ['سنة' => 1, 'سنتين' => 2, 'اتنين' => 2, 'تلاتة' => 3, 'ثلاثة' => 3, 'اربعة' => 4, 'أربعة' => 4, 'خمسة' => 5, 'ستة' => 6, 'ست' => 6, 'سبعة' => 7, 'تمانية' => 8, 'ثمانية' => 8, 'تسعة' => 9, 'عشرة' => 10];
        $numbers = preg_replace_callback('/(?<!\pL)('.implode('|', array_keys($words)).')\s+(?:سنين|سنوات|سنة|سنه)(?!\pL)/u', fn ($m) => $words[$m[1]].' سنوات', $numbers);
        $labels = [
            'child_name' => '(?:اسم\s*(?:الطفل|الطفلة|البنت|الولد|بنتي|ابني)|(?:بنتي|ابني|طفلي)\s+اسم(?:ها|ه)|اسمها|اسمه)',
            'parent_name' => '(?:اسم\s*ولي\s*ال[اأإ]مر|ولي\s*ال[اأإ]مر|اسمي|إسمي)',
            'phone' => '(?:رقم\s*(?:الموبايل|الهاتف|التليفون|التلفون|الواتساب)|موبايلي|تليفوني|رقمي|موبايل|هاتف)',
            'street' => '(?:العنوان|عنواني|عنوان\s*التوصيل|عنوان|ساكن(?:ة|ه)?)',
            'child_age' => '(?:عمر\s*(?:الطفل|البنت|الولد)|العمر|عمره|عمرها|سنها|عمر(?=\s*[:：=]))',
            'child_gender' => '(?:جنس\s*الطفل|الجنس|النوع|نوع\s*الطفل)',
        ];
        $boundary = '(?:'.implode('|', $labels).')\s*[:：=]?';
        $fields = [];
        foreach ($labels as $key => $label) {
            preg_match_all('/(?<!\pL)'.$label.'\s*[:：=]?\s*(.+?)(?=\s*[،,;؛\n]?\s*و?'.$boundary.'|$)/us', $numbers, $matches);
            if (count($matches[1]) === 1) {
                $value = preg_replace('/^[\s،,;؛.]+|[\s،,;؛.]+$/u', '', $matches[1][0]);
                if (in_array($key, ['child_name', 'parent_name'], true)) {
                    $value = preg_split('/(?:\s+(?:ولد|بنت|عمره|عمرها|و?(?:عاوز|عايز|محتاج|بدور|عندها|عنده))|[،,\n])/u', $value)[0];
                }
                if ($value !== '') {
                    $fields[$key] = mb_substr($value, 0, $key === 'street' ? 300 : 100);
                }
            }
        }
        if (! isset($fields['child_name']) && preg_match('/(?:بنتي|ابني)\s+([\pL\pM ]{2,50}?)(?=\s+(?:و?عند(?:ه|ها)|و?عمر(?:ه|ها)|[0-9]{1,2}\s*(?:سنه|سنين|سنوات)))/u', $numbers, $namedChild)
            && ! preg_match('/(?:عاوز|عايز|محتاج|عندها|عنده|اسمها|اسمه)/u', $namedChild[1])) {
            $fields['child_name'] = trim($namedChild[1]);
        }
        preg_match_all('/(?<!\d)(?:\+?20\s*)?01[0125](?:[\s-]?\d){8}(?!\d)|(?<!\d)\+[1-9](?:[\s-]?\d){7,14}(?!\d)/u', $numbers, $phones);
        if (count($phones[0]) === 1) {
            $fields['phone'] = preg_replace('/[\s-]/', '', $phones[0][0]);
        } else {
            unset($fields['phone']);
        }
        $facts = app(SalesChildContext::class)->fromMessages([$numbers]);
        $ambiguous = $facts['needs_child_choice'] || preg_match('/(?:طفلين|بنتين|ولدين|الطفل\s*(?:الأول|الثاني))/u', $text);
        if (! $ambiguous) {
            if ($facts['age'] !== null) {
                $fields['child_age'] = (string) $facts['age'];
            }
            if ($facts['gender']) {
                $fields['child_gender'] = $facts['gender'];
            }
        } else {
            unset($fields['child_name'], $fields['child_age'], $fields['child_gender']);
        }
        $question = preg_match('/(?:[؟?]|(?<!\pL)(?:عاوز|عايز|محتاج|ايه|إيه|ليه|ازاي|هل|ممكن|قصة|قصه|حكاية|حكايه|بكام)(?!\pL))/u', $text);
        if ($expected && ! $question && in_array($expected, self::FIELDS, true) && ! isset($fields[$expected]) && ! $ambiguous && ! $fields) {
            if (in_array($expected, ['child_name', 'parent_name'], true) && preg_match('/^[\pL\pM\s\x{0027}-]{2,80}$/u', $text)) {
                $fields[$expected] = $text;
            } elseif ($expected === 'street' && mb_strlen($text) >= 5 && mb_strlen($text) <= 300) {
                $fields[$expected] = $text;
            }
        }
        if (isset($fields['child_age']) && (! ctype_digit($fields['child_age']) || (int) $fields['child_age'] > 18)) {
            unset($fields['child_age']);
        }
        if (isset($fields['child_gender']) && ! in_array($fields['child_gender'], ['boy', 'girl'], true)) {
            $gender = app(SalesChildContext::class)->fromMessages([$fields['child_gender']])['gender'];
            $normalizedGender = app(SalesCatalog::class)->normalize($fields['child_gender']);
            $gender ??= in_array($normalizedGender, ['انثي', 'female'], true) ? 'girl' : (in_array($normalizedGender, ['ذكر', 'male'], true) ? 'boy' : null);
            if ($gender) {
                $fields['child_gender'] = $gender;
            } else {
                unset($fields['child_gender']);
            }
        }
        $private = (bool) array_intersect_key($fields, array_flip(['child_name', 'parent_name', 'phone', 'street']));

        return ['fields' => $fields, 'handled' => $private || ((bool) $expected && ! $question) || (bool) $ambiguous, 'ambiguous' => (bool) $ambiguous];
    }

    public function remember(Request $request, SalesConversation $conversation, array $capture): array
    {
        $fields = $this->read($request);
        if ($capture['ambiguous'] || (isset($capture['fields']['child_name']) && ($fields['child_name'] ?? '') !== $capture['fields']['child_name'])) {
            unset($fields['child_name'], $fields['child_age'], $fields['child_gender']);
        }
        $fields = array_replace($fields, $capture['fields']);
        $request->session()->put('sales_assistant.intake', Crypt::encryptString(json_encode([
            'conversation' => $conversation->id, 'expires' => now()->addDays(30)->timestamp, 'fields' => $fields,
        ], JSON_THROW_ON_ERROR)));

        return $fields;
    }

    public function receive(Request $request, SalesConversation $conversation, string $nonce, array $capture): SalesTurn
    {
        return DB::transaction(function () use ($request, $conversation, $nonce, $capture) {
            $conversation = SalesConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $existing = SalesTurn::where('conversation_id', $conversation->id)->where('client_nonce', $nonce)->first();
            if ($existing) {
                return $existing;
            }
            $fields = $this->remember($request, $conversation, $capture);
            $facts = (isset($fields['child_age']) ? ' عمر الطفل '.$fields['child_age'].' سنوات.' : '').(isset($fields['child_gender']) ? ($fields['child_gender'] === 'girl' ? ' بنت.' : ' ولد.') : '');
            $message = $capture['ambiguous'] ? 'الرسالة فيها بيانات لأكثر من طفل. نكمّل طفل واحد في كل مرة علشان الصور والمنتجات تتربط صح. مين نبدأ به؟'
                : ($capture['fields'] ? 'فهمت البيانات اللي بعتها. راجعها في بطاقة البيانات هنا، وعدّل أي حاجة محتاجة تصحيح، وبعدها استخدمها للعنصر الحالي. هنسأل عن الناقص بس.'
                    : 'البيان مش واضح كفاية علشان أسجله صح. اكتبه مع توضيح المقصود، زي «اسم الطفل: …» أو كمّل الخانة الظاهرة هنا.');
            $turn = SalesTurn::create(['id' => (string) Str::uuid7(), 'conversation_id' => $conversation->id, 'client_nonce' => $nonce,
                'status' => 'completed', 'budget_day' => now('Africa/Cairo')->toDateString(), 'capability_nonce' => Str::random(64),
                'request_text' => ($capture['ambiguous'] ? 'بيانات طفلين؛ نحتاج تحديد الطفل.' : (isset($capture['fields']['child_name']) ? 'بيانات طفل جديد للمراجعة.' : 'تم استلام بيانات خاصة للمراجعة.')).$facts, 'result' => ['message' => $message, 'recommendations' => [], 'upsell' => false, 'next' => 'discover'], 'finished_at' => now()]);
            $conversation->touch();
            foreach (['message_received', 'reply_completed', 'intake_received'] as $type) {
                app(SalesSessions::class)->event($conversation, $type, metrics: ['private_intake' => true, 'field_count' => count($capture['fields']), 'ambiguous' => $capture['ambiguous']], turnId: $turn->id);
            }

            return $turn;
        }, 3);
    }
}
