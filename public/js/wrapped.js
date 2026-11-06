(() => {
    const track = document.querySelector('[data-track]');
    if (!track) return;

    const slides = [...track.querySelectorAll('.card')];
    const progress = [...document.querySelectorAll('[data-progress] > span')];
    const counter = document.querySelector('[data-counter]');
    const live = document.querySelector('[data-live]');
    const previous = document.querySelector('[data-prev]');
    const next = document.querySelector('[data-next]');
    const playback = document.querySelector('[data-playback]');
    const closeLink = document.querySelector('[data-close]');
    const deck = track.closest('.deck');
    const duration = 9000;
    let current = 0;
    let elapsed = 0;
    let previousFrame = null;
    let isPlaying = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let isClosing = false;

    const close = () => {
        if (isClosing) return;
        isClosing = true;
        location.assign(closeLink.href);
    };

    const showProgress = () => {
        progress.forEach((segment, index) => {
            const percent = index < current ? 100 : index > current ? 0 : Math.min(100, elapsed / duration * 100);
            segment.style.setProperty('--progress', `${percent}%`);
            segment.classList.toggle('is-active', index === current);
        });
    };

    const setPlaying = (playing) => {
        isPlaying = playing;
        playback.textContent = playing ? 'Pause' : 'Play';
        playback.setAttribute('aria-label', playing ? 'Pause story' : 'Play story');
        playback.setAttribute('aria-pressed', String(playing));
        previousFrame = null;
    };

    const update = (index) => {
        if (index === current && counter.textContent === `${index + 1} / ${slides.length}`) return;
        current = index;
        elapsed = 0;
        counter.textContent = `${index + 1} / ${slides.length}`;
        previous.setAttribute('aria-disabled', String(index === 0));
        next.setAttribute('aria-disabled', String(index === slides.length - 1));
        live.textContent = `${index + 1} of ${slides.length}: ${slides[index].querySelector('h2').textContent}`;
        history.replaceState(null, '', `#card-${index + 1}`);
        showProgress();
    };

    const go = (index) => {
        if (index < 0 || index >= slides.length || index === current) return;
        track.style.scrollBehavior = 'auto';
        track.scrollTo({ left: slides[index].offsetLeft - track.offsetLeft, behavior: 'auto' });
        requestAnimationFrame(() => { track.style.scrollBehavior = ''; });
        update(index);
    };

    previous.addEventListener('click', () => go(current - 1));
    next.addEventListener('click', () => go(current + 1));
    playback.addEventListener('click', () => setPlaying(!isPlaying));

    const observer = new IntersectionObserver((entries) => {
        const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
        if (visible) update(slides.indexOf(visible.target));
    }, { root: track, threshold: .6 });
    slides.forEach((slide) => observer.observe(slide));

    track.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, select, textarea') || String(getSelection())) return;
        const x = event.clientX - track.getBoundingClientRect().left;
        if (x < track.clientWidth / 3) go(current - 1);
        if (x > track.clientWidth * 2 / 3) go(current + 1);
    });

    document.addEventListener('click', (event) => {
        if (!deck.contains(event.target)) close();
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' || event.key === 'Esc' || event.code === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
            return;
        }
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (event.key === 'ArrowRight') { event.preventDefault(); go(current + 1); }
        if (event.key === 'ArrowLeft') { event.preventDefault(); go(current - 1); }
    }, true);

    document.addEventListener('visibilitychange', () => { previousFrame = null; });

    const tick = (now) => {
        if (previousFrame !== null && isPlaying && !document.hidden) {
            elapsed = Math.min(duration, elapsed + Math.min(now - previousFrame, 250));
            showProgress();
            if (elapsed === duration) {
                if (current < slides.length - 1) go(current + 1);
                else setPlaying(false);
            }
        }
        previousFrame = now;
        requestAnimationFrame(tick);
    };

    setPlaying(isPlaying);
    showProgress();
    requestAnimationFrame(tick);

    const initial = Number(location.hash.match(/^#card-(\d+)$/)?.[1] ?? 1) - 1;
    if (initial > 0 && initial < slides.length) requestAnimationFrame(() => go(initial));
})();
