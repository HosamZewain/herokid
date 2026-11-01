<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PuzzleGeneration;
use Illuminate\Support\Facades\Storage;

class PuzzleGenerationController extends Controller
{
    public function image(Order $order, PuzzleGeneration $run)
    {
        abort_unless($run->order_id === $order->id && $run->output_path && $run->disk, 404);

        return Storage::disk($run->disk)->response($run->output_path, basename($run->output_path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
