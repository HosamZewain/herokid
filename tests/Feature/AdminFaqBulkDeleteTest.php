<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFaqBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_faq_index_renders_bulk_delete_controls(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $faq = FaqItem::create([
            'question' => 'هل يمكن حذف أكثر من سؤال؟',
            'answer' => 'نعم.',
            'sort_order' => 1,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.faqs.index'))
            ->assertOk()
            ->assertSee('id="faq-bulk-delete-form"', false)
            ->assertSee('data-faq-select-all', false)
            ->assertSee('name="faq_ids[]"', false)
            ->assertSee('value="' . $faq->id . '"', false)
            ->assertSee('حذف المحدد');
    }

    public function test_admin_can_bulk_delete_selected_faqs(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $first = FaqItem::create([
            'question' => 'السؤال الأول',
            'answer' => 'الإجابة الأولى',
            'sort_order' => 1,
            'active' => true,
        ]);
        $second = FaqItem::create([
            'question' => 'السؤال الثاني',
            'answer' => 'الإجابة الثانية',
            'sort_order' => 2,
            'active' => true,
        ]);
        $kept = FaqItem::create([
            'question' => 'السؤال الثالث',
            'answer' => 'الإجابة الثالثة',
            'sort_order' => 3,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.faqs.bulk-destroy'), [
                'faq_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('admin.faqs.index'))
            ->assertSessionHas('success', 'تم حذف 2 سؤال بنجاح.');

        $this->assertDatabaseMissing('faq_items', ['id' => $first->id]);
        $this->assertDatabaseMissing('faq_items', ['id' => $second->id]);
        $this->assertDatabaseHas('faq_items', ['id' => $kept->id]);
    }
}
