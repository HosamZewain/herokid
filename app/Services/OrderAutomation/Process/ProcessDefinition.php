<?php

namespace App\Services\OrderAutomation\Process;

use App\Support\OrderStatusRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Schema 2 describes processes; schema 1 remains owned by the legacy identity runner. */
class ProcessDefinition
{
    public const TYPES = ['generate_previews' => 'تجهيز كل معاينات الهوية والمنتجات', 'approve_previews' => 'اعتماد صور المعاينة المجمعة', 'generate_identity' => 'توليد هوية الطفل وحفظها', 'approve_identity' => 'اعتماد الهوية وتحديث حالة الطلب', 'whatsapp' => 'إرسال قالب واتساب', 'wait_response' => 'انتظار رد العميل', 'change_status' => 'تغيير حالة الطلب', 'condition' => 'شرط وتفرّع', 'manual_review' => 'مراجعة موظف', 'end' => 'إنهاء المسار'];

    public const VARIABLES = ['customer_name' => 'اسم العميل', 'order_reference' => 'رقم الطلب', 'order_details' => 'تفاصيل المنتجات والقصص', 'order_total' => 'القيمة بعد الخصم شاملة الشحن'];

    public static function defaults(string $preset = 'blank'): array
    {
        $base = ['schema' => 2, 'scope' => 'checkout', 'trigger' => 'checkout_created',
            'conditions' => ['type' => 'any', 'status' => 'new', 'story_ids' => [], 'product_ids' => []],
            'nodes' => [['id' => 'finish', 'type' => 'end', 'label' => 'نهاية المسار', 'config' => []]]];
        if ($preset === 'confirmation') {
            $base['nodes'] = [
                ['id' => 'send', 'type' => 'whatsapp', 'label' => 'طلب تأكيد الأوردر', 'config' => ['template' => 'herokid_order_confirmation', 'language' => 'ar-EG', 'variables' => array_keys(self::VARIABLES)], 'next' => 'response'],
                ['id' => 'response', 'type' => 'wait_response', 'label' => 'انتظار قرار العميل', 'config' => ['message_node' => 'send', 'timeout_hours' => 24, 'cases' => [
                    ['key' => 'confirm', 'text' => 'تأكيد الطلب', 'next' => 'confirmed'],
                    ['key' => 'reject', 'text' => 'رفض الطلب', 'next' => 'review'],
                ], 'timeout_next' => 'review']],
                ['id' => 'confirmed', 'type' => 'change_status', 'label' => 'تأكيد الطلب', 'config' => ['from' => 'new', 'to' => 'new_confirmed'], 'next' => 'finish'],
                ['id' => 'review', 'type' => 'manual_review', 'label' => 'مراجعة الرفض أو عدم الرد', 'config' => ['instructions' => 'راجع حالة الطلب مع العميل قبل أي إلغاء.'], 'next' => 'finish'],
                ['id' => 'finish', 'type' => 'end', 'label' => 'نهاية المسار', 'config' => []],
            ];
        }

        if ($preset === 'identity') {
            $base['trigger'] = 'status_entered';
            $base['conditions']['type'] = 'story';
            $base['conditions']['status'] = 'new_confirmed';
            $base['nodes'] = [
                ['id' => 'generate', 'type' => 'generate_identity', 'label' => 'توليد هوية لكل طفل وحفظها للمراجعة', 'config' => ['budget_usd' => 1], 'next' => 'preview'],
                ['id' => 'preview', 'type' => 'whatsapp', 'label' => 'معاينة الهوية وطلب الدفع', 'config' => ['template' => ProcessTemplates::IDENTITY, 'language' => 'ar-EG', 'variables' => ProcessTemplates::IDENTITY_FIELDS], 'next' => 'response'],
                ['id' => 'response', 'type' => 'wait_response', 'label' => 'انتظار اعتماد الهوية', 'config' => ['message_node' => 'preview', 'timeout_hours' => 48,
                    'cases' => [['key' => 'approve_identity', 'text' => 'تأكيد الهوية', 'next' => 'approve'], ['key' => 'revise_identity', 'text' => 'طلب تعديل الهوية', 'next' => 'review']], 'timeout_next' => 'review']],
                ['id' => 'approve', 'type' => 'approve_identity', 'label' => 'اعتماد الهوية وتجهيز الطلب للتوليد', 'config' => ['response_node' => 'response'], 'next' => 'review'],
                ['id' => 'review', 'type' => 'manual_review', 'label' => 'مراجعة الدفع أو التعديلات', 'config' => ['instructions' => 'راجع رد العميل وإثبات التحويل وسجّل الدفعة الفعلية من شاشة المدفوعات. تأكيد الهوية لا يثبت الدفع؛ لا تستكمل الإنتاج قبل المراجعة.'], 'next' => 'finish'],
                ['id' => 'finish', 'type' => 'end', 'label' => 'نهاية المسار', 'config' => []],
            ];
        }

        if ($preset === 'product_review') {
            $base = self::defaults('identity');
            $base['conditions']['type'] = 'any';
            $base['nodes'][0]['type'] = 'generate_previews';
            $base['nodes'][0]['label'] = 'تجهيز كل الهويات وصور المنتجات والكفر';
            $base['nodes'][1]['config']['template'] = ProcessTemplates::REVIEW;
            $base['nodes'][1]['config']['variables'] = ProcessTemplates::REVIEW_FIELDS;
            $base['nodes'][1]['label'] = 'معاينة مجمعة وطلب الدفع';
            $base['nodes'][2]['config']['cases'][0]['text'] = 'تأكيد المعاينة';
            $base['nodes'][2]['config']['cases'][1]['text'] = 'طلب تعديل المعاينة';
            $base['nodes'][3]['type'] = 'approve_previews';
            $base['nodes'][3]['label'] = 'اعتماد الصور المحددة في رسالة المعاينة';
        }

        return $base;
    }

    public function validate(array $input): array
    {
        $statuses = OrderStatusRegistry::keys('order');
        $d = Validator::make($input, [
            'schema' => 'required|integer|in:2', 'scope' => 'required|in:checkout,story,product',
            'trigger' => 'required|in:checkout_created,status_entered',
            'conditions' => 'required|array:type,status,story_ids,product_ids',
            'conditions.type' => 'required|in:any,story,product', 'conditions.status' => ['required', Rule::in($statuses)],
            'conditions.story_ids' => 'present|array|max:500', 'conditions.story_ids.*' => 'integer|distinct|exists:stories,id',
            'conditions.product_ids' => 'present|array|max:500', 'conditions.product_ids.*' => 'integer|distinct|exists:products,id',
            'nodes' => 'required|array|min:1|max:40', 'nodes.*' => 'required|array:id,type,label,config,next',
            'nodes.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,39}$/|distinct',
            'nodes.*.type' => ['required', Rule::in(array_keys(self::TYPES))],
            'nodes.*.label' => 'required|string|max:120', 'nodes.*.config' => 'present|array',
            'nodes.*.next' => 'nullable|string|max:40',
        ])->validate();
        $d['schema'] = 2;
        if (! array_is_list($d['nodes'])) {
            $this->fail('nodes', 'الخطوات لازم تكون قائمة مرتبة.');
        }
        if ($d['scope'] !== 'checkout' && $d['conditions']['type'] !== $d['scope']) {
            $this->fail('conditions.type', 'نطاق القصة أو المنتج لازم يطابق نوع العناصر المحددة.');
        }
        if (($d['conditions']['type'] === 'story' && $d['conditions']['product_ids'])
            || ($d['conditions']['type'] === 'product' && $d['conditions']['story_ids'])) {
            $this->fail('conditions', 'احذف اختيارات النوع غير المستهدف.');
        }
        foreach (['story_ids', 'product_ids'] as $field) {
            $d['conditions'][$field] = array_map('intval', $d['conditions'][$field]);
        }
        $ids = array_column($d['nodes'], 'id');
        $edges = [];
        foreach ($d['nodes'] as $i => &$node) {
            $rules = match ($node['type']) {
                'whatsapp' => ['template' => 'required|string|max:120|regex:/^[a-zA-Z0-9_]+$/', 'language' => 'required|in:ar-EG', 'variables' => 'present|array|max:10', 'variables.*' => ['required', Rule::in(array_keys(self::VARIABLES + ProcessTemplates::EXTRA_VARIABLES))]],
                'wait_response' => ['message_node' => 'required|string|max:40', 'timeout_hours' => 'required|integer|min:1|max:720', 'timeout_next' => 'required|string|max:40', 'cases' => 'required|array|min:1|max:5', 'cases.*' => 'array:key,text,next', 'cases.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,39}$/|distinct', 'cases.*.text' => 'required|string|max:120|distinct', 'cases.*.next' => 'required|string|max:40'],
                'change_status' => ['from' => ['required', Rule::in($statuses)], 'to' => ['required', Rule::in($statuses)]],
                'condition' => ['field' => 'required|in:status,type', 'value' => 'required|string|max:100', 'yes' => 'required|string|max:40', 'no' => 'required|string|max:40'],
                'manual_review' => ['instructions' => 'required|string|max:500'],
                'generate_identity', 'generate_previews' => ['budget_usd' => 'required|numeric|min:0.001|max:20'],
                'approve_identity', 'approve_previews' => ['response_node' => 'required|string|max:40'],
                'end' => [],
            };
            $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($node['config']), $allowed)) {
                $this->fail("nodes.$i.config", 'إعدادات غير مدعومة لهذه الخطوة.');
            }
            $node['config'] = Validator::make($node['config'], $rules)->validate();
            $c = $node['config'];
            if ($node['type'] === 'whatsapp' && (! array_is_list($c['variables']) || (ProcessTemplates::fields($c['template']) !== null && $c['variables'] !== ProcessTemplates::fields($c['template'])))) {
                $this->fail("nodes.$i.config.variables", 'قالب التأكيد يتطلب الاسم، رقم الطلب، التفاصيل، ثم القيمة بنفس الترتيب.');
            }
            if ($node['type'] === 'condition' && ! in_array($c['value'], $c['field'] === 'status' ? $statuses : ['story', 'product', 'mixed'], true)) {
                $this->fail("nodes.$i.config.value", 'قيمة الشرط غير صالحة.');
            }
            if ($node['type'] === 'change_status' && $c['from'] === $c['to']) {
                $this->fail("nodes.$i.config.to", 'اختار حالة مختلفة.');
            }
            if ($node['type'] === 'wait_response') {
                if (! array_is_list($c['cases'])) {
                    $this->fail("nodes.$i.config.cases", 'فروع الرد لازم تكون قائمة.');
                }
                $texts = array_map(fn ($case) => trim($case['text']), $c['cases']);
                if (in_array('', $texts, true) || count(array_unique($texts)) !== count($texts)) {
                    $this->fail("nodes.$i.config.cases", 'نصوص الرد لازم تكون مختلفة وغير فارغة.');
                }
                foreach ($node['config']['cases'] as &$case) {
                    $case['text'] = trim($case['text']);
                }
                unset($case);
            }
            $next = match ($node['type']) {
                'end' => [],
                'wait_response' => [...array_column($c['cases'], 'next'), $c['timeout_next']],
                'condition' => [$c['yes'], $c['no']],
                default => [$node['next'] ?? ''],
            };
            if (in_array($node['type'], ['end', 'wait_response', 'condition'], true)) {
                unset($node['next']);
            }
            foreach ($next as $target) {
                $j = array_search($target, $ids, true);
                if ($j === false || $j <= $i) {
                    $this->fail("nodes.$i.next", 'كل فرع لازم يوصل لخطوة لاحقة موجودة؛ الرجوع والدورات غير مسموحين.');
                }
            }
            $edges[$node['id']] = array_values(array_unique($next));
        }
        unset($node);
        // A referenced send must occur on EVERY path to its wait, not merely earlier in the list.
        $ancestors = [$ids[0] => []];
        foreach ($d['nodes'] as $i => $node) {
            if (! array_key_exists($node['id'], $ancestors)) {
                $this->fail("nodes.$i", 'خطوة غير متصلة ببداية المسار.');
            }
            if ($node['type'] === 'wait_response') {
                $ref = $node['config']['message_node'];
                $send = collect($d['nodes'])->firstWhere('id', $ref);
                if (! $send || $send['type'] !== 'whatsapp' || ! in_array($ref, $ancestors[$node['id']], true)) {
                    $this->fail("nodes.$i.config.message_node", 'الانتظار لازم يرتبط برسالة يتم إرسالها في كل مسار يصل إليه.');
                }
            }
            if ($node['type'] === 'whatsapp' && in_array($node['config']['template'], [ProcessTemplates::IDENTITY, ProcessTemplates::REVIEW], true)) {
                if (! collect($d['nodes'])->contains(fn ($n) => $n['type'] === ($node['config']['template'] === ProcessTemplates::REVIEW ? 'generate_previews' : 'generate_identity') && in_array($n['id'], $ancestors[$node['id']], true))) {
                    $this->fail("nodes.$i", 'رسالة الهوية تحتاج توليدًا محفوظًا في كل مسار يصل إليها.');
                }
            }
            if (in_array($node['type'], ['approve_identity', 'approve_previews'], true)) {
                $unified = $node['type'] === 'approve_previews';
                $approvalText = $unified ? 'تأكيد المعاينة' : 'تأكيد الهوية';
                $wait = collect($d['nodes'])->firstWhere('id', $node['config']['response_node']);
                $send = $wait && $wait['type'] === 'wait_response' ? collect($d['nodes'])->firstWhere('id', $wait['config']['message_node']) : null;
                $approval = $wait ? collect($wait['config']['cases'] ?? [])->firstWhere('text', $approvalText) : null;
                if (! $wait || ! in_array($wait['id'], $ancestors[$node['id']], true) || ! $send || $send['config']['template'] !== ($unified ? ProcessTemplates::REVIEW : ProcessTemplates::IDENTITY)
                    || ! $approval || $approval['text'] !== $approvalText) {
                    $this->fail("nodes.$i", 'اعتماد الهوية يحتاج رد تأكيد الهوية المرتبط بقالب المعاينة.');
                }
            }
            foreach ($edges[$node['id']] as $next) {
                $path = [...$ancestors[$node['id']], $node['id']];
                $ancestors[$next] = isset($ancestors[$next]) ? array_values(array_intersect($ancestors[$next], $path)) : $path;
            }
        }

        return $d;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages(['definition.'.$key => $message]);
    }
}
