(function () {
    'use strict';

    var apiRoot = (window.wssw_api && window.wssw_api.root) || '/';
    var currentCatSlug = (window.wssw_api && window.wssw_api.current_cat_slug) || '';
    var PAGE_KEY_RE = /(^|[-_])page$|^paged$/;

    var termLabels = {};   // "pa_x:slug" -> human label
    var catNodes = {};     // slug -> { name, link, parentLink }

    /* ---------------------------------------------------------------- state */

    function parseState() {
        var params = new URLSearchParams(window.location.search);
        var state = { attrs: {}, min: '', max: '', rest: [] };
        params.forEach(function (value, key) {
            if (key.indexOf('pa_') === 0 && key.length > 3) {
                state.attrs[key] = value.split(',').filter(Boolean);
            } else if (key === 'min_price') {
                state.min = value;
            } else if (key === 'max_price') {
                state.max = value;
            } else if (PAGE_KEY_RE.test(key)) {
                // page resets when filters change
            } else {
                state.rest.push([key, value]);
            }
        });
        return state;
    }

    function hasFilters(state) {
        if (state.min !== '' || state.max !== '') { return true; }
        for (var k in state.attrs) {
            if (state.attrs[k].length) { return true; }
        }
        return false;
    }

    function serialize(state) {
        var parts = [];
        Object.keys(state.attrs).forEach(function (tax) {
            if (state.attrs[tax].length) {
                parts.push(tax + '=' + encodeURIComponent(state.attrs[tax].join(',')));
            }
        });
        if (state.min !== '') { parts.push('min_price=' + encodeURIComponent(state.min)); }
        if (state.max !== '') { parts.push('max_price=' + encodeURIComponent(state.max)); }
        state.rest.forEach(function (pair) {
            if (!PAGE_KEY_RE.test(pair[0])) {
                parts.push(encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1]));
            }
        });
        return parts.length ? '?' + parts.join('&') : '';
    }

    function navigate(url) {
        window.location.href = url;
    }

    function stripFiltersFromSearch(search) {
        var params = new URLSearchParams(search);
        Array.from(params.keys()).forEach(function (key) {
            if (key.indexOf('pa_') === 0 || key === 'min_price' || key === 'max_price') {
                params.delete(key);
            }
        });
        var s = params.toString();
        return s ? '?' + s : '';
    }

    /* ------------------------------------------------------------ categories */

    function findPath(nodes, slug, path) {
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var next = path.concat([node]);
            if (node.slug === slug) { return next; }
            var deep = findPath(node.children || [], slug, next);
            if (deep) { return deep; }
        }
        return null;
    }

    function indexNodes(nodes, parentLink) {
        nodes.forEach(function (node) {
            node.parentLink = parentLink;
            catNodes[node.slug] = node;
            indexNodes(node.children || [], node.link);
        });
    }

    function buildCategoryList(nodes, pathSlugs) {
        var html = '<ul class="wssw-category-list">';
        nodes.forEach(function (node) {
            var hasKids = !!(node.children && node.children.length);
            var open = pathSlugs.indexOf(node.slug) !== -1 && hasKids;
            var current = pathSlugs.indexOf(node.slug) !== -1 && node.slug === currentCatSlug;
            html += '<li class="wssw-category-item' + (hasKids ? ' has-children' : '') +
                (open ? ' open' : '') + (current ? ' current' : '') + '">';
            html += '<div class="wssw-category-link-wrapper">';
            html += '<a href="' + node.link + '">' + escapeHtml(node.name) + '</a>';
            if (hasKids) { html += '<span class="wssw-toggle-icon" role="button" aria-label="Expandir">&#9660;</span>'; }
            html += '</div>';
            if (hasKids) {
                html += '<ul class="wssw-subcategory-list" id="cat-children-' + node.id + '">';
                html += buildCategoryList(node.children, pathSlugs).replace(/^<ul[^>]*>/, '').replace(/<\/ul>$/, '');
                html += '</ul>';
            }
            html += '</li>';
        });
        html += '</ul>';
        return html;
    }

    function loadCategories() {
        var root = document.getElementById('wssw-categories-root');
        if (!root) { return Promise.resolve(); }
        return fetch(apiRoot + 'categories?tree=1')
            .then(function (r) { return r.json(); })
            .then(function (tree) {
                indexNodes(tree, null);
                var path = currentCatSlug ? (findPath(tree, currentCatSlug, []) || []) : [];
                var slugs = path.map(function (n) { return n.slug; });
                root.innerHTML = buildCategoryList(tree, slugs);
            })
            .catch(function (err) { console.error(err); });
    }

    /* ------------------------------------------------------------- attributes */

    function loadAttributes() {
        var root = document.getElementById('wssw-attributes-root');
        if (!root) { return Promise.resolve(); }
        return fetch(apiRoot + 'attributes')
            .then(function (r) { return r.json(); })
            .then(function (groups) {
                var state = parseState();
                var html = '<div class="wssw-attributes-container">';
                groups.forEach(function (group) {
                    html += '<div class="wssw-attribute-group">';
                    html += '<h4 class="wssw-attribute-title">' + escapeHtml(group.label) + '</h4>';
                    html += '<div class="wssw-attribute-tags">';
                    group.terms.forEach(function (term) {
                        var key = group.taxonomy + ':' + term.slug;
                        termLabels[key] = term.name;
                        var selected = (state.attrs[group.taxonomy] || []).indexOf(term.slug) !== -1;
                        var href = location.pathname + attrUrl(state, group.taxonomy, term.slug);
                        html += '<a href="' + escapeAttr(href) + '" class="wssw-attribute-tag' +
                            (selected ? ' active' : '') + '" data-tax="' + escapeAttr(group.taxonomy) +
                            '" data-slug="' + escapeAttr(term.slug) + '">' + escapeHtml(term.name) + '</a>';
                    });
                    html += '</div></div>';
                });
                html += '</div>';
                root.innerHTML = html;
            })
            .catch(function (err) { console.error(err); });
    }

    function attrUrl(state, tax, slug) {
        var cur = (state.attrs[tax] || []).slice();
        var idx = cur.indexOf(slug);
        if (idx >= 0) {
            cur = cur.slice(0, idx).concat(cur.slice(idx + 1));
        } else {
            cur.push(slug);
        }
        var next = { attrs: Object.assign({}, state.attrs), min: state.min, max: state.max, rest: state.rest };
        next.attrs[tax] = cur;
        return serialize(next);
    }

    /* ------------------------------------------------------------------ price */

    function loadPrice() {
        var root = document.getElementById('wssw-price-root');
        if (!root) { return Promise.resolve(); }
        var url = apiRoot + 'price-range' + (currentCatSlug ? '?category=' + encodeURIComponent(currentCatSlug) : '');
        return fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var state = parseState();
                var minR = Math.floor(Number(data.min_price) || 0);
                var maxR = Math.ceil(Number(data.max_price) || 0);
                if (maxR <= minR) { maxR = minR + 1000; }
                var curMin = state.min !== '' ? Math.max(minR, Math.min(maxR, Number(state.min))) : minR;
                var curMax = state.max !== '' ? Math.max(minR, Math.min(maxR, Number(state.max))) : maxR;

                var html = '<div class="wssw-price-filter">';
                html += '<h4 class="wssw-attribute-title">Precio</h4>';
                html += '<div class="wssw-price-slider-container">';
                html += '<input type="range" id="wssw-price-min" class="wssw-price-slider" min="' + minR + '" max="' + maxR + '" value="' + curMin + '" aria-label="Precio mínimo">';
                html += '<input type="range" id="wssw-price-max" class="wssw-price-slider" min="' + minR + '" max="' + maxR + '" value="' + curMax + '" aria-label="Precio máximo">';
                html += '<div class="wssw-price-values">';
                html += '<span>Desde: <strong id="wssw-min-val">' + fmt(curMin) + '</strong></span>';
                html += '<span>Hasta: <strong id="wssw-max-val">' + fmt(curMax) + '</strong></span>';
                html += '</div>';
                html += '<button id="wssw-filter-btn" class="wssw-filter-button">Filtrar</button>';
                html += '</div></div>';
                root.innerHTML = html;

                var minS = document.getElementById('wssw-price-min');
                var maxS = document.getElementById('wssw-price-max');
                var minV = document.getElementById('wssw-min-val');
                var maxV = document.getElementById('wssw-max-val');

                function sync() {
                    var lo = Number(minS.value), hi = Number(maxS.value);
                    if (lo > hi) {
                        var tmp = lo; lo = hi; hi = tmp;
                        minS.value = lo; maxS.value = hi;
                    }
                    minV.textContent = fmt(lo);
                    maxV.textContent = fmt(hi);
                }
                minS.addEventListener('input', sync);
                maxS.addEventListener('input', sync);

                document.getElementById('wssw-filter-btn').addEventListener('click', function () {
                    var st = parseState();
                    var lo = Number(minS.value), hi = Number(maxS.value);
                    st.min = (lo > minR) ? String(lo) : '';
                    st.max = (hi < maxR) ? String(hi) : '';
                    navigate(location.pathname + serialize(st));
                });
            })
            .catch(function (err) { console.error(err); });
    }

    /* ------------------------------------------------------- active filters UI */

    function renderActiveFilters() {
        var wrap = document.querySelector('.wssw-active-filters');
        if (!wrap) { return; }
        var state = parseState();
        if (!hasFilters(state)) {
            wrap.hidden = true;
            wrap.innerHTML = '';
            removeResultsBar();
            return;
        }

        var chips = [];
        var cat = currentCatSlug ? catNodes[currentCatSlug] : null;
        if (cat) {
            chips.push(chipHtml(cat.name, cat.parentLink || location.pathname, 'category'));
        }
        Object.keys(state.attrs).forEach(function (tax) {
            state.attrs[tax].forEach(function (slug) {
                var label = termLabels[tax + ':' + slug] || slug;
                chips.push(chipHtml(label, location.pathname + attrUrl(state, tax, slug), tax + ' ' + slug));
            });
        });
        if (state.min !== '' || state.max !== '') {
            var label = 'Precio: ' + (state.min !== '' ? fmt(state.min) : '0') + ' – ' + fmt(state.max !== '' ? state.max : '∞');
            var priceState = { attrs: state.attrs, min: '', max: '', rest: state.rest };
            chips.push(chipHtml(label, location.pathname + serialize(priceState), 'price'));
        }

        wrap.innerHTML = chips.join('') +
            '<button type="button" class="wssw-clear-filters">Limpiar filtros</button>';
        wrap.hidden = false;

        var clear = wrap.querySelector('.wssw-clear-filters');
        if (clear) {
            clear.addEventListener('click', function () {
                navigate(location.pathname);
            });
        }

        loadResultCount(state);
    }

    function chipHtml(label, href, key) {
        return '<span class="wssw-chip" data-key="' + escapeAttr(key) + '">' + escapeHtml(label) +
            '<a class="wssw-chip-remove" href="' + escapeAttr(href) + '" aria-label="Quitar filtro">&times;</a></span>';
    }

    /* ------------------------------------------------------------ results bar */

    function gridElement() {
        return document.querySelector(
            '.etheme-product-grid-wrapper, .wc-block-grid, .woocommerce .products, ul.products, .shop-products, .product-list'
        );
    }

    function removeResultsBar() {
        var bar = document.querySelector('.wssw-results-bar');
        if (bar) { bar.parentNode.removeChild(bar); }
    }

    function loadResultCount(state) {
        var qs = serialize(state).replace(/^\?/, '');
        var extra = qs ? '&' + qs : '';
        if (currentCatSlug) { extra += '&category=' + encodeURIComponent(currentCatSlug); }
        fetch(apiRoot + 'products?count_only=1' + extra)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showResultsBar(data.total);
            })
            .catch(function () { removeResultsBar(); });
    }

    function showResultsBar(total) {
        removeResultsBar();
        var bar = document.createElement('div');
        bar.className = 'wssw-results-bar';
        bar.textContent = fmt(total) + (total === 1 ? ' producto encontrado' : ' productos encontrados');
        var grid = gridElement();
        var host = grid || document.querySelector('.wssw-responsive-filter-wrapper');
        if (!host) { return; }
        host.parentNode.insertBefore(bar, host);
    }

    /* --------------------------------------------------------------- pagination */

    function rebuildPagination() {
        var state = parseState();
        if (!hasFilters(state)) { return; }
        var links = document.querySelectorAll(
            '.etheme-pagination a, nav.woocommerce-pagination a, .pagination a, .page-numbers a, .page-numbers li a'
        );
        Array.prototype.forEach.call(links, function (a) {
            var href = a.getAttribute('href');
            if (!href) { return; }
            var url;
            try { url = new URL(href, location.href); } catch (e) { return; }
            var base = stripFiltersFromSearch(url.search);
            url.search = base + (base ? '&' : '?') + serialize(state).replace(/^\?/, '');
            // never carry stale filter pages; url's own page param is preserved above
            a.href = url.pathname + url.search + url.hash;
        });
    }

    /* --------------------------------------------------- global click handling */

    function onDocumentClick(e) {
        var el = e.target;

        var icon = closest(el, '.wssw-toggle-icon');
        if (icon) {
            e.preventDefault();
            e.stopPropagation();
            var item = icon.closest ? icon.closest('.wssw-category-item') : null;
            if (item) { item.classList.toggle('open'); }
            return;
        }

        var tag = closest(el, '.wssw-attribute-tag');
        if (tag) {
            e.preventDefault();
            e.stopPropagation();
            var state = parseState();
            navigate(location.pathname + attrUrl(state, tag.getAttribute('data-tax'), tag.getAttribute('data-slug')));
            return;
        }

        var catLink = closest(el, '.wssw-category-item a');
        if (catLink && document.getElementById('wssw-categories-root') &&
            document.getElementById('wssw-categories-root').contains(catLink)) {
            e.preventDefault();
            e.stopPropagation();
            navigate(catLink.getAttribute('href'));
            return;
        }

        var remove = closest(el, '.wssw-chip-remove');
        if (remove) {
            e.preventDefault();
            e.stopPropagation();
            navigate(remove.getAttribute('href'));
            return;
        }
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (el.matches && el.matches(selector)) { return el; }
            el = el.parentNode;
        }
        return null;
    }

    /* ------------------------------------------------------------ mobile overlay */

    function bindOverlay() {
        var toggle = document.getElementById('wssw-filter-toggle');
        var overlay = document.getElementById('wssw-filter-overlay');
        var closeBtn = document.getElementById('wssw-filter-close');
        if (!toggle || !overlay) { return; }

        function open() {
            overlay.classList.add('active');
            toggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('wssw-overlay-open');
        }
        function close() {
            overlay.classList.remove('active');
            toggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('wssw-overlay-open');
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (overlay.classList.contains('active')) { close(); } else { open(); }
        });
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                close();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); }
        });
    }

    /* ------------------------------------------------------------------ utils */

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function escapeAttr(s) { return escapeHtml(s); }

    function fmt(n) {
        var num = Number(n);
        if (isNaN(num)) { return String(n); }
        return num.toLocaleString('es-AR', { maximumFractionDigits: 2 });
    }

    /* ------------------------------------------------------------------- init */

    document.addEventListener('click', onDocumentClick, true);

    bindOverlay();

    loadCategories()
        .then(loadAttributes)
        .then(loadPrice)
        .then(function () {
            renderActiveFilters();
            rebuildPagination();
        });
})();
