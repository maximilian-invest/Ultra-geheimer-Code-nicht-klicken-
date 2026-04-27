<script setup>
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Link from '@tiptap/extension-link'
import {
  Bold as BoldIcon, Italic as ItalicIcon, Underline as UnderlineIcon,
  List, ListOrdered, Quote, Link as LinkIcon, Undo2, Redo2,
} from 'lucide-vue-next'
import { watch, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Nachricht…' },
  minHeight: { type: String, default: '160px' },
  rows: { type: Number, default: null }, // optional fallback hint
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

/**
 * Tiptap erwartet HTML als Content. KI-Entwuerfe / Template-Outputs sind
 * aber typischerweise plain-text mit \n-Trennern. Hier konvertieren wir
 * solche reinen Text-Strings in HTML-Paragraphs, damit die Zeilenumbrueche
 * im Editor richtig dargestellt werden.
 *
 * - Wenn der Input bereits HTML-Tags enthaelt → durchreichen
 * - Sonst: nach Doppel-Newlines splitten → eigene Paragraphs
 *           innerhalb eines Paragraph: Single-Newlines durch <br> ersetzen
 */
function toEditorHtml(input) {
  const raw = String(input || '')
  if (!raw) return ''
  if (/<(p|div|br|ul|ol|li|h[1-6]|strong|em|u|blockquote|a)[\s>]/i.test(raw)) {
    return raw
  }
  const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  const paragraphs = raw.split(/\n{2,}/).map((p) => p.trim()).filter(Boolean)
  if (!paragraphs.length) return ''
  return paragraphs
    .map((p) => '<p>' + escape(p).replace(/\n/g, '<br>') + '</p>')
    .join('')
}

const editor = useEditor({
  content: toEditorHtml(props.modelValue),
  extensions: [
    StarterKit.configure({
      heading: false, // Headings sind in einer Mail untypisch — weglassen
      codeBlock: false,
    }),
    Underline,
    Link.configure({
      openOnClick: false,
      HTMLAttributes: { class: 'text-sky-600 underline', rel: 'noopener noreferrer' },
    }),
  ],
  editorProps: {
    attributes: {
      class: 'sr-tiptap-content',
    },
  },
  onUpdate: ({ editor }) => {
    const html = editor.getHTML()
    // Tiptap gibt bei leerem Editor "<p></p>" zurueck — wir normalisieren
    // das zu leerem String, damit "Pflichtfelder leer"-Checks funktionieren.
    const isEmpty = !editor.getText().trim() && !html.includes('<img') && !html.includes('<br>')
    emit('update:modelValue', isEmpty ? '' : html)
  },
})

// Externe Updates ins Editor-State spiegeln (z. B. wenn Template gewaehlt wird).
watch(() => props.modelValue, (newVal) => {
  if (!editor.value) return
  const current = editor.value.getHTML()
  const incoming = toEditorHtml(newVal)
  // Nur synchronisieren wenn der Wert wirklich anders ist — sonst springt der Cursor.
  if (incoming !== current && incoming !== current.replace(/<p><\/p>$/, '') && (newVal || '') !== current) {
    editor.value.commands.setContent(incoming, { emitUpdate: false })
  }
})

watch(() => props.disabled, (val) => {
  if (editor.value) editor.value.setEditable(!val)
})

onBeforeUnmount(() => {
  editor.value?.destroy()
})

function setLink() {
  if (!editor.value) return
  const previousUrl = editor.value.getAttributes('link').href
  const url = window.prompt('URL eingeben:', previousUrl || 'https://')
  if (url === null) return // Cancel
  if (url === '') {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }
  editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
}
</script>

<template>
  <div class="sr-tiptap-wrap" :class="{ 'sr-tiptap-disabled': disabled }">
    <div v-if="editor" class="sr-tiptap-toolbar">
      <button
        type="button"
        @click="editor.chain().focus().toggleBold().run()"
        :class="{ 'is-active': editor.isActive('bold') }"
        title="Fett (Ctrl+B)"
      >
        <BoldIcon class="w-3.5 h-3.5" />
      </button>
      <button
        type="button"
        @click="editor.chain().focus().toggleItalic().run()"
        :class="{ 'is-active': editor.isActive('italic') }"
        title="Kursiv (Ctrl+I)"
      >
        <ItalicIcon class="w-3.5 h-3.5" />
      </button>
      <button
        type="button"
        @click="editor.chain().focus().toggleUnderline().run()"
        :class="{ 'is-active': editor.isActive('underline') }"
        title="Unterstrichen (Ctrl+U)"
      >
        <UnderlineIcon class="w-3.5 h-3.5" />
      </button>
      <span class="sr-tiptap-sep" />
      <button
        type="button"
        @click="editor.chain().focus().toggleBulletList().run()"
        :class="{ 'is-active': editor.isActive('bulletList') }"
        title="Aufzählung"
      >
        <List class="w-3.5 h-3.5" />
      </button>
      <button
        type="button"
        @click="editor.chain().focus().toggleOrderedList().run()"
        :class="{ 'is-active': editor.isActive('orderedList') }"
        title="Nummerierte Liste"
      >
        <ListOrdered class="w-3.5 h-3.5" />
      </button>
      <button
        type="button"
        @click="editor.chain().focus().toggleBlockquote().run()"
        :class="{ 'is-active': editor.isActive('blockquote') }"
        title="Zitat"
      >
        <Quote class="w-3.5 h-3.5" />
      </button>
      <span class="sr-tiptap-sep" />
      <button
        type="button"
        @click="setLink()"
        :class="{ 'is-active': editor.isActive('link') }"
        title="Link"
      >
        <LinkIcon class="w-3.5 h-3.5" />
      </button>
      <span class="sr-tiptap-sep" />
      <button
        type="button"
        @click="editor.chain().focus().undo().run()"
        :disabled="!editor.can().undo()"
        title="Rückgängig (Ctrl+Z)"
      >
        <Undo2 class="w-3.5 h-3.5" />
      </button>
      <button
        type="button"
        @click="editor.chain().focus().redo().run()"
        :disabled="!editor.can().redo()"
        title="Wiederherstellen (Ctrl+Y)"
      >
        <Redo2 class="w-3.5 h-3.5" />
      </button>
    </div>
    <EditorContent
      :editor="editor"
      class="sr-tiptap-editor"
      :style="{ minHeight }"
      :data-placeholder="placeholder"
    />
  </div>
</template>

<style>
.sr-tiptap-wrap {
  border: 1px solid hsl(240 5.9% 90%);
  border-radius: 6px;
  background: white;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.sr-tiptap-wrap:focus-within {
  border-color: hsl(240 5.9% 70%);
}
.sr-tiptap-disabled {
  opacity: 0.6;
  pointer-events: none;
}

.sr-tiptap-toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 6px;
  border-bottom: 1px solid hsl(240 5.9% 92%);
  background: hsl(240 5% 98%);
  flex-shrink: 0;
}
.sr-tiptap-toolbar button {
  padding: 4px 6px;
  border-radius: 4px;
  color: hsl(240 5% 35%);
  background: transparent;
  border: 0;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 100ms ease, color 100ms ease;
}
.sr-tiptap-toolbar button:hover:not(:disabled) {
  background: hsl(240 5% 92%);
  color: hsl(240 5% 10%);
}
.sr-tiptap-toolbar button.is-active {
  background: hsl(20 95% 50% / 0.15);
  color: #c2410c;
}
.sr-tiptap-toolbar button:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}
.sr-tiptap-sep {
  width: 1px;
  height: 16px;
  background: hsl(240 5.9% 88%);
  margin: 0 3px;
}

.sr-tiptap-editor {
  flex: 1;
  overflow-y: auto;
  padding: 10px 12px;
  font-size: 13px;
  line-height: 1.55;
  color: hsl(240 10% 10%);
}

.sr-tiptap-content {
  outline: none;
  min-height: 100%;
}
.sr-tiptap-content p {
  margin: 0 0 0.55em;
}
.sr-tiptap-content p:last-child {
  margin-bottom: 0;
}
.sr-tiptap-content ul,
.sr-tiptap-content ol {
  padding-left: 22px;
  margin: 0.4em 0;
}
.sr-tiptap-content ul li,
.sr-tiptap-content ol li {
  margin: 2px 0;
}
.sr-tiptap-content blockquote {
  border-left: 3px solid hsl(240 5% 80%);
  padding-left: 12px;
  margin: 0.5em 0;
  color: hsl(240 5% 40%);
}
.sr-tiptap-content a {
  color: #0284c7;
  text-decoration: underline;
}
.sr-tiptap-content strong { font-weight: 600; }
.sr-tiptap-content em { font-style: italic; }

/* Placeholder via :empty CSS */
.sr-tiptap-content p.is-editor-empty:first-child::before,
.sr-tiptap-editor:has(.sr-tiptap-content > p:only-child:empty)::before {
  content: attr(data-placeholder);
  position: absolute;
  pointer-events: none;
  color: hsl(240 5% 60%);
  font-style: italic;
  font-size: 13px;
}
.sr-tiptap-editor {
  position: relative;
}
.sr-tiptap-content p:first-child:empty::before {
  content: '';
}
</style>
