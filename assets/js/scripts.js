jQuery(document).ready(function($) {
    let apiRoot = wssw_api.root;
    if (apiRoot.startsWith('http://')) {
        apiRoot = apiRoot.replace('http://', 'https://');
    }

    async function loadCategories(parentId = 0) {
        try {
            const res = await fetch(`${apiRoot}categories?parent=${parentId}`);
            if (!res.ok) throw new Error('API Error');
            const cats = await res.json();
            let html = '<ul class="wssw-category-list" style="list-style:none; padding:0;">';
            if (parentId > 0) {
                html += `<div class="wssw-back-link" style="cursor:pointer; color:#007cba; margin-bottom:10px; font-weight:bold; display:block;" onclick="jQuery('#wssw-categories-root').trigger('wssw_load_root')">← Volver atrás</div>`;
            }
            if (!cats || cats.length === 0) {
                html += '<li style="font-style:italic; color:#999;">No hay subcategorías disponibles.</li>';
            } else {
                cats.forEach(c => {
                    html += `<li class="wssw-category-item" style="margin: 5px 0;" data-id="${c.id}">
                        <div class="wssw-category-link-wrapper" style="display:flex; align-items:center; justify-content:space-between;">
                            <a href="${c.link}" style="text-decoration:none; color:#333; flex-grow:1;">${c.name}</a>
                            ${c.has_children ? `<span class="wssw-toggle-icon" data-id="${c.id}" style="cursor:pointer; margin-left:10px; font-size:12px; padding:5px; user-select:none;">▼</span>` : ''}
                        </div>
                        ${c.has_children ? `<ul class="wssw-subcategory-list" id="subcat-${c.id}" style="display:none; padding-left:15px; list-style:none; margin-top:5px;"></ul>` : ''}
                    </li>`;
                });
            }
            html += '</ul>';
            $('#wssw-categories-root').html(html);
        } catch (e) { 
            console.error('WSSW Error:', e);
            $('#wssw-categories-root').html('Error al cargar categorías.');
        }
    }

    $(document).on('wssw_load_root', '#wssw-categories-root', function() {
        loadCategories(0);
    });

    $(document).on('click', '.wssw-toggle-icon', async function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $icon = $(this);
        const id = $icon.data('id');
        const $subList = $(`#subcat-${id}`);
        if ($subList.is(':hidden')) {
            $subList.slideDown(200);
            $icon.text('▲');
            if ($subList.is(':empty')) {
                try {
                    const res = await fetch(`${apiRoot}categories?parent=${id}`);
                    const cats = await res.json();
                    let subHtml = '';
                    if (!cats || cats.length === 0) {
                        subHtml = '<li style="font-style:italic; color:#999; font-size:12px;">Sin subcategorías.</li>';
                    } else {
                        cats.forEach(c => {
                            subHtml += `<li class="wssw-category-item" style="margin: 3px 0;" data-id="${c.id}">
                                <div class="wssw-category-link-wrapper" style="display:flex; align-items:center; justify-content:space-between;">
                                    <a href="${c.link}" style="text-decoration:none; color:#333; flex-grow:1; font-size:13px;">${c.name}</a>
                                    ${c.has_children ? `<span class="wssw-toggle-icon" data-id="${c.id}" style="cursor:pointer; margin-left:10px; font-size:11px; padding:3px;">▼</span>` : ''}
                                </div>
                                ${c.has_children ? `<ul class="wssw-subcategory-list" id="subcat-${c.id}" style="display:none; padding-left:15px; list-style:none;"></ul>` : ''}
                            </li>`;
                        });
                    }
                    $subList.html(subHtml);
                } catch (err) { console.error('WSSW Subcat Error:', err); }
            }
        } else {
            $subList.slideUp(200);
            $icon.text('▼');
        }
    });

    async function loadAttributes() {
        try {
            const res = await fetch(`${apiRoot}attributes`);
            if (!res.ok) throw new Error('API Error');
            const attrs = await res.json();
            let html = '<div class="wssw-attributes-container">';
            attrs.forEach(a => {
                html += `<div class="wssw-attribute-group"><h4 class="wssw-attribute-title">${a.label}</h4><div class="wssw-attribute-tags">`;
                a.terms.forEach(t => { html += `<a href="${t.link}" class="wssw-attribute-tag">${t.name}</a>`; });
                html += `</div></div>`;
            });
            html += '</div>';
            $('#wssw-attributes-root').html(html);
        } catch (e) { 
            console.error(e);
            $('#wssw-attributes-root').html('Error al cargar atributos.');
        }
    }

    async function loadPrice() {
        try {
            const res = await fetch(`${apiRoot}price-range`);
            if (!res.ok) throw new Error('API Error');
            const data = await res.json();
            const max = data.max_price || 1000000;
            const html = `<div class="wssw-price-filter"><h4 class="wssw-attribute-title">Filtrar por Precio</h4><div class="wssw-price-slider-container">
                <input type="range" id="wssw-price-min" min="0" max="${max}" value="0" class="wssw-price-slider">
                <input type="range" id="wssw-price-max" min="0" max="${max}" value="${max}" class="wssw-price-slider">
                <div class="wssw-price-values"><span>Desde: <strong id="wssw-min-val">0</strong> $</span><span>Hasta: <strong id="wssw-max-val">${max}</strong> $</span></div>
                <button id="wssw-filter-btn" class="wssw-filter-button">Filtrar</button></div></div>`;
            $('#wssw-price-root').html(html);
            const minS = $('#wssw-price-min'), maxS = $('#wssw-price-max');
            const minV = $('#wssw-min-val'), maxV = $('#wssw-max-val');
            const up = () => {
                let min = parseInt(minS.val()), max = parseInt(maxS.val());
                if(min > max) { let t=min; min=max; max=t; minS.val(min); maxS.val(max); }
                minV.text(min); maxV.text(max);
            };
            minS.on('input', up); maxS.on('input', up);
            $('#wssw-filter-btn').on('click', () => { window.location.href = `/shop/?min_price=${minS.val()}&max_price=${maxS.val()}`; });
        } catch (e) { 
            console.error(e);
            $('#wssw-price-root').html('Error al cargar filtro de precio.');
        }
    }

    const currentCatId = (window.wssw_api && window.wssw_api.current_cat) ? parseInt(window.wssw_api.current_cat) : 0;
    loadCategories(currentCatId); 
    loadAttributes(); 
    loadPrice();
});
