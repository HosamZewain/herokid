<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class OrderChildIdentityPromptService
{
    public const MAX_LENGTH = 65000;

    public const VERSION = '1.0';

    private const CONTEXT_START = '<!-- HERO_KID_IDENTITY_CONTEXT_START -->';

    private const CONTEXT_END = '<!-- HERO_KID_IDENTITY_CONTEXT_END -->';

    public function __construct(
        private readonly OrderSceneTextService $sceneTexts,
    ) {}

    public function forOrder(Order $order, bool $useOverride = true): string
    {
        $order->loadMissing(['story.sceneTemplates', 'sceneTextSnapshots', 'childIdentityPromptOverride']);
        $instructions = $useOverride && $order->childIdentityPromptOverride
            ? trim((string) $order->childIdentityPromptOverride->prompt_text)
            : $this->defaultInstructions();

        return $this->withCurrentContext($instructions, $order);
    }

    public function withCurrentContext(string $prompt, Order $order): string
    {
        $context = $this->contextBlock($order);
        $pattern = '/'.preg_quote(self::CONTEXT_START, '/').'.*?'.preg_quote(self::CONTEXT_END, '/').'/s';

        if (preg_match($pattern, $prompt) === 1) {
            return preg_replace($pattern, $context, $prompt, 1) ?? $prompt;
        }

        return rtrim($prompt)."\n\n".$context;
    }

    public function defaultInstructions(): string
    {
        return <<<'PROMPT'
TASK: Create ONLY the reusable child hero identity for this HeroKid story before generating any story scenes.

Use the supplied original child photos as the authoritative facial-identity references. Preserve the child's recognizable face, age, skin tone, hair, proportions, and distinguishing features. The story context below is provided only to identify the hero role, age-appropriate wardrobe, recurring visual identity, and overall world of the selected story.

OUTPUT REQUIREMENTS:
- Produce one clean landscape character-reference sheet for the same child.
- Show a consistent full-body front view, three-quarter view, side/profile view, and useful facial-expression references.
- Choose age-appropriate hero clothing and styling that fit the selected story and can remain consistent in every later scene.
- Keep a simple neutral background with even lighting so this image can be used as the approved identity reference for all scene generations.
- Do not generate a story scene, spread, page layout, cover, environment, props, other characters, written text, logo, or watermark.
- Do not change the child's gender, age, facial identity, skin tone, hair texture, or recognizable features.
- Return only the identity reference image. Do not generate any story scenes yet.

WORKFLOW NOTE:
This identity will be reviewed and approved first. Only after approval will it be supplied as the primary visual identity reference together with the existing Story Production Prompt to generate the 13 story scenes.
PROMPT;
    }

    private function contextBlock(Order $order): string
    {
        $story = $order->story;
        $handoff = $story ? $this->sceneTexts->present($order, includeProductionScenes: false) : null;
        $sceneContext = collect($handoff['scenes'] ?? [])
            ->filter(fn (array $scene): bool => filled($scene['text'] ?? null))
            ->map(function (array $scene): string {
                $title = filled($scene['title'] ?? null) ? ' — '.Str::squish((string) $scene['title']) : '';
                $text = Str::limit(Str::squish((string) $scene['text']), 900, '…');

                return 'Scene '.((int) $scene['scene_number']).$title.":\n".$text;
            })
            ->implode("\n\n");

        $storyContent = Str::limit($this->cleanMultiline($story?->full_story ?? $story?->full_desc), 3500, '…');

        return self::CONTEXT_START."\n"
            ."## Current Order and Child Profile (managed automatically)\n"
            .'- Order number: '.$this->value($order->order_number)."\n"
            .'- Child name: '.$this->value($order->child_name)."\n"
            .'- Child age: '.$this->value($order->child_age)."\n"
            .'- Child gender: '.$this->gender($order->child_gender)."\n"
            .'- Child interests: '.$this->value($order->interests)."\n\n"
            ."## Selected Story Hero Context (managed automatically)\n"
            ."Use this context to design the recurring hero identity and wardrobe only. Do not reproduce any scene composition in this identity image.\n"
            .'- Story title: '.$this->value($story?->title)."\n"
            .'- Story age range: '.$this->value($story?->age_range)."\n"
            .'- Story gender: '.$this->gender($story?->gender, allowBoth: true)."\n"
            .'- Story description: '.$this->value($story?->short_desc)."\n"
            .'- Educational value / lesson: '.$this->value($order->lesson ?? $story?->lesson_value)."\n"
            .'- Story overview: '.($storyContent !== '' ? $storyContent : 'Not available')."\n\n"
            ."### Personalized scene text excerpts\n"
            .($sceneContext !== '' ? $sceneContext : 'No personalized scene texts are available for this order.')."\n\n"
            ."## Secure Original Child Photo References (managed automatically)\n"
            .$this->photoReferences($order)."\n"
            .self::CONTEXT_END;
    }

    private function photoReferences(Order $order): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        if ($photos === []) {
            return 'No child images were attached to this order.';
        }

        return collect($photos)
            ->map(fn (string $photo, int $index): string => ($index + 1).'. '.URL::signedRoute('orders.production-photo', [
                'order' => $order,
                'index' => $index,
            ]))
            ->implode("\n");
    }

    private function cleanMultiline(?string $value): string
    {
        $clean = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $clean = preg_replace("/[ \t]+\n/", "\n", $clean) ?? '';

        return preg_replace("/\n{3,}/", "\n\n", $clean) ?? '';
    }

    private function value(mixed $value): string
    {
        $clean = Str::squish(html_entity_decode(strip_tags((string) ($value ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $clean !== '' ? $clean : 'Not available';
    }

    private function gender(?string $gender, bool $allowBoth = false): string
    {
        return match ($gender) {
            'boy' => 'Boy',
            'girl' => 'Girl',
            'both' => $allowBoth ? 'Neutral / both' : 'Not available',
            default => 'Not available',
        };
    }
}
