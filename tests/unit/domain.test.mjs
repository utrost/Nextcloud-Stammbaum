import { test } from 'node:test';
import assert from 'node:assert/strict';
import { emptyPerson, editable, dateText, layoutGraph, personName } from '../../src/domain.js';
test('partial dates and literal historical wording stay unchanged', () => {
  assert.equal(dateText({ kind: 'exact', value: '1890' }), '1890');
  assert.equal(dateText({ kind: 'literal', value: '03/04' }), '03/04');
  assert.equal(dateText({ kind: 'period', value: '', end: '1900' }), '… – 1900');
  assert.equal(dateText({ kind: 'between', value: '1880', end: '1890' }), '1880 – 1890');
});
test('new person does not invent genealogy and drafts are independent', () => {
  const p = emptyPerson();
  assert.equal(p.livingStatus, 'unknown');
  assert.equal(p.sex, 'unknown');
  assert.equal(personName(p), 'Unnamed person');
  const d = editable(p);
  d.birthDate.value = '1900';
  assert.equal(p.birthDate.value, '');
});
test('layout keeps a single stable position per person and sorts by generation', () => {
  const people = [
    { id: 'child', givenName: 'Ada', level: 1 },
    { id: 'parent', givenName: 'Clara', level: -1 },
    { id: 'focus', givenName: 'Ben', level: 0 },
  ];
  const a = layoutGraph(people),
    b = layoutGraph([...people].reverse());
  assert.deepEqual(a, b);
  assert.equal(Object.keys(a.positions).length, 3);
  assert.ok(a.positions.parent.y < a.positions.focus.y);
  assert.ok(a.positions.child.y > a.positions.focus.y);
});
