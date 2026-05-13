/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './src/Templates/**/*.twig',
        './public/**/*.php'
    ],
    theme: {
        extend: {
            colors: {
                sage: {
                    DEFAULT: '#7B9468',
                    50:  '#F5F8F2',
                    100: '#EBF1E5',
                    200: '#D5DDC9',
                    300: '#BAC9A7',
                    400: '#9AAF7F',
                    500: '#7B9468',
                    600: '#647A54',
                    700: '#4F6043',
                    800: '#3D4A35',
                    900: '#2F3829'
                },
                card: {
                    border: '#E5E5E0',
                    tint: '#FAFAF9'
                }
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif']
            },
            borderRadius: {
                xl: '12px'
            }
        }
    },
    plugins: [require('daisyui')],
    daisyui: {
        themes: [{
            afk: {
                'primary':       '#7B9468',
                'primary-content': '#FFFFFF',
                'secondary':     '#000000',
                'secondary-content': '#FFFFFF',
                'accent':        '#7B9468',
                'neutral':       '#1A1A1A',
                'base-100':      '#FFFFFF',
                'base-200':      '#FAFAF9',
                'base-300':      '#E5E5E0',
                'info':          '#3B82F6',
                'success':       '#7B9468',
                'warning':       '#F59E0B',
                'error':         '#EF4444',
                '--rounded-box': '0.5rem',
                '--rounded-btn': '0.5rem',
                '--rounded-badge': '9999px',
                '--btn-text-case': 'none',
                '--border-btn':  '1.5px'
            }
        }]
    }
};
