/** Storefront Tailwind build (docs/storefront.md §2). Separate from the admin config so the two
 *  palettes never clash. Output: public/assets/css/store.css (built by bin/build-css.sh). */
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/store/**/*.php',
    './resources/views/errors/*.php',
    './app/Services/Store/**/*.php',
    './app/Controllers/Store/**/*.php',
    './public/assets/js/store.js',
    './public/assets/js/app-bridge.js',
    './public/assets/js/pay-window.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        display: ['Fraunces', 'Georgia', 'serif'],
        sans: ['Manrope', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
      colors: {
        cream: { DEFAULT: '#F6F2EA', 50: '#FBF9F5', 100: '#F6F2EA', 200: '#EDE6D9', 300: '#E0D5C2' },
        ink: { DEFAULT: '#15211F', 700: '#22302D', 500: '#55615E', 400: '#7A8481', 300: '#A7AEAB' },
        teal: { DEFAULT: '#1E6E74', 50: '#E7F1F1', 100: '#CFE3E4', 600: '#185B60', 800: '#0F3A3D' },
        copper: { DEFAULT: '#B4643C', 50: '#F7ECE5', 600: '#9A522F' },
        gold: '#B8925A',
      },
      boxShadow: { card: '0 1px 2px rgba(21,33,31,.05), 0 8px 24px -12px rgba(21,33,31,.18)' },
    },
  },
  plugins: [],
};
