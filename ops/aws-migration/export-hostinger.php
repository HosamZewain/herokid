<?php
// Read-only production export: no source file writes; password is in the dump child environment only, never argv/logs/files.
chdir('/home/u470070883/domains/hero-kid.com/public_html');
require 'vendor/autoload.php';
$e=Dotenv\Dotenv::parse(file_get_contents('.env'));
if (($e['APP_ENV']??null)!=='production') throw new RuntimeException('Unexpected source environment');
$args=['/usr/bin/mysqldump','--no-defaults','--host='.$e['DB_HOST'],'--port='.(int)($e['DB_PORT']??3306),'--user='.$e['DB_USERNAME'],'--single-transaction','--quick','--hex-blob','--skip-lock-tables','--no-tablespaces','--default-character-set=utf8mb4','--skip-add-drop-table','--routines','--events','--triggers'];
if (getenv('MIGRATION_SCHEMA_ONLY')==='1') $args[]='--no-data';
$args[]='--';$args[]=$e['DB_DATABASE'];
$proc=proc_open($args,[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes,null,['MYSQL_PWD'=>$e['DB_PASSWORD'],'LC_ALL'=>'C']);
if (!is_resource($proc)) throw new RuntimeException('Unable to start export');
unset($e);
exit(proc_close($proc));
