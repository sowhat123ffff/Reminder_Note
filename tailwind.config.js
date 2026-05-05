/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './public/**/*.html',
    './public/js/**/*.js',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          50:  '#f4f1ff',
          100: '#ebe5ff',
          200: '#d9ceff',
          300: '#bca6ff',
          400: '#9b76ff',
          500: '#7c4dff',
          600: '#6a36f0',
          700: '#5a26d4',
          800: '#4a20ac',
          900: '#3d1d8a',
          950: '#241055',
        },
        accent: {
          50:  '#fff3ec',
          100: '#ffe0cd',
          200: '#ffbf99',
          300: '#ff9760',
          400: '#ff6f33',
          500: '#ff4d14',
          600: '#f0340b',
          700: '#c7250b',
          800: '#9e2010',
          900: '#7f1c11',
        },
        ink: {
          50:  '#fafaf9',
          100: '#f4f4f3',
          200: '#e7e6e3',
          300: '#d3d1cc',
          400: '#a09d96',
          500: '#76736c',
          600: '#55534d',
          700: '#3d3b36',
          800: '#26241f',
          900: '#15140f',
          950: '#0a0a0b',
        },
      },
      fontFamily: {
        sans: ['Inter', 'PingFang SC', 'HarmonyOS Sans SC', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'Menlo', 'Consolas', 'monospace'],
      },
      borderRadius: {
        '2xl': '1rem',
        '3xl': '1.5rem',
      },
      boxShadow: {
        soft: '0 4px 24px -8px rgba(15, 13, 30, 0.12)',
        glow: '0 0 0 4px rgba(124, 77, 255, 0.18)',
      },
      keyframes: {
        'fade-in': {
          '0%': { opacity: 0, transform: 'translateY(4px)' },
          '100%': { opacity: 1, transform: 'translateY(0)' },
        },
        'pulse-soft': {
          '0%, 100%': { opacity: 1 },
          '50%': { opacity: 0.6 },
        },
        'slide-up': {
          '0%': { opacity: 0, transform: 'translateY(12px)' },
          '100%': { opacity: 1, transform: 'translateY(0)' },
        },
      },
      animation: {
        'fade-in': 'fade-in 0.2s ease-out',
        'pulse-soft': 'pulse-soft 2s ease-in-out infinite',
        'slide-up': 'slide-up 0.25s ease-out',
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
  ],
};
