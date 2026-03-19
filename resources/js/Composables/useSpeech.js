import { ref, computed, onBeforeUnmount } from 'vue';

/**
 * useSpeech — composable for the Web Speech API
 * 
 * TTS: speaks messages with role === 'thinking'
 *      and role === 'system'/'assistant' without '<system_output_results>'
 * STT: voice input via SpeechRecognition
 */
export function useSpeech(options = {}) {

    // ─── API support ───────────────────────────────────────────────────────────

    const hasTTS = typeof window !== 'undefined' && 'speechSynthesis' in window;
    const hasSTT = typeof window !== 'undefined' &&
        ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

    // ─── TTS state ──────────────────────────────────────────────────────────────

    /** Global TTS toggle (on/off) — restored from localStorage */
    const savedTTS = typeof window !== 'undefined'
        ? localStorage.getItem('depthnet_tts_enabled') === 'true'
        : false;
    const ttsEnabled = ref(savedTTS);

    /** Something is currently being spoken */
    const isSpeaking = ref(false);

    /** ID of the message CURRENTLY being spoken (reset when speech ends) */
    const currentlySpeakingId = ref(null);

    /** Internal utterance queue */
    const speakQueue = [];
    let isProcessingQueue = false;

    /** ID of the last spoken message — prevents duplicates during auto-speak */
    const lastSpokenMessageId = ref(null);

    /**
     * Cache of available voices.
     * Chrome loads them asynchronously — wait for the voiceschanged event.
     */
    let voicesCache = [];

    function loadVoices() {
        voicesCache = window.speechSynthesis.getVoices();
    }

    if (hasTTS) {
        loadVoices();
        // Chrome: voices arrive asynchronously
        window.speechSynthesis.addEventListener('voiceschanged', loadVoices);
    }

    /**
     * Page Visibility API — stop speech when the tab goes to the background.
     * Prevents queue buildup and catch-up playback in Chrome.
     */
    // Ignore visibilitychange for the first 2s — Vite HMR and initial page load
    // fire it spuriously during that window
    const initTime = Date.now();
    const VISIBILITY_GRACE_MS = 2000;

    function handleVisibilityChange() {
        if (document.hidden && Date.now() - initTime > VISIBILITY_GRACE_MS) {
            stopSpeaking();
        }
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    /**
     * Select the best available voice for a given language.
     * Priority: local OS voice > any exact match > prefix match > browser default.
     */
    function getBestVoice(lang) {
        const voices = voicesCache.length ? voicesCache : window.speechSynthesis.getVoices();
        if (!voices.length) return null;

        const langPrefix = lang.split('-')[0]; // 'ru' from 'ru-RU'

        // 1. Local OS voice for exact language (localService = built-in, not online)
        const localExact = voices.find(v => v.lang === lang && v.localService);
        if (localExact) return localExact;

        // 2. Any voice for the exact language
        const anyExact = voices.find(v => v.lang === lang);
        if (anyExact) return anyExact;

        // 3. Any voice sharing the language prefix (ru-RU, ru-UA, etc.)
        const anyPrefix = voices.find(v => v.lang.startsWith(langPrefix));
        if (anyPrefix) return anyPrefix;

        // 4. Browser default voice
        return voices.find(v => v.default) || voices[0] || null;
    }

    // ─── STT state ──────────────────────────────────────────────────────────────

    /** Currently recording */
    const isListening = ref(false);

    /** Final text from the last recognition */
    const recognizedText = ref('');

    /** Interim text while recording */
    const interimText = ref('');

    /** STT error */
    const sttError = ref(null);

    let recognition = null;

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Clean message text for speech:
     * — strips [plugin]...[/plugin] command tags
     * — strips the <system_output_results> block
     * — strips Markdown formatting
     * — collapses whitespace
     */
    function cleanTextForSpeech(content) {
        if (!content) return '';

        let text = content;

        // Strip the results block
        const marker = '<system_output_results>';
        const markerIdx = text.lastIndexOf(marker);
        if (markerIdx !== -1) {
            text = text.substring(0, markerIdx);
        }

        // Remove command tags and their contents
        text = text.replace(/\[[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_]*)?\][\s\S]*?\[\/[a-z][a-z0-9_]*\]/gi, '');

        // Remove unclosed command tags
        text = text.replace(/\[[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_]*)?\]/gi, '');

        // Strip HTML tags
        text = text.replace(/<[^>]+>/g, '');

        // Markdown: remove fenced code blocks
        text = text.replace(/```[\s\S]*?```/g, ' [code] ');
        text = text.replace(/`[^`]+`/g, '');

        // Markdown: strip headings, bold, italic, links
        text = text.replace(/#{1,6}\s+/g, '');
        text = text.replace(/\*\*(.+?)\*\*/g, '$1');
        text = text.replace(/\*(.+?)\*/g, '$1');
        text = text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');

        // Decode HTML entities
        text = text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"');

        // Collapse whitespace
        text = text.replace(/\s+/g, ' ').trim();

        return text;
    }

    /**
     * Determine whether a message should be spoken.
     * Speaks: role === 'thinking', or system/assistant/speaking without command results.
     */
    function shouldSpeak(message) {
        if (!message || !message.content) return false;

        if (message.role === 'thinking') return true;

        // system / assistant / speaking — only if no command results present
        if (['system', 'assistant', 'speaking'].includes(message.role)) {
            return !message.content.includes('<system_output_results>');
        }

        return false;
    }

    // ─── TTS core ───────────────────────────────────────────────────────────────

    /** Enqueue text and trigger queue processing */
    function enqueueSpeak(text, messageId = null) {
        if (!hasTTS || !text.trim()) return;

        speakQueue.push({ text, messageId });
        processQueue();
    }

    function processQueue() {
        if (isProcessingQueue || speakQueue.length === 0) return;
        if (!window.speechSynthesis) return;

        // Chrome workaround: cancel if synthesis is stuck
        if (window.speechSynthesis.speaking) {
            window.speechSynthesis.cancel();
        }

        isProcessingQueue = true;
        const { text, messageId } = speakQueue.shift();

        // Track which message is currently being spoken
        currentlySpeakingId.value = messageId;

        const sentences = splitIntoSentences(text);
        let sentenceIndex = 0;

        function speakNext() {
            if (sentenceIndex >= sentences.length) {
                isSpeaking.value = false;
                isProcessingQueue = false;
                currentlySpeakingId.value = null; // reset — speech finished
                if (messageId) lastSpokenMessageId.value = messageId;
                processQueue();
                return;
            }

            const sentence = sentences[sentenceIndex];
            const lang = detectLang(sentence);

            const utterance = new SpeechSynthesisUtterance(sentence);
            utterance.lang = lang;
            utterance.rate = 1.0;
            utterance.pitch = 1.0;
            utterance.volume = 1.0;

            // Explicitly set the best voice — Chrome/Firefox may pick the wrong one
            const voice = getBestVoice(lang);
            if (voice) utterance.voice = voice;

            utterance.onstart = () => { isSpeaking.value = true; };
            utterance.onend = () => {
                sentenceIndex++;
                speakNext();
            };
            utterance.onerror = (e) => {
                if (e.error !== 'interrupted' && e.error !== 'canceled') {
                    console.warn('SpeechSynthesis error:', e.error);
                }
                sentenceIndex++;
                speakNext();
            };

            // Chrome workaround: speak() can hang without a small delay
            setTimeout(() => {
                window.speechSynthesis.speak(utterance);
            }, 50);
        }

        speakNext();
    }

    /** Split text into sentences */
    function splitIntoSentences(text) {
        // Split on . ! ? — must be followed by whitespace or end of string
        const parts = text.match(/[^.!?]+[.!?]+\s*/g) || [text];
        return parts
            .map(s => s.trim())
            .filter(s => s.length > 0);
    }

    /** Detect language — Cyrillic → ru-RU, Latin → en-US */
    function detectLang(text) {
        const cyrillicCount = (text.match(/[а-яёА-ЯЁ]/g) || []).length;
        const latinCount = (text.match(/[a-zA-Z]/g) || []).length;
        return cyrillicCount >= latinCount ? 'ru-RU' : 'en-US';
    }

    /**
     * Manually speak a message (triggered by the speaker button on a message).
     * Does not check lastSpokenMessageId — the same message can be played many times.
     */
    function speakMessage(message) {
        if (!hasTTS) return;
        if (!shouldSpeak(message)) return;

        const text = cleanTextForSpeech(message.content);
        if (!text) return;

        // Stop whatever is playing and start the new message
        stopSpeaking();
        enqueueSpeak(text, message.id);
    }

    /**
     * Auto-speak new messages after a refresh.
     * Checks lastSpokenMessageId to avoid duplicates.
     * No-ops when the tab is hidden — prevents queue buildup.
     */
    // Flag: initial load is done, new messages can now be spoken
    let initialLoadDone = false;

    function markInitialLoadDone() {
        initialLoadDone = true;
    }

    /** Reset initial load flag — call before switching preset */
    function resetInitialLoad() {
        initialLoadDone = false;
        stopSpeaking();
    }

    function speakNewMessages(messages) {
        if (!hasTTS || !ttsEnabled.value || !messages?.length) return;

        // Skip historical messages during initial page load
        if (!initialLoadDone) return;

        // Skip if tab is hidden
        if (typeof document !== 'undefined' && document.hidden) return;

        for (const msg of messages) {
            if (!shouldSpeak(msg)) continue;
            if (msg.id && msg.id === lastSpokenMessageId.value) continue;
            const text = cleanTextForSpeech(msg.content);
            if (!text) continue;
            enqueueSpeak(text, msg.id);
        }
    }

    /** Stop speech and clear the queue */
    function stopSpeaking() {
        speakQueue.length = 0;
        isProcessingQueue = false;
        isSpeaking.value = false;
        currentlySpeakingId.value = null;
        if (hasTTS) {
            window.speechSynthesis.cancel();
        }
    }

    /** Toggle TTS on/off and persist the state to localStorage */
    function toggleTTS() {
        ttsEnabled.value = !ttsEnabled.value;
        localStorage.setItem('depthnet_tts_enabled', ttsEnabled.value ? 'true' : 'false');
        if (!ttsEnabled.value) {
            stopSpeaking();
        }
    }

    // ─── STT core ───────────────────────────────────────────────────────────────

    function initRecognition() {
        if (!hasSTT) return null;

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const r = new SpeechRecognition();

        r.continuous = false;         // One phrase at a time — simpler and more reliable
        r.interimResults = true;      // Show interim results while speaking
        r.maxAlternatives = 1;
        // Priority: explicit option → <html lang> → navigator.language → fallback
        const sttLang = options.sttLang
            || document.documentElement.lang
            || navigator.language
            || 'ru-RU';
        r.lang = sttLang;

        r.onstart = () => {
            isListening.value = true;
            sttError.value = null;
            interimText.value = '';
        };

        r.onresult = (event) => {
            let interim = '';
            let final = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;
                if (event.results[i].isFinal) {
                    final += transcript;
                } else {
                    interim += transcript;
                }
            }

            interimText.value = interim;
            if (final) {
                recognizedText.value = final.trim();
                interimText.value = '';
            }
        };

        r.onerror = (event) => {
            // 'no-speech' means silence — not a real error
            if (event.error !== 'no-speech') {
                sttError.value = event.error;
                console.warn('SpeechRecognition error:', event.error);
            }
            isListening.value = false;
        };

        r.onend = () => {
            isListening.value = false;
            interimText.value = '';
        };

        return r;
    }

    /**
     * Start/stop voice recording.
     * Returns Promise<string> — recognized text, or '' if stopped without input.
     */
    function toggleListening() {
        if (!hasSTT) return Promise.resolve('');

        if (isListening.value) {
            recognition?.stop();
            return Promise.resolve(recognizedText.value);
        }

        return new Promise((resolve) => {
            recognizedText.value = '';
            interimText.value = '';

            recognition = initRecognition();
            if (!recognition) {
                resolve('');
                return;
            }

            // Recording ended — resolve with the recognized text
            recognition.onend = () => {
                isListening.value = false;
                interimText.value = '';
                resolve(recognizedText.value);
            };

            try {
                recognition.start();
            } catch (e) {
                console.warn('Failed to start recognition:', e);
                isListening.value = false;
                resolve('');
            }
        });
    }

    /** Stop recording */
    function stopListening() {
        if (recognition && isListening.value) {
            recognition.stop();
        }
    }

    // ─── Cleanup ────────────────────────────────────────────────────────────────

    function cleanup() {
        stopSpeaking();
        stopListening();
        if (hasTTS) {
            window.speechSynthesis.removeEventListener('voiceschanged', loadVoices);
        }
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        }
    }

    onBeforeUnmount(cleanup);

    // ─── Public API ─────────────────────────────────────────────────────────────

    return {
        // Browser capabilities
        hasTTS,
        hasSTT,

        // TTS state
        ttsEnabled,
        isSpeaking,
        currentlySpeakingId,
        lastSpokenMessageId,

        // STT state
        isListening,
        recognizedText,
        interimText,
        sttError,

        // TTS methods
        speakMessage,
        speakNewMessages,
        markInitialLoadDone,
        resetInitialLoad,
        stopSpeaking,
        toggleTTS,

        // STT methods
        toggleListening,
        stopListening,

        // Utilities (for tests / custom scenarios)
        cleanTextForSpeech,
        shouldSpeak,
    };
}