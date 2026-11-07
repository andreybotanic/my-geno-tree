import { createServer } from 'node:http'
import { WebSocketServer } from 'ws'
import { VoiceStream, type VoiceConfig } from './stream.js'

export function createGateway(config: VoiceConfig) {
  const sockets = new WebSocketServer({ noServer: true, maxPayload: 65536, perMessageDeflate: false })
  const server = createServer((_request, response) => { response.writeHead(200); response.end('ok') })
  server.on('upgrade', (request, socket, head) => {
    if (request.url !== '/api/v1/chat-transcriptions/stream'
      || !config.origins.includes(request.headers.origin ?? '')) {
      socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n')
      return
    }
    sockets.handleUpgrade(request, socket, head, (client) => { new VoiceStream(client, config) })
  })
  return { server, sockets }
}
