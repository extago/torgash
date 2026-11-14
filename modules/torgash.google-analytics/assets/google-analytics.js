/*
 * Google Analytics 4, told what happens on the page (torgash.google-analytics, PLAN §73).
 *
 * The page's own events, raised by their owners, become GA4's recommended ones:
 *   torgash:cart-changed      add_to_cart / remove_from_cart
 *   torgash:wishlist-changed  add_to_wishlist
 *   torgash:checkout-started  begin_checkout
 *   torgash:order-placed      purchase, transaction_id = the order's number
 *   torgash:enquiry-sent      generate_lead, for the forms the shop ticked
 * by gtag() — or, through Tag Manager, into the data layer in GA4's format.
 * Nothing is sent before the tag runs (`torgashGoogleTag`, set by the tag
 * itself: after the visitor's consent where the shop waits for it).
 *
 * Products come in the books' currency (the events' `currency`); Google
 * Analytics converts. An address the page changed itself ("Show more") is
 * counted by Google's own enhanced measurement (browser history events), so
 * it is not sent from here a second time.
 */
(function () {
    var tag = document.querySelector('script[data-google-tag]');
    if (!tag || window.torgashGoogleAnalytics) return;
    window.torgashGoogleAnalytics = true;

    var manager = tag.getAttribute('data-manager') === '1';
    var ecommerce = tag.getAttribute('data-ecommerce') === '1';
    var bySku = tag.getAttribute('data-item-id') !== 'id';
    var leads = (tag.getAttribute('data-leads') || '').split(',').filter(Boolean);
    // Google's limit for one event.
    var MAX_ITEMS = 200;

    function send(name, params, commerce) {
        if (!window.torgashGoogleTag) return;
        window.dataLayer = window.dataLayer || [];

        if (manager) {
            if (commerce) {
                // Google's rule for a data layer: clear the last e-commerce object first.
                window.dataLayer.push({ ecommerce: null });
                window.dataLayer.push({ event: name, ecommerce: params });
            } else {
                var data = { event: name };
                for (var key in params) data[key] = params[key];
                window.dataLayer.push(data);
            }
        } else if (typeof window.gtag === 'function') {
            window.gtag('event', name, params);
        }
    }

    function money(value) {
        return Math.round(Number(value || 0) * 100) / 100;
    }

    function item(line) {
        var out = { item_id: String(bySku && line.sku ? line.sku : line.id), item_name: line.name || '' };
        if (line.brand) out.item_brand = line.brand;
        (line.categories || []).slice(0, 5).forEach(function (name, i) {
            out[i === 0 ? 'item_category' : 'item_category' + (i + 1)] = String(name);
        });
        out.price = money(line.price);
        out.quantity = Number(line.quantity) || 1;
        return out;
    }

    function commerce(name, lines, currency, extra) {
        if (!ecommerce || !lines || !lines.length) return;
        var items = lines.slice(0, MAX_ITEMS).map(item);
        var params = {
            currency: currency,
            value: money(items.reduce(function (sum, it) { return sum + it.price * it.quantity; }, 0)),
            items: items
        };
        for (var key in extra || {}) {
            if (extra[key] !== null && extra[key] !== undefined && extra[key] !== '') params[key] = extra[key];
        }
        send(name, params, true);
    }

    document.addEventListener('torgash:cart-changed', function (event) {
        var detail = event.detail || {};
        commerce('add_to_cart', detail.added, detail.currency);
        commerce('remove_from_cart', detail.removed, detail.currency);
    });

    document.addEventListener('torgash:wishlist-changed', function (event) {
        var detail = event.detail || {};
        commerce('add_to_wishlist', detail.added, detail.currency);
    });

    document.addEventListener('torgash:checkout-started', function (event) {
        var detail = event.detail || {};
        commerce('begin_checkout', detail.lines, detail.currency, { coupon: detail.coupon });
    });

    // The value is what the goods came to after the order's discounts:
    // Google wants delivery apart, as shipping.
    document.addEventListener('torgash:order-placed', function (event) {
        var order = event.detail || {};
        if (!order.number) return;
        commerce('purchase', order.lines, order.currency, {
            transaction_id: String(order.number),
            value: Math.max(0, money(order.total - order.shipping)),
            shipping: money(order.shipping),
            coupon: order.coupon
        });
    });

    document.addEventListener('torgash:enquiry-sent', function (event) {
        var kind = (event.detail && event.detail.kind) || '';
        if (kind && leads.indexOf(kind) !== -1) send('generate_lead', { lead_source: kind }, false);
    });
})();
