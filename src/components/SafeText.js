import { h } from 'vue';
// A deliberately small formatting vocabulary: text nodes only, no HTML or image renderer.
function inline(text) {
  const nodes = [],
    pattern = /\*\*([^*\n]+)\*\*|\*([^*\n]+)\*|(?<!!)\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g;
  let at = 0,
    m;
  while ((m = pattern.exec(text))) {
    nodes.push(text.slice(at, m.index));
    if (m[1]) nodes.push(h('strong', m[1]));
    else if (m[2]) nodes.push(h('em', m[2]));
    else {
      try {
        const url = new URL(m[4]);
        nodes.push(
          ['http:', 'https:'].includes(url.protocol)
            ? h('a', { href: url.href, target: '_blank', rel: 'noopener noreferrer' }, m[3])
            : m[0],
        );
      } catch {
        nodes.push(m[0]);
      }
    }
    at = pattern.lastIndex;
  }
  nodes.push(text.slice(at));
  return nodes;
}
export default {
  props: { text: { type: String, default: '' } },
  setup(props) {
    return () =>
      h(
        'div',
        { class: 'safe-text' },
        props.text
          .split(/\n\s*\n/)
          .filter(Boolean)
          .map((block) => {
            const lines = block.split('\n');
            if (lines.every((l) => /^[-*] /.test(l)))
              return h(
                'ul',
                lines.map((l) => h('li', inline(l.slice(2)))),
              );
            if (lines.every((l) => /^\d+\. /.test(l)))
              return h(
                'ol',
                lines.map((l) => h('li', inline(l.replace(/^\d+\. /, '')))),
              );
            return h('p', inline(block));
          }),
      );
  },
};
