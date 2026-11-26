import { onMounted, onBeforeUnmount } from 'vue';

/**
 * Keeps the chat layout inside the visible area when the on-screen keyboard opens.
 *
 * The root container is `position: fixed; inset: 0` (see .chat-app-root below),
 * so normally it simply follows the layout viewport:
 *   - Android Chrome + <meta name="viewport" ... interactive-widget=resizes-content>:
 *     the layout viewport shrinks with the keyboard -> nothing to compute.
 *
 * Only when the keyboard OVERLAYS the page instead of resizing it (iOS Safari,
 * or the meta tag is missing) visualViewport becomes smaller than the layout
 * viewport — then we set --app-height explicitly.
 *
 * Previously --app-height was always set from visualViewport.height; on Android
 * with the bottom address bar that value could be smaller than the real area,
 * leaving a white strip above the keyboard.
 */
export function useAppViewport() {
    let raf = 0;
    const root = document.documentElement;

    function apply() {
        raf = 0;
        const vv = window.visualViewport;
        const overlay = vv && (window.innerHeight - vv.height) > 1;

        if (overlay) {
            root.style.setProperty('--app-height', `${Math.round(vv.height)}px`);
            // iOS scrolls the document to reveal the focused field — undo it,
            // the layout already fits into the visible area.
            if (window.scrollY !== 0) window.scrollTo(0, 0);
        } else {
            root.style.removeProperty('--app-height');
        }
    }

    function schedule() {
        if (!raf) raf = requestAnimationFrame(apply);
    }

    onMounted(() => {
        root.classList.add('chat-viewport-locked');
        apply();
        const vv = window.visualViewport;
        if (vv) {
            vv.addEventListener('resize', schedule);
            vv.addEventListener('scroll', schedule);
        }
        window.addEventListener('resize', schedule);
        window.addEventListener('orientationchange', schedule);
    });

    onBeforeUnmount(() => {
        if (raf) cancelAnimationFrame(raf);
        const vv = window.visualViewport;
        if (vv) {
            vv.removeEventListener('resize', schedule);
            vv.removeEventListener('scroll', schedule);
        }
        window.removeEventListener('resize', schedule);
        window.removeEventListener('orientationchange', schedule);
        root.classList.remove('chat-viewport-locked');
        root.style.removeProperty('--app-height');
    });
}
