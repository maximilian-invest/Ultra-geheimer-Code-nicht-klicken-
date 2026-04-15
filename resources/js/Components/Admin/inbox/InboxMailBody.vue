<script setup>
import { computed } from 'vue'
import DOMPurify from 'dompurify'
import { cleanEmailBody, htmlToText } from './mailText.js'

const props = defineProps({
  message: { type: Object, required: true },
})

// Always prefer HTML when it's present. The earlier newline-count
// heuristic back-fired on mails like Immowelt's "+-+ ASCII art" platform
// template — those have plenty of newlines in body_text but the text
// version is garbage, while the HTML version renders with proper
// layout, brand images, and working links. Fall back to the cleaned
// text pipeline only when body_html is actually empty.
const rawHtml = computed(() => String(props.message.body_html || ''))
const rawText = computed(() => String(props.message.full_body || props.message.body_text || props.message.body || ''))

const preferHtml = computed(() => rawHtml.value.trim().length > 0)

// Sanitise HTML with a permissive-but-safe allowlist. Scripts, iframes,
// forms, and event handlers are stripped. Links get target="_blank"
// rel="noopener noreferrer" appended via a post-sanitise hook.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('target', '_blank')
    node.setAttribute('rel', 'noopener noreferrer')
  }
})

const sanitisedHtml = computed(() => {
  if (!preferHtml.value) return ''
  return DOMPurify.sanitize(rawHtml.value, {
    ALLOWED_TAGS: [
      'a', 'p', 'div', 'span', 'br', 'hr',
      'img', 'figure', 'figcaption',
      'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
      'ul', 'ol', 'li',
      'blockquote', 'pre', 'code',
      'strong', 'em', 'b', 'i', 'u', 's', 'small', 'sup', 'sub',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ],
    ALLOWED_ATTR: [
      'href', 'src', 'alt', 'title', 'class', 'style',
      'width', 'height', 'colspan', 'rowspan', 'align', 'valign',
      'cellpadding', 'cellspacing', 'border', 'bgcolor', 'color',
      'target', 'rel',
    ],
    FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'base', 'style'],
    FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'onblur', 'srcdoc', 'formaction'],
  })
})

const fallbackText = computed(() => {
  if (preferHtml.value) return ''
  return cleanEmailBody(rawText.value || htmlToText(rawHtml.value))
})
</script>

<template>
  <div class="mail-body">
    <div v-if="preferHtml" class="mail-body-html" v-html="sanitisedHtml"></div>
    <div v-else class="mail-body-text">{{ fallbackText }}</div>
  </div>
</template>

<style scoped>
.mail-body {
  font-size: 13.5px;
  line-height: 1.65;
  color: hsl(var(--foreground));
  contain: content;
}
.mail-body-html {
  overflow-x: auto;
}
.mail-body-html :deep(p) { margin: 0 0 12px; }
.mail-body-html :deep(p:last-child) { margin-bottom: 0; }
.mail-body-html :deep(a) {
  color: hsl(217 91% 45%);
  text-decoration: none;
  border-bottom: 1px solid hsl(217 91% 85%);
}
.mail-body-html :deep(a:hover) { border-bottom-color: hsl(217 91% 45%); }
.mail-body-html :deep(img) { max-width: 100%; height: auto; }
.mail-body-html :deep(table) {
  border-collapse: collapse;
  max-width: 100%;
}
.mail-body-html :deep(blockquote) {
  margin: 0 0 12px;
  padding: 0 0 0 12px;
  border-left: 3px solid hsl(0 0% 88%);
  color: hsl(0 0% 40%);
}
.mail-body-html :deep(pre),
.mail-body-html :deep(code) {
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  background: hsl(0 0% 96%);
  border-radius: 4px;
  padding: 2px 4px;
}
.mail-body-html :deep(pre) { padding: 8px 10px; overflow-x: auto; }
.mail-body-text {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: inherit;
  margin: 0;
}
</style>
