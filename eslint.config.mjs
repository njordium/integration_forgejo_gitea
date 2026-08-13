import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		ignores: [
			'js/**',
			'node_modules/**',
			'vendor/**',
		],
	},
	{
		// Project style: guard clauses like `if (!x) { return null }` on a
		// single line are more readable than a 3-line expansion. And we're
		// not using JSDoc as a type system — no TypeScript in the tree — so
		// the "@param must have {type}" rules add noise without value.
		rules: {
			'@stylistic/max-statements-per-line': ['error', { max: 2 }],
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-param-description': 'off',
		},
	},
]
