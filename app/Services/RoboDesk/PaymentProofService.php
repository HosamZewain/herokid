<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Models\OrderPaymentProof;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentProofService
{
    public function __construct(private readonly RoboDeskDispatcher $dispatcher) {}

    public function store(string $checkoutGroupKey, UploadedFile $file, array $context = []): OrderPaymentProof
    {
        if (! Order::query()->where('checkout_group_key', $checkoutGroupKey)->exists()) {
            throw ValidationException::withMessages(['checkout_reference' => 'Checkout reference was not found.']);
        }

        if (filled($context['message_id'] ?? null)) {
            $existing = OrderPaymentProof::query()->where('external_message_id', $context['message_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $uuid = (string) Str::uuid();
        $extension = strtolower($file->guessExtension() ?: 'bin');
        $path = $file->storeAs('robodesk/payment-proofs/'.$uuid, 'proof.'.$extension, 'local');

        $proof = OrderPaymentProof::query()->create([
            'uuid' => $uuid,
            'checkout_group_key' => $checkoutGroupKey,
            'source' => 'robodesk',
            'external_message_id' => $context['message_id'] ?? null,
            'external_conversation_id' => $context['conversation_id'] ?? null,
            'sender_phone' => $context['sender_phone'] ?? null,
            'disk' => 'local',
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
            'status' => 'pending',
            'metadata' => ['received_via' => 'robodesk_webhook'],
        ]);

        // Tell the customer their proof arrived. Reviewing it stays manual —
        // nothing here touches payment status or the payment ledger.
        $this->dispatcher->paymentProofReceived($proof);

        return $proof;
    }
}
