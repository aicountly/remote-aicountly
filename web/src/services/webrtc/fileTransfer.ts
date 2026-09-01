/**
 * Chunked file transfer over an RTCDataChannel (§36).
 *
 * The bytes go directly between the two browsers. Nothing is uploaded, so
 * there is no server-side storage, no retention question and no deletion
 * question for a file two people pass between themselves during a call.
 *
 * Three things make this harder than "send the file":
 *
 *   * **SCTP has a message size limit.** 16 KiB payloads are the value every
 *     browser handles; larger messages are silently dropped or close the
 *     channel outright on some implementations.
 *   * **A data channel has no backpressure of its own.** Writing faster than
 *     the network drains grows `bufferedAmount` without bound until the channel
 *     is torn down. {@link FileSender} waits on `bufferedamountlow` instead.
 *   * **The receiver must not trust the sender.** A peer can claim a 1 KB file
 *     and then send megabytes. The receiver enforces the declared size and
 *     aborts the moment it is exceeded, so a hostile peer cannot exhaust
 *     memory.
 */

/** Payload bytes per chunk. Safe across every browser's SCTP implementation. */
export const CHUNK_SIZE = 16 * 1024

/**
 * Each binary chunk carries an 8-byte header so the receiver can attribute it
 * without a separate control message per chunk:
 *
 *     bytes 0-3   uint32  slot   — which transfer, within this sender
 *     bytes 4-7   uint32  index  — which chunk
 *
 * `slot` is assigned by the sender and announced in the offer. It only has to
 * be unique per sender, because the receiver already knows which peer a message
 * came from.
 */
export const CHUNK_HEADER_BYTES = 8

/** Stop writing above this, and resume when the channel drains. */
export const BUFFER_HIGH_WATER = 512 * 1024

/** Resume once the channel has drained to here. */
export const BUFFER_LOW_WATER = 128 * 1024

export type TransferDirection = 'incoming' | 'outgoing'

export type TransferPhase =
  | 'offered'
  | 'accepted'
  | 'transferring'
  | 'completed'
  | 'declined'
  | 'cancelled'
  | 'failed'

export interface TransferView {
  uuid: string
  direction: TransferDirection
  fileName: string
  fileSize: number
  mimeType: string | null
  phase: TransferPhase
  bytesTransferred: number
  progress: number
  peerId: string
  peerName: string
  error: string | null
  /** Set on a completed incoming transfer: what the Save button downloads. */
  blob: Blob | null
}

export function encodeChunkHeader(slot: number, index: number, payload: ArrayBuffer): ArrayBuffer {
  const message = new ArrayBuffer(CHUNK_HEADER_BYTES + payload.byteLength)
  const view = new DataView(message)

  view.setUint32(0, slot, true)
  view.setUint32(4, index, true)
  new Uint8Array(message, CHUNK_HEADER_BYTES).set(new Uint8Array(payload))

  return message
}

export function decodeChunk(message: ArrayBuffer): { slot: number; index: number; payload: ArrayBuffer } | null {
  if (message.byteLength < CHUNK_HEADER_BYTES) return null

  const view = new DataView(message)

  return {
    slot: view.getUint32(0, true),
    index: view.getUint32(4, true),
    payload: message.slice(CHUNK_HEADER_BYTES),
  }
}

export function chunkCount(fileSize: number, chunkSize = CHUNK_SIZE): number {
  return Math.max(1, Math.ceil(fileSize / chunkSize))
}

/**
 * Reduce a peer-supplied name to something safe to use as a download filename.
 *
 * The server sanitises on the way in as well. Doing it again here is not
 * belt-and-braces: this is the hop where the string becomes a *path*, and the
 * string arrived over a data channel from another browser, which is not a
 * trusted source however it was stored in between.
 */
export function safeFileName(name: string): string {
  const cleaned = name
    .replace(/[/\\]/g, '_')
    // Control characters, written as explicit escapes. A literal range in a
    // character class is far too easy to get wrong — the obvious-looking
    // version of this silently strips spaces and punctuation instead.
    .replace(/[\u0000-\u001F\u007F]/g, '')
    .replace(/^\.+/, '')
    .trim()

  return cleaned === '' ? 'file' : cleaned.slice(0, 255)
}

/**
 * Reassembles one incoming file.
 *
 * Every guard here exists because the sender is another browser, and the only
 * thing this side actually knows is what it agreed to accept.
 */
export class FileReceiver {
  private readonly chunks: Uint8Array[] = []
  private received = 0
  private nextIndex = 0
  private done = false

  constructor(
    readonly transferUuid: string,
    readonly fileName: string,
    readonly fileSize: number,
    readonly totalChunks: number,
  ) {}

  get bytesReceived(): number {
    return this.received
  }

  get isComplete(): boolean {
    return this.done
  }

  /**
   * @returns `'ok'` while more is expected, `'complete'` on the last chunk, or
   *          an error code the caller turns into an aborted transfer.
   */
  accept(index: number, payload: ArrayBuffer): 'ok' | 'complete' | 'OUT_OF_ORDER' | 'TOO_LARGE' | 'ALREADY_DONE' {
    if (this.done) return 'ALREADY_DONE'

    // The channel is ordered and reliable, so a gap means something is wrong
    // rather than merely late.
    if (index !== this.nextIndex) return 'OUT_OF_ORDER'

    // The declared size is the contract. A peer that keeps sending past it is
    // trying to fill memory, and gets cut off at the first byte over.
    if (this.received + payload.byteLength > this.fileSize) return 'TOO_LARGE'

    this.chunks.push(new Uint8Array(payload))
    this.received += payload.byteLength
    this.nextIndex += 1

    if (this.received === this.fileSize) {
      this.done = true

      return 'complete'
    }

    return 'ok'
  }

  /**
   * The assembled file.
   *
   * Deliberately `application/octet-stream` rather than the sender's claimed
   * MIME type: an object URL the browser is willing to render inline is an
   * object URL that can run script in this origin. A downloaded file is what
   * was asked for, so it is downloaded as opaque bytes.
   */
  toBlob(): Blob {
    return new Blob(this.chunks as BlobPart[], { type: 'application/octet-stream' })
  }

  discard(): void {
    this.chunks.length = 0
    this.received = 0
    this.done = false
  }
}

export interface SenderTransport {
  /** Push one framed chunk. Returns false when the channel is not open. */
  send: (message: ArrayBuffer) => boolean
  /** Current outbound buffer depth, for backpressure. */
  bufferedAmount: () => number
  /** Resolves once the buffer has drained below the low-water mark. */
  waitForDrain: () => Promise<void>
}

/**
 * Reads a file and pushes it out in chunks, respecting backpressure.
 *
 * Cancellable at any point — `cancel()` stops the loop before the next slice is
 * read, so cancelling a 25 MB transfer does not first finish reading it.
 */
export class FileSender {
  private cancelled = false
  private sent = 0

  constructor(
    private readonly file: Blob,
    private readonly slot: number,
    private readonly transport: SenderTransport,
    private readonly chunkSize: number = CHUNK_SIZE,
  ) {}

  get bytesSent(): number {
    return this.sent
  }

  cancel(): void {
    this.cancelled = true
  }

  /**
   * @param onProgress called after each chunk with the running byte total
   * @throws Error with a machine code when the channel closes or is cancelled
   */
  async run(onProgress: (bytesSent: number) => void): Promise<void> {
    const total = chunkCount(this.file.size, this.chunkSize)

    for (let index = 0; index < total; index++) {
      if (this.cancelled) {
        throw new Error('CANCELLED')
      }

      // Wait before reading, not after: holding a slice in memory while the
      // channel drains is the one thing that makes a large transfer expensive.
      if (this.transport.bufferedAmount() > BUFFER_HIGH_WATER) {
        await this.transport.waitForDrain()

        if (this.cancelled) throw new Error('CANCELLED')
      }

      const start = index * this.chunkSize
      const slice = this.file.slice(start, Math.min(start + this.chunkSize, this.file.size))
      const payload = await slice.arrayBuffer()

      if (!this.transport.send(encodeChunkHeader(this.slot, index, payload))) {
        throw new Error('CHANNEL_CLOSED')
      }

      this.sent += payload.byteLength
      onProgress(this.sent)
    }
  }
}
