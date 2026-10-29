<?php
chdir('/var/www/herokid-staging');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
if (DB::connection()->getDatabaseName() !== 'herokid_staging' || config('filesystems.disks.s3.bucket') !== 'herokid-staging-media-645314607155') throw new RuntimeException('Staging identity mismatch');
$slug='migration-synthetic-20261029';
$pdf=(new Mpdf\Mpdf(['tempDir'=>storage_path('app/private/migration-processing')]))->Output('', 'S');
$image=imagecreatetruecolor(640,480); $color=imagecolorallocate($image,30,100,200); imagefill($image,0,0,$color); ob_start(); imagepng($image); $png=ob_get_clean(); imagedestroy($image);
$fixtures=[
 ['s3_private',"story-library/$slug/story.pdf",$pdf],
 ['s3_private',"story-library/$slug/cover.png",$png],
 ['studio_private',"settings/$slug/logo.png",$png],
 ['s3_private',"orders/$slug/protected.pdf",$pdf],
 ['local',"expenses/$slug/receipt.pdf",$pdf],
 ['public',"stories/$slug/catalog.png",$png],
 ['local',"migration-processing/$slug/probe.txt",'synthetic processing probe'],
];
foreach($fixtures as [$disk,$path,$bytes]) {
 if (!Storage::disk($disk)->put($path,$bytes)) throw new RuntimeException("Write failed: $disk");
 if (hash('sha256',Storage::disk($disk)->get($path))!==hash('sha256',$bytes)) throw new RuntimeException("Checksum mismatch: $disk");
 echo "$disk $path SHA256 ".hash('sha256',$bytes)."\n";
}
$story=App\Models\LibraryStory::updateOrCreate(['slug'=>$slug],[
 'uuid'=>(string)Illuminate\Support\Str::uuid(),'title'=>'Synthetic migration validation',
 'language'=>'en','reading_direction'=>'ltr','status'=>'published','published_at'=>now(),
 'disk'=>'s3_private','pdf_path'=>"story-library/$slug/story.pdf",'cover_path'=>"story-library/$slug/cover.png",'cover_mime'=>'image/png',
 'page_count'=>1,'file_size'=>strlen($pdf),'checksum'=>hash('sha256',$pdf),
]);
$job=(new App\Jobs\GeneratePublicImageVariants('public',"stories/$slug/catalog.png"))->onConnection('redis')->onQueue('migration-validation');
dispatch($job);
echo 'SYNTHETIC_REDIS_JOB_QUEUED PDF_BYTES '.strlen($pdf)."\n";
