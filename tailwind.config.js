/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './includes/**/*.php',
    './api/**/*.php',
  ],
  safelist: [
    // Dynamic status / role / accent classes built in PHP
    {
      pattern:
        /^(bg|text|border|border-l|from|to|ring)-(zinc|slate|gray|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(50|100|200|300|400|500|600|700|800|900)$/,
    },
    {
      pattern: /^(bg|text|border)-(black|white)(\/\d+)?$/,
    },
    'hidden',
    'opacity-0',
    'pointer-events-none',
    'role-teacher',
    'role-admin',
    'role-student',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
