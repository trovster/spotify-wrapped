(() => {
    const button = document.querySelector('[data-story-download]');
    const deck = document.querySelector('.deck');
    const track = deck?.querySelector('[data-track]');

    if (!button || !deck || !track) return;

    const width = 1080;
    const height = 1920;
    const slides = [...track.querySelectorAll('.card')];
    const status = deck.querySelector('[data-download-status]') ?? (() => {
        const region = document.createElement('p');
        region.setAttribute('aria-live', 'polite');
        region.className = 'sr-only';
        deck.append(region);
        return region;
    })();
    const label = button.querySelector('[data-download-label]');
    const originalLabel = label?.textContent ?? button.textContent;
    const originalAriaLabel = button.getAttribute('aria-label');

    const visibleSlideIndex = () => {
        const active = [...deck.querySelectorAll('[data-progress] > span')].findIndex((segment) => segment.classList.contains('is-active'));
        if (active >= 0 && active < slides.length) return active;

        const fromHash = Number(location.hash.match(/^#card-(\d+)$/)?.[1]) - 1;
        if (Number.isInteger(fromHash) && fromHash >= 0 && fromHash < slides.length) return fromHash;

        return Math.max(0, slides.findIndex((slide) => slide.getBoundingClientRect().left >= track.getBoundingClientRect().left - 2));
    };

    const setButtonState = (busy) => {
        button.disabled = busy;
        button.setAttribute('aria-busy', String(busy));
        if (label) label.textContent = busy ? 'Creating PNG…' : originalLabel;
        else button.textContent = busy ? 'Creating PNG…' : originalLabel;
        if (originalAriaLabel !== null) button.setAttribute('aria-label', busy ? 'Creating story PNG' : originalAriaLabel);
    };

    const readyFonts = async () => {
        if (!document.fonts) return;
        await Promise.race([
            Promise.allSettled([
                document.fonts.ready,
                document.fonts.load('800 72px Unbounded'),
                document.fonts.load('900 160px Unbounded'),
            ]),
            new Promise((resolve) => setTimeout(resolve, 4000)),
        ]);
    };

    const loadArtwork = (slide) => new Promise((resolve) => {
        const source = slide.querySelector('.card__image');
        const url = source?.dataset.exportSrc || source?.currentSrc || source?.src;
        if (!url) {
            resolve(null);
            return;
        }

        const artwork = new Image();
        let complete = false;
        const finish = (image) => {
            if (complete) return;
            complete = true;
            clearTimeout(timeout);
            artwork.onload = null;
            artwork.onerror = null;
            resolve(image);
        };
        const timeout = setTimeout(() => finish(null), 10000);
        artwork.crossOrigin = 'anonymous';
        artwork.onload = () => finish(artwork);
        artwork.onerror = () => finish(null);
        artwork.src = url;
    });

    const wrapLines = (context, text, maxWidth) => {
        const words = text.trim().replace(/\s+/g, ' ').split(' ').filter(Boolean);
        const lines = [];
        let line = '';

        for (const word of words) {
            const candidate = line ? `${line} ${word}` : word;
            if (context.measureText(candidate).width <= maxWidth) {
                line = candidate;
                continue;
            }

            if (line) lines.push(line);
            line = word;
            while (context.measureText(line).width > maxWidth && line.length > 1) {
                let split = line.length - 1;
                while (split > 1 && context.measureText(line.slice(0, split) + '‐').width > maxWidth) split--;
                lines.push(line.slice(0, split) + '‐');
                line = line.slice(split);
            }
        }

        if (line) lines.push(line);
        return lines;
    };

    const font = (weight, size, family = 'Unbounded, Arial, sans-serif') => `${weight} ${size}px ${family}`;

    const fitSingleLine = (context, text, startSize, minSize, maxWidth, tracking) => {
        for (let size = startSize; size >= minSize; size -= 2) {
            context.font = font(900, size);
            if ('letterSpacing' in context) context.letterSpacing = `${size * tracking}px`;
            if (context.measureText(text).width <= maxWidth) return size;
        }
        return minSize;
    };

    const layoutCopy = (context, headline, body) => {
        const maxWidth = 920;
        const availableHeight = 586;
        let result;

        for (let headlineSize = 78; headlineSize >= 34; headlineSize -= 2) {
            const bodySize = Math.max(21, Math.round(headlineSize * .47));
            context.font = font(800, headlineSize);
            const headlineLines = wrapLines(context, headline, maxWidth);
            context.font = font(500, bodySize, 'Inter, Arial, sans-serif');
            const bodyLines = wrapLines(context, body, maxWidth);
            const headlineHeight = headlineLines.length * headlineSize * 1.2;
            const bodyHeight = bodyLines.length * bodySize * 1.48;
            result = { headlineLines, bodyLines, headlineSize, bodySize, headlineHeight, bodyHeight };
            if (headlineHeight + bodyHeight + 37 <= availableHeight) break;
        }

        return result;
    };

    const drawBackground = (context, slide) => {
        const style = getComputedStyle(slide);
        const dark = style.getPropertyValue('--c1').trim() || '#20194f';
        const light = style.getPropertyValue('--c2').trim() || '#5a4da4';
        const glow = style.getPropertyValue('--glow').trim() || '#b1a5ff';
        const gradient = context.createLinearGradient(0, 0, width, height);
        gradient.addColorStop(0, dark);
        gradient.addColorStop(.55, dark);
        gradient.addColorStop(1, light);
        context.fillStyle = gradient;
        context.fillRect(0, 0, width, height);

        const bloom = context.createRadialGradient(900, 250, 50, 900, 250, 1000);
        bloom.addColorStop(0, `${glow}66`);
        bloom.addColorStop(1, `${glow}00`);
        context.fillStyle = bloom;
        context.fillRect(0, 0, width, height);

        context.save();
        context.strokeStyle = glow;
        context.lineWidth = 8;
        context.globalAlpha = .31;
        for (let radius = 120; radius <= 1250; radius += 125) {
            context.beginPath();
            context.arc(850, 405, radius, 0, Math.PI * 2);
            context.stroke();
        }
        context.restore();
        return glow;
    };

    const drawRing = (context, glow) => {
        const x = width / 2;
        const y = 575;

        context.save();
        context.strokeStyle = glow;
        context.globalAlpha = .45;
        context.lineWidth = 36;
        context.shadowColor = glow;
        context.shadowBlur = 90;
        context.beginPath();
        context.arc(x, y, 290, 0, Math.PI * 2);
        context.stroke();
        context.shadowBlur = 0;
        context.globalAlpha = .64;
        context.stroke();

        context.globalAlpha = .2;
        context.strokeStyle = '#000';
        context.lineWidth = 38;
        context.beginPath();
        context.arc(x, y, 253, 0, Math.PI * 2);
        context.stroke();

        context.globalAlpha = .65;
        context.strokeStyle = glow;
        context.lineWidth = 20;
        context.beginPath();
        context.arc(x, y, 225, 0, Math.PI * 2);
        context.stroke();
        context.restore();
    };

    const drawArtwork = (context, artwork, glow, hasStat) => {
        if (!artwork || !hasStat) drawRing(context, glow);
        if (!artwork) return;

        const x = width / 2;
        const y = 575;
        const radius = hasStat ? 160 : 205;
        context.save();
        context.shadowColor = '#0009';
        context.shadowBlur = 42;
        context.shadowOffsetY = 20;
        context.fillStyle = '#f5f6ffb3';
        context.beginPath();
        context.arc(x, y, radius + 12, 0, Math.PI * 2);
        context.fill();
        context.shadowColor = 'transparent';
        context.shadowBlur = 0;
        context.shadowOffsetY = 0;
        context.strokeStyle = glow;
        context.globalAlpha = .3;
        context.lineWidth = 19;
        context.beginPath();
        context.arc(x, y, radius + 24, 0, Math.PI * 2);
        context.stroke();
        context.globalAlpha = 1;
        context.beginPath();
        context.arc(x, y, radius, 0, Math.PI * 2);
        context.clip();
        const sourceWidth = artwork.naturalWidth;
        const sourceHeight = artwork.naturalHeight;
        const scale = Math.max(radius * 2 / sourceWidth, radius * 2 / sourceHeight);
        context.drawImage(artwork, x - sourceWidth * scale / 2, y - sourceHeight * scale / 2, sourceWidth * scale, sourceHeight * scale);
        context.restore();
    };

    const drawCenteredText = (context, text, top) => {
        const metrics = context.measureText(text);
        const fontSize = Number(context.font.match(/(\d+(?:\.\d+)?)px/)?.[1] || 30);
        const ascent = metrics.actualBoundingBoxAscent || fontSize * .8;
        const descent = metrics.actualBoundingBoxDescent || 0;
        const left = metrics.actualBoundingBoxLeft || 0;
        const right = metrics.actualBoundingBoxRight || metrics.width;
        context.fillText(text, width / 2 + (left - right) / 2, top + ascent);
        return ascent + descent;
    };

    const drawStat = (context, slide, hasArtwork) => {
        const value = slide.querySelector('.card__stat strong')?.textContent.trim();
        const unit = slide.querySelector('.card__stat span')?.textContent.trim();
        if (!value) return;

        context.save();
        context.fillStyle = '#f5f6ff';
        context.textAlign = 'left';
        context.textBaseline = 'alphabetic';
        const tracking = hasArtwork ? -.035 : -.1;
        const size = fitSingleLine(context, value, hasArtwork ? 150 : 225, 80, 900, tracking);
        context.font = font(900, size);
        if ('letterSpacing' in context) context.letterSpacing = `${size * tracking}px`;
        const valueMetrics = context.measureText(value);
        const valueHeight = (valueMetrics.actualBoundingBoxAscent || size * .8) + (valueMetrics.actualBoundingBoxDescent || 0);
        const gap = unit ? (hasArtwork ? 22 : 26) : 0;
        let unitHeight = 0;
        if (unit) {
            context.font = font(800, 30, 'Inter, Arial, sans-serif');
            if ('letterSpacing' in context) context.letterSpacing = '3px';
            const unitMetrics = context.measureText(unit.toLocaleUpperCase());
            unitHeight = (unitMetrics.actualBoundingBoxAscent || 24) + (unitMetrics.actualBoundingBoxDescent || 0);
        }
        const top = (hasArtwork ? 885 : 575) - (valueHeight + gap + unitHeight) / 2;
        context.shadowColor = '#0008';
        context.shadowBlur = 48;
        context.shadowOffsetY = 12;
        context.font = font(900, size);
        if ('letterSpacing' in context) context.letterSpacing = `${size * tracking}px`;
        drawCenteredText(context, value, top);
        if (unit) {
            context.font = font(800, 30, 'Inter, Arial, sans-serif');
            if ('letterSpacing' in context) context.letterSpacing = '3px';
            drawCenteredText(context, unit.toLocaleUpperCase(), top + valueHeight + gap);
        }
        context.restore();
    };

    const drawText = (context, slide, dateRange) => {
        const eyebrow = slide.querySelector('.card__eyebrow')?.textContent.trim().replace(/\s+/g, ' ') || '';
        const index = slide.querySelector('.card__index')?.textContent.trim() || '';
        const headline = slide.querySelector('.card__copy h2')?.textContent.trim() || '';
        const body = slide.querySelector('.card__copy p')?.textContent.trim() || '';
        const layout = layoutCopy(context, headline, body);

        context.textBaseline = 'top';
        context.textAlign = 'left';
        context.fillStyle = '#f5f6ff';
        context.font = font(800, 32, 'Inter, Arial, sans-serif');
        context.fillText(eyebrow.toLocaleUpperCase(), 80, 193, 920);

        context.font = font(800, 29, 'Inter, Arial, sans-serif');
        context.fillText(index, 80, 1060);

        let y = 1115;
        context.font = font(800, layout.headlineSize);
        for (const line of layout.headlineLines) {
            context.fillText(line, 80, y);
            y += layout.headlineSize * 1.2;
        }

        y += 37;
        context.fillStyle = '#f5f6ffe8';
        context.font = font(500, layout.bodySize, 'Inter, Arial, sans-serif');
        const bodyLineHeight = layout.bodySize * 1.48;
        for (const line of layout.bodyLines) {
            if (y + bodyLineHeight > 1710) break;
            context.fillText(line, 80, y);
            y += bodyLineHeight;
        }

        context.fillStyle = '#ffffff75';
        context.fillRect(80, 1760, 920, 2);
        context.fillStyle = '#f5f6ff';
        context.font = font(700, 29, 'Inter, Arial, sans-serif');
        context.fillText(dateRange, 80, 1800, 920);
    };

    const render = (slide, artwork, dateRange) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Canvas is unavailable');

        const glow = drawBackground(context, slide);

        context.textBaseline = 'top';
        context.font = font(900, 58);
        context.fillStyle = '#f5f6ff';
        context.fillText('Listen', 80, 93);
        const brandWidth = context.measureText('Listen').width;
        context.fillStyle = '#ff6a3d';
        context.fillText('.', 80 + brandWidth, 93);

        drawArtwork(context, artwork, glow, Boolean(slide.querySelector('.card__stat strong')));
        drawStat(context, slide, Boolean(artwork));
        drawText(context, slide, dateRange);
        return canvas;
    };

    const toBlob = (canvas) => new Promise((resolve, reject) => {
        try {
            canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('PNG encoding failed')), 'image/png');
        } catch (error) {
            reject(error);
        }
    });

    const download = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        deck.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    };

    button.addEventListener('click', async () => {
        if (button.disabled) return;
        setButtonState(true);
        status.textContent = 'Creating your story PNG.';

        try {
            const index = visibleSlideIndex();
            const slide = slides[index];
            if (!slide) throw new Error('Story card is unavailable');

            const [artwork] = await Promise.all([loadArtwork(slide), readyFonts()]);
            const dateRange = deck.dataset.dateRange || deck.querySelector('.deck-brand .sr-only')?.textContent.trim() || '';
            let includedArtwork = Boolean(artwork);
            let blob;

            try {
                blob = await toBlob(render(slide, artwork, dateRange));
            } catch (error) {
                if (!artwork) throw error;
                includedArtwork = false;
                blob = await toBlob(render(slide, null, dateRange));
            }

            const wrapId = (deck.dataset.wrapId || 'story').replace(/[^a-zA-Z0-9_-]/g, '');
            download(blob, `wrapped-${wrapId}-${index + 1}.png`);
            status.textContent = includedArtwork || !slide.querySelector('.card__image')
                ? 'Story PNG downloaded.'
                : 'Story PNG downloaded without artwork because the image was unavailable.';
        } catch (error) {
            status.textContent = 'Could not download the PNG. Try again, or reload this page if the problem continues.';
            console.error('Story PNG export failed:', error);
        } finally {
            setButtonState(false);
        }
    });
})();
