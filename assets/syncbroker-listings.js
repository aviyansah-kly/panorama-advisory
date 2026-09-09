(() => {
  const root = document.querySelector('[data-sync-listings]');
  if (!root) return;

  const isEn = document.documentElement.lang.toLowerCase().startsWith('en');
  const locale = isEn ? 'en-CA' : 'fr-CA';

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));

  const strip = (html) => {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return (d.textContent || '').replace(/\s+/g,' ').trim();
  };

  const money = (value) => {
    if (value == null || value === '') return '';
    const n = Number(value);
    if (!Number.isFinite(n)) return String(value);
    return new Intl.NumberFormat(locale, {
      style:'currency',
      currency:'CAD',
      maximumFractionDigits:0
    }).format(n);
  };

  const addressOf = (p) => {
    const parts = [
      p.no_civique_debut,
      p.nom_rue_complet,
      p.appartement ? (isEn ? '— ' + p.appartement : '— ' + p.appartement) : ''
    ].filter(Boolean);
    return parts.join(' ');
  };

  const typeOf = (p) => {
    if (p.genre_propriete) {
      return isEn ? p.genre_propriete.description_anglaise : p.genre_propriete.description_francaise;
    }
    if (p.categorie_propriete) {
      return isEn ? p.categorie_propriete.description_anglaise : p.categorie_propriete.description_francaise;
    }
    return '';
  };

  const cityOf = (p) => p.municipalite?.description || '';

  const areaOf = (p) => {
    if (p.superficie_habitable != null) {
      const unit = p.um_superficie_habitable;
      const label = isEn ? unit?.description_abregee_anglaise : unit?.description_abregee_francaise;
      return new Intl.NumberFormat(locale).format(Number(p.superficie_habitable)) + (label ? ' ' + label : '');
    }
    if (p.superficie_terrain != null) {
      const unit = p.um_superficie_terrain;
      const label = isEn ? unit?.description_abregee_anglaise : unit?.description_abregee_francaise;
      return new Intl.NumberFormat(locale).format(Number(p.superficie_terrain)) + (label ? ' ' + label : '');
    }
    return '';
  };

  const descOf = (p) => {
    const remarks = Array.isArray(p.remarques) ? p.remarques : [];
    const langCode = isEn ? 'A' : 'F';
    const remark = remarks.find(r => r.code_langue === langCode && r.texte)?.texte;
    if (remark) return remark;

    const full = isEn ? p.addenda_complet_a : p.addenda_complet_f;
    return strip(full).slice(0, 320);
  };

  const imageOf = (p) => {
    const photos = Array.isArray(p.photos) ? [...p.photos] : [];
    photos.sort((a,b) => Number(a.seq || 0) - Number(b.seq || 0));
    return photos.find(x => x.photourl)?.photourl || '';
  };

  const detailOf = (p) => {
    if (p.url_desc_detaillee) return p.url_desc_detaillee;
    if (p.no_inscription) {
      return 'https://passerelle.centris.ca/redirect.aspx?CodeDest=SYNCBROKER&NoMLS=' + encodeURIComponent(p.no_inscription);
    }
    return '#';
  };

  const statusOf = (p) => {
    if (p.prix_location_demande != null) return isEn ? 'For lease' : 'À louer';
    if (p.prix_demande != null) return isEn ? 'For sale' : 'À vendre';
    return '';
  };

  const priceOf = (p) => {
    if (p.prix_location_demande != null) return money(p.prix_location_demande);
    if (p.prix_demande != null) return money(p.prix_demande);
    return '';
  };

  const render = (p) => {
    const id = p.no_inscription || '';
    const address = addressOf(p) || (isEn ? 'Property' : 'Propriété');
    const city = cityOf(p);
    const type = typeOf(p);
    const meta = [city,type].filter(Boolean).join(' — ');
    const description = descOf(p);
    const area = areaOf(p);
    const price = priceOf(p);
    const status = statusOf(p);
    const image = imageOf(p);
    const href = detailOf(p);

    return '<a data-listing href="' + esc(href) + '" target="_blank" rel="noopener" style="display:grid;grid-template-columns:56px clamp(150px,17vw,260px) minmax(0,3.2fr) minmax(0,1.3fr) minmax(0,1.5fr);gap:clamp(14px,2vw,32px);align-items:start;padding:clamp(20px,2.2vw,28px) 8px clamp(22px,2.4vw,30px) 0;border-bottom:1px solid rgba(30,58,41,.11);text-decoration:none;color:inherit">'
      + '<span style="font:400 11px/1.9 IBM Plex Mono,monospace;letter-spacing:.12em;color:#4E5349">' + esc(id) + '</span>'
      + (image
        ? '<img src="' + esc(image) + '" alt="' + esc(address) + '" loading="lazy" style="display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#E7E3DA">'
        : '<span style="display:block;width:100%;aspect-ratio:4/3;background:#E7E3DA"></span>')
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

  fetch('/proprietes/syncbroker.php', { cache:'no-store' })
    .then(r => r.ok ? r.json() : Promise.reject())
    .then(data => {
      if (!Array.isArray(data) || !data.length) return;
      const header = root.querySelector('[data-listing]');
      root.innerHTML = (header ? header.outerHTML : '') + data.map(render).join('');
      document.querySelectorAll('[data-sync-count]').forEach(el => {
        el.textContent = String(data.length).padStart(2,'0');
      });
    })
    .catch(() => {});
})();