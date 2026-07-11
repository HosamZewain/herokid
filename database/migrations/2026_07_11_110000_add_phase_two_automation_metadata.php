<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_story_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('production_story_versions', 'production_automation_run_id')) {
                $table->foreignId('production_automation_run_id')->nullable()->after('production_project_id');
            }
            if (! Schema::hasColumn('production_story_versions', 'production_automation_step_id')) {
                $table->foreignId('production_automation_step_id')->nullable()->after('production_automation_run_id');
            }
            if (! Schema::hasColumn('production_story_versions', 'production_automation_attempt_id')) {
                $table->foreignId('production_automation_attempt_id')->nullable()->after('production_automation_step_id');
            }
            if (! Schema::hasColumn('production_story_versions', 'input_fingerprint')) {
                $table->string('input_fingerprint')->nullable()->after('full_story_content')->index();
            }
            if (! Schema::hasColumn('production_story_versions', 'output_fingerprint')) {
                $table->string('output_fingerprint')->nullable()->after('input_fingerprint')->index();
            }
            if (! Schema::hasColumn('production_story_versions', 'automation_metadata_json')) {
                $table->json('automation_metadata_json')->nullable()->after('output_fingerprint');
            }
            if (! Schema::hasColumn('production_story_versions', 'validation_summary_json')) {
                $table->json('validation_summary_json')->nullable()->after('automation_metadata_json');
            }
        });

        Schema::table('production_character_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('production_character_profiles', 'production_automation_run_id')) {
                $table->foreignId('production_automation_run_id')->nullable()->after('production_project_id');
            }
            if (! Schema::hasColumn('production_character_profiles', 'production_automation_step_id')) {
                $table->foreignId('production_automation_step_id')->nullable()->after('production_automation_run_id');
            }
            if (! Schema::hasColumn('production_character_profiles', 'production_automation_attempt_id')) {
                $table->foreignId('production_automation_attempt_id')->nullable()->after('production_automation_step_id');
            }
            if (! Schema::hasColumn('production_character_profiles', 'input_fingerprint')) {
                $table->string('input_fingerprint')->nullable()->after('approved_reference_photos')->index();
            }
            if (! Schema::hasColumn('production_character_profiles', 'output_fingerprint')) {
                $table->string('output_fingerprint')->nullable()->after('input_fingerprint')->index();
            }
            if (! Schema::hasColumn('production_character_profiles', 'automation_metadata_json')) {
                $table->json('automation_metadata_json')->nullable()->after('output_fingerprint');
            }
            if (! Schema::hasColumn('production_character_profiles', 'validation_summary_json')) {
                $table->json('validation_summary_json')->nullable()->after('automation_metadata_json');
            }
        });

        Schema::table('production_story_versions', function (Blueprint $table) {
            $table->foreign('production_automation_run_id', 'psv_auto_run_fk')->references('id')->on('production_automation_runs')->nullOnDelete();
            $table->foreign('production_automation_step_id', 'psv_auto_step_fk')->references('id')->on('production_automation_steps')->nullOnDelete();
            $table->foreign('production_automation_attempt_id', 'psv_auto_attempt_fk')->references('id')->on('production_automation_attempts')->nullOnDelete();
        });

        Schema::table('production_character_profiles', function (Blueprint $table) {
            $table->foreign('production_automation_run_id', 'pcp_auto_run_fk')->references('id')->on('production_automation_runs')->nullOnDelete();
            $table->foreign('production_automation_step_id', 'pcp_auto_step_fk')->references('id')->on('production_automation_steps')->nullOnDelete();
            $table->foreign('production_automation_attempt_id', 'pcp_auto_attempt_fk')->references('id')->on('production_automation_attempts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'production_story_versions' => [
                'production_automation_run_id' => 'psv_auto_run_fk',
                'production_automation_step_id' => 'psv_auto_step_fk',
                'production_automation_attempt_id' => 'psv_auto_attempt_fk',
            ],
            'production_character_profiles' => [
                'production_automation_run_id' => 'pcp_auto_run_fk',
                'production_automation_step_id' => 'pcp_auto_step_fk',
                'production_automation_attempt_id' => 'pcp_auto_attempt_fk',
            ],
        ] as $tableName => $foreignNames) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $foreignNames): void {
                foreach ($foreignNames as $column => $foreignName) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropForeign($foreignName);
                    }
                }

                foreach ([
                    'production_automation_run_id',
                    'production_automation_step_id',
                    'production_automation_attempt_id',
                    'input_fingerprint',
                    'output_fingerprint',
                    'automation_metadata_json',
                    'validation_summary_json',
                ] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
