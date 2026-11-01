/** Compile the reviewed source dictionaries into portable gettext catalogs. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const directory = path.resolve(__dirname, '../languages');
const messages = {};
for (const part of ['scripts', 'settings', 'templates', 'backend', 'core', 'wizard', 'fixes', 'admin-ui']) {
    Object.assign(messages, JSON.parse(fs.readFileSync(path.join(directory, part + '-es.json'), 'utf8')));
}
const header = 'Project-Id-Version: DialogaWP\nLanguage: es_ES\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n != 1);\n';
const pairs = [
    ["You've handled %s visitor conversation so far!", "You've handled %s visitor conversations so far!"],
    ['%d admin test lead hidden', '%d admin test leads hidden'],
    ['%d lead', '%d leads'], ['%d link click', '%d link clicks'],
    ['across %1$d visitor (%2$s avg)', 'across %1$d visitors (%2$s avg)'],
    ['across %d visitor', 'across %d visitors'],
    ['%d active flow', '%d active flows'],
    ['%d conversation with lead data', '%d conversations with lead data'],
    ['%d conversation with a conversion link click', '%d conversations with conversion link clicks'],
    ['%d lead record shown', '%d lead records shown'],
    ['Lead records: %d result', 'Lead records: %d results']
];
const plurals = new Map(pairs);
const pluralOthers = new Set(pairs.map(pair => pair[1]));
const entries = [['', header], ...Object.keys(messages).filter(key => !pluralOthers.has(key)).map(key => plurals.has(key)
    ? [key + '\0' + plurals.get(key), messages[key] + '\0' + messages[plurals.get(key)]] : [key, messages[key]])];
entries.sort((a, b) => Buffer.compare(Buffer.from(a[0]), Buffer.from(b[0])));
const count = entries.length;
const originals = entries.map(entry => Buffer.from(entry[0]));
const translations = entries.map(entry => Buffer.from(entry[1]));
const offset = 28 + count * 16;
const buffers = [...originals, ...translations];
const output = Buffer.alloc(offset + buffers.reduce((sum, buffer) => sum + buffer.length + 1, 0));
[0x950412de, 0, count, 28, 28 + count * 8, 0, 0].forEach((value, index) => output.writeUInt32LE(value, index * 4));
let cursor = offset;
buffers.forEach((buffer, index) => {
    output.writeUInt32LE(buffer.length, 28 + index * 8);
    output.writeUInt32LE(cursor, 32 + index * 8);
    buffer.copy(output, cursor);
    cursor += buffer.length + 1;
});
fs.writeFileSync(path.join(directory, 'dialogawp-es_ES.mo'), output);
function quote(value) { return JSON.stringify(value); }
let po = 'msgid ""\nmsgstr ' + quote(header) + '\n\n';
let pot = 'msgid ""\nmsgstr ' + quote(header.replace('Language: es_ES\n', 'Language: \n')) + '\n\n';
for (const [key, value] of entries.slice(1)) {
    const ids = key.split('\0');
    const values = value.split('\0');
    const prefix = ids.length === 2 ? 'msgid ' + quote(ids[0]) + '\nmsgid_plural ' + quote(ids[1]) + '\n' : 'msgid ' + quote(key) + '\n';
    po += prefix + (ids.length === 2 ? 'msgstr[0] ' + quote(values[0]) + '\nmsgstr[1] ' + quote(values[1]) : 'msgstr ' + quote(value)) + '\n\n';
    pot += prefix + (ids.length === 2 ? 'msgstr[0] ""\nmsgstr[1] ""' : 'msgstr ""') + '\n\n';
}
fs.writeFileSync(path.join(directory, 'dialogawp-es_ES.po'), po);
fs.writeFileSync(path.join(directory, 'dialogawp.pot'), pot);
console.log('Compiled ' + count + ' gettext entries.');
