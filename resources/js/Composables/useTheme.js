import { ref, onMounted } from 'vue';

/**
 * Composable for theme management
 * @returns {Object} Theme state and methods
 */
export function useTheme() {
  const isDark = ref(false);

  /**
   * Toggle between light and dark theme
   */
  const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem('chat-theme', isDark.value ? 'dark' : 'light');

    // Update document class for global theme
    if (isDark.value) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  };

  /**
   * Initialize theme from localStorage or system preference
   */
  const initializeTheme = () => {
    const savedTheme = localStorage.getItem('chat-theme');
    if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      isDark.value = true;
      document.documentElement.classList.add('dark');
    }
  };

  onMounted(() => {
    initializeTheme();
  });

  return {
    isDark,
    toggleTheme,
    initializeTheme
  };
}
