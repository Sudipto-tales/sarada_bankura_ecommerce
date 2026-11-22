/* =====================================================================
   Maa Sarada storefront — shared design-preview module
   Loaded on every page AFTER the Tailwind CDN script.
   Holds: Tailwind theme · schema-shaped demo data · helpers · header/footer
   chrome · product card · bag/saved (localStorage) · UPI payment window.
   In the PHP build each block maps to a partial / query (see data-map).
   ===================================================================== */

tailwind.config = {
  theme: {
    extend: {
      fontFamily: { display: ['Fraunces', 'Georgia', 'serif'], sans: ['Manrope', 'system-ui', 'sans-serif'] },
      colors: {
        cream: { DEFAULT: '#F6F2EA', 50: '#FBF9F5', 100: '#F6F2EA', 200: '#EDE6D9', 300: '#E0D5C2' },
        ink: { DEFAULT: '#15211F', 700: '#22302D', 500: '#55615E', 400: '#7A8481', 300: '#A7AEAB' },
        teal: { DEFAULT: '#1E6E74', 50: '#E7F1F1', 100: '#CFE3E4', 600: '#185B60', 800: '#0F3A3D' },
        copper: { DEFAULT: '#B4643C', 50: '#F7ECE5', 600: '#9A522F' },
        gold: '#B8925A'
      },
      boxShadow: { card: '0 1px 2px rgba(21,33,31,.05), 0 8px 24px -12px rgba(21,33,31,.18)' }
    }
  }
};

(function injectBaseCss() {
  const css = `
  html{scroll-behavior:smooth} body{background:#F6F2EA;color:#15211F;-webkit-font-smoothing:antialiased}
  .no-scrollbar::-webkit-scrollbar{display:none}.no-scrollbar{scrollbar-width:none}
  [data-lucide]{width:1.1em;height:1.1em;stroke-width:1.75}
  .mega{opacity:0;visibility:hidden;transform:translateY(6px);transition:.18s ease}
  .has-mega:hover .mega,.has-mega:focus-within .mega{opacity:1;visibility:visible;transform:none}
  .card-img .alt{opacity:0;transition:opacity .35s ease}.card:hover .card-img .alt{opacity:1}
  .chip[aria-pressed="true"],.tab[aria-selected="true"]{background:#15211F;color:#F6F2EA;border-color:#15211F}
  .field{width:100%;height:44px;padding:0 14px;border-radius:12px;background:#FBF9F5;border:1px solid rgba(21,33,31,.12);outline:none;font-size:14.5px}
  .field:focus{border-color:#1E6E74;box-shadow:0 0 0 4px rgba(30,110,116,.12)} textarea.field{height:auto;padding:10px 14px}
  .field[aria-invalid="true"]{border-color:#B4643C}
  .lbl{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:48px;padding:0 24px;border-radius:999px;font-weight:600;font-size:14.5px;transition:.15s}
  .btn-dark{background:#15211F;color:#F6F2EA}.btn-dark:hover{background:#22302D}
  .btn-copper{background:#B4643C;color:#fff}.btn-copper:hover{background:#9A522F}
  .btn-line{border:1px solid rgba(21,33,31,.2)}.btn-line:hover{border-color:#15211F}
  .btn:disabled{opacity:.45;cursor:not-allowed}
  .eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.2em;color:#1E6E74;font-weight:600}
  body.map-on [data-map]{outline:1.5px dashed #1E6E74;outline-offset:3px;position:relative}
  body.map-on [data-map]::before{content:attr(data-map);position:absolute;top:-11px;left:10px;z-index:40;font:600 10.5px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;background:#1E6E74;color:#fff;padding:3px 7px;border-radius:5px;white-space:nowrap;max-width:92%;overflow:hidden;text-overflow:ellipsis;pointer-events:none}
  @media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important;scroll-behavior:auto!important}}`;
  const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
})();

/* ---------- procedural stone swatch (stands in for product_media) ---------- */
function stone(o) {
  const { base = '#f3f1ec', vein = '#7d7f80', tint = '#d9d6cf', fx = .0028, fy = .009, seed = 3, cloud = .55, speck = false, gold = null, w = .1 } = o;
  const A = '0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 1 0 0 0 0';
  const veins = speck
    ? `<feTurbulence type="fractalNoise" baseFrequency=".75" numOctaves="2" seed="${seed}" result="s"/><feColorMatrix in="s" type="matrix" values="${A}" result="sa"/><feComponentTransfer in="sa" result="sv"><feFuncA type="discrete" tableValues="0 0 0 .55 .9 1"/></feComponentTransfer><feFlood flood-color="${vein}"/><feComposite in2="sv" operator="in" result="veins"/>`
    : `<feComponentTransfer in="a" result="v"><feFuncA type="table" tableValues="0 0 0 0 0 0 ${w} .85 ${w} 0 0 0 0 0 0"/></feComponentTransfer><feFlood flood-color="${vein}"/><feComposite in2="v" operator="in" result="veins"/>`;
  const g = gold ? `<feTurbulence type="fractalNoise" baseFrequency="${fx * 1.4} ${fy * .7}" numOctaves="4" seed="${seed + 7}" result="n2"/><feColorMatrix in="n2" type="matrix" values="${A}" result="a2"/><feComponentTransfer in="a2" result="g"><feFuncA type="table" tableValues="0 0 0 0 0 0 0 .8 0 0 0 0 0 0 0"/></feComponentTransfer><feFlood flood-color="${gold}"/><feComposite in2="g" operator="in" result="gold"/>` : '';
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600" viewBox="0 0 600 600"><filter id="m" x="0" y="0" width="100%" height="100%"><feTurbulence type="fractalNoise" baseFrequency="${fx} ${fy}" numOctaves="5" seed="${seed}" result="n"/><feColorMatrix in="n" type="matrix" values="${A}" result="a"/><feComponentTransfer in="a" result="c"><feFuncA type="linear" slope="${cloud}" intercept="-.12"/></feComponentTransfer><feFlood flood-color="${tint}"/><feComposite in2="c" operator="in" result="cl"/>${veins}${g}<feMerge><feMergeNode in="cl"/><feMergeNode in="veins"/>${gold ? '<feMergeNode in="gold"/>' : ''}</feMerge></filter><rect width="600" height="600" fill="${base}"/><rect width="600" height="600" filter="url(#m)"/></svg>`;
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}
const U = (id, w = 900) => `https://images.unsplash.com/photo-${id}?w=${w}&q=75&auto=format&fit=crop`;

/* =====================================================================
   DATA — shaped like the schema
   ===================================================================== */
const BUSINESS = { // config/business.php until Payment settings exist
  name: 'Maa Sarada Marble & Sanitation', state: 'West Bengal', gst_percent: 18, gstin: '19XXXXX0000X1Z5',
  advance_percent: 20, upi_id: 'maasarada@sbi', upi_limit: 100000, phone: '+91 00000 00000', whatsapp: '910000000000',
  address: 'Main Road, Bankura, West Bengal 722101', hours: 'Mon–Sat · 10:00–20:00 IST'
};

const COLOURS = { // attribute_values for attributes.code = colour
  'Statuario White': { base: '#f4f2ee', vein: '#6f7275', tint: '#dcd9d3', seed: 3 },
  'Botticino Beige': { base: '#ece0c8', vein: '#c4ab80', tint: '#d9c6a2', seed: 11, cloud: .9 },
  'Nero Black': { base: '#18191b', vein: '#e2e2de', tint: '#2a2c2f', seed: 4 },
  'Emperador Brown': { base: '#5a4030', vein: '#d8c2a3', tint: '#3f2c20', seed: 6, w: .25 },
  'Verde Green': { base: '#1d3b30', vein: '#cfe0d6', tint: '#10251e', seed: 8 },
  'Onyx Honey': { base: '#d99a4a', vein: '#f6dba6', tint: '#a8622a', seed: 2, fx: .008, fy: .005, cloud: 1.1, w: .3 },
  'Makrana Pink': { base: '#ecd0c7', vein: '#b98576', tint: '#d7aca0', seed: 13 },
  'Ivory Cream': { base: '#f3ecdf', vein: '#cbbb9c', tint: '#e4d8c2', seed: 21, cloud: .4 }
};
const colourStone = l => stone(COLOURS[l]);

const CATEGORIES = [ // product_categories (2 levels)
  { id: 1, parent_id: null, name: 'Flooring', slug: 'flooring', is_featured: 1, icon: 'grid-2x2', image: U('1618221195710-dd6b41faaea6'), description: 'Living rooms, bathrooms and outdoor paving.' },
  { id: 2, parent_id: null, name: 'Wall Cladding', slug: 'wall-cladding', is_featured: 1, icon: 'brick-wall', image: U('1604014237800-1c9102c219da'), description: 'Feature walls inside, facades outside.' },
  { id: 3, parent_id: null, name: 'Countertops', slug: 'countertops', is_featured: 1, icon: 'chef-hat', image: U('1556911220-bff31c812dba'), description: 'Kitchen slabs and vanity tops cut to size.' },
  { id: 4, parent_id: null, name: 'Imported', slug: 'imported', image: U('1600607687939-ce8a6c25118c'), description: 'Italy, Spain, Turkey, Greece, Iran and Vietnam.' },
  { id: 5, parent_id: null, name: 'Indian', slug: 'indian', image: U('1590381105924-c72589b9ef3f'), description: 'Makrana, Ambaji, Udaipur and Rajnagar.' },
  { id: 7, parent_id: 1, name: 'Living Room', slug: 'living-room', image: U('1600210492486-724fe5c67fb0', 400) },
  { id: 8, parent_id: 1, name: 'Bathroom', slug: 'bathroom', image: U('1584622650111-993a426fbf0a', 400) },
  { id: 9, parent_id: 1, name: 'Outdoor', slug: 'outdoor', image: U('1600585154340-be6161a56a0c', 400) },
  { id: 10, parent_id: 2, name: 'Interior Walls', slug: 'interior-walls', image: U('1604014237800-1c9102c219da', 400) },
  { id: 11, parent_id: 2, name: 'Exterior Facade', slug: 'exterior-facade', image: U('1600566753190-17f0baa2a6c3', 400) },
  { id: 12, parent_id: 3, name: 'Kitchen', slug: 'kitchen', image: U('1565183928294-7063f23ce0f8', 400) },
  { id: 13, parent_id: 3, name: 'Vanity Tops', slug: 'vanity-tops', image: U('1620626011761-996317b8d101', 400) }
];

const TAGS = [ // tags (purpose: discovery | merchandising | internal)
  { name: 'premium', purpose: 'merchandising' }, { name: 'Bestseller', purpose: 'merchandising' }, { name: 'New arrival', purpose: 'merchandising' },
  { name: 'imported', purpose: 'discovery', icon: 'globe', label: 'Imported' }, { name: 'white', purpose: 'discovery', icon: 'circle', label: 'White stones' },
  { name: 'Book-matched', purpose: 'discovery', icon: 'book-open', copy: 'Mirrored slabs for feature walls' },
  { name: 'Heat resistant', purpose: 'discovery', icon: 'flame', copy: 'Hot pans, cooking zones' },
  { name: 'Low maintenance', purpose: 'discovery', icon: 'sparkles', copy: 'Easy daily cleaning' },
  { name: 'Outdoor safe', purpose: 'discovery', icon: 'sun', copy: 'Sun, rain, anti-slip finishes' },
  { name: 'Vastu friendly', purpose: 'discovery', icon: 'compass', copy: 'Colours chosen by direction' },
  { name: 'Showroom only', purpose: 'internal' }, { name: 'Clearance lot', purpose: 'internal' }, { name: 'Fragile handling', purpose: 'internal' }
];

const BRANDS = [ // brands (tier signature|core|value)
  { id: 1, name: 'Maa Sarada Studio', slug: 'maa-sarada-studio', tier: 'signature', country: 'India', description: 'Our own selection — slabs picked by hand at the quarry.', image: colourStone('Ivory Cream') },
  { id: 2, name: 'Imported Collection', slug: 'imported-collection', tier: 'signature', country: 'Italy', description: 'Statuario, Calacatta and Nero from European quarries.', image: colourStone('Statuario White') },
  { id: 3, name: 'Rajasthan Quarry', slug: 'rajasthan-quarry', tier: 'core', country: 'India', description: 'Makrana, Rajnagar and Udaipur marble at honest prices.', image: colourStone('Makrana Pink') }
];

const OPTION_GROUPS = [ // option_groups + option_values (variant axes)
  { id: 1, name: 'Thickness', values: [{ id: 11, value: '16 mm' }, { id: 12, value: '18 mm' }, { id: 13, value: '20 mm' }, { id: 14, value: '30 mm' }] },
  { id: 2, name: 'Finish', values: [{ id: 21, value: 'Polished' }, { id: 22, value: 'Honed' }, { id: 23, value: 'Leather' }] },
  { id: 3, name: 'Size', values: [{ id: 31, value: '600 × 600' }, { id: 32, value: '800 × 800' }] },
  { id: 4, name: 'Grade', values: [{ id: 41, value: 'Standard' }, { id: 42, value: 'Premium' }] }
];
const optionValue = id => { for (const g of OPTION_GROUPS) { const v = g.values.find(x => x.id === id); if (v) return { group: g, ...v }; } };

const ATTR_LABELS = { // attributes (code → name) shown in the spec table, in attributes.sort order
  stone_type: 'Stone type', origin: 'Origin', colour: 'Colour', pattern: 'Pattern / vein', finish: 'Finish', thickness: 'Thickness',
  grade: 'Grade', slab_size: 'Size (mm)', application: 'Application', edge_profile: 'Edge profile', water_absorption: 'Water absorption'
};

/* variant helper: v(id, sku, [optionValueIds], price, compare, extra) → product_variants row + product_variant_values */
const V = (id, sku, values, price, compare_at_price = null, x = {}) => ({ id, sku, values, price, compare_at_price, unit: null, status: 'active', is_default: 0, ...x });

const PRODUCTS = [
  { id: 1, slug: 'statuario-white', sku: 'MS-IT-001', name: 'Statuario White', brand: 2, type: 'Marble & Stone', status: 'active', is_featured: 1, is_bestseller: 1, show_price: 1, is_enquiry_only: 0,
    cats: [1, 7, 2, 10, 4], primary: 7, tags: ['premium', 'imported', 'white', 'Book-matched'], colour: 'Statuario White',
    short_description: 'Bright white with bold grey veins. Book-matched pairs make a statement on a feature wall.',
    description: 'Statuario is quarried in the Apuan Alps above Carrara. Its bright white ground and dramatic grey veins make it the first choice for living rooms and feature walls. We keep book-matched pairs, so the veins mirror across two slabs. Every lot is photographed in our showroom before dispatch.',
    pricing: { price: 640, compare_at_price: 850, unit: 'sq ft' },
    attrs: { stone_type: 'Marble', origin: 'Italy', colour: 'Statuario White', pattern: 'Heavy veining', grade: 'Premium', slab_size: '1200 × 2400', application: 'Flooring, Wall cladding', edge_profile: 'Straight', water_absorption: '0.2 %' },
    options: [1, 2], variants: [
      V(101, 'MS-IT-001-16P', [11, 21], 590, 790), V(102, 'MS-IT-001-18P', [12, 21], 640, 850, { is_default: 1 }), V(103, 'MS-IT-001-20P', [13, 21], 720, 960),
      V(104, 'MS-IT-001-16H', [11, 22], 610, 810), V(105, 'MS-IT-001-18H', [12, 22], 660, 880), V(106, 'MS-IT-001-20H', [13, 22], 740, null, { status: 'inactive' })],
    rating: 4.8, reviews: 176, stock: 'in', room: U('1600607687939-ce8a6c25118c', 900), gallery: [U('1600210492486-724fe5c67fb0', 900), U('1604014237800-1c9102c219da', 900)] },
  { id: 2, slug: 'makrana-white', sku: 'MS-IN-004', name: 'Makrana White', brand: 3, status: 'active', is_bestseller: 1, show_price: 1, is_enquiry_only: 0,
    cats: [1, 7, 5], primary: 7, tags: ['Bestseller', 'white', 'Vastu friendly'], colour: 'Ivory Cream',
    short_description: 'The marble of the Taj. Soft white, fine grain, glows in daylight.',
    pricing: { price: 240, compare_at_price: 320, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Makrana, Rajasthan', finish: 'Polished', thickness: '18 mm', grade: 'Premium', pattern: 'Light veining' },
    options: [4], variants: [V(201, 'MS-IN-004-S', [41], 240, 320, { is_default: 1 }), V(202, 'MS-IN-004-P', [42], 310, 400)],
    rating: 4.7, reviews: 288, stock: 'in', room: U('1616486338812-3dadae4b4ace', 900), gallery: [U('1618220179428-22790b461013', 900)] },
  { id: 3, slug: 'black-galaxy-granite', sku: 'MS-GR-002', name: 'Black Galaxy Granite', brand: 1, status: 'active', is_bestseller: 1, show_price: 1, is_enquiry_only: 0,
    cats: [3, 12, 5], primary: 12, tags: ['Heat resistant', 'Low maintenance'], img: stone({ base: '#151517', vein: '#b9a982', tint: '#26262a', speck: true, seed: 4 }),
    short_description: 'Deep black with gold flecks. Takes hot pans and daily cooking without fuss.',
    pricing: { price: 290, compare_at_price: 385, unit: 'sq ft' }, attrs: { stone_type: 'Granite', origin: 'Andhra Pradesh', finish: 'Polished', pattern: 'Speckled', edge_profile: 'Half bullnose', water_absorption: '0.1 %' },
    options: [1], variants: [V(301, 'MS-GR-002-18', [12], 270, 360), V(302, 'MS-GR-002-20', [13], 290, 385, { is_default: 1 }), V(303, 'MS-GR-002-30', [14], 380, 490)],
    rating: 4.8, reviews: 341, stock: 'low', room: U('1565183928294-7063f23ce0f8', 900), gallery: [U('1556911220-bff31c812dba', 900), U('1602028915047-37269d1a73f7', 900)] },
  { id: 4, slug: 'nero-marquina', sku: 'MS-IT-007', name: 'Nero Marquina', brand: 2, status: 'active', show_price: 1, is_enquiry_only: 0,
    cats: [2, 10, 3, 13, 4], primary: 10, tags: ['premium', 'imported'], colour: 'Nero Black',
    short_description: 'Jet black with crisp white veins. Dramatic in bathrooms and vanity tops.',
    pricing: { price: 420, compare_at_price: 560, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Spain', finish: 'Honed', thickness: '18 mm', pattern: 'Light veining' },
    options: [], variants: [], rating: 4.7, reviews: 158, stock: 'in', room: U('1600566752355-35792bedcfea', 900), gallery: [] },
  { id: 5, slug: 'botticino-beige', sku: 'MS-IT-010', name: 'Botticino Beige', brand: 2, status: 'active', show_price: 1, is_enquiry_only: 0,
    cats: [1, 7, 8, 4], primary: 7, tags: ['New arrival', 'imported', 'Low maintenance'], colour: 'Botticino Beige',
    short_description: 'Warm cream with soft fossil clouds. Forgiving, homely, easy to keep.',
    pricing: { price: 195, compare_at_price: 265, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Italy', thickness: '16 mm', pattern: 'Cloudy' },
    options: [2], variants: [V(501, 'MS-IT-010-P', [21], 195, 265, { is_default: 1 }), V(502, 'MS-IT-010-H', [22], 205, 275)],
    rating: 4.5, reviews: 187, stock: 'in', room: U('1618220179428-22790b461013', 900), gallery: [] },
  { id: 6, slug: 'udaipur-green', sku: 'MS-IN-012', name: 'Udaipur Green', brand: 3, status: 'active', show_price: 1, is_enquiry_only: 0,
    cats: [2, 10, 5], primary: 10, tags: ['Vastu friendly', 'New arrival'], colour: 'Verde Green',
    short_description: 'Forest green with pale veins. A calm, rich feature wall.',
    pricing: { price: 520, compare_at_price: 700, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Udaipur, Rajasthan', finish: 'Polished', thickness: '18 mm' },
    options: [], variants: [], rating: 4.5, reviews: 96, stock: null, room: U('1615873968403-89e068629265', 900), gallery: [] },
  { id: 7, slug: 'emperador-dark', sku: 'MS-IT-015', name: 'Emperador Dark', brand: 2, status: 'active', show_price: 0, is_enquiry_only: 0,
    cats: [2, 10, 1, 7, 4], primary: 10, tags: ['premium', 'imported'], colour: 'Emperador Brown',
    short_description: 'Chocolate brown with gold veins. Ask for today’s rate.',
    pricing: { price: 780, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Spain', finish: 'Polished', thickness: '18 mm' },
    options: [], variants: [], rating: 4.6, reviews: 64, stock: 'in', room: U('1586023492125-27b2c045efd7', 900), gallery: [] },
  { id: 8, slug: 'honey-onyx', sku: 'MS-EX-001', name: 'Honey Onyx', brand: 2, status: 'active', show_price: 0, is_enquiry_only: 1,
    cats: [2, 10, 3, 13, 4], primary: 10, tags: ['premium', 'imported', 'Book-matched'], colour: 'Onyx Honey',
    short_description: 'Translucent honey onyx. Backlit walls and bar counters. Quoted per project.',
    pricing: { price: 1450, unit: 'sq ft' }, attrs: { stone_type: 'Onyx', origin: 'Iran', finish: 'Polished', thickness: '20 mm', grade: 'Exotic' },
    options: [], variants: [], rating: 4.9, reviews: 74, stock: null, room: U('1600210492486-724fe5c67fb0', 900), gallery: [] },
  { id: 9, slug: 'flamed-grey-granite', sku: 'MS-GR-008', name: 'Flamed Grey Granite', brand: 1, status: 'active', show_price: 1, is_enquiry_only: 0,
    cats: [1, 9, 2, 11, 5], primary: 9, tags: ['Outdoor safe', 'Heat resistant', 'Low maintenance'], img: stone({ base: '#8d8f90', vein: '#2f3133', tint: '#a9abac', speck: true, seed: 7 }),
    short_description: 'Rough flamed surface that stays grippy in rain. Paths, porches, facades.',
    pricing: { price: 145, compare_at_price: 175, unit: 'sq ft' }, attrs: { stone_type: 'Granite', origin: 'Karnataka', finish: 'Flamed', thickness: '20 mm' },
    options: [], variants: [], rating: 4.4, reviews: 52, stock: 'low', room: U('1600573472550-8090b5e0745e', 900), gallery: [] },
  { id: 10, slug: 'ivory-cream-tiles', sku: 'MS-TL-003', name: 'Ivory Cream Tiles 600×600', brand: 1, status: 'active', show_price: 1, is_enquiry_only: 0,
    cats: [1, 8, 2, 10, 5], primary: 8, tags: ['New arrival', 'Low maintenance'], colour: 'Ivory Cream',
    short_description: 'Calibrated marble tiles, sold by the box. Quick to lay, no cutting waste.',
    pricing: { price: 1850, compare_at_price: 2200, unit: 'box', pack_qty: 10.76, pack_unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Ambaji, Gujarat', thickness: '16 mm', slab_size: '600 × 600' },
    options: [2], variants: [V(1001, 'MS-TL-003-P', [21], 1850, 2200, { is_default: 1, unit: 'box' }), V(1002, 'MS-TL-003-H', [22], 1790, 2150, { unit: 'box' }), V(1003, 'MS-TL-003-L', [23], 1990, null, { unit: 'box' })],
    rating: 4.6, reviews: 41, stock: 'in', room: U('1584622650111-993a426fbf0a', 900), gallery: [] },
  { id: 11, slug: 'rajnagar-pink', sku: 'MS-IN-020', name: 'Rajnagar Pink', brand: 3, status: 'active', show_price: 1, is_enquiry_only: 0,
    cats: [1, 7, 5], primary: 7, tags: ['Vastu friendly'], colour: 'Makrana Pink',
    short_description: 'Soft pink marble, traditional for pooja rooms and temples.',
    pricing: { price: 120, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Rajnagar, Rajasthan', finish: 'Polished', thickness: '16 mm' },
    options: [], variants: [], rating: 4.3, reviews: 38, stock: 'out', room: U('1631679706909-1844bbd07221', 900), gallery: [] },
  { id: 12, slug: 'calacatta-gold', sku: 'MS-IT-002', name: 'Calacatta Gold', brand: 2, status: 'coming_soon', show_price: 1, is_enquiry_only: 0,
    cats: [3, 12, 2, 10, 4], primary: 12, tags: ['premium', 'imported', 'New arrival'], img: stone({ base: '#f5f1e8', vein: '#9b9a95', tint: '#e5dfd2', seed: 5, gold: '#b8924a' }),
    short_description: 'White with warm gold veins. First lot lands next month.',
    pricing: { price: 980, compare_at_price: 1350, unit: 'sq ft' }, attrs: { stone_type: 'Marble', origin: 'Italy', finish: 'Polished', thickness: '20 mm' },
    options: [], variants: [], rating: 0, reviews: 0, stock: null, room: U('1556911220-bff31c812dba', 900), gallery: [] }
];
PRODUCTS.forEach(p => { p.img = p.img || colourStone(p.colour); p.description = p.description || p.short_description; });

const REVIEWS = [ // product_reviews WHERE status = 'published'
  { product: 'statuario-white', customer_name: 'Ritika S.', city: 'Bankura', application: 'Living room floor', rating: 5, title: 'Exactly the white I wanted', body: 'Laid 900 sq ft across living and dining. Slab-to-slab match was very close — that was my biggest worry.', response: 'Thank you Ritika! Enjoy the new floor.', date: '12 Nov 2026' },
  { product: 'black-galaxy-granite', customer_name: 'Amit G.', city: 'Durgapur', application: 'Kitchen countertop', rating: 5, title: 'Tough and good looking', body: 'Two years of daily cooking, no stains. The edge finishing was neat and delivery was on time.', date: '2 Nov 2026' },
  { product: 'makrana-white', customer_name: 'Sanchita M.', city: 'Bishnupur', application: 'Pooja room', rating: 5, title: 'Pure Makrana', body: 'They showed me three lots in the showroom and helped me choose. Very patient team.', date: '28 Oct 2026' },
  { product: 'nero-marquina', customer_name: 'Debasish P.', city: 'Kolkata', application: 'Bathroom walls', rating: 4, title: 'Beautiful, plan the layout', body: 'Stone is gorgeous. Ask them to lay out the veins before fixing — they did it when I asked.', response: 'Good tip — we now offer a dry-lay preview on request.', date: '20 Oct 2026' },
  { product: 'statuario-white', customer_name: 'Arindam K.', city: 'Asansol', application: 'Feature wall', rating: 5, title: 'Book-matched wall is stunning', body: 'Took the 20 mm honed for the TV wall. The mirrored veins are the first thing guests notice.', date: '9 Oct 2026' },
  { product: 'statuario-white', customer_name: 'Payel D.', city: 'Bankura', application: 'Staircase', rating: 4, title: 'Lovely, needs care', body: 'Polished finish is slippery when wet on stairs. Honed would have been the better pick for us.', response: 'Thanks Payel — we now suggest honed for stairs.', date: '1 Oct 2026' }
];

/* =====================================================================
   HELPERS
   ===================================================================== */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const inr = (n, paise = false) => '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: paise ? 2 : 0, maximumFractionDigits: paise ? 2 : 0 });
const stars = r => '★★★★★'.slice(0, Math.round(r)) + '☆☆☆☆☆'.slice(0, 5 - Math.round(r));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const qs = k => new URLSearchParams(location.search).get(k);
const live = PRODUCTS.filter(p => p.status === 'active' || p.status === 'coming_soon');
const topCats = () => CATEGORIES.filter(c => !c.parent_id);
const children = id => CATEGORIES.filter(c => c.parent_id === id);
const catById = id => CATEGORIES.find(c => c.id === id);
const catBySlug = s => CATEGORIES.find(c => c.slug === s);
const countIn = id => live.filter(p => p.cats.includes(id)).length;
const brandOf = p => BRANDS.find(b => b.id === p.brand);
const bySlug = s => PRODUCTS.find(p => p.slug === s);
const publicTags = p => p.tags.map(n => TAGS.find(t => t.name === n)).filter(t => t && t.purpose !== 'internal');
const productUrl = p => `product.html?slug=${p.slug}`;
const icons = () => window.lucide && lucide.createIcons();
const round2 = x => Math.round(x * 100) / 100;
const ceil2 = x => Math.ceil(x * 100 - 1e-9) / 100; // advance is rounded UP (payments.md)

/* variants */
const activeVariants = p => p.variants.filter(v => v.status === 'active');
const defaultVariant = p => activeVariants(p).find(v => v.is_default) || activeVariants(p)[0] || null;
const variantById = (p, id) => p.variants.find(v => v.id === +id) || null;
const variantTitle = v => v ? v.values.map(id => optionValue(id).value).join(' · ') : '';
const unitOf = (p, v) => (v && v.unit) || p.pricing.unit;
const priceOf = (p, v) => v ? v.price : p.pricing.price;
const compareOf = (p, v) => v ? v.compare_at_price : p.pricing.compare_at_price;
const minPrice = p => p.variants.length ? Math.min(...activeVariants(p).map(v => v.price)) : p.pricing.price;
const purchasable = p => p.status === 'active' && p.show_price && !p.is_enquiry_only;

/* bag + saved — localStorage (wrapped, page works without it) */
const store = {
  get(k, d) { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch { return d; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { } }
};
const bag = {
  lines: () => store.get('ms.bag', []),
  save(l) { store.set('ms.bag', l); updateCounts(); },
  add(slug, variantId, qty) {
    const l = bag.lines(), key = slug + ':' + (variantId || 0), hit = l.find(x => x.key === key);
    hit ? hit.qty = round2(hit.qty + qty) : l.push({ key, slug, variant_id: variantId || 0, qty });
    bag.save(l);
  },
  setQty(key, qty) { const l = bag.lines(); const x = l.find(i => i.key === key); if (x) x.qty = qty; bag.save(l); },
  remove(key) { bag.save(bag.lines().filter(x => x.key !== key)); },
  clear() { bag.save([]); },
  detailed() {
    return bag.lines().map(l => { const p = bySlug(l.slug); if (!p) return null; const v = variantById(p, l.variant_id); const price = priceOf(p, v);
      return { ...l, p, v, price, unit: unitOf(p, v), line: round2(price * l.qty) }; }).filter(Boolean);
  },
  totals(state = BUSINESS.state) {
    const items = bag.detailed(), subtotal = round2(items.reduce((s, i) => s + i.line, 0));
    const savings = round2(items.reduce((s, i) => { const c = compareOf(i.p, i.v); return s + (c ? (c - i.price) * i.qty : 0); }, 0));
    const tax = round2(subtotal * BUSINESS.gst_percent / 100), total = round2(subtotal + tax);
    return { items, subtotal, savings, tax, total, intra: state === BUSINESS.state, advance: ceil2(total * BUSINESS.advance_percent / 100) };
  }
};
const saved = {
  all: () => store.get('ms.saved', []),
  has: s => saved.all().includes(s),
  toggle(s) { const a = saved.all(); const i = a.indexOf(s); i < 0 ? a.push(s) : a.splice(i, 1); store.set('ms.saved', a); updateCounts(); return i < 0; }
};
function updateCounts() {
  $$('[data-count=bag]').forEach(e => e.textContent = bag.lines().length);
  $$('[data-count=saved]').forEach(e => e.textContent = saved.all().length);
}
function toast(m) {
  let t = $('#toast'); if (!t) return;
  t.textContent = m; t.style.opacity = 1; clearTimeout(t._h); t._h = setTimeout(() => t.style.opacity = 0, 2000);
}

/* =====================================================================
   CHROME — utility bar, header + mega menu, drawer, footer, bottom bar
   ===================================================================== */
const WRAP = 'w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-10 2xl:px-16';

function megaHtml() {
  const spaces = topCats().filter(c => children(c.id).length), origins = topCats().filter(c => !children(c.id).length), f = PRODUCTS[0];
  return `${spaces.map(c => `<div class="col-span-2"><a href="shop.html?cat=${c.slug}" class="flex items-center gap-2 font-semibold text-[15px] hover:text-teal"><i data-lucide="${c.icon}"></i>${c.name}</a>
      <ul class="mt-3 space-y-1">${children(c.id).map(s => `<li><a href="shop.html?cat=${s.slug}" class="flex items-center justify-between gap-3 py-1.5 px-2 -mx-2 rounded-lg hover:bg-cream text-[14px] text-ink-500 hover:text-ink">${s.name}<span class="text-[11.5px] text-ink-300">${countIn(s.id)}</span></a></li>`).join('')}
      <li><a href="shop.html?cat=${c.slug}" class="block py-1.5 text-[13px] font-semibold text-teal">All ${c.name.toLowerCase()} →</a></li></ul></div>`).join('')}
    <div class="col-span-2"><p class="font-semibold text-[15px]">By origin</p>
      <ul class="mt-3 space-y-1">${origins.map(c => `<li><a href="shop.html?cat=${c.slug}" class="flex items-center justify-between py-1.5 px-2 -mx-2 rounded-lg hover:bg-cream text-[14px] text-ink-500 hover:text-ink">${c.name}<span class="text-[11.5px] text-ink-300">${countIn(c.id)}</span></a></li>`).join('')}</ul>
      <p class="font-semibold text-[15px] mt-5">By need</p>
      <div class="mt-2 flex flex-wrap gap-1.5">${TAGS.filter(t => t.copy).map(t => `<a href="shop.html?tag=${encodeURIComponent(t.name)}" class="text-[12px] px-2.5 py-1 rounded-full bg-cream hover:bg-cream-300">${t.name}</a>`).join('')}</div></div>
    <a href="${productUrl(f)}" class="col-span-4 relative rounded-2xl overflow-hidden min-h-[230px] group"><img src="${f.room}" alt="" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition duration-700"><div class="absolute inset-0 bg-gradient-to-t from-ink/80 to-transparent"></div>
      <div class="absolute left-5 bottom-5 text-cream"><p class="text-[11px] uppercase tracking-[.16em] text-cream/70">Featured</p><p class="font-display text-[24px]">${f.name}</p><p class="text-[13px] text-cream/80">From ${inr(minPrice(f))} / sq ft</p></div></a>`;
}

function renderChrome({ active = '', minimal = false } = {}) {
  const head = document.createElement('div');
  head.innerHTML = minimal ? `
  <header class="bg-cream border-b border-ink/10"><div class="${WRAP} h-[72px] flex items-center justify-between">
    <a href="index.html" class="flex items-center gap-2.5"><img src="assets/logo.webp" alt="" class="h-11 w-auto"><span class="leading-tight"><span class="block font-display text-[19px]">Maa Sarada</span><span class="block text-[10.5px] uppercase tracking-[.18em] text-ink-500">Marble &amp; Stone · Bankura</span></span></a>
    <span class="flex items-center gap-2 text-[13px] text-ink-500"><i data-lucide="lock-keyhole" class="text-teal"></i> Secure checkout</span>
  </div></header>` : `
  <div class="bg-ink text-cream/80 text-[12.5px]"><div class="${WRAP} h-9 flex items-center justify-between gap-4">
    <div class="flex items-center gap-5 overflow-x-auto no-scrollbar whitespace-nowrap"><span class="flex items-center gap-1.5"><i data-lucide="truck"></i> Delivery included in every price</span><span class="hidden sm:flex items-center gap-1.5"><i data-lucide="smartphone"></i> Pay ${BUSINESS.advance_percent}% advance or full by UPI</span><span class="hidden md:flex items-center gap-1.5"><i data-lucide="receipt-text"></i> GST invoice on every order</span></div>
    <div class="hidden md:flex items-center gap-5 whitespace-nowrap"><a href="track.html" class="hover:text-cream">Track order</a><a href="track.html#pay" class="hover:text-cream">Pay balance</a><a href="tel:${BUSINESS.phone}" class="flex items-center gap-1.5 hover:text-cream"><i data-lucide="phone"></i> Showroom</a></div>
  </div></div>
  <header class="sticky top-0 z-30 bg-cream/90 backdrop-blur border-b border-ink/10">
    <div class="${WRAP} h-[72px] flex items-center gap-4 lg:gap-8">
      <button id="menuBtn" class="lg:hidden -ml-1 p-2 rounded-lg hover:bg-ink/5" aria-label="Open menu" aria-expanded="false"><i data-lucide="menu" class="!w-6 !h-6"></i></button>
      <a href="index.html" class="flex items-center gap-2.5 shrink-0" aria-label="Maa Sarada home"><img src="assets/logo.webp" alt="" class="h-11 w-auto"><span class="hidden sm:block leading-tight"><span class="block font-display text-[19px] tracking-tight">Maa Sarada</span><span class="block text-[10.5px] uppercase tracking-[.18em] text-ink-500">Marble &amp; Stone · Bankura</span></span></a>
      <nav class="hidden lg:flex items-center gap-1 text-[14px] font-medium" aria-label="Main">
        <div class="has-mega"><a href="shop.html" class="flex items-center gap-1 px-3 py-2 rounded-full hover:bg-ink/5 ${active === 'shop' ? 'bg-ink/5' : ''}">Shop by space <i data-lucide="chevron-down" class="!w-4 !h-4"></i></a>
          <div class="mega absolute left-0 right-0 top-full"><div class="${WRAP}"><div class="bg-white rounded-b-2xl shadow-card border border-ink/10 border-t-0 p-7 grid grid-cols-12 gap-8">${megaHtml()}</div></div></div></div>
        <a href="shop.html?cat=imported" class="px-3 py-2 rounded-full hover:bg-ink/5">Imported</a>
        <a href="shop.html?cat=indian" class="px-3 py-2 rounded-full hover:bg-ink/5">Indian</a>
        <a href="brands.html" class="px-3 py-2 rounded-full hover:bg-ink/5 ${active === 'brands' ? 'bg-ink/5' : ''}">Brands</a>
        <a href="shop.html?offer=1" class="px-3 py-2 rounded-full hover:bg-ink/5 text-copper">Offers</a>
        <a href="calculator.html" class="px-3 py-2 rounded-full hover:bg-ink/5 ${active === 'plan' ? 'bg-ink/5' : ''}">Plan your space</a>
      </nav>
      <form action="shop.html" class="relative flex-1 max-w-md ml-auto hidden md:block" role="search">
        <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-400"></i>
        <input id="q" name="q" type="search" autocomplete="off" value="${esc(qs('q') || '')}" placeholder="Search stone, room or need…" aria-label="Search" class="w-full h-11 pl-10 pr-4 rounded-full bg-white border border-ink/10 focus:border-teal focus:ring-4 focus:ring-teal/10 outline-none text-[14px]">
        <div id="suggest" class="hidden absolute left-0 right-0 top-[calc(100%+8px)] bg-white rounded-2xl shadow-card border border-ink/10 p-2 z-40"></div>
      </form>
      <div class="flex items-center gap-1 ml-auto md:ml-0">
        <a href="shop.html" class="md:hidden p-2.5 rounded-full hover:bg-ink/5" aria-label="Search"><i data-lucide="search"></i></a>
        <a href="saved.html" class="relative p-2.5 rounded-full hover:bg-ink/5" aria-label="Saved"><i data-lucide="heart"></i><b data-count="saved" class="absolute top-0.5 right-0.5 min-w-[17px] h-[17px] px-1 rounded-full bg-teal text-white text-[10px] grid place-items-center">0</b></a>
        <a href="cart.html" class="relative p-2.5 rounded-full hover:bg-ink/5" aria-label="Bag"><i data-lucide="shopping-bag"></i><b data-count="bag" class="absolute top-0.5 right-0.5 min-w-[17px] h-[17px] px-1 rounded-full bg-copper text-white text-[10px] grid place-items-center">0</b></a>
        <a href="account.html" class="hidden lg:flex items-center gap-2 ml-1 pl-3 pr-4 h-10 rounded-full border border-ink/15 hover:border-ink/40 text-[13.5px] font-medium ${active === 'account' ? 'border-ink/40' : ''}"><i data-lucide="user-round"></i> Account</a>
      </div>
    </div>
  </header>
  <div id="drawer" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-label="Menu"><div class="absolute inset-0 bg-ink/40" data-close></div>
    <div class="absolute left-0 top-0 bottom-0 w-[86%] max-w-sm bg-cream p-5 overflow-y-auto">
      <div class="flex items-center justify-between mb-5"><span class="font-display text-xl">Shop</span><button class="p-2 rounded-full hover:bg-ink/5" data-close aria-label="Close menu"><i data-lucide="x"></i></button></div>
      <div class="space-y-2">${topCats().map(c => children(c.id).length
        ? `<details class="bg-white rounded-xl px-4 py-3"><summary class="flex items-center justify-between font-semibold cursor-pointer list-none">${c.name}<i data-lucide="chevron-down"></i></summary><ul class="mt-2">${children(c.id).map(s => `<li><a href="shop.html?cat=${s.slug}" class="flex justify-between py-2 text-ink-500">${s.name}<span class="text-ink-300 text-[12px]">${countIn(s.id)}</span></a></li>`).join('')}<li><a href="shop.html?cat=${c.slug}" class="block py-2 font-semibold text-teal text-[13px]">All ${c.name.toLowerCase()}</a></li></ul></details>`
        : `<a href="shop.html?cat=${c.slug}" class="flex justify-between bg-white rounded-xl px-4 py-3 font-semibold">${c.name}<span class="text-ink-300 text-[12px] font-normal">${countIn(c.id)}</span></a>`).join('')}</div>
      <div class="mt-6 grid gap-1 text-[15px]"><a href="brands.html" class="py-2">Brands</a><a href="shop.html?offer=1" class="py-2 text-copper">Offers</a><a href="calculator.html" class="py-2">Plan your space</a><a href="track.html" class="py-2">Track order</a><a href="account.html" class="py-2">Account</a></div>
    </div></div>`;
  document.body.prepend(...head.children);

  const foot = document.createElement('div');
  foot.innerHTML = minimal ? `
  <footer class="mt-16 border-t border-ink/10"><div class="${WRAP} py-6 flex flex-col sm:flex-row gap-2 justify-between text-[12.5px] text-ink-500"><span>© 2026 ${BUSINESS.name}</span><span class="flex gap-4"><a href="policy.html#returns" class="hover:text-ink">Returns</a><a href="policy.html#delivery" class="hover:text-ink">Delivery</a><a href="policy.html#privacy" class="hover:text-ink">Privacy</a></span></div></footer>` : `
  <footer class="mt-24 bg-ink text-cream/75"><div class="${WRAP} py-14 grid grid-cols-2 lg:grid-cols-12 gap-10">
    <div class="col-span-2 lg:col-span-4"><div class="flex items-center gap-3"><img src="assets/logo.webp" alt="" class="h-14 w-auto"><div><p class="font-display text-[22px] text-cream">Maa Sarada</p><p class="text-[11px] uppercase tracking-[.18em]">Marble &amp; Stone · Bankura</p></div></div>
      <p class="mt-5 max-w-xs text-[14px] leading-relaxed">Natural stone for homes across Bankura, Bishnupur, Durgapur and beyond. Chosen slab by slab.</p>
      <p class="mt-4 text-[13px]">${BUSINESS.address}<br>${BUSINESS.hours}</p>
      <div class="mt-5 flex items-center gap-2 text-[12px]"><span class="px-2.5 py-1 rounded-md bg-cream/10">UPI</span><span class="px-2.5 py-1 rounded-md bg-cream/10">GST invoice</span><span class="px-2.5 py-1 rounded-md bg-cream/10">Delivery included</span></div></div>
    <div class="lg:col-span-4 grid grid-cols-2 gap-8">${topCats().filter(c => children(c.id).length).map(c => `<div><h3 class="text-cream font-semibold text-[14px] mb-3"><a href="shop.html?cat=${c.slug}">${c.name}</a></h3><ul class="space-y-2 text-[14px]">${children(c.id).map(s => `<li><a href="shop.html?cat=${s.slug}" class="hover:text-cream">${s.name}</a></li>`).join('')}</ul></div>`).join('')}
      <div><h3 class="text-cream font-semibold text-[14px] mb-3">Origin</h3><ul class="space-y-2 text-[14px]">${topCats().filter(c => !children(c.id).length).map(c => `<li><a href="shop.html?cat=${c.slug}" class="hover:text-cream">${c.name}</a></li>`).join('')}</ul></div></div>
    <div class="lg:col-span-2"><h3 class="text-cream font-semibold text-[14px] mb-3">Plan</h3><ul class="space-y-2 text-[14px]"><li><a href="visualizer.html" class="hover:text-cream">Room visualiser</a></li><li><a href="calculator.html" class="hover:text-cream">Calculator</a></li><li><a href="catalogues.html" class="hover:text-cream">Catalogues</a></li><li><a href="saved.html" class="hover:text-cream">Saved</a></li><li><a href="brands.html" class="hover:text-cream">Brands</a></li></ul></div>
    <div class="lg:col-span-2"><h3 class="text-cream font-semibold text-[14px] mb-3">Help</h3><ul class="space-y-2 text-[14px]"><li><a href="track.html" class="hover:text-cream">Track your order</a></li><li><a href="track.html#pay" class="hover:text-cream">Pay a balance</a></li><li><a href="policy.html#returns" class="hover:text-cream">Returns &amp; replacements</a></li><li><a href="policy.html#delivery" class="hover:text-cream">Delivery</a></li><li><a href="contact.html" class="hover:text-cream">Contact &amp; visit</a></li></ul></div>
  </div><div class="border-t border-cream/10"><div class="${WRAP} py-5 flex flex-col sm:flex-row gap-2 justify-between text-[12.5px] text-cream/50"><span>© 2026 ${BUSINESS.name}, Bankura</span><span class="flex gap-4"><a href="policy.html#privacy" class="hover:text-cream">Privacy</a><a href="policy.html#terms" class="hover:text-cream">Terms</a><span>Prices include delivery · GST extra</span></span></div></div></footer>
  <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-cream/95 backdrop-blur border-t border-ink/10 grid grid-cols-5 text-[11px] font-medium" aria-label="Quick">
    ${[['index.html', 'house', 'Home', 'home'], ['shop.html', 'layout-grid', 'Shop', 'shop'], ['calculator.html', 'ruler', 'Plan', 'plan'], ['saved.html', 'heart', 'Saved', 'saved'], ['cart.html', 'shopping-bag', 'Bag', 'bag']].map(([h, i, l, k]) => `<a href="${h}" class="py-2.5 flex flex-col items-center gap-0.5 ${active === k ? 'text-teal' : ''}"><i data-lucide="${i}"></i>${l}</a>`).join('')}
  </nav>`;
  foot.innerHTML += `
  <button id="mapBtn" class="fixed z-40 left-4 bottom-20 lg:bottom-5 h-10 px-4 rounded-full bg-teal text-white text-[12.5px] font-semibold shadow-card flex items-center gap-2"><i data-lucide="database"></i> <span>Show schema map</span></button>
  <div id="toast" role="status" aria-live="polite" class="fixed z-[60] left-1/2 -translate-x-1/2 bottom-24 lg:bottom-8 bg-ink text-cream text-[13.5px] px-4 py-2.5 rounded-full shadow-card opacity-0 transition pointer-events-none"></div>`;
  document.body.append(...foot.children);
  if (!minimal) document.body.classList.add('pb-16', 'lg:pb-0');

  // behaviour
  const mb = $('#menuBtn');
  if (mb) { mb.onclick = () => { $('#drawer').classList.remove('hidden'); mb.setAttribute('aria-expanded', 'true'); };
    $('#drawer').addEventListener('click', e => { if (e.target.closest('[data-close]')) { $('#drawer').classList.add('hidden'); mb.setAttribute('aria-expanded', 'false'); } }); }
  $('#mapBtn').onclick = () => { const on = document.body.classList.toggle('map-on'); $('#mapBtn span').textContent = on ? 'Hide schema map' : 'Show schema map'; };
  const q = $('#q'); if (q) wireSuggest(q);
  updateCounts();
}

function wireSuggest(input) {
  const box = $('#suggest');
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    if (q.length < 2) return box.classList.add('hidden');
    const cats = CATEGORIES.filter(c => c.name.toLowerCase().includes(q)).slice(0, 3);
    const tags = TAGS.filter(t => t.purpose === 'discovery' && t.name.toLowerCase().includes(q)).slice(0, 3);
    const prods = live.filter(p => [p.name, p.attrs.origin, p.colour, p.attrs.stone_type].join(' ').toLowerCase().includes(q)).slice(0, 4);
    const g = (t, a) => a.length ? `<p class="px-3 pt-2 pb-1 text-[11px] uppercase tracking-[.14em] text-ink-400 font-semibold">${t}</p>${a.join('')}` : '';
    box.innerHTML = (g('Stones', prods.map(p => `<a href="${productUrl(p)}" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-cream"><img src="${p.img}" class="w-9 h-9 rounded-lg object-cover" alt=""><span class="text-[13.5px] leading-tight"><b class="font-semibold">${p.name}</b><br><small class="text-ink-400">${p.attrs.origin} · ${purchasable(p) ? inr(minPrice(p)) + '/' + p.pricing.unit : 'Price on request'}</small></span></a>`))
      + g('Rooms & spaces', cats.map(c => { const par = catById(c.parent_id); return `<a href="shop.html?cat=${c.slug}" class="flex items-center justify-between px-3 py-2 rounded-xl hover:bg-cream text-[13.5px]">${par ? `<span><span class="text-ink-400">${par.name} ›</span> ${c.name}</span>` : c.name}<small class="text-ink-400">${countIn(c.id)}</small></a>`; }))
      + g('Needs', tags.map(t => `<a href="shop.html?tag=${encodeURIComponent(t.name)}" class="flex items-center gap-2 px-3 py-2 rounded-xl hover:bg-cream text-[13.5px]"><i data-lucide="${t.icon}" class="text-teal"></i>${t.label || t.name}</a>`)))
      || `<p class="px-3 py-3 text-[13.5px] text-ink-500">No match for “${esc(input.value)}”. Try a colour, room or origin.</p>`;
    box.classList.remove('hidden'); icons();
  });
  document.addEventListener('click', e => { if (!e.target.closest('#suggest') && e.target !== input) box.classList.add('hidden'); });
}

/* =====================================================================
   PRODUCT CARD — every state the schema allows
   ===================================================================== */
function priceBlock(p) {
  const pr = p.pricing, unit = p.pricing.unit;
  if (p.status === 'coming_soon') return `<p class="text-[13px] text-ink-500">Launching soon${p.show_price ? ` · from <b class="text-ink">${inr(minPrice(p))}</b>/${unit}` : ''}</p>`;
  if (!p.show_price || p.is_enquiry_only) return `<p class="text-[14px] font-semibold">Price on request</p><p class="text-[11.5px] text-ink-400">${p.is_enquiry_only ? 'Enquiry only · we call with a quote' : 'Ask for today’s rate'}</p>`;
  const dv = defaultVariant(p), price = p.variants.length ? minPrice(p) : pr.price, cmp = p.variants.length ? null : pr.compare_at_price;
  const c = cmp || (dv && dv.compare_at_price && dv.price === price ? dv.compare_at_price : null), off = c ? Math.round((1 - price / c) * 100) : 0;
  return `<p class="flex items-baseline gap-1.5 flex-wrap">${p.variants.length > 1 ? '<span class="text-[11.5px] text-ink-400">from</span>' : ''}<b class="text-[18px] font-bold">${inr(price)}</b><span class="text-[12.5px] text-ink-500">/ ${unit}</span>${c ? `<del class="text-[12.5px] text-ink-300">${inr(c)}</del><span class="text-[11.5px] font-bold text-copper">${off}% off</span>` : ''}</p>
    ${pr.pack_qty ? `<p class="text-[11.5px] text-ink-400">1 box = ${pr.pack_qty} ${pr.pack_unit} · ${inr(price / pr.pack_qty)}/${pr.pack_unit}</p>` : ''}`;
}
function badgeHtml(p) {
  const b = [];
  if (p.status === 'coming_soon') b.push('<span class="bg-ink text-cream">Coming soon</span>');
  else if (p.stock === 'out') b.push('<span class="bg-white text-ink-500">Out of stock</span>');
  else if (p.is_bestseller) b.push('<span class="bg-gold text-white">Bestseller</span>');
  else if (p.tags.includes('New arrival')) b.push('<span class="bg-teal text-white">New</span>');
  else if (p.tags.includes('premium')) b.push('<span class="bg-ink text-cream">Premium</span>');
  if (p.stock === 'low' && p.status === 'active') b.push('<span class="bg-copper-50 text-copper">Low stock</span>');
  return b.join('');
}
function ctaHtml(p) {
  const line = 'w-full h-10 rounded-full border border-ink/20 hover:border-ink text-[13px] font-semibold flex items-center justify-center gap-1.5';
  if (p.status === 'coming_soon') return `<button class="${line}" data-notify="${p.slug}"><i data-lucide="bell"></i> Notify me</button>`;
  if (p.is_enquiry_only || !p.show_price) return `<a href="${productUrl(p)}#enquire" class="${line}"><i data-lucide="message-circle"></i> Enquire</a>`;
  if (p.stock === 'out') return `<a href="${productUrl(p)}#enquire" class="${line}"><i data-lucide="message-circle"></i> Ask for restock</a>`;
  if (activeVariants(p).length > 1) return `<a href="${productUrl(p)}" class="w-full h-10 rounded-full bg-ink hover:bg-ink-700 text-cream text-[13px] font-semibold flex items-center justify-center gap-1.5"><i data-lucide="sliders-horizontal"></i> Choose ${p.options.map(id => OPTION_GROUPS.find(g => g.id === id).name.toLowerCase()).join(' & ')}</a>`;
  return `<button class="w-full h-10 rounded-full bg-ink hover:bg-ink-700 text-cream text-[13px] font-semibold flex items-center justify-center gap-1.5" data-quick-add="${p.slug}"><i data-lucide="shopping-bag"></i> Add to bag</button>`;
}
function card(p) {
  const need = publicTags(p).find(t => t.copy), isSaved = saved.has(p.slug);
  const specs = [p.attrs.finish, p.attrs.thickness, activeVariants(p).length > 1 ? `${activeVariants(p).length} options` : null].filter(Boolean).join(' · ');
  return `<article class="card group bg-white rounded-[20px] border border-ink/5 hover:shadow-card transition overflow-hidden flex flex-col">
    <a href="${productUrl(p)}" class="card-img relative block aspect-square overflow-hidden bg-cream">
      <img src="${p.img}" alt="${esc(p.name)} stone" class="absolute inset-0 w-full h-full object-cover" loading="lazy">
      <img src="${p.room}" alt="" class="alt absolute inset-0 w-full h-full object-cover" loading="lazy">
      <span class="absolute top-3 left-3 flex flex-col items-start gap-1 [&>span]:text-[10.5px] [&>span]:font-bold [&>span]:uppercase [&>span]:tracking-[.1em] [&>span]:px-2 [&>span]:py-1 [&>span]:rounded-md">${badgeHtml(p)}</span>
    </a>
    <div class="p-3.5 sm:p-4 flex flex-col flex-1">
      <div class="flex items-start justify-between gap-2">
        <p class="text-[11px] uppercase tracking-[.12em] text-ink-400 truncate">${esc(p.attrs.origin)}</p>
        <button class="p-1.5 -mt-1 -mr-1.5 rounded-full hover:bg-cream ${isSaved ? 'text-copper' : 'text-ink-400'}" data-save="${p.slug}" aria-label="Save ${esc(p.name)}" aria-pressed="${isSaved}"><i data-lucide="heart" ${isSaved ? 'fill="currentColor"' : ''}></i></button>
      </div>
      <h3 class="font-display text-[19px] sm:text-[20px] leading-tight mt-0.5"><a href="${productUrl(p)}" class="hover:text-teal">${esc(p.name)}</a></h3>
      <p class="text-[12.5px] text-ink-500 mt-1 truncate">${specs || esc(p.attrs.stone_type)}</p>
      ${p.reviews ? `<p class="text-[12px] mt-1.5"><span class="text-gold">★</span> <b>${p.rating}</b> <span class="text-ink-400">(${p.reviews})</span>${need ? ` <span class="text-ink-300 mx-1">·</span><span class="text-teal">${need.name}</span>` : ''}</p>` : '<p class="text-[12px] mt-1.5 text-ink-400">No reviews yet</p>'}
      <div class="mt-3">${priceBlock(p)}</div>
      <div class="mt-auto pt-4">${ctaHtml(p)}</div>
    </div></article>`;
}
const quickQty = p => p.pricing.unit === 'box' ? 1 : 100; // no min_qty column yet — see notes

/* global click actions shared by every page */
document.addEventListener('click', e => {
  const t = e.target.closest('[data-save],[data-quick-add],[data-notify]'); if (!t) return;
  const d = t.dataset;
  if (d.save) { const on = saved.toggle(d.save); toast(on ? 'Saved' : 'Removed from saved'); t.classList.toggle('text-copper', on); t.classList.toggle('text-ink-400', !on); t.setAttribute('aria-pressed', on); t.innerHTML = `<i data-lucide="heart" ${on ? 'fill="currentColor"' : ''}></i>`; icons(); document.dispatchEvent(new CustomEvent('saved-changed')); }
  if (d.quickAdd) { const p = bySlug(d.quickAdd), q = quickQty(p); bag.add(p.slug, 0, q); toast(`Added ${q} ${p.pricing.unit} — change it in your bag`); }
  if (d.notify) toast('We’ll message you when it arrives');
});

/* =====================================================================
   UPI PAYMENT WINDOW (payments.md §1) — one component used by
   checkout, order page, pay link. 6-minute timer, name + UTR/screenshot.
   ===================================================================== */
function openPayWindow({ amount, ref, title = 'Pay', onSubmit, onExpire }) {
  const SECS = 6 * 60; let left = SECS, timer;
  const big = amount > BUSINESS.upi_limit;
  const el = document.createElement('div');
  el.className = 'fixed inset-0 z-[70] flex items-end sm:items-center justify-center p-0 sm:p-4';
  el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-label', 'Payment window');
  el.innerHTML = `<div class="absolute inset-0 bg-ink/60 backdrop-blur-sm"></div>
  <div class="relative w-full sm:max-w-[560px] max-h-[96vh] overflow-y-auto bg-cream-50 rounded-t-[28px] sm:rounded-[28px] shadow-2xl" data-map="payment_attempts (expires_at = +6 min) → payments on submit">
    <div class="sticky top-0 z-10 bg-ink text-cream px-6 py-4 flex items-center justify-between rounded-t-[28px]">
      <div><p class="text-[11px] uppercase tracking-[.16em] text-cream/60">${esc(title)} · ${ref}</p><p class="font-display text-[28px] leading-tight">${inr(amount, true)}</p></div>
      <div class="text-right"><p class="text-[11px] uppercase tracking-[.16em] text-cream/60">Time left</p><p id="pwTime" class="font-display text-[28px] tabular-nums">06:00</p></div>
    </div>
    <div class="h-1 bg-ink/10"><i id="pwBar" class="block h-full bg-copper transition-[width] duration-1000 ease-linear" style="width:100%"></i></div>
    <div id="pwBody" class="p-6">
      <p class="flex items-start gap-2 text-[13px] bg-copper-50 text-copper-600 rounded-xl px-3.5 py-2.5"><i data-lucide="triangle-alert" class="mt-0.5 shrink-0"></i> Don’t close or refresh this window until you submit.</p>
      <h3 class="mt-5 font-semibold flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-ink text-cream text-[12px] grid place-items-center">1</span> Pay</h3>
      <div class="mt-3 grid sm:grid-cols-[auto,1fr] gap-5 items-center bg-white rounded-2xl p-4 border border-ink/5">
        <div id="pwQr" class="w-[148px] h-[148px] mx-auto bg-white p-1.5 rounded-xl ring-1 ring-ink/10"></div>
        <div class="space-y-2.5 text-[13.5px]">
          <div><p class="text-ink-400 text-[12px]">UPI ID</p><p class="flex items-center gap-2 font-semibold">${BUSINESS.upi_id} <button type="button" class="text-teal text-[12px] font-semibold" data-copy="${BUSINESS.upi_id}">Copy</button></p></div>
          <div><p class="text-ink-400 text-[12px]">Reference — keep it in the payment note</p><p class="flex items-center gap-2 font-semibold">${ref} <button type="button" class="text-teal text-[12px] font-semibold" data-copy="${ref}">Copy</button></p></div>
          <a href="upi://pay?pa=${BUSINESS.upi_id}&pn=Maa%20Sarada&am=${amount.toFixed(2)}&cu=INR&tn=${ref}&tr=${ref}" class="sm:hidden btn btn-dark !h-11 w-full"><i data-lucide="smartphone"></i> Open UPI app</a>
          <p class="hidden sm:block text-[12px] text-ink-400">Scan with GPay, PhonePe, Paytm or any UPI app.</p>
        </div>
      </div>
      <details class="mt-3 text-[13px] ${big ? 'open' : ''}" ${big ? 'open' : ''}><summary class="cursor-pointer font-semibold text-teal">${big ? 'Above the UPI limit — use bank transfer' : 'Prefer bank transfer (NEFT/IMPS)?'}</summary>
        <dl class="mt-2 grid grid-cols-2 gap-2 bg-white rounded-xl p-3.5 border border-ink/5"><dt class="text-ink-400">Account name</dt><dd class="font-semibold">Maa Sarada Marble</dd><dt class="text-ink-400">Account no.</dt><dd class="font-semibold">XXXX XXXX 4821</dd><dt class="text-ink-400">IFSC</dt><dd class="font-semibold">SBIN000XXXX</dd></dl></details>

      <h3 class="mt-6 font-semibold flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-ink text-cream text-[12px] grid place-items-center">2</span> Tell us you’ve paid</h3>
      <form id="pwForm" class="mt-3 space-y-4" novalidate>
        <label class="block"><span class="lbl">Name on your UPI / bank account <span class="text-copper">*</span></span><input id="pwName" class="field" autocomplete="name" placeholder="As shown in your UPI app"></label>
        <div class="grid sm:grid-cols-[1fr,auto,1fr] gap-3 items-end">
          <label class="block"><span class="lbl">Payment ID / UTR</span><input id="pwUtr" class="field" inputmode="numeric" placeholder="12-digit UTR"></label>
          <span class="text-[12px] text-ink-400 pb-3 text-center">or</span>
          <label class="block"><span class="lbl">Screenshot</span><span class="field !flex items-center gap-2 cursor-pointer text-ink-500"><i data-lucide="image-up"></i><span id="pwFileName" class="truncate">Upload image</span><input id="pwFile" type="file" accept="image/*" class="sr-only"></span></label>
        </div>
        <p id="pwErr" class="hidden text-[13px] text-copper"></p>
        <button class="btn btn-copper w-full">I have paid — Submit</button>
        <p class="text-[12px] text-ink-400 text-center">We check every payment by hand. You’ll see “Payment under review” until we confirm it.</p>
      </form>
    </div>
  </div>`;
  document.body.appendChild(el); document.body.style.overflow = 'hidden';
  if (window.QRCode) new QRCode($('#pwQr', el), { text: `upi://pay?pa=${BUSINESS.upi_id}&pn=Maa Sarada&am=${amount.toFixed(2)}&cu=INR&tn=${ref}`, width: 134, height: 134, colorDark: '#15211F' });
  icons();
  const warn = e => { e.preventDefault(); e.returnValue = ''; };
  window.addEventListener('beforeunload', warn);
  const close = () => { clearInterval(timer); window.removeEventListener('beforeunload', warn); el.remove(); document.body.style.overflow = ''; };
  const tick = () => {
    left--; $('#pwTime', el).textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
    $('#pwBar', el).style.width = (left / SECS * 100) + '%';
    if (left <= 60) $('#pwTime', el).classList.add('text-[#E9B48F]');
    if (left <= 0) expire();
  };
  const expire = () => {
    clearInterval(timer); window.removeEventListener('beforeunload', warn);
    $('#pwBody', el).innerHTML = `<div class="text-center py-6"><span class="w-14 h-14 mx-auto rounded-full bg-copper-50 text-copper grid place-items-center"><i data-lucide="timer-off" class="!w-7 !h-7"></i></span>
      <h3 class="font-display text-[26px] mt-4">Time ran out</h3><p class="text-ink-500 mt-2 max-w-sm mx-auto text-[14px]">If you already paid, <b class="text-ink">don’t pay again</b>. Message us on WhatsApp with your payment ID and we’ll sort it out.</p>
      <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center"><button data-retry class="btn btn-dark">Try again</button><a href="https://wa.me/${BUSINESS.whatsapp}?text=${encodeURIComponent('Payment ' + ref)}" class="btn btn-line"><i data-lucide="message-circle"></i> WhatsApp us</a><button data-x class="btn">Close</button></div></div>`;
    icons(); onExpire && onExpire();
    $('[data-retry]', el).onclick = () => { close(); openPayWindow({ amount, ref: ref.replace(/\d+$/, n => String(+n + 1).padStart(n.length, '0')), title, onSubmit, onExpire }); };
    $('[data-x]', el).onclick = close;
  };
  timer = setInterval(tick, 1000);
  el._expire = expire; window._payWindow = el; // design aid: _payWindow._expire() previews the timeout state
  el.addEventListener('click', e => { const c = e.target.closest('[data-copy]'); if (c) { navigator.clipboard?.writeText(c.dataset.copy); toast('Copied'); } });
  $('#pwFile', el).onchange = e => $('#pwFileName', el).textContent = e.target.files[0]?.name || 'Upload image';
  $('#pwForm', el).onsubmit = e => {
    e.preventDefault();
    const name = $('#pwName', el).value.trim(), utr = $('#pwUtr', el).value.trim(), file = $('#pwFile', el).files[0], err = $('#pwErr', el);
    $('#pwName', el).setAttribute('aria-invalid', !name);
    if (!name) { err.textContent = 'Enter the name on your UPI account.'; err.classList.remove('hidden'); return; }
    if (!utr && !file) { err.textContent = 'Add the payment ID / UTR or upload a screenshot.'; err.classList.remove('hidden'); return; }
    close(); onSubmit && onSubmit({ name, utr, file: file?.name });
  };
}

/* demo order used by order / pay / track / account pages (orders + order_items + payments) */
const DEMO_ORDER = {
  order_no: 'MSM-2026-0142', public_token: 'k7Qe2xVn', created_at: '22 Nov 2026, 4:12 PM IST', channel: 'online', channel_detail: 'website',
  customer: { name: 'Ritika Sharma', phone: '+91 98300 00000', email: 'ritika@example.com' },
  address: { name: 'Ritika Sharma', phone: '+91 98300 00000', line1: '14, Lokepur Road', line2: 'Near Kenduadihi Bus Stand', area: 'Kenduadihi', city: 'Bankura', district: 'Bankura', state: 'West Bengal', pincode: '722102' },
  items: [{ slug: 'statuario-white', variant_id: 102, qty: 486 }, { slug: 'black-galaxy-granite', variant_id: 302, qty: 42 }],
  gst_percent: 18,
  payments: [{ ref: 'MSM-PA-0042', amount: 77854.00, status: 'verified', method: 'UPI', utr: '43210987XXXX', at: '22 Nov, 4:16 PM' }],
  customer_note: 'This stone lot was sold out in the size you need. We’ve refunded your advance of ₹77,854 to your UPI account (UTR 5512XXXX).'
};
function orderTotals(o) {
  const items = o.items.map(i => { const p = bySlug(i.slug), v = variantById(p, i.variant_id), price = priceOf(p, v); return { ...i, p, v, price, unit: unitOf(p, v), line: round2(price * i.qty) }; });
  const subtotal = round2(items.reduce((s, i) => s + i.line, 0)), tax = round2(subtotal * o.gst_percent / 100), total = round2(subtotal + tax);
  const paid = round2(o.payments.filter(p => p.status === 'verified').reduce((s, p) => s + p.amount, 0));
  const pending = round2(o.payments.filter(p => p.status === 'pending').reduce((s, p) => s + p.amount, 0));
  return { items, subtotal, tax, total, paid, pending, due: round2(Math.max(0, total - paid - pending)) };
}

/* =====================================================================
   VARIANT PICKER — product_variants × product_variant_values × option_values
   sel = { [option_group_id]: option_value_id }
   ===================================================================== */
const selFromVariant = v => Object.fromEntries((v?.values || []).map(id => [optionValue(id).group.id, id]));
const pickVariant = (p, sel) => p.variants.find(v => p.options.every(g => v.values.includes(sel[g]))) || null;
function valueState(p, sel, gid, vid) {
  const want = { ...sel, [gid]: vid }, exact = pickVariant(p, want);
  if (exact) return exact.status === 'active' ? 'ok' : 'inactive';            // combination exists
  return activeVariants(p).some(v => v.values.includes(vid)) ? 'other' : 'none'; // only with other choices
}
function renderOptions(el, p, sel, onPick, dark = false) {
  el.innerHTML = p.options.map(gid => {
    const g = OPTION_GROUPS.find(x => x.id === gid), used = g.values.filter(val => p.variants.some(v => v.values.includes(val.id)));
    return `<div><p class="text-[11.5px] uppercase tracking-[.14em] font-semibold mb-2 ${dark ? 'text-cream/55' : 'text-ink-500'}">${g.name} <span class="normal-case tracking-normal font-normal ${dark ? 'text-cream/80' : 'text-ink'}">· ${optionValue(sel[gid])?.value || 'Choose'}</span></p>
      <div class="flex flex-wrap gap-2">${used.map(val => {
        const st = valueState(p, sel, gid, val.id), on = sel[gid] === val.id, v = pickVariant(p, { ...sel, [gid]: val.id });
        const base = dark ? (on ? 'bg-cream text-ink border-cream' : 'border-cream/25 hover:border-cream/60') : (on ? 'bg-ink text-cream border-ink' : 'bg-white border-ink/15 hover:border-ink');
        const off = st === 'inactive' || st === 'none' ? 'line-through opacity-45' : st === 'other' ? 'border-dashed' : '';
        const price = v && v.status === 'active' && p.show_price ? `<span class="opacity-60 font-normal ml-1">${inr(v.price)}</span>` : '';
        return `<button type="button" class="px-4 h-10 rounded-full border text-[13.5px] font-semibold transition ${base} ${off}" data-g="${gid}" data-v="${val.id}" aria-pressed="${on}" ${st === 'none' ? 'disabled' : ''} title="${st === 'inactive' ? 'Not available in this combination' : st === 'other' ? 'Available with a different ' + p.options.filter(x => x !== gid).map(x => OPTION_GROUPS.find(y => y.id === x).name.toLowerCase()).join('/') : ''}">${val.value}${price}</button>`;
      }).join('')}</div></div>`;
  }).join('');
  el.onclick = e => {
    const b = e.target.closest('[data-g]'); if (!b) return;
    const gid = +b.dataset.g, vid = +b.dataset.v; let next = { ...sel, [gid]: vid }, v = pickVariant(p, next);
    if (!v || v.status !== 'active') { // jump to the nearest active combination that keeps this choice
      const alt = activeVariants(p).filter(x => x.values.includes(vid)).sort((a, b) => b.values.filter(id => Object.values(sel).includes(id)).length - a.values.filter(id => Object.values(sel).includes(id)).length || Math.abs(a.price - (pickVariant(p, sel)?.price || 0)) - Math.abs(b.price - (pickVariant(p, sel)?.price || 0)))[0]; if (alt) next = selFromVariant(alt);
    }
    onPick(next);
  };
}

/* design preview: fill an empty bag so cart / checkout have something to show (?empty=1 to see the empty state) */
function seedDemoBag() {
  if (bag.lines().length || qs('empty')) return;
  bag.save([{ key: 'statuario-white:102', slug: 'statuario-white', variant_id: 102, qty: 486 }, { key: 'black-galaxy-granite:302', slug: 'black-galaxy-granite', variant_id: 302, qty: 42 }, { key: 'ivory-cream-tiles:1002', slug: 'ivory-cream-tiles', variant_id: 1002, qty: 12 }]);
}
const PAGE_HEAD_NOTE = 'Design preview';
