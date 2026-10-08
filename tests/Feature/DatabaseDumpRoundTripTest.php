<?php

namespace Tests\Feature;

use App\Services\DatabaseExports\DatabaseDumpWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DatabaseDumpRoundTripTest extends TestCase
{
    public function test_native_dump_restores_schema_unicode_binary_views_triggers_routines_and_events(): void
    {
        $writer = app(DatabaseDumpWriter::class);
        $client = (new ExecutableFinder)->find('mysql') ?? (new ExecutableFinder)->find('mariadb');
        if (! $writer->available() || ! $client) {
            $this->markTestSkipped('Native MySQL dump and restore clients required.');
        }
        $original = config('database.default');
        $control = DB::connection($original);
        $settings = $control->getConfig();
        $suffix = strtolower(Str::random(12));
        $source = 'testing_export_source_'.$suffix;
        $restore = 'testing_export_restore_'.$suffix;
        $directory = sys_get_temp_dir().'/herokid-export-test-'.$suffix;
        mkdir($directory, 0700);
        try {
            foreach ([$source, $restore] as $database) {
                $control->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
            }
            config(['database.connections.export_source' => array_merge($settings, ['database' => $source, 'url' => null]),
                'database.connections.export_restore' => array_merge($settings, ['database' => $restore, 'url' => null]),
                'database.default' => 'export_source']);
            $db = DB::connection('export_source');
            $db->statement('CREATE TABLE fixture (id INT PRIMARY KEY, name TEXT, optional_text TEXT NULL, payload BLOB) ENGINE=InnoDB');
            $db->statement('CREATE TABLE child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES fixture(id)) ENGINE=InnoDB');
            $text = "اختبار تجريبي 😀\nquote ' slash \\";
            $binary = "\x00\x01\xff\x00";
            $db->insert('INSERT INTO fixture VALUES (?, ?, ?, ?)', [1, $text, null, $binary]);
            $db->insert('INSERT INTO child VALUES (1, 1)');
            $db->statement('CREATE VIEW fixture_view AS SELECT id, name FROM fixture');
            $db->unprepared('CREATE TRIGGER fixture_trigger BEFORE INSERT ON fixture FOR EACH ROW SET NEW.optional_text = COALESCE(NEW.optional_text, "trigger-test")');
            $db->unprepared('CREATE PROCEDURE fixture_procedure() SELECT COUNT(*) AS total FROM fixture');
            $db->unprepared('CREATE EVENT fixture_event ON SCHEDULE EVERY 1 DAY DISABLE DO UPDATE fixture SET id = id');
            $writer->write($directory.'/database.sql');
            $this->assertFileDoesNotExist($directory.'/client.cnf');
            $this->assertGreaterThan(0, filesize($directory.'/database.sql'));
            $this->assertStringNotContainsString('CREATE DATABASE', file_get_contents($directory.'/database.sql'));
            $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
            $credentials = $directory.'/restore.cnf';
            file_put_contents($credentials, "[client]\nuser=".$quote($settings['username'])."\npassword=".$quote($settings['password'] ?? '')."\nhost=".$quote($settings['host'])."\nport=".(int) ($settings['port'] ?? 3306)."\n");
            chmod($credentials, 0600);
            $input = fopen($directory.'/database.sql', 'rb');
            try {
                $process = new Process([$client, '--defaults-file='.$credentials, '--default-character-set=utf8mb4', '--', $restore]);
                $process->setInput($input)->setTimeout(90)->disableOutput();
                $this->assertSame(0, $process->run(), 'Native restore failed (output suppressed to protect credentials/data).');
            } finally {
                fclose($input);
            }
            $restored = DB::connection('export_restore');
            $row = $restored->table('fixture')->first();
            $this->assertSame($text, $row->name);
            $this->assertNull($row->optional_text);
            $this->assertSame($binary, $row->payload);
            $this->assertSame(1, $restored->table('child')->count());
            $this->assertSame($text, $restored->table('fixture_view')->value('name'));
            $this->assertSame(1, (int) $restored->select('CALL fixture_procedure()')[0]->total);
            $restored->insert('INSERT INTO fixture (id, name) VALUES (2, "next")');
            $this->assertSame('trigger-test', $restored->table('fixture')->where('id', 2)->value('optional_text'));
            $this->assertSame(1, $restored->table('information_schema.EVENTS')->where('EVENT_SCHEMA', $restore)->count());

            // Refuse a misleading lock-free backup when a nontransactional table exists.
            $db->statement('CREATE TABLE unsafe_fixture (id INT) ENGINE=MyISAM');
            try {
                $writer->write($directory.'/unsafe.sql');
                $this->fail('Nontransactional dump should be rejected.');
            } catch (RuntimeException $e) {
                $this->assertSame('NON_TRANSACTIONAL_TABLES', $e->getMessage());
            }
        } finally {
            config(['database.default' => $original]);
            DB::purge('export_source');
            DB::purge('export_restore');
            foreach ([$source, $restore] as $database) {
                $control->statement('DROP DATABASE IF EXISTS `'.$database.'`');
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
