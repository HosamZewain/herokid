<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WhatsAppSenderTypeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // MySQL DDL commits implicitly, so do not run this schema test in a
        // RefreshDatabase transaction or roll back unrelated application migrations.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_widening_preserves_existing_rows_and_rollback_never_truncates_metadata(): void
    {
        $migration = require database_path('migrations/2026_10_09_000200_widen_whatsapp_message_sender_type.php');
        $migration->down();
        $conversation = DB::table('whatsapp_conversations')->insertGetId([
            'account_key' => 'synthetic', 'phone_hash' => hash('sha256', 'synthetic'), 'phone' => 'synthetic-encrypted-phone',
        ]);
        foreach (['agent', null] as $index => $senderType) {
            DB::table('whatsapp_conversation_messages')->insert([
                'conversation_id' => $conversation, 'remote_hash' => hash('sha256', (string) $index),
                'remote_id' => 'synthetic-encrypted-id-'.$index, 'direction' => 'outbound',
                'body' => 'synthetic-encrypted-body', 'sender_type' => $senderType,
                'fingerprint' => hash('sha256', 'fingerprint-'.$index), 'sent_at' => '2026-10-09 10:20:00.001',
            ]);
        }
        $before = DB::table('whatsapp_conversation_messages')->orderBy('id')->get()->toJson();
        $migration->up();
        $this->assertSame($before, DB::table('whatsapp_conversation_messages')->orderBy('id')->get()->toJson());
        $id = DB::table('whatsapp_conversation_messages')->insertGetId([
            'conversation_id' => $conversation, 'remote_hash' => hash('sha256', 'long-sender'),
            'remote_id' => 'synthetic-long-id', 'direction' => 'outbound', 'sender_type' => str_repeat('s', 64),
            'fingerprint' => hash('sha256', 'long-fingerprint'), 'sent_at' => '2026-10-09 10:20:00.001',
        ]);
        $all = DB::table('whatsapp_conversation_messages')->orderBy('id')->get()->toJson();
        try {
            $migration->down();
            $this->fail('Rollback must refuse to truncate existing metadata.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No data was changed', $exception->getMessage());
        }
        $this->assertSame($all, DB::table('whatsapp_conversation_messages')->orderBy('id')->get()->toJson());
        DB::table('whatsapp_conversation_messages')->where('id', $id)->delete();
        $migration->down();
        $this->assertSame($before, DB::table('whatsapp_conversation_messages')->orderBy('id')->get()->toJson());
        $migration->up();
    }
}
