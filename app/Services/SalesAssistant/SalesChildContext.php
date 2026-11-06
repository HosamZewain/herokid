<?php

namespace App\Services\SalesAssistant;

use App\Models\SalesTurn;

class SalesChildContext
{
    /** Replay retained, already-redacted facts, never names, photos or contact forms. */
    public function forTurn(SalesTurn $turn): array
    {
        $messages = SalesTurn::where('conversation_id', $turn->conversation_id)
            ->where('created_at', '>', now()->subDays(30))
            ->where(fn ($q) => $q->where('created_at', '<', $turn->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $turn->created_at)->where('id', '<=', $turn->id)))
            ->orderBy('created_at')->orderBy('id')->select(['id', 'request_text'])->cursor();

        return $this->fromMessages($messages->map(fn ($t) => $t->request_text));
    }

    public function fromMessages(iterable $messages): array
    {
        $context = ['age' => null, 'gender' => null, 'needs_child_choice' => false];
        foreach ($messages as $message) {
            $text = app(SalesCatalog::class)->normalize((string) $message);
            // A negated gender is not a new child's identity.
            $text = preg_replace('/(?:مش|ليس|ليست)\s+(?:ولد|بنت|boy|girl)(?!\pL)/u', '', $text);
            $girl = (bool) preg_match('/(?<!\pL)[ولب]{0,2}(?:بنتي|ابنتي|ابنتنا|بنتنا|بنت|البنت|daughter|girl)(?!\pL)/u', $text);
            $boy = (bool) preg_match('/(?<!\pL)[ولب]{0,2}(?:ابني|ولدي|ولد|الولد|son|boy)(?!\pL)/u', $text);
            preg_match_all('/(?<!\d)(\d{1,2})\s*(?:سنه|سنين|سنوات|عام|اعوام|years?|yrs?)(?!\pL)/u', $text, $ageMatches);
            preg_match_all('/(?:عمره|عمرها|سنها|سنه|age)\s*(?:هو|هي|is)?\s*(\d{1,2})(?!\d)/u', $text, $explicitAges);
            $ages = array_values(array_unique(array_filter(array_map('intval', array_merge($ageMatches[1], $explicitAges[1])), fn ($age) => $age >= 0 && $age <= 18)));
            if (($girl && $boy) || count($ages) > 1 || preg_match('/(?:طفلين|طفلتين|بنتين|ولدين|طفلين|two children)/u', $text)) {
                $context = ['age' => null, 'gender' => null, 'needs_child_choice' => true];

                continue;
            }
            $gender = $girl ? 'girl' : ($boy ? 'boy' : null);
            $newChild = (bool) preg_match('/(?:طفل|طفله|بنتي|ابني|بنت|ولد)\s+(?:ال)?(?:تاني|تانيه|ثاني|ثانيه|اخر|اخري|جديد|جديده)(?!\pL)/u', $text);
            if ($newChild || ($gender && $context['gender'] && $gender !== $context['gender'])) {
                $context = ['age' => null, 'gender' => null, 'needs_child_choice' => false];
            }
            if ($gender) {
                $context['gender'] = $gender;
                $context['needs_child_choice'] = false;
            }
            if (count($ages) === 1 && ! $context['needs_child_choice']) {
                $context['age'] = $ages[0];
            }
        }

        return $context;
    }

    public function repeatsKnownQuestion(string $message, array $context): bool
    {
        $text = app(SalesCatalog::class)->normalize($message);
        $ageQuestion = preg_match('/(?:كام|كم)\s+(?:سنه|سنوات|عمر)|(?:عمره|عمرها|سنها|سنه|عمر|سن)\s*(?:طفلك|بنتك|ابنك|الطفل|البنت|الولد)?\s*(?:هو|هي)?\s*(?:كام|كم)|(?:عنده|عندها)\s*(?:كام|كم)/u', $text);
        $genderQuestion = preg_match('/(?:ولد\s*(?:ولا|او|ام)\s*بنت|بنت\s*(?:ولا|او|ام)\s*ولد)/u', $text);

        return ($context['age'] !== null && $ageQuestion) || ($context['gender'] !== null && $genderQuestion);
    }

    public function fallback(array $context, bool $hasChoices): string
    {
        if ($context['needs_child_choice']) {
            return 'نقدر نختار لكل طفل لوحده. تحب نبدأ بولد ولا بنت، وعمره كام سنة؟';
        }
        $child = match ($context['gender']) {
            'girl' => 'لبنتك', 'boy' => 'لابنك', default => 'لطفلك',
        };
        $age = $context['age'] !== null ? ' بعمر '.$context['age'].' سنين' : '';

        return $hasChoices
            ? "دي اختيارات مناسبة {$child}{$age}. راجع البطاقات، واختار القصة علشان نكمل بياناتها وصورها."
            : "ما لقيتش ترشيحًا مؤكدًا {$child}{$age} يناسب طلبك الحالي. نقدر نجرّب موضوع تاني أو تكلم الفريق يساعدك في الاختيار.";
    }
}
