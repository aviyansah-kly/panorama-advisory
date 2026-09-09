(() => {
  const root = document.querySelector('[data-sync-listings]');
  if (!root) return;

  const lang = document.documentElement.lang.startsWith('en') ? 'en' : 'fr';
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const pick = (obj, keys) => {
    for (const k of keys) {
      if (obj && obj[k] != null && obj[k] !== '') return obj[k];
    }
    return '';
  };

  const asList = data => {
    if (Array.isArray(data)) return data;
    for (const k of ['properties','listings','items','results','data']) {
      if (Array.isArray(data?.[k])) return data[k];
      if (data?.[k] && typeof data[k] === 'object') {
        const nested = asList(data[k]);
        if (nested.length) return nested;
      }
    }
    return [];
  };

  const localized = value => {
    if (value == null) return '';
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    if (typeof value === 'object') {
      return String(
        value[lang] ??
        value[lang === 'en' ? 'en_CA' : 'fr_CA'] ??
        value.value ??
        value.label ??
        ''
      );
    }
    return '';
  };

  const renderRow = p => {
    const id = localized(pick(p,['centris','centris_number','mls','mls_number','id','reference','ref']));
    const address = localized(pick(p,['address','adresse','full_address','street_address','title','name'])) || (lang === 'en' ? 'Property' : 'Propriété');
    const city = localized(pick(p,['city','ville','municipality','municipalite','borough']));
    const type = localized(pick(p,['property_type','type','category','categorie','usage']));
    const description = localized(pick(p,[lang === 'en' ? 'description_en' : 'description_fr','description','summary','resume']));
    const area = localized(pick(p,['area','superficie','building_area','land_area','surface','square_feet']));
    const price = localized(pick(p,['price','prix','asking_price','sale_price','rent','lease_price']));
    const status = localized(pick(p,['transaction_type','operation','status']));
    const image = localized(pick(p,['main_image','image','photo','thumbnail','image_url']));
    const url = localized(pick(p,['url','link','permalink','property_url','listing_url'])) || (id ? 'https://agencepanorama.ca/properties/' + encodeURIComponent(id) + '/' : '#');
    const meta = [city,type].filter(Boolean).join(' — ');

    return '<a data-listing href="' + esc(url) + '" style="display:grid;grid-template-columns:56px clamp(150px,17vw,260px) minmax(0,3.2fr) minmax(0,1.3fr) minmax(0,1.5fr);gap:clamp(14px,2vw,32px);align-items:start;padding:clamp(20px,2.2vw,28px) 8px clamp(22px,2.4vw,30px) 0;border-bottom:1px solid rgba(30,58,41,.11);text-decoration:none;color:inherit">'
      + '<span style="font:400 11px/1.9 IBM Plex Mono,monospace;letter-spacing:.12em;color:#4E5349">' + esc(id) + '</span>'
      + (image ? '<img src="' + esc(image) + '" alt="' + esc(address) + '" loading="lazy" style="display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#E7E3DA">' : '<span style="display:block;width:100%;aspect-ratio:4/3;background:#E7E3DA"></span>')
      + '<span style="display:flex;flex-direction:column;gap:10px">'
      + '<span style="font-family:Archivo,sans-serif;font-weight:500;font-size:clamp(20px,1.9vw,26px);line-height:1.14;letter-spacing:-.036em;color:#141714">' + esc(address) + '</span>'
      + (meta ? '<span style="font:500 11px/1.7 Archivo,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#4E5349">' + esc(meta) + '</span>' : '')
      + (description ? '<span style="margin-top:4px;font:400 clamp(16px,1.25vw,18px)/1.66 Archivo,sans-serif;color:#33372F">' + esc(description) + '</span>' : '')
      + '</span>'
      + '<span style="font:400 11px/1.9 IBM Plex Mono,monospace;letter-spacing:.12em;color:#4E5349">' + esc(area) + '</span>'
      + '<span style="display:flex;flex-direction:column;gap:8px">'
      + '<span style="font-family:Archivo,sans-serif;font-weight:500;font-size:clamp(18px,1.5vw,22px);line-height:1.2;letter-spacing:-.03em;color:#141714">' + esc(price) + '</span>'
      + (status ? '<span style="font:500 11px/1.7 Archivo,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#4E5349">' + esc(status) + '</span>' : '')
      + '</span></a>';
  };

  fetch('/data/syncbroker-properties.json', { cache: 'no-store' })
    .then(r => r.ok ? r.json() : Promise.reject())
    .then(data => {
      const rows = asList(data);
      if (!rows.length) return;
      const header = root.querySelector('[data-listing]');
      root.innerHTML = (header ? header.outerHTML : '') + rows.map(renderRow).join('');
      document.querySelectorAll('[data-sync-count]').forEach(el => {
        el.textContent = String(rows.length).padStart(2,'0');
      });
    })
    .catch(() => {});
})();