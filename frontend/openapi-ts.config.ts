import { defineConfig } from '@hey-api/openapi-ts'

export default defineConfig({
  input: '../openapi/openapi.yaml',
  output: {
    path: 'src/api/generated',
    clean: true,
  },
  plugins: ['@hey-api/typescript'],
})
