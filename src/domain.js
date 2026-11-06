export function emptyPerson() {
  return {
    givenName: '',
    familyName: '',
    displayNote: '',
    livingStatus: 'unknown',
    sex: 'unknown',
    visibility: 'members',
    birthDate: { kind: 'exact', value: '', end: '' },
    deathDate: { kind: 'exact', value: '', end: '' },
    birthPlace: '',
    deathPlace: '',
    confirmUnnamed: false,
    confirmRelease: false,
  };
}
export function personName(person, unnamed = 'Unnamed person') {
  return [person?.givenName, person?.familyName].filter(Boolean).join(' ') || unnamed;
}
export function dateText(date) {
  if (!date || (!date.value && !date.end)) return '';
  const prefix = {
    exact: '',
    about: '≈ ',
    before: '< ',
    after: '> ',
    calculated: 'cal. ',
    estimated: 'est. ',
    literal: '',
  }[date.kind];
  if (date.kind === 'between' || date.kind === 'period') return `${date.value || '…'} – ${date.end || '…'}`;
  return `${prefix ?? ''}${date.value}`;
}
export function requestId() {
  // getRandomValues is available on the HTTP development origin; randomUUID is not assumed.
  const bytes = new Uint8Array(16);
  globalThis.crypto.getRandomValues(bytes);
  return [...bytes].map((x) => x.toString(16).padStart(2, '0')).join('');
}
export function editable(person) {
  return Object.fromEntries(
    Object.keys(emptyPerson()).map((k) => [k, structuredClone(person[k] ?? emptyPerson()[k])]),
  );
}
export function layoutGraph(people) {
  const groups = new Map();
  for (const p of people) {
    const level = p.level ?? 0;
    if (!groups.has(level)) groups.set(level, []);
    groups.get(level).push(p);
  }
  const levels = [...groups.keys()].sort((a, b) => a - b);
  const width = Math.max(700, ...[...groups.values()].map((g) => g.length * 230 + 60));
  const positions = {};
  for (const [i, level] of levels.entries()) {
    const group = groups
      .get(level)
      .sort((a, b) => personName(a).localeCompare(personName(b)) || a.id.localeCompare(b.id));
    group.forEach(
      (p, j) => (positions[p.id] = { x: (width - group.length * 230) / 2 + j * 230, y: 50 + i * 170 }),
    );
  }
  return { positions, width, height: Math.max(300, levels.length * 170 + 90) };
}
