<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqAccordionTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_and_faq_pages_render_csp_safe_faq_accordions(): void
    {
        FaqItem::create([
            'question' => 'هل يمكن فتح السؤال؟',
            'answer' => 'نعم، يظهر هذا الجواب عند الضغط.',
            'active' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-faq-toggle', false)
            ->assertSee('data-faq-answer', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee('x-data', false)
            ->assertDontSee('x-show', false);

        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('data-faq-toggle', false)
            ->assertSee('data-faq-answer', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee('x-data', false)
            ->assertDontSee('x-show', false);
    }
}
