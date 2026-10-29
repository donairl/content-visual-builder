// Generates fixtures/validation/cases.json from a valid base document plus one mutation per case.
// Run: node schema/tools/build-validation-cases.mjs
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..', 'fixtures', 'validation', 'cases.json');

const adv = () => ({ cssId: '', cssClasses: '', hideOn: [] });
const node = (id, type, props = {}, style = {}, children = []) => ({ id, type, props, style, advanced: adv(), children });

function base() {
  return {
    schemaVersion: 1,
    rootIds: ['cont0001'],
    nodes: {
      cont0001: node('cont0001', 'container', { contentWidth: 'boxed', tag: 'section' }, {}, ['head0001', 'vid00001']),
      head0001: node('head0001', 'heading', { text: 'Hello', tag: 'h2', link: { url: '', newTab: false, nofollow: false } }),
      vid00001: node('vid00001', 'video', { url: '', title: 'Video', autoplay: false, muted: false, loop: false, controls: true }),
    },
    settings: { metaDescription: '', contentWidth: 1140, backgroundColor: null, textColor: null, fontFamily: null },
  };
}

const cases = [];
const valid = (name, mutate) => {
  const doc = base();
  mutate(doc);
  cases.push({ name, valid: true, document: doc });
};
const invalid = (name, errorPath, mutate) => {
  const doc = base();
  mutate(doc);
  cases.push({ name, valid: false, errorPath, document: doc });
};
const head = (d) => d.nodes.head0001;

valid('base document', () => {});
valid('empty document', (d) => { d.rootIds = []; d.nodes = {}; });
valid('null device value is unset', (d) => { head(d).style.color = { desktop: '#fff', tablet: null }; });
valid('responsive size on all devices', (d) => {
  head(d).style.fontSize = { desktop: { value: 40, unit: 'px' }, tablet: { value: 2.125, unit: 'rem' }, mobile: { value: 7.5, unit: 'vw' } };
});
valid('common advanced style keys allowed on any widget', (d) => {
  head(d).style.margin = { desktop: { top: -10, unit: 'px' } };
  head(d).style.zIndex = { desktop: 3 };
});
valid('advanced attributes', (d) => { head(d).advanced = { cssId: 'intro-title', cssClasses: 'big accent_2', hideOn: ['mobile', 'tablet'] }; });
valid('missing props fall back to defaults', (d) => { head(d).props = {}; });
valid('settings with every key set', (d) => {
  d.settings = { metaDescription: 'Hi', contentWidth: 960, backgroundColor: '#ffffff', textColor: '#111', fontFamily: 'serif' };
});

invalid('wrong schema version', 'schemaVersion', (d) => { d.schemaVersion = 2; });
invalid('unknown top-level key', 'extra', (d) => { d.extra = true; });
invalid('rootIds references missing node', 'rootIds', (d) => { d.rootIds.push('miss0001'); });
invalid('orphan node', 'nodes.orph0001', (d) => { d.nodes.orph0001 = node('orph0001', 'spacer'); });
invalid('node reachable twice', 'nodes.head0001', (d) => { d.rootIds.push('head0001'); });
invalid('cycle', 'nodes.cont0001', (d) => { d.nodes.cont0001.children.push('cont0001'); });
invalid('id does not match key', 'nodes.head0001.id', (d) => { head(d).id = 'head0002'; });
invalid('bad node id pattern', 'nodes.BAD-ID01', (d) => {
  d.nodes['BAD-ID01'] = node('BAD-ID01', 'spacer');
  d.nodes.cont0001.children.push('BAD-ID01');
});
invalid('missing node key', 'nodes.head0001.advanced', (d) => { delete head(d).advanced; });
invalid('unknown node key', 'nodes.head0001.extra', (d) => { head(d).extra = 1; });
invalid('unknown widget type', 'nodes.head0001.type', (d) => { head(d).type = 'carousel'; });
invalid('leaf with children', 'nodes.head0001.children', (d) => {
  d.nodes.spac0001 = node('spac0001', 'spacer');
  head(d).children = ['spac0001'];
});
invalid('children references missing node', 'nodes.cont0001.children', (d) => { d.nodes.cont0001.children.push('miss0002'); });
invalid('too deep', 'nodes.deep0012', (d) => {
  let parent = 'cont0001';
  for (let i = 1; i <= 12; i++) {
    const id = 'deep' + String(i).padStart(4, '0');
    d.nodes[id] = node(id, 'container', { contentWidth: 'full', tag: 'div' });
    d.nodes[parent].children.push(id);
    parent = id;
  }
});
invalid('unknown prop', 'nodes.head0001.props.color', (d) => { head(d).props.color = 'red'; });
invalid('text too long', 'nodes.head0001.props.text', (d) => { head(d).props.text = 'x'.repeat(1001); });
invalid('enum out of range', 'nodes.head0001.props.tag', (d) => { head(d).props.tag = 'script'; });
invalid('javascript link', 'nodes.head0001.props.link', (d) => { head(d).props.link = { url: 'javascript:alert(1)', newTab: false, nofollow: false }; });
invalid('protocol-relative link', 'nodes.head0001.props.link', (d) => { head(d).props.link = { url: '//evil.example', newTab: false, nofollow: false }; });
invalid('link with unknown key', 'nodes.head0001.props.link', (d) => { head(d).props.link = { url: '', onclick: 'x' }; });
invalid('bool prop not boolean', 'nodes.vid00001.props.autoplay', (d) => { d.nodes.vid00001.props.autoplay = 'yes'; });
invalid('video url not http', 'nodes.vid00001.props.url', (d) => { d.nodes.vid00001.props.url = 'ftp://example.com/a.mp4'; });
invalid('image url with quote', 'nodes.imag0001.props.image', (d) => {
  d.nodes.imag0001 = node('imag0001', 'image', { image: { url: 'https://x.example/a".png', width: null, height: null, id: null } });
  d.nodes.cont0001.children.push('imag0001');
});
invalid('richtext not string', 'nodes.text0001.props.html', (d) => {
  d.nodes.text0001 = node('text0001', 'text', { html: ['<p>x</p>'] });
  d.nodes.cont0001.children.push('text0001');
});
invalid('unknown icon', 'nodes.icon0001.props.icon', (d) => {
  d.nodes.icon0001 = node('icon0001', 'icon', { icon: 'rocket' });
  d.nodes.cont0001.children.push('icon0001');
});
invalid('style key not allowed for widget', 'nodes.head0001.style.hoverBackgroundColor', (d) => { head(d).style.hoverBackgroundColor = { desktop: '#000' }; });
invalid('unknown style key', 'nodes.head0001.style.float', (d) => { head(d).style.float = { desktop: 'left' }; });
invalid('unknown device', 'nodes.head0001.style.color', (d) => { head(d).style.color = { watch: '#000' }; });
invalid('named color rejected', 'nodes.head0001.style.color.desktop', (d) => { head(d).style.color = { desktop: 'red' }; });
invalid('size unit not allowed', 'nodes.head0001.style.fontSize.desktop', (d) => { head(d).style.fontSize = { desktop: { value: 12, unit: 'pt' } }; });
invalid('size out of range', 'nodes.head0001.style.fontSize.tablet', (d) => { head(d).style.fontSize = { tablet: { value: 401, unit: 'px' } }; });
invalid('too many decimals', 'nodes.head0001.style.lineHeight.desktop', (d) => { head(d).style.lineHeight = { desktop: 1.2345 }; });
invalid('box without unit', 'nodes.head0001.style.padding.desktop', (d) => { head(d).style.padding = { desktop: { top: 10 } }; });
invalid('negative padding', 'nodes.head0001.style.padding.mobile', (d) => { head(d).style.padding = { mobile: { top: -1, unit: 'px' } }; });
invalid('integer property with fraction', 'nodes.head0001.style.zIndex.desktop', (d) => { head(d).style.zIndex = { desktop: 1.5 }; });
invalid('background bad type', 'nodes.cont0001.style.background.desktop', (d) => { d.nodes.cont0001.style.background = { desktop: { type: 'video' } }; });
invalid('background image url with parenthesis', 'nodes.cont0001.style.background.desktop', (d) => {
  d.nodes.cont0001.style.background = { desktop: { type: 'image', imageUrl: 'https://x.example/a).png' } };
});
invalid('shadow missing key', 'nodes.cont0001.style.boxShadow.desktop', (d) => {
  d.nodes.cont0001.style.boxShadow = { desktop: { x: 0, y: 0, blur: 4, color: '#000', inset: false } };
});
invalid('border bad style', 'nodes.cont0001.style.border.desktop', (d) => { d.nodes.cont0001.style.border = { desktop: { style: 'wavy' } }; });
invalid('font key unknown', 'nodes.head0001.style.fontFamily.desktop', (d) => { head(d).style.fontFamily = { desktop: 'Comic Sans' }; });
invalid('css id starts with digit', 'nodes.head0001.advanced.cssId', (d) => { head(d).advanced.cssId = '1abc'; });
invalid('css classes with quote', 'nodes.head0001.advanced.cssClasses', (d) => { head(d).advanced.cssClasses = 'a"b'; });
invalid('hideOn duplicate', 'nodes.head0001.advanced.hideOn', (d) => { head(d).advanced.hideOn = ['mobile', 'mobile']; });
invalid('unknown advanced key', 'nodes.head0001.advanced.onclick', (d) => { head(d).advanced.onclick = 'x'; });
invalid('settings width too small', 'settings.contentWidth', (d) => { d.settings.contentWidth = 100; });
invalid('settings unknown key', 'settings.favicon', (d) => { d.settings.favicon = '/x.ico'; });

// Custom CSS (README §2): the wrapper rule must not be closable from inside.
const customCss = {
  valid: [
    ['declarations', 'color: red;'],
    ['nested rules', '&:hover { opacity: .8 } a { color: #f00 }'],
    ['nested media query', '@media (max-width: 767px) { & { display: none } }'],
    ['brace inside string', 'content: "}";'],
    ['escaped quote inside string', "content: '\\'';"],
    ['escaped newline inside string', 'content: "a\\\nb";'],
    ['unquoted url', 'background: url(/img/a.png) no-repeat'],
    ['quoted url with space', 'background: url( "https://x.example/a b.png" )'],
    ['url with trailing space', 'background: url(/a.png  )'],
    ['brace inside comment', '/* } */ color: red'],
    ['attribute selector', '[data-x="1"] { color: red }'],
    ['multi-line', 'a {\n  color: red;\n}\n'],
  ],
  invalid: [
    ['closes the wrapper', '} body { display: none } .x {'],
    ['stray closing brace', 'color: red; }'],
    ['unclosed block', 'a { color: red'],
    ['html tag', '</style><script>alert(1)</script>'],
    ['unclosed comment', 'color: red /* open'],
    ['unclosed string', 'content: "unterminated'],
    ['raw newline in string', 'content: "line\nbreak";'],
    ['escape outside string', '\\7d body { color: red }'],
    ['escaped url breakout', 'x: \\75rl(") } body{color:red} p{ ")'],
    ['brace in unquoted url', 'background: url(a{b)'],
    ['space in unquoted url', 'background: url(a b)'],
    ['quote in unquoted url', "background: url(a'b)"],
    ['mismatched brackets', '( }'],
    ['control character', 'color: red;\u0000'],
  ],
};
for (const [name, css] of customCss.valid) valid(`custom css: ${name}`, (d) => { head(d).advanced.customCss = css; });
for (const [name, css] of customCss.invalid) {
  invalid(`custom css: ${name}`, 'nodes.head0001.advanced.customCss', (d) => { head(d).advanced.customCss = css; });
}
valid('page custom css', (d) => { d.settings.customCss = '& h1 { margin: 0 }'; });
invalid('page custom css closes the wrapper', 'settings.customCss', (d) => { d.settings.customCss = '} body { color: red'; });
invalid('custom css too long', 'nodes.head0001.advanced.customCss', (d) => { head(d).advanced.customCss = 'a'.repeat(10001); });

// Document-level references to global styles (README §9).
valid('global color reference', (d) => { head(d).style.color = { desktop: 'global:primary', mobile: 'global:gone-1' }; });
valid('global color in page settings', (d) => { d.settings.textColor = 'global:text'; });
valid('global font reference', (d) => { head(d).style.globalFont = { desktop: 'display' }; });
invalid('global color with bad id', 'nodes.head0001.style.color.desktop', (d) => { head(d).style.color = { desktop: 'global:Primary' }; });
invalid('global font with bad id', 'nodes.head0001.style.globalFont.desktop', (d) => { head(d).style.globalFont = { desktop: '1st' }; });
invalid('global font not allowed on widget', 'nodes.vid00001.style.globalFont', (d) => { d.nodes.vid00001.style.globalFont = { desktop: 'display' }; });

writeFileSync(out, JSON.stringify(cases, null, 2) + '\n');
console.log(`wrote ${cases.length} cases to ${out}`);

// Globals objects (README §9).
const globalsOut = join(here, '..', 'fixtures', 'validation', 'globals-cases.json');
const baseGlobals = () => ({
  colors: [{ id: 'primary', name: 'Primary', value: '#5b50e6' }],
  fonts: [{ id: 'display', name: 'Display', style: { fontFamily: { desktop: 'serif' }, fontSize: { desktop: { value: 48, unit: 'px' }, mobile: null } } }],
});
const globalsCases = [];
const gValid = (name, mutate) => { const g = baseGlobals(); mutate(g); globalsCases.push({ name, valid: true, globals: g }); };
const gInvalid = (name, errorPath, mutate) => {
  const g = baseGlobals();
  const replaced = mutate(g);
  globalsCases.push({ name, valid: false, errorPath, globals: replaced === undefined ? g : replaced });
};
gValid('base globals', () => {});
gValid('empty lists', (g) => { g.colors = []; g.fonts = []; });
gValid('transparent and 8-digit hex', (g) => { g.colors.push({ id: 'glass', name: 'Glass', value: 'transparent' }, { id: 'tint-2', name: 'Tint 2', value: '#11223344' }); });
gValid('font preset with every typography key', (g) => {
  g.fonts[0].style = {
    fontFamily: { desktop: 'mono' }, fontSize: { tablet: { value: 2, unit: 'rem' } }, fontWeight: { desktop: '900' }, lineHeight: { desktop: 1.25 },
    letterSpacing: { mobile: { value: -1, unit: 'px' } }, textTransform: { desktop: 'capitalize' }, fontStyle: { desktop: 'italic' }, textDecoration: { desktop: 'underline' },
  };
});
gInvalid('not an object', 'globals', () => 'colors');
gInvalid('unknown top-level key', 'gradients', (g) => { g.gradients = []; });
gInvalid('missing list', 'fonts', (g) => { delete g.fonts; });
gInvalid('list is not a list', 'colors', (g) => { g.colors = 'red'; });
gInvalid('too many colors', 'colors', (g) => { g.colors = Array.from({ length: 51 }, (_, i) => ({ id: `c${i}`, name: `C${i}`, value: '#000' })); });
gInvalid('entry not an object', 'colors.0', (g) => { g.colors = ['#000']; });
gInvalid('unknown entry key', 'colors.0.label', (g) => { g.colors[0].label = 'x'; });
gInvalid('missing entry key', 'colors.0.value', (g) => { delete g.colors[0].value; });
gInvalid('bad id', 'colors.0.id', (g) => { g.colors[0].id = 'Primary'; });
gInvalid('duplicate id', 'colors.1.id', (g) => { g.colors.push({ ...g.colors[0], name: 'Again' }); });
gInvalid('blank name', 'colors.0.name', (g) => { g.colors[0].name = '   '; });
gInvalid('name too long', 'fonts.0.name', (g) => { g.fonts[0].name = 'x'.repeat(61); });
gInvalid('named color', 'colors.0.value', (g) => { g.colors[0].value = 'red'; });
gInvalid('color referencing a global', 'colors.0.value', (g) => { g.colors[0].value = 'global:primary'; });
gInvalid('non-typography key in font', 'fonts.0.style.color', (g) => { g.fonts[0].style.color = { desktop: '#000' }; });
gInvalid('globalFont inside a font', 'fonts.0.style.globalFont', (g) => { g.fonts[0].style.globalFont = { desktop: 'display' }; });
gInvalid('bad device in font', 'fonts.0.style.fontSize', (g) => { g.fonts[0].style.fontSize = { watch: { value: 1, unit: 'px' } }; });
gInvalid('bad value in font', 'fonts.0.style.fontWeight.desktop', (g) => { g.fonts[0].style.fontWeight = { desktop: 'bold' }; });
writeFileSync(globalsOut, JSON.stringify(globalsCases, null, 2) + '\n');
console.log(`wrote ${globalsCases.length} cases to ${globalsOut}`);
