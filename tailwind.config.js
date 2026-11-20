/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/**/*.php',
    './app/**/*.php',
    './html/**/*.html',
  ],
  theme: {
    extend: {
      colors: {
        sand: '#eee6d6',
        cream: '#f2ead9',
        ink: '#292821',
        gold: '#efb323',
        muted: '#8b806d',
        line: '#eadcc4',
        mint: '#dff2e5',
        green: '#26966a',
      },
      fontFamily: {
        sans: ['Manrope', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
      boxShadow: {
        soft: '0 8px 28px rgba(77,62,34,.07)',
      },
    },
  },
  plugins: [],
};
