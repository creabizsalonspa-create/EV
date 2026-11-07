// Isolated source-backed DOM contract: vendor metadata cannot claim live charger truth.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.dataset = {};
    this.textContent = '';
    this.className = '';
  }
  append(...nodes) {this.children.push(...nodes);}
}
const document = {
  documentElement: {lang: 'en'},
  createElement: tag => new Element(tag),
};
const window = {};
const file = 'services/business-api/public/station-information.js';
vm.runInNewContext(fs.readFileSync(file, 'utf8'), {document, window, Object}, {filename: file});
assert.equal(typeof window.StationInformation.attachCard, 'function');

function output(station) {
  const card = document.createElement('article');
  window.StationInformation.attachCard(card, station);
  const walk = node => [node.textContent, ...node.children.flatMap(walk)].join(' ');
  return walk(card);
}
const missing = output({});
assert.match(missing, /not published station hours/);
assert.doesNotMatch(missing, /08:00/);

const info = {
  source_label: 'VENDOR_REPORTED_UNVERIFIED',
  description_en: 'Basement plaza', description_ar: 'الساحة السفلية',
  access_en: 'Gate B', access_ar: 'البوابة ب',
  weekly_hours: Object.fromEntries(['mon','tue','wed','thu','fri','sat','sun']
    .map(day => [day, day === 'fri' ? {open:null,close:null} : {open:'08:00',close:'22:00'}])),
  closed_dates: ['2026-12-02'],
  amenities: ['wifi','parking'],
  accessibility: ['step_free'],
};
const en = output({information: info});
assert.match(en, /Operator-reported details/);
assert.match(en, /Monday: 08:00–22:00/);
assert.match(en, /Friday: Closed/);
const overnight = output({information: {...info, weekly_hours: {...info.weekly_hours, mon: {open: '20:00', close: '02:00'}, tue: {open: '00:00', close: '00:00'}}}});
assert.match(overnight, /Monday: 20:00–02:00 \(next day\)/);
assert.match(overnight, /Tuesday: 24 hours/);
assert.match(en, /Holiday closures: 2026-12-02/);
assert.match(en, /Access: Gate B/);
assert.match(en, /Accessibility: Step-free access/);
assert.match(en, /availability remains unknown/);

document.documentElement.lang = 'ar';
const ar = output({information: info});
assert.match(ar, /الجمعة: مغلق/);
assert.match(ar, /البوابة ب/);
assert.match(ar, /مجهول|غير معروف/);

document.documentElement.lang = 'en';
const injection = '<img src=x onerror=alert(1)>';
const untrusted = output({information: {...info, description_en: injection}});
assert.ok(untrusted.includes(injection)); // visible plain text, no HTML interpretation.
assert.doesNotMatch(fs.readFileSync(file, 'utf8'), /\.innerHTML\s*=/);
console.log('PASS: station details remain source-qualified; missing metadata, weekdays/closures, accessibility, EN/AR and safe text rendering.');
