<?php
// Builds an INACTIVE candidate only. Never modifies either running application's .env.
require '/var/www/herokid/vendor/autoload.php';
$root='/home/ubuntu/migration-20261029/';
$source=file_get_contents($root.'production.env.source');
$current=file_get_contents('/var/www/herokid/.env');
$overlay=file_get_contents('/home/ubuntu/migration-ops-prepared/production.env.overlay');
$expected=Dotenv\Dotenv::parse($source);
$aws=Dotenv\Dotenv::parse($current);
$values=Dotenv\Dotenv::parse($overlay);
$remove=array_merge(array_keys($values),['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_URL','DATABASE_URL','AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN','AWS_PROFILE','AWS_SHARED_CREDENTIALS_FILE','AWS_CONFIG_FILE','AWS_ENDPOINT']);
foreach($remove as $key) $source=preg_replace('/^'.preg_quote($key,'/').'=.*\R?/m','',$source);
$rawDb=[];
foreach(['DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD'] as $key) {
 if (preg_match('/^'.preg_quote($key,'/').'=(.*)$/m',$current,$m)) $rawDb[]=$key.'='.$m[1];
}
$candidate=rtrim($source)."\n".implode("\n",$rawDb)."\n".$overlay;
$parsed=Dotenv\Dotenv::parse($candidate);
if (($parsed['APP_KEY']??null)!==($expected['APP_KEY']??null) || ($parsed['DB_PASSWORD']??null)!==($aws['DB_PASSWORD']??null) || ($parsed['DB_USERNAME']??null)!==($aws['DB_USERNAME']??null) || $parsed['DB_DATABASE']!=='herokid' || $parsed['AWS_BUCKET']!=='herokid-prod-media-2026') throw new RuntimeException('Candidate verification failed');
foreach(['AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN'] as $key) if (!empty($parsed[$key])) throw new RuntimeException('Static AWS credentials prohibited');
$output=$root.'production.env.prepared';
if (file_exists($output)) throw new RuntimeException('Candidate already exists; inspect before replacing');
umask(0077);file_put_contents($output,$candidate);chmod($output,0600);
echo "INACTIVE_PRODUCTION_ENV_PREPARED APP_KEY_PRESERVED AWS_DB_CREDENTIALS_PRESERVED STATIC_AWS_CREDENTIALS_ABSENT\n";
