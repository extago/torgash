/*
 * Yandex Metrica, told what happens on the page (torgash.metrika, PLAN §73).
 *
 * The page's own events, raised by their owners, become the counter's:
 *   torgash:cart-changed     e-commerce add / remove, goal `basket`
 *   torgash:order-placed     e-commerce purchase, goal `order` with its sum
 *   torgash:wishlist-changed goal `wishlist`
 *   torgash:one-click-opened goal `buy_with_one_click_form_opened`
 *   torgash:enquiry-sent     goal `call_request` for a call back
 * and an address the page put into the address bar itself ("Show more") is
 * counted as a page view.
 *
 * Products come in the books' currency (the events' `currency`); Metrica does
 * not convert, so each sum is multiplied by the rate of the counter's
 * currency. E-commerce data goes into the counter's container
 * (`dataLayerYM`), which the counter reads whenever it starts — after the
 * visitor's consent, if the shop asks for it; a goal is reached only by a
 * counter that is running.
 */
(function () {
    var tag = document.querySelector('script[data-metrika-counter]');
    if (!tag || window.torgashMetrika) return;
    window.torgashMetrika = true;

    var id = Number(tag.getAttribute('data-metrika-counter'));
    var goals = (tag.getAttribute('data-goals') || '').split(',').filter(Boolean);
    var ecommerce = tag.getAttribute('data-ecommerce') === '1';
    var currency = tag.getAttribute('data-currency');
    var rate = Number(tag.getAttribute('data-rate')) || 1;
    // Metrica's own limit for one push into the container.
    var LIMIT = 8000;

    window.dataLayerYM = window.dataLayerYM || [];

    function running() {
        return typeof window.ym === 'function';
    }

    function goal(name, params) {
        if (goals.indexOf(name) !== -1 && running()) window.ym(id, 'reachGoal', name, params || {});
    }

    function money(value) {
        return Math.round(Number(value || 0) * rate * 100) / 100;
    }

    function product(line) {
        var item = { id: String(line.id), name: line.name || '', price: money(line.price), quantity: Number(line.quantity) || 1 };
        if (line.brand) item.brand = line.brand;
        if (line.categories && line.categories.length) {
            item.category = line.categories.map(function (name) { return String(name).replace(/\//g, '|'); }).join('/');
        }
        return item;
    }

    function push(action, lines) {
        if (!ecommerce || !lines || !lines.length) return;
        var data = { ecommerce: { currencyCode: currency } };
        data.ecommerce[action] = { products: lines.map(product) };
        window.dataLayerYM.push(data);
    }

    // A purchase too big for one push goes as parts of one order, N-1, N-2…,
    // the order's sum split so that the parts add up to it.
    function purchase(order) {
        var products = (order.lines || []).map(product);
        var parts = [[]];
        products.forEach(function (item) {
            var part = parts[parts.length - 1];
            if (part.length && JSON.stringify(part.concat([item])).length > LIMIT) parts.push(part = []);
            part.push(item);
        });

        var goods = function (part) {
            return part.reduce(function (sum, item) { return sum + item.price * item.quantity; }, 0);
        };
        var total = money(order.total);
        var rest = total - parts.reduce(function (sum, part) { return sum + goods(part); }, 0);

        parts.forEach(function (part, i) {
            var action = { id: parts.length > 1 ? order.number + '-' + (i + 1) : String(order.number) };
            action.revenue = parts.length > 1 ? Math.round((goods(part) + (i === 0 ? rest : 0)) * 100) / 100 : total;
            if (order.coupon) action.coupon = order.coupon;
            window.dataLayerYM.push({ ecommerce: { currencyCode: currency, purchase: { actionField: action, products: part } } });
        });
    }

    document.addEventListener('torgash:cart-changed', function (event) {
        var detail = event.detail || {};
        push('add', detail.added);
        push('remove', detail.removed);
        if (detail.added && detail.added.length) goal('basket');
    });

    document.addEventListener('torgash:order-placed', function (event) {
        var order = event.detail || {};
        if (!order.number) return;
        if (ecommerce) purchase(order);
        goal('order', { order_price: money(order.total), currency: currency });
    });

    document.addEventListener('torgash:wishlist-changed', function (event) {
        if (event.detail && event.detail.added && event.detail.added.length) goal('wishlist');
    });

    document.addEventListener('torgash:one-click-opened', function () {
        goal('buy_with_one_click_form_opened');
    });

    document.addEventListener('torgash:enquiry-sent', function (event) {
        if (event.detail && event.detail.kind === 'callback') goal('call_request');
    });

    if (tag.getAttribute('data-hits') === '1') {
        var last = location.href;
        var hit = function () {
            if (location.href !== last && running()) window.ym(id, 'hit', location.href, { referer: last });
            last = location.href;
        };
        document.addEventListener('htmx:pushedIntoHistory', hit);
        document.addEventListener('htmx:replacedInHistory', hit);
    }
})();
