import { describe, expect, it, vi } from 'vitest'

import {
  CHUNK_HEADER_BYTES,
  FileReceiver,
  FileSender,
  chunkCount,
  decodeChunk,
  encodeChunkHeader,
  safeFileName,
} from './fileTransfer'
import type { SenderTransport } from './fileTransfer'

/**
 * The transfer layer, tested where it is actually load-bearing: the framing
 * both ends have to agree on, and the receiver guards that exist because the
 * sender is another browser and cannot be trusted (§36).
 */

function payload(bytes: number, fill = 7): ArrayBuffer {
  return new Uint8Array(bytes).fill(fill).buffer
}

describe('chunk framing', () => {
  it('round-trips the slot, index and payload', () => {
    const framed = encodeChunkHeader(3, 42, payload(16, 9))
    const decoded = decodeChunk(framed)

    expect(decoded).not.toBeNull()
    expect(decoded!.slot).toBe(3)
    expect(decoded!.index).toBe(42)
    expect(decoded!.payload.byteLength).toBe(16)
    expect(new Uint8Array(decoded!.payload)[0]).toBe(9)
  })

  it('survives the largest slot and index the header can hold', () => {
    const decoded = decodeChunk(encodeChunkHeader(0xffffffff, 0xffffffff, payload(1)))

    expect(decoded!.slot).toBe(0xffffffff)
    expect(decoded!.index).toBe(0xffffffff)
  })

  it('rejects a message too short to be a chunk', () => {
    expect(decodeChunk(new ArrayBuffer(CHUNK_HEADER_BYTES - 1))).toBeNull()
  })

  it('counts at least one chunk, even for an empty file', () => {
    expect(chunkCount(0)).toBe(1)
    expect(chunkCount(1, 16)).toBe(1)
    expect(chunkCount(16, 16)).toBe(1)
    expect(chunkCount(17, 16)).toBe(2)
  })
})

describe('safeFileName', () => {
  it('keeps an ordinary name, spaces and all', () => {
    expect(safeFileName('Trial balance 2026.pdf')).toBe('Trial balance 2026.pdf')
  })

  it('cannot carry a path', () => {
    // The property that matters is that no separator survives, so the result
    // is a name and never a location. What is left of `..` is inert without
    // one. This matches the server's sanitiser byte for byte.
    expect(safeFileName('../../etc/passwd')).toBe('_.._etc_passwd')
    expect(safeFileName('C:\\Windows\\system32\\config')).toBe('C:_Windows_system32_config')

    for (const hostile of ['../../etc/passwd', 'a/b\\c', '/absolute/path']) {
      expect(safeFileName(hostile)).not.toMatch(/[/\\]/)
    }
  })

  it('cannot be a dotfile', () => {
    expect(safeFileName('.bashrc')).toBe('bashrc')
  })

  it('strips control characters', () => {
    expect(safeFileName('report\u0000\u001f.csv')).toBe('report.csv')
  })

  it('never returns an empty name', () => {
    expect(safeFileName('...')).toBe('file')
    expect(safeFileName('   ')).toBe('file')
  })

  it('caps the length', () => {
    expect(safeFileName('a'.repeat(400))).toHaveLength(255)
  })
})

describe('FileReceiver', () => {
  it('assembles chunks in order and reports completion', () => {
    const receiver = new FileReceiver('t', 'notes.txt', 24, 2)

    expect(receiver.accept(0, payload(16))).toBe('ok')
    expect(receiver.bytesReceived).toBe(16)
    expect(receiver.accept(1, payload(8))).toBe('complete')
    expect(receiver.isComplete).toBe(true)
    expect(receiver.toBlob().size).toBe(24)
  })

  it('downloads as opaque bytes whatever the sender claimed', () => {
    const receiver = new FileReceiver('t', 'invoice.svg', 4, 1)
    receiver.accept(0, payload(4))

    // An object URL the browser will render inline can run script in this
    // origin, so a received file is never given back its claimed type.
    expect(receiver.toBlob().type).toBe('application/octet-stream')
  })

  it('refuses a gap: the channel is ordered, so a gap means something is wrong', () => {
    const receiver = new FileReceiver('t', 'notes.txt', 32, 2)

    expect(receiver.accept(0, payload(16))).toBe('ok')
    expect(receiver.accept(2, payload(16))).toBe('OUT_OF_ORDER')
  })

  it('cuts off a sender that exceeds the size it declared', () => {
    const receiver = new FileReceiver('t', 'small.bin', 16, 1)

    // The declared size is the contract; a peer that keeps sending past it is
    // filling memory, and gets nothing more accepted.
    expect(receiver.accept(0, payload(24))).toBe('TOO_LARGE')
    expect(receiver.bytesReceived).toBe(0)
  })

  it('accepts nothing after it is complete', () => {
    const receiver = new FileReceiver('t', 'notes.txt', 8, 1)

    expect(receiver.accept(0, payload(8))).toBe('complete')
    expect(receiver.accept(1, payload(8))).toBe('ALREADY_DONE')
  })

  it('releases its buffers when discarded', () => {
    const receiver = new FileReceiver('t', 'notes.txt', 16, 1)
    receiver.accept(0, payload(16))
    receiver.discard()

    expect(receiver.bytesReceived).toBe(0)
    expect(receiver.toBlob().size).toBe(0)
  })
})

/** A transport that records what it was given and reports a chosen buffer depth. */
function transport(bufferedAmount = 0): SenderTransport & { sent: ArrayBuffer[]; drains: number } {
  const sent: ArrayBuffer[] = []

  return {
    sent,
    drains: 0,
    send(message) {
      sent.push(message)

      return true
    },
    bufferedAmount: () => bufferedAmount,
    waitForDrain() {
      this.drains += 1

      return Promise.resolve()
    },
  }
}

describe('FileSender', () => {
  it('sends every chunk, framed with its slot', async () => {
    const link = transport()
    const file = new Blob([new Uint8Array(40)])
    const sender = new FileSender(file, 5, link, 16)

    const progress: number[] = []
    await sender.run((bytes) => progress.push(bytes))

    expect(link.sent).toHaveLength(3)
    expect(progress).toEqual([16, 32, 40])
    expect(sender.bytesSent).toBe(40)

    const first = decodeChunk(link.sent[0])!
    expect(first.slot).toBe(5)
    expect(first.index).toBe(0)

    const last = decodeChunk(link.sent[2])!
    expect(last.index).toBe(2)
    expect(last.payload.byteLength).toBe(8)
  })

  it('waits for the channel to drain before reading the next slice', async () => {
    // Above the high-water mark, so every pass waits: writing faster than the
    // network drains is what tears the channel down.
    const link = transport(4 * 1024 * 1024)
    const sender = new FileSender(new Blob([new Uint8Array(32)]), 1, link, 16)

    await sender.run(() => undefined)

    expect(link.drains).toBe(2)
  })

  it('stops on cancel without sending the rest', async () => {
    const link = transport()
    const sender = new FileSender(new Blob([new Uint8Array(64)]), 1, link, 16)

    const run = sender.run((bytes) => {
      if (bytes >= 16) sender.cancel()
    })

    await expect(run).rejects.toThrow('CANCELLED')
    expect(link.sent).toHaveLength(1)
  })

  it('fails loudly when the channel has closed', async () => {
    const link = transport()
    link.send = vi.fn(() => false)

    const sender = new FileSender(new Blob([new Uint8Array(16)]), 1, link, 16)

    await expect(sender.run(() => undefined)).rejects.toThrow('CHANNEL_CLOSED')
  })
})
