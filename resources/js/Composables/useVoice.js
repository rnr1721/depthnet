import { ref, onBeforeUnmount } from 'vue';

/**
 * useVoice — unified Web Speech composable.
 *
 * Key idea vs. the old useSpeech: ONE SpeechRecognition instance for everything,
 * driven by an explicit state machine. No competing recognizers, no restart races,
 * no dead zones where the wake word falls through.
 *
 * STT state machine:
 *
 *   IDLE         recognition not running
 *   WAKE         running, listening for the wake word only
 *   DICTATING    running, accumulating a phrase to hand back to the caller
 *
 * Transitions:
 *   IDLE  --start()-->            WAKE        (background wake listener on)
 *   WAKE  --wake word heard-->    DICTATING   (onWake fired, mic stays open)
 *   WAKE  --toggleMic()-->        DICTATING   (manual start, skips wake word)
 *   DICTATING --silence timeout-->            finalize → back to WAKE (or IDLE if no wake word)
 *   DICTATING --toggleMic()/stop-->           finalize → back to WAKE (or IDLE)
 *
 * The browser kills a continuous session after ~60s of silence; we transparently
 * restart it from onend while staying in the same logical state.
 *
 * TTS is unchanged in spirit from the old composable — the bug was never there.
 */
export function useVoice(options = {}) {

    // ─── Capabilities ────────────────────────────────────────────────────────
    const hasTTS = typeof window !== 'undefined' && 'speechSynthesis' in window;
    const hasSTT = typeof window !== 'undefined' &&
        ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

    // ═══════════════════════════════════════════════════════════════════════════
    //  STT — platform-adaptive recognition
    //
    //  Desktop: continuous wake-word listener + hands-free dictation.
    //  Mobile:  push-to-talk only. One phrase per tap (continuous=false),
    //           text goes to the input field, user sends manually.
    //           No always-on wake word (it's the source of the mic "beeping"
    //           and the duplicated-interim bug on mobile Chrome).
    // ═══════════════════════════════════════════════════════════════════════════

    function isMobileDevice() {
        if (typeof navigator === 'undefined') return false;
        const ua = navigator.userAgent || '';
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua)
            || (typeof window !== 'undefined' && window.innerWidth < 1024);
    }

    const IS_MOBILE = isMobileDevice();
    // Continuous sessions are only reliable on desktop.
    const USE_CONTINUOUS = !IS_MOBILE;

    const STATE = { IDLE: 'idle', WAKE: 'wake', DICTATING: 'dictating' };

    // Public reactive flags (mirror the machine for the UI)
    const isListening = ref(false);          // true while DICTATING
    const isWakeWordListening = ref(false);  // true while WAKE
    const wakeWordDetected = ref(false);     // brief flash when wake word matched
    const interimText = ref('');
    const recognizedText = ref('');
    const sttError = ref(null);

    // Internal machine state — not reactive, single source of truth
    let machineState = STATE.IDLE;
    let recognition = null;
    let wantRunning = false;     // do we WANT recognition alive? (survives auto-stop)
    let restarting = false;      // guard against double restart from onend
    // Mic can be paused for multiple overlapping reasons (TTS speaking AND waiting
    // for the agent's reply). We resume only when ALL reasons are cleared.
    const pauseReasons = new Set(); // 'tts' | 'busy'
    let dictationSource = 'manual'; // how dictation started: 'wake' | 'manual'

    // Echo guard: suppress wake word matching while TTS speaks (+ a short tail).
    // 1200ms tail is safer for back-and-forth voice dialogue where room acoustics
    // and speaker lag let the agent's trailing audio reach the mic.
    const ECHO_TAIL_MS = options.echoTailMs ?? 1200;
    let suppressWakeUntil = 0;

    // Silence-based finalization (used in continuous/desktop mode).
    const SILENCE_MS = options.silenceMs ?? 1500;
    let silenceTimer = null;
    let dictationBuffer = '';

    // Config
    let wakeWords = [];
    let wakeSkeletons = [];   // consonant skeletons for fuzzy vowel-tolerant matching
    let onWakeCallback = null;
    let onPhraseCallback = null;
    const sttLang = options.sttLang
        || (typeof document !== 'undefined' ? document.documentElement.lang : '')
        || 'ru-RU';

    // ─── Wake word normalization (cyrillic ↔ latin) ───────────────────────────

    const LAT_TO_CYR = [
        ['shch', 'щ'], ['sch', 'щ'], ['yo', 'ё'], ['zh', 'ж'], ['kh', 'х'],
        ['ts', 'ц'], ['ch', 'ч'], ['sh', 'ш'], ['yu', 'ю'], ['ya', 'я'],
        ['iy', 'и'], ['ye', 'е'], ['a', 'а'], ['b', 'б'], ['v', 'в'],
        ['g', 'г'], ['d', 'д'], ['e', 'е'], ['z', 'з'], ['i', 'и'],
        ['j', 'й'], ['k', 'к'], ['l', 'л'], ['m', 'м'], ['n', 'н'],
        ['o', 'о'], ['p', 'п'], ['r', 'р'], ['s', 'с'], ['t', 'т'],
        ['u', 'у'], ['f', 'ф'], ['y', 'ы'], ['h', 'х'], ['c', 'к'],
    ];

    function translitLatToCyr(s) {
        let out = s;
        for (const [lat, cyr] of LAT_TO_CYR) out = out.split(lat).join(cyr);
        return out;
    }

    function normalize(s) {
        return (s || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-zа-яё0-9 ]/gi, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /** Consonant skeleton: drop vowels and transliterate to one alphabet, so
     *  "флэш" / "флеш" / "флаш" / "flash" all collapse to the same key. Used as a
     *  fuzzy fallback when exact/translit matching misses on vowel differences. */
    function skeleton(s) {
        // transliterate latin→cyrillic first so both scripts share an alphabet,
        // then drop all vowels (latin + cyrillic).
        const cyr = translitLatToCyr(normalize(s));
        return cyr.replace(/[аеёиоуыэюяaeiouy]/gi, '');
    }

    /**
     * Accepts a single wake word, a comma/pipe-separated string, or an array.
     * Each entry becomes match variants (exact + latin→cyrillic translit).
     */
    function buildWakeVariants(word) {
        const raw = Array.isArray(word)
            ? word
            : String(word || '').split(/[,|]/);
        const variants = new Set();
        const skeletons = new Set();
        for (const part of raw) {
            const base = normalize(part);
            if (!base) continue;
            variants.add(base);
            if (/[a-z]/.test(base)) variants.add(translitLatToCyr(base));
            const sk = skeleton(part);
            if (sk.length >= 3) skeletons.add(sk); // skip very short skeletons (false positives)
        }
        wakeSkeletons = [...skeletons];
        return [...variants].filter(Boolean);
    }

    function matchesWakeWord(transcript) {
        const norm = normalize(transcript);
        const normTranslit = translitLatToCyr(norm);
        // 1. exact / transliterated substring match
        if (wakeWords.some(w => norm.includes(w) || normTranslit.includes(w))) return true;
        // 2. fuzzy consonant-skeleton match (handles flash/флэш/флеш vowel drift)
        if (wakeSkeletons.length) {
            const heardSkeleton = skeleton(transcript);
            return wakeSkeletons.some(sk => heardSkeleton.includes(sk));
        }
        return false;
    }

    function isWakeSuppressed() {
        return Date.now() < suppressWakeUntil;
    }

    // ─── Recognition lifecycle ────────────────────────────────────────────────

    function buildRecognition() {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        const r = new SR();
        r.continuous = USE_CONTINUOUS;   // desktop: long session; mobile: one phrase
        r.interimResults = true;
        r.maxAlternatives = 3;
        r.lang = sttLang;

        r.onstart = () => { restarting = false; syncFlags(); };
        r.onresult = handleResult;

        r.onerror = (e) => {
            if (e.error === 'no-speech' || e.error === 'aborted') return;
            sttError.value = e.error;
            console.warn('[useVoice] recognition error:', e.error);
        };

        r.onend = () => {
            // While paused for TTS, do not restart — the mic must stay closed so
            // the agent's spoken reply can't enter results[]. resumeRecognition()
            // brings it back when speech finishes.
            if (isMicPaused()) return;
            if (USE_CONTINUOUS && wantRunning && !restarting) {
                // Desktop: browser auto-stopped a continuous session — restart it.
                restarting = true;
                setTimeout(() => {
                    if (wantRunning && !isMicPaused() && recognition) {
                        try { recognition.start(); } catch (err) { restarting = false; }
                    }
                }, 200);
            } else if (!USE_CONTINUOUS && machineState === STATE.DICTATING) {
                // Mobile: a single-phrase session ended → finalize whatever we got.
                finalizeDictation();
            } else if (!wantRunning) {
                syncFlags();
            }
        };

        return r;
    }

    function handleResult(event) {
        // GLOBAL echo guard: while TTS is speaking (+ tail), ignore ALL input —
        // both wake matching and dictation. The agent's own voice must never be
        // transcribed into the user's message. This is the key fix for self-pickup.
        if (isWakeSuppressed()) {
            // Drop any interim that leaked in just before suppression kicked in.
            if (machineState === STATE.DICTATING) interimText.value = '';
            return;
        }

        // WAKE: only check for the wake word (desktop only — mobile never enters WAKE).
        if (machineState === STATE.WAKE) {
            for (let i = 0; i < event.results.length; i++) {
                const res = event.results[i];
                for (let j = 0; j < res.length; j++) {
                    if (matchesWakeWord(res[j].transcript)) { onWakeWordHit(); return; }
                }
            }
            return;
        }

        if (machineState !== STATE.DICTATING) return;

        // DICTATING: rebuild the whole transcript from results[] (assign, don't
        // accumulate — that fixes the interim "Я Я хочу" duplication).
        let finalText = '';
        let interim = '';
        for (let i = 0; i < event.results.length; i++) {
            const res = event.results[i];
            if (!res || !res[0]) continue;
            if (res.isFinal) finalText += res[0].transcript + ' ';
            else interim += res[0].transcript;
        }

        let combined = (finalText + interim).replace(/\s+/g, ' ').trim();

        // Strip the wake word (and anything before/including it) that leaked into
        // results[] during the WAKE→DICTATING handoff. This kills the phrase-level
        // "Флэш ... Флэш ..." duplication where the pre-roll got re-included.
        combined = stripWakePrefix(combined);

        dictationBuffer = combined;
        interimText.value = ''; // combined already holds interim; avoid double-count

        // Desktop uses a silence timer to end a phrase; mobile relies on onend.
        if (USE_CONTINUOUS) armSilenceTimer();
    }

    /**
     * Remove the wake word and EVERYTHING before it from a dictated transcript.
     * In WAKE mode the recognizer keeps accumulating whatever was said before the
     * wake word into results[]; once dictation starts we rebuild from the whole
     * array, so chatter that preceded "флэш" leaks in. We find the LAST wake-word
     * token and keep only what comes after it.
     *
     * "болтаю о своём флэш открой файл"  → "открой файл"
     * "флэш флэш привет"                 → "привет"
     * "флэш"                             → ""  (wake word only, nothing to send)
     */
    function isWakeToken(token) {
        const n = normalize(token);
        if (!n) return false; // empty/punctuation — not a wake token, just skippable
        const nt = translitLatToCyr(n);
        if (wakeWords.some(w => n === w || nt === w || n.includes(w) || nt.includes(w))) return true;
        if (wakeSkeletons.length) {
            const sk = skeleton(token);
            if (sk.length >= 3 && wakeSkeletons.some(s => sk === s)) return true;
        }
        return false;
    }

    function stripWakePrefix(text) {
        if (!wakeWords.length || !text) return text;
        const words = text.split(/\s+/).filter(Boolean);
        // find the LAST token that is a wake word
        let lastWakeIdx = -1;
        for (let i = 0; i < words.length; i++) {
            if (isWakeToken(words[i])) lastWakeIdx = i;
        }
        if (lastWakeIdx === -1) {
            // No wake word in the text (manual dictation, or wake matched fuzzily on
            // a multi-word boundary). Leave it alone but still dedupe.
            return dedupeRepeatedPrefix(text);
        }
        // keep everything AFTER the last wake token
        const tail = words.slice(lastWakeIdx + 1).join(' ').trim();
        return dedupeRepeatedPrefix(tail);
    }

    /**
     * Collapse the specific "said it, mic didn't show, said it again" duplication:
     * if the transcript is two near-identical halves, keep one. We compare the
     * normalized first half against the second; if they match, drop the first.
     */
    function dedupeRepeatedPrefix(text) {
        const words = text.split(/\s+/).filter(Boolean);
        const n = words.length;
        if (n < 4) return text; // too short to be a meaningful duplicate
        // try splitting into two equal halves
        if (n % 2 === 0) {
            const half = n / 2;
            const first = words.slice(0, half).join(' ').toLowerCase();
            const second = words.slice(half).join(' ').toLowerCase();
            if (normalize(first) === normalize(second)) {
                return words.slice(half).join(' ').trim();
            }
        }
        return text;
    }

    function onWakeWordHit() {
        wakeWordDetected.value = true;
        setTimeout(() => {
            wakeWordDetected.value = false;
            enterDictating('wake');
            onWakeCallback?.();
        }, 350);
    }

    // ─── Silence finalization (desktop) ─────────────────────────────────────────

    function armSilenceTimer() {
        clearSilenceTimer();
        silenceTimer = setTimeout(finalizeDictation, SILENCE_MS);
    }

    function clearSilenceTimer() {
        if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
    }

    let finalizing = false; // guard: onend + silence timer must not double-fire

    function finalizeDictation() {
        if (finalizing) return;
        finalizing = true;
        clearSilenceTimer();

        const text = (dictationBuffer + ' ' + interimText.value).replace(/\s+/g, ' ').trim();
        const source = dictationSource;
        dictationBuffer = '';
        interimText.value = '';
        recognizedText.value = text;

        if (text) onPhraseCallback?.(text, source);

        // Desktop with a wake word returns to listening; otherwise stop.
        if (USE_CONTINUOUS && wakeWords.length) {
            enterWake();
        } else {
            stopAll();
        }
        finalizing = false;
    }

    // ─── State transitions ──────────────────────────────────────────────────────

    function enterWake() {
        // Mobile never runs an always-on wake listener.
        if (!USE_CONTINUOUS) { stopAll(); return; }
        machineState = STATE.WAKE;
        syncFlags();
        ensureRunning();
    }

    function enterDictating(source = 'manual') {
        machineState = STATE.DICTATING;
        dictationSource = source;
        dictationBuffer = '';
        interimText.value = '';
        recognizedText.value = '';
        finalizing = false;
        syncFlags();
        ensureRunning();
        if (USE_CONTINUOUS) armSilenceTimer();
    }

    function syncFlags() {
        isWakeWordListening.value = (machineState === STATE.WAKE);
        isListening.value = (machineState === STATE.DICTATING);
    }

    function ensureRunning() {
        if (!hasSTT) return;
        wantRunning = true;
        // Mobile: rebuild a fresh recognizer per phrase — cleaner than reusing.
        if (!recognition || !USE_CONTINUOUS) recognition = buildRecognition();
        try { recognition.start(); }
        catch (e) {
            if (e.name !== 'InvalidStateError') console.warn('[useVoice] start failed:', e);
        }
    }

    /**
     * Hard-pause the microphone for a named reason ('tts' while the agent speaks,
     * 'busy' while a request is in flight). Physically stops recognition so neither
     * the agent's voice nor a premature new phrase can enter results[].
     */
    function pauseRecognition(reason = 'tts') {
        if (!hasSTT) return;
        if (!USE_CONTINUOUS) return; // only meaningful on desktop's open mic
        pauseReasons.add(reason);
        if (machineState === STATE.IDLE) return; // nothing running to pause
        clearSilenceTimer();
        if (recognition) {
            try { recognition.stop(); } catch (e) { /* ignore */ }
        }
    }

    /**
     * Clear a pause reason. The mic only actually resumes once ALL reasons are
     * gone — so TTS finishing won't reopen the mic if we're still awaiting a reply.
     * Returns the mic to WAKE listening (desktop). Mobile never auto-resumes.
     */
    function resumeRecognition(reason = 'tts') {
        if (!hasSTT) return;
        pauseReasons.delete(reason);
        if (pauseReasons.size > 0) return; // still paused for another reason
        restarting = false;
        // Clear anything buffered around the pause.
        dictationBuffer = '';
        interimText.value = '';
        if (USE_CONTINUOUS && wakeWords.length) {
            machineState = STATE.WAKE;
            syncFlags();
            ensureRunning();
        } else {
            stopAll();
        }
    }

    function isMicPaused() { return pauseReasons.size > 0; }

    // ─── Public STT API ───────────────────────────────────────────────────────

    function startWakeWord(word, onWake) {
        if (!hasSTT) return;
        // Mobile: no always-on wake word. Push-to-talk button is the entry point.
        if (!USE_CONTINUOUS) return;
        wakeWords = buildWakeVariants(word);
        onWakeCallback = onWake || null;
        if (!wakeWords.length) return;
        enterWake();
    }

    function stopWakeWord() {
        wakeWords = [];
        onWakeCallback = null;
        if (machineState === STATE.WAKE) stopAll();
    }

    function toggleMic() {
        if (!hasSTT) return;
        if (machineState === STATE.DICTATING) {
            finalizeDictation();
        } else {
            enterDictating('manual');
        }
    }

    function stopAll() {
        wantRunning = false;
        clearSilenceTimer();
        machineState = STATE.IDLE;
        dictationBuffer = '';
        interimText.value = '';
        syncFlags();
        if (recognition) {
            try { recognition.stop(); } catch (e) { /* ignore */ }
        }
    }

    function onPhrase(cb) { onPhraseCallback = cb; }

    /**
     * Host signals whether a request is in flight. While busy, the mic is paused
     * so the user can't fire off 2-3 messages by voice before the reply arrives.
     * Pass isProcessing from the chat component. Combines with the TTS pause via
     * the reason set — the mic reopens only when both are clear.
     */
    function setBusy(busy) {
        if (busy) pauseRecognition('busy');
        else resumeRecognition('busy');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  TTS — carried over from useSpeech (this part worked fine)
    // ═══════════════════════════════════════════════════════════════════════════

    const savedTTS = typeof window !== 'undefined'
        ? localStorage.getItem('depthnet_tts_enabled') === 'true'
        : false;
    const ttsEnabled = ref(savedTTS);
    const isSpeaking = ref(false);
    const currentlySpeakingId = ref(null);
    const lastSpokenMessageId = ref(null);

    const speakQueue = [];
    let isProcessingQueue = false;
    let voicesCache = [];

    function loadVoices() { voicesCache = window.speechSynthesis.getVoices(); }

    if (hasTTS) {
        loadVoices();
        window.speechSynthesis.addEventListener('voiceschanged', loadVoices);
    }

    const initTime = Date.now();
    const VISIBILITY_GRACE_MS = 2000;
    function handleVisibilityChange() {
        if (document.hidden && Date.now() - initTime > VISIBILITY_GRACE_MS) stopSpeaking();
    }
    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    function getBestVoice(lang) {
        const voices = voicesCache.length ? voicesCache : window.speechSynthesis.getVoices();
        if (!voices.length) return null;
        const prefix = lang.split('-')[0];
        return voices.find(v => v.lang === lang && v.localService)
            || voices.find(v => v.lang === lang)
            || voices.find(v => v.lang.startsWith(prefix))
            || voices.find(v => v.default)
            || voices[0] || null;
    }

    function cleanTextForSpeech(content) {
        if (!content) return '';
        let text = content;
        const marker = '<system_output_results>';
        const idx = text.lastIndexOf(marker);
        if (idx !== -1) text = text.substring(0, idx);
        text = text.replace(/\[[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_]*)?\][\s\S]*?\[\/[a-z][a-z0-9_]*\]/gi, '');
        text = text.replace(/\[[a-z][a-z0-9_]*(?:\s+[a-z][a-z0-9_]*)?\]/gi, '');
        text = text.replace(/<[^>]+>/g, '');
        text = text.replace(/```[\s\S]*?```/g, ' [code] ');
        text = text.replace(/`[^`]+`/g, '');
        text = text.replace(/#{1,6}\s+/g, '');
        text = text.replace(/\*\*(.+?)\*\*/g, '$1');
        text = text.replace(/\*(.+?)\*/g, '$1');
        text = text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
        text = text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"');
        return text.replace(/\s+/g, ' ').trim();
    }

    function shouldSpeak(message) {
        if (!message || !message.content) return false;
        if (message.role === 'thinking') return true;
        if (['system', 'assistant', 'speaking'].includes(message.role)) {
            return !message.content.includes('<system_output_results>');
        }
        return false;
    }

    function splitIntoSentences(text) {
        const parts = text.match(/[^.!?]+[.!?]+\s*/g) || [text];
        return parts.map(s => s.trim()).filter(Boolean);
    }

    function detectLang(text) {
        const cyr = (text.match(/[а-яёА-ЯЁ]/g) || []).length;
        const lat = (text.match(/[a-zA-Z]/g) || []).length;
        return cyr >= lat ? 'ru-RU' : 'en-US';
    }

    function enqueueSpeak(text, messageId = null) {
        if (!hasTTS || !text.trim()) return;
        // Close the mic before any audio plays, so the agent's voice can't be
        // recognized. Resumed once the whole queue drains (see resume below).
        pauseRecognition();
        speakQueue.push({ text, messageId });
        processQueue();
    }

    function processQueue() {
        if (isProcessingQueue || !speakQueue.length || !window.speechSynthesis) return;
        if (window.speechSynthesis.speaking) window.speechSynthesis.cancel();
        isProcessingQueue = true;
        const { text, messageId } = speakQueue.shift();
        currentlySpeakingId.value = messageId;
        const sentences = splitIntoSentences(text);
        let i = 0;
        function next() {
            if (i >= sentences.length) {
                isSpeaking.value = false;
                isProcessingQueue = false;
                currentlySpeakingId.value = null;
                if (messageId) lastSpokenMessageId.value = messageId;
                // One more tail in case this was the last item in the queue.
                suppressWakeUntil = Date.now() + ECHO_TAIL_MS;
                // If nothing else is queued, the agent is done talking — reopen the
                // mic after a short tail so trailing speaker audio can fully decay.
                if (speakQueue.length === 0) {
                    setTimeout(() => {
                        if (speakQueue.length === 0 && !isSpeaking.value) {
                            resumeRecognition();
                        }
                    }, ECHO_TAIL_MS);
                }
                processQueue();
                return;
            }
            const sentence = sentences[i];
            const lang = detectLang(sentence);
            const u = new SpeechSynthesisUtterance(sentence);
            u.lang = lang; u.rate = 1.0; u.pitch = 1.0; u.volume = 1.0;
            const v = getBestVoice(lang);
            if (v) u.voice = v;
            u.onstart = () => {
                isSpeaking.value = true;
                // Hold a wide suppression window for the whole utterance.
                suppressWakeUntil = Date.now() + 60000;
            };
            u.onend = () => {
                // Only relax to the short tail when there is genuinely nothing
                // left to speak — neither more sentences here nor queued items.
                // Otherwise keep the window wide so the gap between sentences
                // can't let the agent's own voice leak into recognition.
                const moreSentences = (i + 1) < sentences.length;
                const moreQueued = speakQueue.length > 0;
                if (!moreSentences && !moreQueued) {
                    suppressWakeUntil = Date.now() + ECHO_TAIL_MS;
                }
                i++; next();
            };
            u.onerror = (e) => {
                if (e.error !== 'interrupted' && e.error !== 'canceled') {
                    console.warn('SpeechSynthesis error:', e.error);
                }
                const moreSentences = (i + 1) < sentences.length;
                const moreQueued = speakQueue.length > 0;
                if (!moreSentences && !moreQueued) {
                    suppressWakeUntil = Date.now() + ECHO_TAIL_MS;
                }
                i++; next();
            };
            setTimeout(() => window.speechSynthesis.speak(u), 50);
        }
        next();
    }

    function speakMessage(message) {
        if (!hasTTS || !shouldSpeak(message)) return;
        const text = cleanTextForSpeech(message.content);
        if (!text) return;
        stopSpeaking();
        enqueueSpeak(text, message.id);
    }

    let initialLoadDone = false;
    function markInitialLoadDone() { initialLoadDone = true; }
    function resetInitialLoad() { initialLoadDone = false; stopSpeaking(); }

    function speakNewMessages(messages) {
        if (!hasTTS || !ttsEnabled.value || !messages?.length) return;
        if (!initialLoadDone) return;
        if (typeof document !== 'undefined' && document.hidden) return;
        for (const msg of messages) {
            if (!shouldSpeak(msg)) continue;
            if (msg.id && msg.id === lastSpokenMessageId.value) continue;
            const text = cleanTextForSpeech(msg.content);
            if (!text) continue;
            enqueueSpeak(text, msg.id);
        }
    }

    function stopSpeaking(resumeMic = false) {
        speakQueue.length = 0;
        isProcessingQueue = false;
        isSpeaking.value = false;
        currentlySpeakingId.value = null;
        suppressWakeUntil = 0; // user stopped TTS — re-enable wake immediately
        if (hasTTS) window.speechSynthesis.cancel();
        // Reopen the mic immediately when the stop is user-initiated (not when we
        // stop just to start a new utterance — that path re-pauses right away).
        if (resumeMic) resumeRecognition();
    }

    function toggleTTS() {
        ttsEnabled.value = !ttsEnabled.value;
        localStorage.setItem('depthnet_tts_enabled', ttsEnabled.value ? 'true' : 'false');
        if (!ttsEnabled.value) stopSpeaking(true);
    }

    // ─── Cleanup ────────────────────────────────────────────────────────────────

    function cleanup() {
        stopSpeaking();
        stopAll();
        if (hasTTS) window.speechSynthesis.removeEventListener('voiceschanged', loadVoices);
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        }
    }
    onBeforeUnmount(cleanup);

    // ─── Public API ───────────────────────────────────────────────────────────
    return {
        hasTTS, hasSTT,

        // TTS
        ttsEnabled, isSpeaking, currentlySpeakingId, lastSpokenMessageId,
        speakMessage, speakNewMessages, markInitialLoadDone, resetInitialLoad,
        stopSpeaking, toggleTTS, cleanTextForSpeech, shouldSpeak,

        // STT
        isListening, isWakeWordListening, wakeWordDetected,
        interimText, recognizedText, sttError,
        startWakeWord, stopWakeWord, toggleMic, stopAll, onPhrase, setBusy,
    };
}