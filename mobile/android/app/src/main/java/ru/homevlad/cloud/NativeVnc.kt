package ru.homevlad.cloud

import android.graphics.Bitmap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okio.ByteString
import okio.ByteString.Companion.toByteString
import org.json.JSONObject
import java.io.IOException
import java.nio.ByteBuffer
import javax.crypto.Cipher
import javax.crypto.spec.SecretKeySpec
import kotlin.math.min

internal class NativeVnc(ticket: String, path: String) : AutoCloseable {
    private sealed interface Message {
        data class Setup(val text: String) : Message
        data class Binary(val bytes: ByteString) : Message
    }

    private val messages = Channel<Message>(16)
    private var incoming = ByteString.EMPTY
    private var offset = 0
    @Volatile private var dimensions = 0
    private var pixels = IntArray(0)
    private var needFullFrame = true

    private val socket: WebSocket

    init {
        require(path == "/console/socket" && Regex("[a-f0-9]{64}").matches(ticket))
        val request = Request.Builder().url("wss://${BuildConfig.HOST}$path")
            .header("Origin", "https://${BuildConfig.HOST}").build()
        socket = client.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                if (!webSocket.send(ticket)) messages.close(IOException("Не удалось отправить билет консоли"))
            }

            override fun onMessage(webSocket: WebSocket, text: String) = enqueue(webSocket, Message.Setup(text))

            override fun onMessage(webSocket: WebSocket, bytes: ByteString) {
                if (bytes.size > MAX_MESSAGE) {
                    messages.close(IOException("Слишком большой кадр VNC"))
                    webSocket.cancel()
                } else enqueue(webSocket, Message.Binary(bytes))
            }

            override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                messages.close(IOException("Сеанс VNC завершён (код $code)"))
                webSocket.close(1000, null)
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                messages.close(IOException("Не удалось подключить консоль", t))
            }
        })
    }

    private fun enqueue(webSocket: WebSocket, message: Message) {
        try {
            // The listener runs on OkHttp's reader thread. Wait for decoder capacity
            // instead of dropping fragments when a full framebuffer arrives.
            runBlocking { messages.send(message) }
        } catch (_: Exception) {
            webSocket.cancel()
        }
    }

    suspend fun frames(onFrame: suspend (Bitmap) -> Unit) = withContext(Dispatchers.Default) {
        withTimeout(15_000) {
            val setup = (messages.receive() as? Message.Setup)?.text ?: badProtocol()
            val grant = JSONObject(setup)
            if (grant.optString("type") != "qemu") {
                throw IOException("Для нативной консоли LXC настройте API-токен Proxmox на ноде")
            }
            handshake(grant.optString("password"))
            requestUpdate()
        }
        while (true) {
            when (read(1).u8(0)) {
                0 -> {
                    val count = read(3).u16(1)
                    if (applyRectangles(count)) {
                        val width = dimensions ushr 16
                        val height = dimensions and 0xffff
                        onFrame(Bitmap.createBitmap(pixels, width, height, Bitmap.Config.ARGB_8888))
                        needFullFrame = false
                    }
                    requestUpdate()
                }
                1 -> read(read(5).u16(3) * 6)
                2 -> Unit
                3 -> read(read(7).u32(3).also { if (it !in 0..MAX_MESSAGE) badProtocol() })
                else -> badProtocol()
            }
        }
    }

    fun press(key: Int, vararg modifiers: Int) {
        val data = ByteBuffer.allocate(16 * (modifiers.size + 1))
        modifiers.forEach { data.keyEvent(it, true) }
        data.keyEvent(key, true).keyEvent(key, false)
        for (index in modifiers.indices.reversed()) data.keyEvent(modifiers[index], false)
        send(data.array())
    }

    fun type(text: String, enter: Boolean) {
        val keys = text.codePoints().limit(1024).toArray()
        val data = ByteBuffer.allocate((keys.size + if (enter) 1 else 0) * 16)
        for (code in keys) {
            val key = when (code) {
                10, 13 -> ENTER
                in 32..126, in 160..255 -> code
                else -> 0x01000000 or code
            }
            data.keyEvent(key, true).keyEvent(key, false)
        }
        if (enter) data.keyEvent(ENTER, true).keyEvent(ENTER, false)
        if (data.position() > 0) send(data.array())
    }

    fun click(x: Float, y: Float, right: Boolean) {
        val size = dimensions
        if (size == 0) return
        val width = size ushr 16
        val height = size and 0xffff
        val touchX = (x * width).toInt().coerceIn(0, width - 1).toShort()
        val touchY = (y * height).toInt().coerceIn(0, height - 1).toShort()
        val data = ByteBuffer.allocate(12)
        data.put(5).put(if (right) 4 else 1).putShort(touchX).putShort(touchY)
        data.put(5).put(0).putShort(touchX).putShort(touchY)
        send(data.array())
    }

    override fun close() {
        messages.cancel()
        if (!socket.close(1000, null)) socket.cancel()
    }

    private suspend fun handshake(password: String) {
        val banner = read(12).decodeToString()
        val minor = Regex("RFB 003\\.([0-9]{3})\\n").matchEntire(banner)
            ?.groupValues?.get(1)?.toIntOrNull() ?: badProtocol()
        val version = when {
            minor >= 8 -> 8
            minor >= 7 -> 7
            minor >= 3 -> 3
            else -> badProtocol()
        }
        send("RFB 003.00$version\n".encodeToByteArray())
        val method = if (version == 3) read(4).u32(0) else {
            val count = read(1).u8(0)
            if (count == 0) badProtocol()
            val methods = read(count)
            val selected = when {
                password.isNotEmpty() && 2.toByte() in methods -> 2
                1.toByte() in methods -> 1
                else -> badProtocol()
            }
            send(byteArrayOf(selected.toByte()))
            selected
        }
        when (method) {
            1 -> Unit
            2 -> {
                if (password.isEmpty()) badProtocol()
                val sourceKey = password.encodeToByteArray().copyOf(8)
                val key = ByteArray(8) { (Integer.reverse(sourceKey[it].toInt() and 255) ushr 24).toByte() }
                val cipher = Cipher.getInstance("DES/ECB/NoPadding")
                try {
                    cipher.init(Cipher.ENCRYPT_MODE, SecretKeySpec(key, "DES"))
                    send(cipher.doFinal(read(16)))
                } finally {
                    sourceKey.fill(0)
                    key.fill(0)
                }
            }
            else -> badProtocol()
        }
        if ((method == 2 || version == 8) && read(4).u32(0) != 0) {
            throw IOException("Сервер отклонил билет VNC. Подключитесь снова")
        }
        send(byteArrayOf(1))
        val init = read(24)
        resize(init.u16(0), init.u16(2))
        read(init.u32(20).also { if (it !in 0..65536) badProtocol() })
        // 32-bit little-endian BGRX followed by Raw and DesktopSize encodings.
        val commands = ByteBuffer.allocate(32)
        commands.put(byteArrayOf(0, 0, 0, 0, 32, 24, 0, 1, 0, -1, 0, -1, 0, -1, 16, 8, 0, 0, 0, 0))
        commands.put(byteArrayOf(2, 0, 0, 2)).putInt(0).putInt(-223)
        send(commands.array())
    }

    private suspend fun applyRectangles(count: Int): Boolean {
        var changed = false
        repeat(count) {
            val header = read(12)
            val x = header.u16(0)
            val y = header.u16(2)
            val w = header.u16(4)
            val h = header.u16(6)
            when (header.u32(8)) {
                -223 -> {
                    resize(w, h)
                    changed = false
                }
                -224 -> return changed
                0 -> {
                    val width = dimensions ushr 16
                    val height = dimensions and 0xffff
                    if (w == 0 || h == 0 || x + w > width || y + h > height) badProtocol()
                    val rowSize = w * 4
                    repeat(h) { row ->
                        val raw = read(rowSize)
                        val start = (y + row) * width + x
                        for (column in 0 until w) {
                            val index = column * 4
                            pixels[start + column] = (0xff shl 24) or (raw.u8(index + 2) shl 16) or
                                (raw.u8(index + 1) shl 8) or raw.u8(index)
                        }
                    }
                    changed = true
                }
                else -> badProtocol()
            }
        }
        return changed
    }

    private fun resize(width: Int, height: Int) {
        if (width !in 1..4096 || height !in 1..4096 || width * height > 8_847_360) {
            throw IOException("Неподдерживаемый размер экрана гостя")
        }
        pixels = IntArray(width * height)
        dimensions = (width shl 16) or height
        needFullFrame = true
    }

    private fun requestUpdate() {
        val width = (dimensions ushr 16).toShort()
        val height = dimensions.toShort()
        send(ByteBuffer.allocate(10).put(3).put(if (needFullFrame) 0 else 1)
            .putInt(0).putShort(width).putShort(height).array())
    }

    private suspend fun read(count: Int): ByteArray {
        if (count !in 0..MAX_MESSAGE) badProtocol()
        val out = ByteArray(count)
        var filled = 0
        while (filled < count) {
            if (offset == incoming.size) {
                incoming = (messages.receive() as? Message.Binary)?.bytes ?: badProtocol()
                offset = 0
                if (incoming.size == 0) badProtocol()
            }
            val length = min(count - filled, incoming.size - offset)
            incoming.copyInto(offset, out, filled, length)
            offset += length
            filled += length
        }
        return out
    }

    private fun send(bytes: ByteArray) {
        if (!socket.send(bytes.toByteString())) throw IOException("Сеанс VNC закрыт")
    }

    companion object {
        const val ENTER = 0xff0d
        private const val MAX_MESSAGE = 4 * 1024 * 1024
        private val client = OkHttpClient.Builder().followRedirects(false).followSslRedirects(false).build()
    }
}

private fun ByteBuffer.keyEvent(key: Int, down: Boolean): ByteBuffer =
    put(4).put(if (down) 1 else 0).putShort(0).putInt(key)

private fun ByteArray.u8(at: Int): Int = this[at].toInt() and 255

private fun ByteArray.u16(at: Int): Int = (u8(at) shl 8) or u8(at + 1)

private fun ByteArray.u32(at: Int): Int = (u16(at) shl 16) or u16(at + 2)

private fun badProtocol(): Nothing = throw IOException("Сервер передал неподдерживаемые данные VNC")
