import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
  // Ignore build output and dependencies
  { ignores: ['js/', 'dist/', 'node_modules/', 'vendor/', 'tests/', 'l10n/'] },

  // Base JS recommended rules
  js.configs.recommended,

  // Vue 3 recommended rules
  ...pluginVue.configs['flat/recommended'],

  // Project-specific settings
  {
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        ...globals.browser,
        OC: 'readonly',
        OCA: 'readonly',
        OCP: 'readonly',
        t: 'readonly',
        n: 'readonly',
      },
    },
    rules: {
      // JS: unused vars erlaubt mit _ prefix
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      'no-empty': ['error', { allowEmptyCatch: true }],

      // Vue: style rules off — existing code style is consistent
      'vue/multi-word-component-names': 'off',
      'vue/no-v-html': 'off',
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-closing-bracket-spacing': 'off',
      'vue/attributes-order': 'off',
      'vue/no-multi-spaces': 'off',
      'vue/no-template-shadow': 'off',
      'vue/html-indent': 'off',
      'vue/require-toggle-inside-transition': 'off',
      'vue/html-self-closing': ['error', {
        html: { void: 'always', normal: 'any', component: 'always' },
        svg: 'always',
        math: 'always',
      }],
    },
  },
]
