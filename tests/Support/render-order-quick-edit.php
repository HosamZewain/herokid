<?php

use App\Models\Permission;
use App\Models\User;
use App\Support\RequestSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
request()->attributes->set(RequestSettings::class, []);
URL::forceRootUrl(getenv('QUICK_EDIT_TEST_URL'));
$admin = new User(['role' => 'admin']);
$admin->setRelation('permissions', new Collection([new Permission(['key' => 'orders.update'])]));
$admin->setRelation('adminRoles', new Collection);
Auth::setUser($admin);
$manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
echo '<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="sanitized-test-token">';
echo '<link rel="stylesheet" href="/build/'.$manifest['resources/css/app.css']['file'].'">';
echo '<script type="module" src="/build/'.$manifest['resources/js/app.js']['file'].'"></script></head><body class="p-4">';
echo '<button data-quick-open="contact">تعديل بيانات التواصل</button><button data-quick-open="add">إضافة منتج</button><button data-quick-open="add-story">إضافة قصة</button><button data-quick-open="item" data-item-id="9">تعديل منتج</button><button data-quick-open="story" data-order-id="1">تعديل قصة</button><button data-quick-open="remove" data-item-id="9">حذف منتج</button>';
echo view('admin.orders._quick-edit', ['group' => [
    'representative_id' => 1, 'trashed' => false, 'customer_name' => 'عميل اختبار', 'phone' => '01012345678', 'delivery' => [],
]])->render();
echo '</body></html>';
