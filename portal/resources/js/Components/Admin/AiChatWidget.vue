<script setup>
import { ref, computed, inject, nextTick, watch, onMounted, onUnmounted } from 'vue'
import { X, Send, Loader2, Trash2, Mic, MicOff, Volume2, VolumeX, ChevronDown } from 'lucide-vue-next'

const API = inject('API')
const toast = inject('toast')

// Chat state
const isOpen = ref(false)
const messages = ref([])
const inputText = ref('')
const isLoading = ref(false)
const chatContainer = ref(null)
const inputRef = ref(null)

// Voice state (text mode TTS)
const isSpeaking = ref(false)
const voiceEnabled = ref(true)
const speechSupported = ref(false)
const recognitionSupported = ref(false)
let recognition = null
let currentAudio = null
const isListening = ref(false)

// Voice conversation mode (WebRTC Realtime API)
const voiceMode = ref(false)
const voicePhase = ref('idle') // idle, connecting, listening, thinking, speaking
const voiceTranscript = ref('')
const voiceAiTranscript = ref('')
let rtcPc = null
let rtcDc = null
let rtcAudioEl = null
let localStream = null

const suggestions = [
  'Welche Einheiten bei the37 sind verfügbar?',
  'Wie viele Anfragen gab es diese Woche?',
  'Wie hoch ist die Provision bei the37?',
  'Zeige alle Kaufanbote',
]

onMounted(() => {
  // Check for basic speech recognition (for text mode mic button)
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
  if (SpeechRecognition) {
    recognitionSupported.value = true
    recognition = new SpeechRecognition()
    recognition.lang = 'de-AT'
    recognition.continuous = false
    recognition.interimResults = true

    recognition.onresult = (event) => {
      let transcript = ''
      let isFinal = false
      for (let i = event.resultIndex; i < event.results.length; i++) {
        transcript += event.results[i][0].transcript
        if (event.results[i].isFinal) isFinal = true
      }
      inputText.value = transcript
      if (isFinal) {
        isListening.value = false
        nextTick(() => sendMessage())
      }
    }
    recognition.onerror = () => { isListening.value = false }
    recognition.onend = () => { isListening.value = false }
  }

  speechSupported.value = 'speechSynthesis' in window || true // TTS via OpenAI always available
  const savedPref = localStorage.getItem('sherlock_voice_enabled')
  if (savedPref !== null) voiceEnabled.value = savedPref === 'true'
})

onUnmounted(() => {
  exitVoiceMode()
})

function toggleChat() {
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    nextTick(() => { if (inputRef.value) inputRef.value.focus() })
  } else {
    if (voiceMode.value) exitVoiceMode()
  }
}

function clearChat() { messages.value = [] }

function toggleVoice() {
  voiceEnabled.value = !voiceEnabled.value
  localStorage.setItem('sherlock_voice_enabled', voiceEnabled.value)
}

// ==========================================
// VOICE MODE (OpenAI Realtime API + WebRTC)
// ==========================================
async function enterVoiceMode() {
  voiceMode.value = true
  voicePhase.value = 'connecting'
  voiceTranscript.value = ''
  voiceAiTranscript.value = ''

  try {
    // 1. Get ephemeral token from backend
    const tokenRes = await fetch(API.value + '&action=realtime_session')
    const tokenData = await tokenRes.json()
    if (tokenData.error) {
      toast('Fehler: ' + (tokenData.error || 'Session creation failed'))
      voiceMode.value = false
      return
    }

    const ephemeralKey = tokenData.client_secret?.value
    if (!ephemeralKey) {
      toast('Fehler: Kein Session-Token erhalten')
      voiceMode.value = false
      return
    }

    // 2. Create RTCPeerConnection
    rtcPc = new RTCPeerConnection()

    // 3. Setup remote audio playback
    rtcAudioEl = document.createElement('audio')
    rtcAudioEl.autoplay = true
    rtcAudioEl.setAttribute('playsinline', '')
    rtcPc.ontrack = (e) => {
      rtcAudioEl.srcObject = e.streams[0]
    }

    // 4. Get microphone and add track
    localStream = await navigator.mediaDevices.getUserMedia({ audio: true })
    localStream.getTracks().forEach(track => rtcPc.addTrack(track, localStream))

    // 5. Create data channel for events
    rtcDc = rtcPc.createDataChannel('oai-events')
    rtcDc.addEventListener('open', onDataChannelOpen)
    rtcDc.addEventListener('message', onDataChannelMessage)

    // 6. Create SDP offer
    const offer = await rtcPc.createOffer()
    await rtcPc.setLocalDescription(offer)

    // 7. Send offer to OpenAI Realtime API
    const sdpRes = await fetch('https://api.openai.com/v1/realtime?model=gpt-4o-mini-realtime-preview-2024-12-17', {
      method: 'POST',
      body: rtcPc.localDescription.sdp,
      headers: {
        'Authorization': 'Bearer ' + ephemeralKey,
        'Content-Type': 'application/sdp',
      },
    })

    if (!sdpRes.ok) {
      const errText = await sdpRes.text()
      console.error('SDP error:', errText)
      toast('Verbindungsfehler: ' + sdpRes.status)
      exitVoiceMode()
      return
    }

    // 8. Set remote SDP answer
    const answerSdp = await sdpRes.text()
    await rtcPc.setRemoteDescription({ type: 'answer', sdp: answerSdp })

    voicePhase.value = 'listening'
  } catch (e) {
    console.error('Voice mode error:', e)
    toast('Sprachmodus Fehler: ' + e.message)
    exitVoiceMode()
  }
}

function onDataChannelOpen() {
  console.log('Realtime DataChannel open')
  voicePhase.value = 'listening'
}

function onDataChannelMessage(event) {
  try {
    const msg = JSON.parse(event.data)

    switch (msg.type) {
      case 'session.created':
      case 'session.updated':
        voicePhase.value = 'listening'
        break

      case 'input_audio_buffer.speech_started':
        voicePhase.value = 'listening'
        voiceTranscript.value = ''
        break

      case 'input_audio_buffer.speech_stopped':
        voicePhase.value = 'thinking'
        break

      case 'conversation.item.input_audio_transcription.completed':
        voiceTranscript.value = msg.transcript || ''
        // Add to chat history
        if (msg.transcript) {
          messages.value.push({ role: 'user', text: msg.transcript, time: new Date() })
        }
        break

      case 'response.audio_transcript.delta':
        voicePhase.value = 'speaking'
        voiceAiTranscript.value += (msg.delta || '')
        break

      case 'response.audio_transcript.done':
        // Add complete AI response to chat
        if (voiceAiTranscript.value) {
          messages.value.push({ role: 'assistant', text: voiceAiTranscript.value, time: new Date() })
        }
        voiceAiTranscript.value = ''
        break

      case 'response.done':
        voicePhase.value = 'listening'
        voiceTranscript.value = ''
        break

      case 'response.function_call_arguments.done':
        handleToolCall(msg)
        break

      case 'error':
        console.error('Realtime error:', msg.error)
        if (msg.error?.message) toast('Fehler: ' + msg.error.message)
        break
    }
  } catch (e) {
    console.warn('DC message parse error:', e)
  }
}

async function handleToolCall(msg) {
  const callId = msg.call_id
  const toolName = msg.name
  const args = msg.arguments || '{}'

  voicePhase.value = 'thinking'

  try {
    // Execute tool via backend
    const res = await fetch(API.value + '&action=execute_tool', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tool_name: toolName, arguments: args }),
    })
    const data = await res.json()
    const resultStr = JSON.stringify(data.result || data)

    // Send tool result back to Realtime API
    if (rtcDc && rtcDc.readyState === 'open') {
      // 1. Add the function call output
      rtcDc.send(JSON.stringify({
        type: 'conversation.item.create',
        item: {
          type: 'function_call_output',
          call_id: callId,
          output: resultStr.substring(0, 4000), // Limit size for voice
        }
      }))

      // 2. Trigger response generation
      rtcDc.send(JSON.stringify({
        type: 'response.create',
      }))
    }
  } catch (e) {
    console.error('Tool execution error:', e)
    if (rtcDc && rtcDc.readyState === 'open') {
      rtcDc.send(JSON.stringify({
        type: 'conversation.item.create',
        item: {
          type: 'function_call_output',
          call_id: callId,
          output: JSON.stringify({ error: e.message }),
        }
      }))
      rtcDc.send(JSON.stringify({ type: 'response.create' }))
    }
  }
}

function voiceTap() {
  if (voicePhase.value === 'speaking') {
    // Interrupt AI speaking
    if (rtcDc && rtcDc.readyState === 'open') {
      rtcDc.send(JSON.stringify({ type: 'response.cancel' }))
    }
    voicePhase.value = 'listening'
  } else if (voicePhase.value === 'idle') {
    voicePhase.value = 'listening'
  }
}

function exitVoiceMode() {
  voiceMode.value = false
  voicePhase.value = 'idle'
  voiceTranscript.value = ''
  voiceAiTranscript.value = ''

  // Cleanup WebRTC
  if (rtcDc) { try { rtcDc.close() } catch(e) {} rtcDc = null }
  if (rtcPc) { try { rtcPc.close() } catch(e) {} rtcPc = null }
  if (localStream) {
    localStream.getTracks().forEach(t => t.stop())
    localStream = null
  }
  if (rtcAudioEl) {
    rtcAudioEl.srcObject = null
    rtcAudioEl = null
  }
}

// ==========================================
// TEXT MODE - Speech Recognition (unchanged)
// ==========================================
function toggleListening() {
  if (isListening.value) stopListening()
  else startListening()
}

function startListening() {
  if (!recognition) return
  try {
    recognition.start()
    isListening.value = true
    inputText.value = ''
  } catch(e) {}
}

function stopListening() {
  if (recognition && isListening.value) {
    try { recognition.stop() } catch(e) {}
    isListening.value = false
  }
}

// ==========================================
// TEXT MODE - TTS via OpenAI (for text chat read-aloud)
// ==========================================
async function speakText(text, onDone) {
  if (!text || !voiceEnabled.value) { if (onDone) onDone(); return }
  isSpeaking.value = true

  let ttsText = text
  if (ttsText.length > 800) {
    const cut = ttsText.substring(0, 800)
    const lastSentence = Math.max(cut.lastIndexOf('. '), cut.lastIndexOf('? '), cut.lastIndexOf('\n'))
    ttsText = lastSentence > 200 ? cut.substring(0, lastSentence + 1) : cut + '...'
  }

  try {
    const res = await fetch(API.value + '&action=ai_tts', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'audio/mpeg' },
      body: JSON.stringify({ text: ttsText })
    })
    if (res.ok && res.headers.get('content-type')?.includes('audio')) {
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const audio = new Audio(url)
      audio.setAttribute('playsinline', '')
      currentAudio = audio
      audio.onended = () => { isSpeaking.value = false; URL.revokeObjectURL(url); currentAudio = null; if (onDone) onDone() }
      audio.onerror = () => { isSpeaking.value = false; URL.revokeObjectURL(url); currentAudio = null; if (onDone) onDone() }
      await audio.play()
      return
    }
  } catch(e) {}
  isSpeaking.value = false
  if (onDone) onDone()
}

function stopSpeaking() {
  if (currentAudio) { currentAudio.pause(); currentAudio = null }
  isSpeaking.value = false
}

// ==========================================
// TEXT CHAT (Claude via ai_chat)
// ==========================================
function getHistory() {
  return messages.value
    .filter(m => m.role === 'user' || m.role === 'assistant')
    .map(m => ({ role: m.role, content: m.text }))
}

async function sendMessage(text) {
  const msg = (text || inputText.value).trim()
  if (!msg || isLoading.value) return

  inputText.value = ''
  messages.value.push({ role: 'user', text: msg, time: new Date() })
  scrollToBottom()
  isLoading.value = true

  try {
    const history = getHistory().slice(0, -1)
    const res = await fetch(API.value + '&action=ai_chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ message: msg, history })
    })
    const data = await res.json()

    if (data.reply) {
      messages.value.push({ role: 'assistant', text: data.reply, time: new Date() })
      if (voiceEnabled.value) nextTick(() => speakText(data.reply))
    } else if (data.error) {
      messages.value.push({ role: 'assistant', text: 'Fehler: ' + data.error, time: new Date(), isError: true })
    }
  } catch (e) {
    messages.value.push({ role: 'assistant', text: 'Verbindungsfehler: ' + e.message, time: new Date(), isError: true })
  } finally {
    isLoading.value = false
    scrollToBottom()
    nextTick(() => { if (inputRef.value) inputRef.value.focus() })
  }
}

function scrollToBottom() {
  nextTick(() => { if (chatContainer.value) chatContainer.value.scrollTop = chatContainer.value.scrollHeight })
}

function handleKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage() }
}

function formatTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' })
}

function formatMessage(text) {
  if (!text) return ''
  return text
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/`(.*?)`/g, '<code class="bg-gray-200 dark:bg-gray-600 px-1 rounded text-sm">$1</code>')
    .replace(/\n/g, '<br>')
}

const phaseLabel = computed(() => {
  switch(voicePhase.value) {
    case 'connecting': return 'Verbinde...'
    case 'listening': return 'Ich höre zu...'
    case 'thinking': return 'Moment...'
    case 'speaking': return ''
    default: return 'Bereit'
  }
})

const phaseColor = computed(() => {
  switch(voicePhase.value) {
    case 'connecting': return 'from-blue-500 to-cyan-600'
    case 'listening': return 'from-green-500 to-emerald-600'
    case 'thinking': return 'from-amber-500 to-orange-600'
    case 'speaking': return 'from-violet-500 to-indigo-600'
    default: return 'from-gray-400 to-gray-500'
  }
})
</script>

<template>
  <!-- Floating Button -->
  <button
    v-if="!isOpen"
    @click="toggleChat"
    class="fixed bottom-6 right-6 z-50 w-14 h-14 rounded-full bg-gradient-to-br from-violet-600 to-indigo-600 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 flex items-center justify-center group"
    title="Sherlock - KI-Assistent"
  >
    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" class="group-hover:rotate-12 transition-transform"><path d="M12 2L14.09 8.26L20 9.27L15.55 13.97L16.91 20L12 16.9L7.09 20L8.45 13.97L4 9.27L9.91 8.26L12 2Z"/></svg>
    <span class="absolute -top-1 -right-1 w-3 h-3 bg-green-400 rounded-full border-2 border-white animate-pulse"></span>
  </button>

  <!-- Voice Mode Overlay (WebRTC) -->
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="opacity-0 scale-95"
    enter-to-class="opacity-100 scale-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="opacity-100 scale-100"
    leave-to-class="opacity-0 scale-95"
  >
    <div v-if="isOpen && voiceMode" class="fixed inset-0 z-50 bg-gradient-to-b from-gray-900 via-gray-800 to-gray-900 flex flex-col items-center justify-between p-8">
      <!-- Top bar -->
      <div class="w-full flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" class="text-white"><path d="M12 2L14.09 8.26L20 9.27L15.55 13.97L16.91 20L12 16.9L7.09 20L8.45 13.97L4 9.27L9.91 8.26L12 2Z"/></svg>
          </div>
          <div>
            <div class="text-white font-semibold">Sherlock</div>
            <div class="text-white/50 text-xs">Sprachassistent</div>
          </div>
        </div>
        <button @click="exitVoiceMode" class="p-3 rounded-full bg-red-500/20 hover:bg-red-500/40 text-red-400 transition">
          <X :size="22" />
        </button>
      </div>

      <!-- Center - Orb animation -->
      <div class="flex-1 flex flex-col items-center justify-center">
        <div class="relative mb-8" @click="voiceTap">
          <!-- Pulse rings -->
          <div v-if="voicePhase === 'listening'" class="absolute inset-0 rounded-full bg-green-500/20 animate-ping" style="animation-duration:1.5s;margin:-20px"></div>
          <div v-if="voicePhase === 'speaking'" class="absolute inset-0 rounded-full bg-violet-500/20 animate-ping" style="animation-duration:2s;margin:-20px"></div>
          <div v-if="voicePhase === 'thinking'" class="absolute inset-0 rounded-full bg-amber-500/10 animate-pulse" style="margin:-15px"></div>
          <div v-if="voicePhase === 'connecting'" class="absolute inset-0 rounded-full bg-blue-500/20 animate-ping" style="animation-duration:1s;margin:-20px"></div>

          <!-- Main orb -->
          <div
            class="w-32 h-32 rounded-full bg-gradient-to-br flex items-center justify-center cursor-pointer transition-all duration-500 shadow-2xl"
            :class="[phaseColor, voicePhase === 'listening' ? 'scale-110' : '', voicePhase === 'speaking' ? 'scale-105' : '', voicePhase === 'thinking' || voicePhase === 'connecting' ? 'animate-pulse' : '']"
            :style="voicePhase === 'listening' ? 'box-shadow:0 0 60px rgba(34,197,94,0.4)' : voicePhase === 'speaking' ? 'box-shadow:0 0 60px rgba(139,92,246,0.4)' : ''"
          >
            <Mic v-if="voicePhase === 'listening'" :size="40" class="text-white" />
            <Loader2 v-else-if="voicePhase === 'thinking' || voicePhase === 'connecting'" :size="40" class="text-white animate-spin" />
            <Volume2 v-else-if="voicePhase === 'speaking'" :size="40" class="text-white" />
            <Mic v-else :size="40" class="text-white/70" />
          </div>
        </div>

        <div class="text-white/80 text-lg font-medium mb-3">{{ phaseLabel }}</div>

        <!-- Live transcript -->
        <div v-if="voiceTranscript" class="text-white/40 text-sm text-center max-w-xs">{{ voiceTranscript }}</div>
      </div>

      <!-- Bottom hint -->
      <div class="text-white/30 text-xs text-center">
        <p v-if="voicePhase === 'speaking'">Tippe auf den Kreis um zu unterbrechen</p>
        <p v-else-if="voicePhase === 'connecting'">Verbindung wird hergestellt...</p>
        <p v-else-if="voicePhase === 'idle'">Tippe auf den Kreis zum Sprechen</p>
        <p v-else>&nbsp;</p>
      </div>
    </div>
  </Transition>

  <!-- Chat Panel (text mode) -->
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="translate-x-full opacity-0"
    enter-to-class="translate-x-0 opacity-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="translate-x-0 opacity-100"
    leave-to-class="translate-x-full opacity-0"
  >
    <div
      v-if="isOpen && !voiceMode"
      class="fixed bottom-0 right-0 z-50 w-full sm:w-[420px] h-[600px] sm:h-[650px] sm:bottom-6 sm:right-6 sm:rounded-2xl bg-white dark:bg-gray-800 shadow-2xl border border-gray-200 dark:border-gray-700 flex flex-col overflow-hidden"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-violet-600 to-indigo-600 text-white">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L14.09 8.26L20 9.27L15.55 13.97L16.91 20L12 16.9L7.09 20L8.45 13.97L4 9.27L9.91 8.26L12 2Z"/></svg>
          </div>
          <div>
            <span class="font-semibold text-sm">Sherlock</span>
            <span class="text-[10px] text-violet-200 ml-1.5">KI-Assistent</span>
          </div>
        </div>
        <div class="flex items-center gap-0.5">
          <button
            @click="enterVoiceMode"
            class="p-1.5 hover:bg-white/20 rounded-lg transition group"
            title="Sprachmodus starten"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <line x1="4" y1="8" x2="4" y2="16" class="group-hover:animate-pulse" />
              <line x1="8" y1="5" x2="8" y2="19" class="group-hover:animate-pulse" style="animation-delay:0.1s" />
              <line x1="12" y1="3" x2="12" y2="21" class="group-hover:animate-pulse" style="animation-delay:0.2s" />
              <line x1="16" y1="5" x2="16" y2="19" class="group-hover:animate-pulse" style="animation-delay:0.1s" />
              <line x1="20" y1="8" x2="20" y2="16" class="group-hover:animate-pulse" />
            </svg>
          </button>
          <button @click="toggleVoice" class="p-1.5 hover:bg-white/20 rounded-lg transition" :title="voiceEnabled ? 'Sprachausgabe aus' : 'Sprachausgabe an'">
            <Volume2 v-if="voiceEnabled" :size="16" />
            <VolumeX v-else :size="16" class="opacity-60" />
          </button>
          <button @click="clearChat" class="p-1.5 hover:bg-white/20 rounded-lg transition" title="Chat leeren">
            <Trash2 :size="16" />
          </button>
          <button @click="toggleChat" class="p-1.5 hover:bg-white/20 rounded-lg transition" title="Schliessen">
            <X :size="18" />
          </button>
        </div>
      </div>

      <!-- Messages -->
      <div ref="chatContainer" class="flex-1 overflow-y-auto px-4 py-3 space-y-3">
        <div v-if="messages.length === 0" class="flex flex-col items-center justify-center h-full text-center px-4">
          <div class="w-20 h-20 rounded-full bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center mb-4">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="currentColor" class="text-violet-600 dark:text-violet-400"><path d="M12 2L14.09 8.26L20 9.27L15.55 13.97L16.91 20L12 16.9L7.09 20L8.45 13.97L4 9.27L9.91 8.26L12 2Z"/></svg>
          </div>
          <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-1">Hallo, ich bin Sherlock!</h3>
          <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Dein KI-Assistent für alles rund um SR-Homes.</p>
          <p class="text-xs text-violet-500 dark:text-violet-400 mb-4 flex items-center gap-1.5">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="4" y1="8" x2="4" y2="16"/><line x1="8" y1="5" x2="8" y2="19"/><line x1="12" y1="3" x2="12" y2="21"/><line x1="16" y1="5" x2="16" y2="19"/><line x1="20" y1="8" x2="20" y2="16"/></svg>
            Starte den Sprachmodus für freihändiges Arbeiten
          </p>
          <div class="w-full space-y-2">
            <button v-for="s in suggestions" :key="s" @click="sendMessage(s)"
              class="w-full text-left px-3 py-2 text-sm rounded-xl border border-gray-200 dark:border-gray-600 hover:bg-violet-50 dark:hover:bg-violet-900/20 hover:border-violet-300 dark:hover:border-violet-500 text-gray-700 dark:text-gray-300 transition-colors"
            >{{ s }}</button>
          </div>
        </div>

        <template v-for="(msg, i) in messages" :key="i">
          <div v-if="msg.role === 'user'" class="flex justify-end">
            <div class="max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-br-md bg-violet-600 text-white text-sm leading-relaxed">
              {{ msg.text }}
              <div class="text-[10px] text-violet-200 mt-1 text-right">{{ formatTime(msg.time) }}</div>
            </div>
          </div>
          <div v-else class="flex justify-start gap-2">
            <div class="w-7 h-7 rounded-full bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center flex-shrink-0 mt-1">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="text-violet-600 dark:text-violet-400"><path d="M12 2L14.09 8.26L20 9.27L15.55 13.97L16.91 20L12 16.9L7.09 20L8.45 13.97L4 9.27L9.91 8.26L12 2Z"/></svg>
            </div>
            <div class="max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-bl-md text-sm leading-relaxed"
              :class="msg.isError ? 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200'">
              <div v-html="formatMessage(msg.text)"></div>
              <div class="flex items-center justify-between mt-1">
                <div class="text-[10px] text-gray-400">{{ formatTime(msg.time) }}</div>
                <button v-if="!msg.isError" @click="speakText(msg.text)" class="text-gray-400 hover:text-violet-500 transition ml-2" title="Vorlesen">
                  <Volume2 :size="12" />
                </button>
              </div>
            </div>
          </div>
        </template>

        <div v-if="isLoading" class="flex justify-start gap-2">
          <div class="w-7 h-7 rounded-full bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center flex-shrink-0">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="text-violet-600 dark:text-violet-400 animate-pulse"><path d="M12 2L14.09 8.26L20 9.27L15.55 13.97L16.91 20L12 16.9L7.09 20L8.45 13.97L4 9.27L9.91 8.26L12 2Z"/></svg>
          </div>
          <div class="px-4 py-3 rounded-2xl rounded-bl-md bg-gray-100 dark:bg-gray-700">
            <div class="flex items-center gap-1.5">
              <div class="w-2 h-2 bg-violet-400 rounded-full animate-bounce" style="animation-delay:0ms"></div>
              <div class="w-2 h-2 bg-violet-400 rounded-full animate-bounce" style="animation-delay:150ms"></div>
              <div class="w-2 h-2 bg-violet-400 rounded-full animate-bounce" style="animation-delay:300ms"></div>
            </div>
          </div>
        </div>

        <div v-if="isListening" class="flex justify-end">
          <div class="px-4 py-3 rounded-2xl rounded-br-md bg-violet-100 dark:bg-violet-900/30 border-2 border-violet-400 animate-pulse">
            <div class="flex items-center gap-2 text-violet-600 dark:text-violet-400 text-sm">
              <Mic :size="16" class="animate-pulse" />
              <span>{{ inputText || 'Ich höre zu...' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Speaking bar -->
      <div v-if="isSpeaking" class="px-4 py-1.5 bg-violet-50 dark:bg-violet-900/20 border-t border-violet-200 dark:border-violet-800 flex items-center justify-between">
        <div class="flex items-center gap-2 text-violet-600 dark:text-violet-400 text-xs">
          <div class="flex items-center gap-0.5">
            <div class="w-1 h-3 bg-violet-400 rounded-full animate-pulse"></div>
            <div class="w-1 h-4 bg-violet-500 rounded-full animate-pulse" style="animation-delay:75ms"></div>
            <div class="w-1 h-2 bg-violet-400 rounded-full animate-pulse" style="animation-delay:150ms"></div>
            <div class="w-1 h-5 bg-violet-500 rounded-full animate-pulse" style="animation-delay:225ms"></div>
            <div class="w-1 h-3 bg-violet-400 rounded-full animate-pulse" style="animation-delay:300ms"></div>
          </div>
          <span>Sherlock spricht...</span>
        </div>
        <button @click="stopSpeaking" class="text-violet-500 hover:text-violet-700 text-xs font-medium">Stopp</button>
      </div>

      <!-- Input -->
      <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
        <div class="flex items-end gap-2">
          <button
            v-if="recognitionSupported"
            @click="toggleListening"
            :disabled="isLoading"
            class="p-2.5 rounded-xl transition-all flex-shrink-0"
            :class="isListening ? 'bg-red-500 text-white animate-pulse shadow-lg shadow-red-500/30' : 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300 hover:bg-violet-100 dark:hover:bg-violet-900/30 hover:text-violet-600'"
            :title="isListening ? 'Aufnahme stoppen' : 'Spracheingabe'"
          >
            <Mic v-if="!isListening" :size="18" />
            <MicOff v-else :size="18" />
          </button>

          <textarea
            ref="inputRef"
            v-model="inputText"
            @keydown="handleKeydown"
            placeholder="Frag Sherlock..."
            rows="1"
            class="flex-1 px-3.5 py-2.5 text-sm rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:border-transparent resize-none max-h-24"
            :disabled="isLoading || isListening"
          ></textarea>

          <button
            @click="sendMessage()"
            :disabled="!inputText.trim() || isLoading"
            class="p-2.5 rounded-xl bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex-shrink-0"
          >
            <Loader2 v-if="isLoading" :size="18" class="animate-spin" />
            <Send v-else :size="18" />
          </button>
        </div>
        <p class="text-[10px] text-gray-400 mt-1.5 text-center">Sherlock · KI-Assistent von SR-Homes</p>
      </div>
    </div>
  </Transition>
</template>
