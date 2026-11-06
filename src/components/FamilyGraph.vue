<script setup>
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { t, title } from '../i18n.js';
import { layoutGraph, graphCard, graphZoom, personName, dateText } from '../domain.js';
const props = defineProps({ graph: Object, selected: String, modelValue: Object, restoreCamera: Boolean });
const emit = defineEmits(['select', 'update:modelValue']);
const viewport = ref(null),
  textView = ref(false);
const layout = computed(() => layoutGraph(props.graph.people));
const camera = computed(() => props.modelValue || { x: 20, y: 20, zoom: 1 });
const name = (p) => personName(p, t('Unnamed person'));
const peopleById = computed(() => Object.fromEntries(props.graph.people.map((p) => [p.id, p])));
function move(v) {
  emit('update:modelValue', { ...camera.value, ...v });
}
function zoom(delta, px, py) {
  const c = camera.value;
  const z = Math.max(graphZoom.min, Math.min(graphZoom.max, c.zoom * delta));
  px ??= viewport.value.clientWidth / 2;
  py ??= viewport.value.clientHeight / 2;
  move({ zoom: z, x: px - ((px - c.x) * z) / c.zoom, y: py - ((py - c.y) * z) / c.zoom });
}
function fit() {
  if (!viewport.value) return;
  const z = Math.min(
    1,
    (viewport.value.clientWidth - 40) / layout.value.width,
    (viewport.value.clientHeight - 30) / layout.value.height,
  );
  move({
    zoom: Math.max(graphZoom.min, z),
    x: (viewport.value.clientWidth - layout.value.width * Math.max(graphZoom.min, z)) / 2,
    y: 20,
  });
}
function center(zoom = camera.value.zoom) {
  const p = layout.value.positions[props.graph.focusId];
  if (p && viewport.value)
    move({
      zoom,
      x: viewport.value.clientWidth / 2 - (p.x + graphCard.width / 2) * zoom,
      y: viewport.value.clientHeight / 2 - (p.y + graphCard.height / 2) * zoom,
    });
}
watch(
  () => props.graph.focusId,
  () => nextTick(center),
);
let resizeObserver;
let viewportSize = null;
watch(viewport, (element) => {
  resizeObserver?.disconnect();
  if (!element) {
    pointers.clear();
    drag = pinch = null;
    return;
  }
  resizeObserver = new ResizeObserver(() => {
    const width = element.clientWidth,
      height = element.clientHeight;
    // A hidden research canvas reports zero; retain its last visible dimensions.
    if (!width || !height) return;
    if (viewportSize)
      move({
        x: camera.value.x + (width - viewportSize.width) / 2,
        y: camera.value.y + (height - viewportSize.height) / 2,
      });
    viewportSize = { width, height };
  });
  resizeObserver.observe(element);
});
onBeforeUnmount(() => resizeObserver?.disconnect());
onMounted(() => {
  if (!props.restoreCamera) nextTick(center);
});
const pointers = new Map();
let drag = null,
  pinch = null,
  suppressClick = false;
function midpoint() {
  const [a, b] = [...pointers.values()];
  const rect = viewport.value.getBoundingClientRect();
  return {
    x: (a.x + b.x) / 2 - rect.left,
    y: (a.y + b.y) / 2 - rect.top,
    distance: Math.hypot(a.x - b.x, a.y - b.y),
  };
}
function down(e) {
  if (e.button !== 0 || pointers.size >= 2) return;
  const button = !!e.target.closest('button');
  if (e.pointerType !== 'touch' && button) return;
  if (!pointers.size) suppressClick = false;
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY, button });
  if (pointers.size === 2) {
    pinch = { ...midpoint(), c: { ...camera.value } };
    drag = null;
    suppressClick = true;
  } else if (!button) {
    drag = { x: e.clientX, y: e.clientY, c: { ...camera.value } };
    if (e.pointerType !== 'touch') e.currentTarget.setPointerCapture(e.pointerId);
  }
}
function pointer(e) {
  const point = pointers.get(e.pointerId);
  if (!point) return;
  pointers.set(e.pointerId, { ...point, x: e.clientX, y: e.clientY });
  if (pinch && pointers.size === 2) {
    const next = midpoint();
    const z = Math.max(
      graphZoom.min,
      Math.min(graphZoom.max, (pinch.c.zoom * next.distance) / Math.max(1, pinch.distance)),
    );
    move({
      zoom: z,
      x: next.x - ((pinch.x - pinch.c.x) * z) / pinch.c.zoom,
      y: next.y - ((pinch.y - pinch.c.y) * z) / pinch.c.zoom,
    });
  } else if (drag) {
    if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) > 6) suppressClick = true;
    move({ x: drag.c.x + e.clientX - drag.x, y: drag.c.y + e.clientY - drag.y });
  }
}
function releasePointer(e) {
  if (!pointers.delete(e.pointerId)) return;
  pinch = null;
  drag = null;
  const point = [...pointers.values()][0];
  if (point && !point.button) drag = { x: point.x, y: point.y, c: { ...camera.value } };
}
function filterClick(e) {
  if (suppressClick && e.detail > 0) {
    e.preventDefault();
    e.stopPropagation();
    suppressClick = false;
  }
}
function key(e) {
  if (e.target !== viewport.value) return;
  const c = camera.value;
  const actions = {
    ArrowLeft: () => move({ x: c.x + 40 }),
    ArrowRight: () => move({ x: c.x - 40 }),
    ArrowUp: () => move({ y: c.y + 40 }),
    ArrowDown: () => move({ y: c.y - 40 }),
    '+': () => zoom(1.2),
    '=': () => zoom(1.2),
    '-': () => zoom(1 / 1.2),
    Home: fit,
  };
  if (actions[e.key]) {
    e.preventDefault();
    actions[e.key]();
  }
}
function path(edge) {
  const a = layout.value.positions[edge.sourceId],
    b = layout.value.positions[edge.targetId];
  if (!a || !b) return '';
  const x1 = a.x + graphCard.width / 2,
    y1 = a.y + graphCard.height / 2,
    x2 = b.x + graphCard.width / 2,
    y2 = b.y + graphCard.height / 2;
  return edge.kind === 'partner'
    ? `M${x1},${y1} L${x2},${y2}`
    : `M${x1},${y1} C${x1},${(y1 + y2) / 2} ${x2},${(y1 + y2) / 2} ${x2},${y2}`;
}
</script>
<template>
  <div class="graph-shell">
    <div class="graph-toolbar">
      <div class="segmented">
        <button :aria-pressed="!textView" @click="textView = false">{{ t('Diagram') }}</button
        ><button :aria-pressed="textView" @click="textView = true">{{ t('Text view') }}</button>
      </div>
      <div v-if="!textView" class="button-row">
        <button :aria-label="t('Zoom out')" @click="zoom(1 / 1.2)">−</button
        ><button
          class="zoom-reset"
          :aria-label="t('Center focus at 100%')"
          :title="t('Center focus at 100%')"
          @click="center(1)"
        >
          {{ Math.round(camera.zoom * 1000) / 10 }}%</button
        ><button :aria-label="t('Zoom in')" @click="zoom(1.2)">+</button
        ><button @click="fit">{{ t('Fit') }}</button>
      </div>
    </div>
    <p v-if="graph.limited" class="feedback" role="status">
      {{ t('This view is limited. More relatives may exist.') }}
    </p>
    <div
      v-if="!textView"
      ref="viewport"
      class="graph-viewport"
      tabindex="0"
      role="region"
      :aria-label="t('Family diagram')"
      @keydown="key"
      @pointerdown="down"
      @pointermove="pointer"
      @pointerup="releasePointer"
      @pointercancel="releasePointer"
      @lostpointercapture="releasePointer"
      @click.capture="filterClick"
      @wheel.prevent="
        (e) => {
          const r = viewport.getBoundingClientRect();
          zoom(e.deltaY < 0 ? 1.1 : 1 / 1.1, e.clientX - r.left, e.clientY - r.top);
        }
      "
    >
      <div
        class="graph-world"
        :style="{
          width: layout.width + 'px',
          height: layout.height + 'px',
          transform: `translate(${camera.x}px,${camera.y}px) scale(${camera.zoom})`,
        }"
      >
        <svg :width="layout.width" :height="layout.height" aria-hidden="true">
          <path
            v-for="e in graph.relations"
            :key="e.id"
            :d="path(e)"
            :class="['edge', e.kind, e.parentType]"
          />
        </svg>
        <button
          v-for="p in graph.people"
          :key="p.id"
          class="person-node"
          :data-person-id="p.id"
          :class="{ selected: p.id === selected, focus: p.id === graph.focusId }"
          :style="{
            left: layout.positions[p.id].x + 'px',
            top: layout.positions[p.id].y + 'px',
            width: graphCard.width + 'px',
            height: graphCard.height + 'px',
          }"
          :aria-pressed="p.id === selected"
          :title="name(p)"
          @click="emit('select', p)"
        >
          <span class="node-avatar" aria-hidden="true">{{
            (p.givenName?.[0] || p.familyName?.[0] || '?').toUpperCase()
          }}</span
          ><span class="node-text"
            ><strong>{{ name(p) }}</strong
            ><small>{{
              [dateText(p.birthDate), dateText(p.deathDate)].filter(Boolean).join(' — ') ||
              t('No date recorded')
            }}</small
            ><span v-if="p.id === graph.focusId" class="focus-label">{{ t('Focus') }}</span></span
          >
        </button>
      </div>
    </div>
    <div v-else class="graph-text">
      <ul>
        <li v-for="p in graph.people" :key="p.id">
          <button :data-person-id="p.id" @click="emit('select', p)">{{ name(p) }}</button>
        </li>
      </ul>
      <h3>{{ t('Connections') }}</h3>
      <ul>
        <li v-for="e in graph.relations" :key="e.id">
          {{ name(peopleById[e.sourceId]) }} → {{ name(peopleById[e.targetId]) }} ·
          {{ t(e.kind === 'partner' ? 'Partner' : 'Parent')
          }}<template v-if="e.kind === 'parent'"> ({{ t(title(e.parentType)) }})</template>
        </li>
      </ul>
    </div>
    <details v-if="graph.contexts?.length" class="graph-contexts">
      <summary>{{ t('Family contexts') }}</summary>
      <p class="muted">{{ t('Grouping creates no connections. Members are listed in recorded order.') }}</p>
      <section v-for="c in graph.contexts" :key="c.id">
        <h3>{{ c.label }}</h3>
        <ol>
          <li v-for="m in c.members" :key="m.personId">
            <button class="text-button" @click="emit('select', peopleById[m.personId])">
              {{ name(peopleById[m.personId]) }}
            </button>
            · {{ t(title(m.role)) }}
          </li>
        </ol>
      </section>
    </details>
    <div class="legend">
      <span><i class="line biological"></i>{{ t('Biological') }}</span
      ><span><i class="line adoptive"></i>{{ t('Adoptive') }} / {{ t('Foster') }}</span
      ><span><i class="line other"></i>{{ t('Unknown') }} / {{ t('Step') }} / {{ t('Other') }}</span
      ><span><i class="line partner"></i>{{ t('Partner') }}</span>
    </div>
  </div>
</template>
