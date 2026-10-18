export const siteLogoUrl = (doc = document) => doc.querySelector('meta[name="herokid-logo"]')?.content || '/site-logo/384.png';

export const drawContainedLogo = (context, logo, x, y, width, height) => {
    const sourceWidth = logo.naturalWidth || logo.width;
    const sourceHeight = logo.naturalHeight || logo.height;
    if (!sourceWidth || !sourceHeight) return;
    const ratio = Math.min(width / sourceWidth, height / sourceHeight);
    const drawWidth = sourceWidth * ratio;
    const drawHeight = sourceHeight * ratio;
    context.drawImage(logo, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight);
};
