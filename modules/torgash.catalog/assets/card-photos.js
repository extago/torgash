/*
 * A card's photo follows the pointer (catalog's product-card partial): the
 * figure is split into one invisible strip per photo, and the strip under the
 * pointer is the photo shown; the marks under it follow. Leaving the figure
 * goes back to the first photo. Delegated from the document, so cards that
 * arrive over HTMX (the search window, "show more") work without being told.
 * A mouse only — a finger has no "over", and its tap is the link's.
 */
(function () {
    function show(figure, zone) {
        var img = figure.querySelector('img[data-card-photo]');

        if (!img || !zone) {
            return;
        }
        if (img.getAttribute('src') !== zone.dataset.src) {
            img.src = zone.dataset.src;
            img.alt = zone.dataset.alt || img.alt;
        }

        var index = Number(zone.dataset.cardPhotoZone);

        figure.querySelectorAll('.torgash-card-marks > span').forEach(function (mark, n) {
            mark.classList.toggle('is-active', n === index);
        });
    }

    document.addEventListener('pointerover', function (event) {
        if (event.pointerType && event.pointerType !== 'mouse') {
            return;
        }

        var zone = event.target.closest && event.target.closest('[data-card-photo-zone]');

        if (!zone) {
            return;
        }

        var figure = zone.closest('figure');

        // The first time the pointer arrives, fetch the rest, so moving along
        // does not wait for each photo in turn.
        if (!figure.dataset.cardPhotosLoaded) {
            figure.dataset.cardPhotosLoaded = '1';
            figure.querySelectorAll('[data-card-photo-zone]').forEach(function (other) {
                new Image().src = other.dataset.src;
            });
        }

        show(figure, zone);
    });

    document.addEventListener('pointerout', function (event) {
        var figure = event.target.closest && event.target.closest('figure');

        if (!figure || !figure.querySelector('[data-card-photo-zone]')) {
            return;
        }
        if (event.relatedTarget && figure.contains(event.relatedTarget)) {
            return;
        }

        show(figure, figure.querySelector('[data-card-photo-zone]'));
    });
})();
