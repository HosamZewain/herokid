<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('source_snapshot_json')->nullable();
            $table->text('production_notes')->nullable();
            $table->string('current_stage')->nullable()->index();
            $table->timestamp('sent_to_studio_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('production_story_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('title')->nullable();
            $table->string('target_age_group')->nullable();
            $table->json('educational_values_json')->nullable();
            $table->longText('full_story_content')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['production_project_id', 'version_number'], 'production_story_versions_project_version_unique');
        });

        Schema::create('production_character_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->unique()->constrained('production_projects')->cascadeOnDelete();
            $table->text('appearance_summary')->nullable();
            $table->text('hair_details')->nullable();
            $table->text('skin_tone')->nullable();
            $table->text('eye_color_traits')->nullable();
            $table->text('typical_expression')->nullable();
            $table->text('identity_rules')->nullable();
            $table->text('wardrobe_direction')->nullable();
            $table->text('approved_visual_style')->nullable();
            $table->text('negative_instructions')->nullable();
            $table->json('reference_photo_selection')->nullable();
            $table->json('approved_reference_photos')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('production_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->foreignId('production_story_version_id')->nullable()->constrained('production_story_versions')->nullOnDelete();
            $table->unsignedInteger('scene_number')->default(1);
            $table->string('title')->nullable();
            $table->longText('story_text')->nullable();
            $table->text('educational_value')->nullable();
            $table->text('visual_direction')->nullable();
            $table->text('child_action_pose')->nullable();
            $table->text('text_safe_area_notes')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('review_notes')->nullable();
            $table->string('base_scene_image_path')->nullable();
            $table->string('generated_child_image_path')->nullable();
            $table->string('approved_final_image_path')->nullable();
            $table->timestamps();

            $table->index(['production_project_id', 'scene_number']);
        });

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver')->unique();
            $table->boolean('is_active')->default(false);
            $table->string('configuration_reference')->nullable();
            $table->boolean('supports_text_to_image')->default(false);
            $table->boolean('supports_image_to_image')->default(false);
            $table->boolean('supports_editing')->default(false);
            $table->boolean('supports_upscaling')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('code');
            $table->string('display_name');
            $table->json('generation_capabilities_json')->nullable();
            $table->decimal('estimated_cost_per_output', 10, 4)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['ai_provider_id', 'code']);
        });

        Schema::create('scene_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->foreignId('production_scene_id')->nullable()->constrained('production_scenes')->nullOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('generation_mode')->nullable();
            $table->longText('prompt_snapshot')->nullable();
            $table->longText('negative_prompt_snapshot')->nullable();
            $table->json('input_assets_json')->nullable();
            $table->string('output_asset_path')->nullable();
            $table->json('output_metadata_json')->nullable();
            $table->decimal('estimated_cost', 10, 4)->nullable();
            $table->decimal('actual_cost', 10, 4)->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_project_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->string('asset_type');
            $table->string('label')->nullable();
            $table->string('file_path')->nullable();
            $table->json('metadata_json')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_qa_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->string('category');
            $table->string('item_key');
            $table->string('label');
            $table->boolean('is_mandatory')->default(true);
            $table->string('result')->default('not_reviewed');
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('override_allowed')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['production_project_id', 'item_key']);
        });

        Schema::create('production_project_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('description')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_project_activity_logs');
        Schema::dropIfExists('production_qa_checks');
        Schema::dropIfExists('production_project_assets');
        Schema::dropIfExists('scene_generation_jobs');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
        Schema::dropIfExists('production_scenes');
        Schema::dropIfExists('production_character_profiles');
        Schema::dropIfExists('production_story_versions');
        Schema::dropIfExists('production_projects');
    }
};
