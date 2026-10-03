# Order marketing attribution

`order_source` is the creation channel (website/mobile/WhatsApp/manual), not the
customer's acquisition source. It remains unchanged for existing consumers,
creation/edit forms, reports, and Agent APIs.

The admin order list displays marketing source, ad name, and campaign name.
Checkout and individual story detail pages additionally display ad-set name,
campaign/ad-set/ad IDs, UTM source/medium/campaign/content/term, referral domain,
landing domain, and whether a Meta click identifier was recorded. Raw URLs and
`fbclid` values are not displayed: URLs can contain private tokens. Existing
order CSV columns keep their meaning; marketing columns are appended.

## Collection and persistence

Allowed query parameters:

```text
utm_source, utm_medium, utm_campaign, utm_content, utm_term,
campaign_id, adset_id, ad_id, campaign_name, adset_name, ad_name, fbclid
```

Names and IDs must be provided by the incoming link. All fields are inert text,
bounded to their database column lengths, escaped in HTML and formula-protected
in CSV. Arrays and unresolved `{{...}}` macros are ignored. Ordinary navigation
does not erase tracking. The existing first-tracked-touch session policy is
retained, with fields kept together: a later campaign cannot fill a missing ad
name/ID belonging to an earlier campaign. The initial landing/referrer evidence
is retained separately. This is session-level attribution, not a cross-device
identity solution or Meta Ads Manager's attribution model.

Cart data includes nullable ad/campaign/ad-set names (additive migration).
Successful checkout copies attribution into each order's
`delivery_details.marketing_attribution`, including story-only, product-only,
and mixed checkouts. Orders continue to retain this evidence when edited.
Admin reads prefer that snapshot and can use the related converted visitor cart
for legacy orders without a snapshot. Reads never backfill or rewrite orders.
Different sources in a merged checkout are displayed independently.

## Classification and limitations

- Meta source/referral/click evidence plus paid medium or an ad ID: **إعلان ميتا**.
- Meta evidence alone: **ميتا — نوع الزيارة غير مؤكد**. `fbclid` alone does not
  prove an ad, and does not encode its name.
- Other UTM sources or external referral domains: display the captured source.
- Recorded landing with no source/referral evidence: **مباشر / بدون تتبع**.
  This includes tracking stripped by privacy settings; it cannot prove that the
  customer never saw an ad.
- Historical website orders without evidence: **غير معروف — لا توجد بيانات تتبع**.
- Manual channels without marketing evidence keep their existing channel label.

An ad name is never inferred from `utm_content`, a campaign name, product name,
or a click ID. Missing names remain explicitly unavailable. Existing links that
send campaign/content codes still show those codes as UTM values, not ad names.
Names represent the supplied link value at purchase time, not necessarily the
current name after renaming an ad in Ads Manager. No Marketing API credentials,
external API calls, or retrospective attribution guesses are introduced.

## Configure advertising links

Use Ads Manager's **URL parameters** / URL parameter builder to supply:

```text
utm_source=meta
utm_medium=paid_social
utm_campaign=<campaign tracking value>
campaign_name=<campaign name>
campaign_id=<campaign ID>
adset_name=<ad-set name>
adset_id=<ad-set ID>
ad_name=<ad name>
ad_id=<ad ID>
```

Choose the corresponding dynamic values in Meta's builder where supported;
otherwise supply URL-encoded literal values. Open a real test landing link and
confirm values arrive expanded rather than as literal placeholders before
publishing. Keep existing UTM campaign/content values if other reports depend
on them. Do not put private customer details or secrets in advertising URLs.
See Meta's help (requires login):
https://www.facebook.com/business/help/2360940870872492

After deploying and migrating, test a tagged checkout and inspect its admin
details. Older orders can expose only evidence actually saved at the time;
this release cannot reconstruct missing historical names or tracking.
