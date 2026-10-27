/**
 * A gallery inside a text, on the storefront: `<div data-gallery>` of images
 * (written by torgash.editor's gallery node) becomes a stage that swipes, with
 * arrows on a computer, a "1/5", a strip of thumbnails beneath and a click
 * opening the shop's full-screen viewer (lightbox.mjs, `storefront: true`).
 *
 * Built from the images already on the page rather than from data in the
 * markup — the text holds nothing but <img> tags, so it reads the same to a
 * feed, the search index and a browser without this script (where app.css
 * shows the bare images as a strip that swipes). The thumbnails are the same
 * files as the slides: a content image has no conversions, and the browser
 * downloads each file once for both.
 *
 * Its icons and words are the page's (`<template data-content-gallery-kit>`,
 * printed by the footer); this file holds neither, like lightbox.mjs.
 */
import { openLightbox } from './lightbox.mjs';

const base = new URL('.', import.meta.url).href.replace(/\/$/, '');

/** The kit's icon templates, put where lightbox.mjs looks for them (once). */
function kit() {
    const holder = document.querySelector('template[data-content-gallery-kit]');

    if (holder && !holder.dataset.placed) {
        holder.dataset.placed = '1';

        // A product page draws the same templates for its own gallery; the
        // first of each name wins, and both say the same.
        holder.content.querySelectorAll('template[data-gallery-icon]').forEach((template) => {
            const name = template.dataset.galleryIcon;

            if (!document.querySelector(`template[data-gallery-icon="${name}"]`)) {
                document.body.append(template.cloneNode(true));
            }
        });
    }

    const icon = (name) => document.querySelector(`template[data-gallery-icon="${name}"]`);

    return {
        svg: (name) => icon(name)?.innerHTML.trim() ?? '',
        label: (name) => icon(name)?.dataset.label ?? '',
        words: holder?.dataset ?? {},
    };
}

function element(tag, className, attributes = {}) {
    const node = document.createElement(tag);
    node.className = className;
    Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));

    return node;
}

function enhance(box, { svg, label, words }) {
    const pictures = [...box.querySelectorAll(':scope > img')].filter((img) => img.getAttribute('src'));

    if (!pictures.length) return;

    box.dataset.galleryReady = '';

    const count = pictures.length;
    const items = pictures.map((img) => ({
        url: img.getAttribute('src'),
        thumb: img.getAttribute('src'),
        width: parseInt(img.getAttribute('width'), 10) || 0,
        height: parseInt(img.getAttribute('height'), 10) || 0,
        alt: img.alt || '',
    }));

    const stage = element('div', 'torgash-gallery-stage');
    const track = element('div', 'torgash-gallery-track');
    stage.append(track);

    const of = (n) => (words.photoOf || ':n / :count').replace(':n', n).replace(':count', count);

    pictures.forEach((img, i) => {
        const slide = element('button', 'torgash-gallery-slide', { type: 'button', 'aria-label': of(i + 1) });
        slide.append(img);
        slide.addEventListener('click', () => open(i));
        track.append(slide);
    });

    let active = 0;
    const thumbs = [];
    let arrows = [];
    let counter = null;

    if (count > 1) {
        arrows = [-1, 1].map((step) => {
            const name = step < 0 ? 'chevron-left' : 'chevron-right';
            const arrow = element('button', 'torgash-gallery-arrow', { type: 'button', 'data-step': step, 'aria-label': label(name), title: label(name) });
            arrow.innerHTML = svg(name);
            arrow.addEventListener('click', () => go(active + step));
            stage.append(arrow);

            return arrow;
        });

        counter = element('div', 'torgash-gallery-count', { 'aria-hidden': 'true' });
        stage.append(counter);

        const strip = element('div', 'torgash-gallery-thumbs');

        items.forEach((item, i) => {
            const thumb = element('button', 'torgash-gallery-thumb', { type: 'button', 'aria-label': of(i + 1) });
            const img = element('img', '', { src: item.thumb, alt: '', loading: 'lazy', decoding: 'async' });
            thumb.append(img);
            thumb.addEventListener('click', () => go(i));
            strip.append(thumb);
            thumbs.push(thumb);
        });

        box.append(stage, strip);
    } else {
        box.append(stage);
    }

    function draw() {
        thumbs.forEach((thumb, i) => thumb.setAttribute('aria-current', i === active ? 'true' : 'false'));
        arrows.forEach((arrow) => {
            const step = Number(arrow.dataset.step);
            arrow.disabled = active + step < 0 || active + step >= count;
        });

        if (counter) counter.innerHTML = svg('image') + `<span>${active + 1}/${count}</span>`;

        // Keep the lit thumbnail in view by scrolling the strip itself —
        // scrollIntoView would move the page too.
        const thumb = thumbs[active];
        const strip = thumb?.parentElement;

        if (thumb && strip) {
            if (thumb.offsetLeft < strip.scrollLeft) {
                strip.scrollTo({ left: thumb.offsetLeft - 2, behavior: 'smooth' });
            } else if (thumb.offsetLeft + thumb.offsetWidth > strip.scrollLeft + strip.clientWidth) {
                strip.scrollTo({ left: thumb.offsetLeft + thumb.offsetWidth - strip.clientWidth + 2, behavior: 'smooth' });
            }
        }
    }

    // The track is scrolled, not transformed: a swipe, an arrow and a
    // thumbnail all move the same thing, and `active` is read back from
    // where it came to rest.
    function go(i) {
        active = Math.max(0, Math.min(count - 1, i));
        track.scrollTo({ left: active * track.clientWidth, behavior: 'smooth' });
        draw();
    }

    let settling = null;

    track.addEventListener('scroll', () => {
        clearTimeout(settling);
        settling = setTimeout(() => {
            const at = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));

            if (at !== active) {
                active = at;
                draw();
            }
        }, 60);
    });

    function open(i) {
        openLightbox({
            items,
            index: i,
            base,
            storefront: true,
            title: document.querySelector('h1')?.textContent.trim() || '',
            onChange: (j) => go(j),
        });
    }

    draw();
}

/** Every gallery on the page not built yet — also the ones HTMX brings later. */
export function enhanceAll(root = document) {
    const boxes = root.querySelectorAll('[data-gallery]:not([data-gallery-ready])');

    if (!boxes.length) return;

    const parts = kit();
    boxes.forEach((box) => enhance(box, parts));
}
