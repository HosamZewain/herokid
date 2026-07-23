<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareSettings;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareText;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChildIdentitySettingsController extends Controller
{
    public function edit(ChildIdentitySettings $settings, ChildIdentityShareSettings $shareSettings)
    {
        return view('admin.child-identities.settings', [
            'values' => [
                'enabled' => $settings->enabled(),
                'size' => $settings->size(),
                'quality' => $settings->quality(),
                'prompt' => $settings->promptTemplate(),
                'version' => $settings->promptVersion(),
                'limit' => $settings->customerSuccessfulLimit(),
                'processing_copy' => $settings->processingCopy(),
                'sharing' => [
                    'enabled' => $shareSettings->enabled(),
                    'channels' => $shareSettings->channels(),
                    'caption_ar' => $shareSettings->captionTemplate(),
                    'caption_en' => $shareSettings->englishCaptionTemplate(),
                    'hashtags' => $shareSettings->hashtags(),
                    'card_headline' => $shareSettings->cardHeadline(),
                    'card_cta' => $shareSettings->cardCta(),
                    'landing_title' => $shareSettings->landingTitle(),
                    'landing_description' => $shareSettings->landingDescription(),
                    'landing_cta' => $shareSettings->landingCta(),
                    'attribution_days' => $shareSettings->attributionDays(),
                    'allow_first_name' => $shareSettings->allowFirstName(),
                    'qr_enabled' => $shareSettings->qrEnabled(),
                    'feed_quality' => $shareSettings->quality('feed'),
                    'story_quality' => $shareSettings->quality('story'),
                    'template_version' => $shareSettings->templateVersion(),
                ],
            ],
        ]);
    }

    public function update(Request $request, ChildIdentityShareText $shareText)
    {
        $sharingSubmitted = $request->hasAny([
            'sharing_enabled', 'share_channels', 'share_caption_ar', 'share_caption_en',
            'share_hashtags', 'share_card_headline', 'share_card_cta',
            'share_landing_title', 'share_landing_description', 'share_landing_cta',
            'share_attribution_days', 'share_allow_first_name', 'share_qr_enabled',
            'share_feed_quality', 'share_story_quality', 'share_template_version',
        ]);
        $sharingRequired = $sharingSubmitted ? 'required' : 'sometimes';
        $rules = [
            'enabled' => ['nullable', 'boolean'],
            'image_size' => ['required', Rule::in(['1536x1024', '1024x1536', '1024x1024'])],
            'image_quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'prompt_template' => ['required', 'string', 'min:50', 'max:20000'],
            'prompt_version' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'processing_copy' => ['nullable', 'array'],
            'sharing_enabled' => ['nullable', 'boolean'],
            'share_channels' => ['nullable', 'array'],
            'share_caption_ar' => [$sharingRequired, 'string', 'max:5000'],
            'share_caption_en' => ['nullable', 'string', 'max:5000'],
            'share_hashtags' => [$sharingRequired, 'string', 'max:2000'],
            'share_card_headline' => [$sharingRequired, 'string', 'max:160'],
            'share_card_cta' => [$sharingRequired, 'string', 'max:160'],
            'share_landing_title' => [$sharingRequired, 'string', 'max:160'],
            'share_landing_description' => [$sharingRequired, 'string', 'max:500'],
            'share_landing_cta' => [$sharingRequired, 'string', 'max:160'],
            'share_attribution_days' => [$sharingRequired, 'integer', 'min:1', 'max:365'],
            'share_allow_first_name' => ['nullable', 'boolean'],
            'share_qr_enabled' => ['nullable', 'boolean'],
            'share_feed_quality' => [$sharingRequired, 'integer', 'min:70', 'max:96'],
            'share_story_quality' => [$sharingRequired, 'integer', 'min:70', 'max:96'],
            'share_template_version' => [$sharingRequired, 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ];

        foreach (ChildIdentitySettings::PROCESSING_COPY_DEFAULTS as $key => $default) {
            $rules["processing_copy.{$key}"] = ['sometimes', 'required', 'string', 'max:500'];
        }

        $validated = $request->validate($rules);
        $updates = [
            'child_identity_enabled' => $request->boolean('enabled') ? '1' : '0',
            'child_identity_image_size' => $validated['image_size'],
            'child_identity_image_quality' => $validated['image_quality'],
            'child_identity_prompt_template' => $validated['prompt_template'],
            'child_identity_prompt_version' => $validated['prompt_version'],
        ];

        if ($sharingSubmitted) {
            $shareText->validateTemplate($validated['share_caption_ar']);
            if (filled($validated['share_caption_en'] ?? null)) {
                $shareText->validateTemplate($validated['share_caption_en']);
            }
            $updates = array_merge($updates, [
                'child_identity_sharing_enabled' => $request->boolean('sharing_enabled') ? '1' : '0',
                'child_identity_share_caption_ar' => $validated['share_caption_ar'],
                'child_identity_share_caption_en' => $validated['share_caption_en'] ?? '',
                'child_identity_share_hashtags' => $shareText->normalizeHashtags($validated['share_hashtags']),
                'child_identity_share_card_headline' => $validated['share_card_headline'],
                'child_identity_share_card_cta' => $validated['share_card_cta'],
                'child_identity_share_landing_title' => $validated['share_landing_title'],
                'child_identity_share_landing_description' => $validated['share_landing_description'],
                'child_identity_share_landing_cta' => $validated['share_landing_cta'],
                'child_identity_share_attribution_days' => (string) $validated['share_attribution_days'],
                'child_identity_share_allow_first_name' => $request->boolean('share_allow_first_name') ? '1' : '0',
                'child_identity_share_qr_enabled' => $request->boolean('share_qr_enabled') ? '1' : '0',
                'child_identity_share_feed_quality' => (string) $validated['share_feed_quality'],
                'child_identity_share_story_quality' => (string) $validated['share_story_quality'],
                'child_identity_share_template_version' => $validated['share_template_version'],
            ]);
            foreach (['native', 'whatsapp', 'facebook', 'instagram', 'copy_link', 'copy_caption', 'download'] as $channel) {
                $updates["child_identity_share_channel_{$channel}"] = in_array(
                    $channel,
                    $validated['share_channels'] ?? [],
                    true,
                ) ? '1' : '0';
            }
        }

        foreach (($validated['processing_copy'] ?? []) as $key => $value) {
            if (array_key_exists($key, ChildIdentitySettings::PROCESSING_COPY_DEFAULTS)) {
                $updates["child_identity_processing_{$key}"] = $value;
            }
        }

        foreach ($updates as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $request->user()->id]);
        }

        AdminActivityLogger::log(
            'child_identity.settings_updated',
            'تحديث إعدادات هويات الأطفال.',
            properties: [
                'enabled' => $request->boolean('enabled'),
                'image_size' => $validated['image_size'],
                'image_quality' => $validated['image_quality'],
                'prompt_version' => $validated['prompt_version'],
                'processing_copy_updated' => array_keys($validated['processing_copy'] ?? []),
                'sharing_updated' => $sharingSubmitted,
                'sharing_enabled' => $sharingSubmitted ? $request->boolean('sharing_enabled') : null,
                'share_channels' => $sharingSubmitted ? ($validated['share_channels'] ?? []) : null,
                'share_template_version' => $validated['share_template_version'] ?? null,
            ],
        );

        return back()->with('success', 'تم حفظ إعدادات هويات الأطفال.');
    }
}
