import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('resources/js/football-stories.js', 'utf8');
const validation = source.slice(source.indexOf('function initializeValidation('), source.indexOf('export function trackHeroKidEvent'));
for (const [minimum, count, blocked] of [[2, 1, true], [2, 2, false], [3, 2, true], [3, 3, false], [2, 5, false]]) {
    test(`football validation uses configured minimum ${minimum}, with ${count} photos`, () => {
        const context = vm.createContext({});
        vm.runInContext(validation, context);
        let submit, started = false;
        const form = {
            elements: { child_name: { value: 'Test Child' }, child_age: { value: '7' } },
            querySelector(selector) {
                if (selector === '[data-football-upload-config]') return { textContent: JSON.stringify({ minimumFiles: minimum }) };
                if (selector.includes('child_gender')) return {};
                return null;
            },
            querySelectorAll(selector) { return selector.includes('photo_upload_ids') ? Array(count).fill({}) : []; },
            addEventListener(event, callback) { assert.equal(event, 'submit'); submit = callback; },
        };
        context.initializeValidation(form, () => [{}], () => false, value => { started = value; });
        const event = { preventDefault() { throw new Error('blocked'); } };
        if (blocked) assert.throws(() => submit(event), /blocked/);
        else submit(event);
        assert.equal(started, !blocked);
    });
}
