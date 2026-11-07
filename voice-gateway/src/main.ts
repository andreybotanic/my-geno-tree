import { createGateway } from './server.js'

const gateway = createGateway({
  authUrl: process.env.VOICE_AUTH_URL ?? 'http://proxy:8082/api/v1/chat-transcription-sessions',
  speachesUrl: process.env.SPEACHES_URL ?? 'ws://whisper:8000/v1/realtime',
  model: process.env.WHISPER_MODEL ?? 'Systran/faster-whisper-small',
  origins: (process.env.VOICE_ORIGINS ?? 'https://localhost,https://my-geno-tree.localhost').split(','),
})
gateway.server.listen(Number(process.env.VOICE_PORT ?? 8080), '0.0.0.0')
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    for (const socket of gateway.sockets.clients) socket.close()
    gateway.server.close(() => process.exit(0))
  })
}
