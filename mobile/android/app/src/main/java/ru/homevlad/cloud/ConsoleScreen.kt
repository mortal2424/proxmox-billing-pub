package ru.homevlad.cloud

import android.graphics.Bitmap
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.TimeoutCancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import kotlin.math.min

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ConsoleScreen(model: CloudModel, guest: JSONObject, onClose: () -> Unit) {
    var connection by remember { mutableStateOf<NativeVnc?>(null) }
    var frame by remember { mutableStateOf<Bitmap?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    var keyboard by remember { mutableStateOf(false) }
    var secret by remember { mutableStateOf(false) }
    var rightClick by remember { mutableStateOf(false) }
    var text by remember { mutableStateOf("") }
    val lifecycleOwner = LocalLifecycleOwner.current
    val closeAction by rememberUpdatedState(onClose)

    BackHandler(onBack = onClose)
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_STOP) closeAction()
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    LaunchedEffect(attempt) {
        frame = null
        error = null
        var current: NativeVnc? = null
        try {
            val grant = model.request("/api/mobile_console.php", JSONObject().put("id", guest.getInt("id")))
            current = NativeVnc(grant.getString("ticket"), grant.getString("socketPath"))
            connection = current
            current.frames { bitmap -> withContext(Dispatchers.Main) { frame = bitmap } }
        } catch (exception: TimeoutCancellationException) {
            error = "Сервер не ответил вовремя"
        } catch (exception: CancellationException) {
            throw exception
        } catch (exception: Exception) {
            error = exception.localizedMessage ?: "Не удалось открыть консоль"
        } finally {
            current?.close()
            connection = null
        }
    }

    fun send(action: (NativeVnc) -> Unit) {
        val active = connection ?: return
        try { action(active) }
        catch (failure: Exception) {
            error = failure.localizedMessage ?: "Сеанс VNC закрыт"
            active.close()
        }
    }

    fun submit(enter: Boolean) {
        send { it.type(text, enter) }
        text = ""
    }

    Scaffold(
        modifier = Modifier.imePadding(),
        topBar = { TopAppBar(title = { Text(guest.getString("name"), maxLines = 1) },
            navigationIcon = { TextButton(onClick = onClose) { Text("Закрыть") } }) }
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            BoxWithConstraints(Modifier.weight(1f).fillMaxWidth().background(Color.Black), contentAlignment = Alignment.Center) {
                val bitmap = frame
                if (bitmap != null) {
                    val scale = min(maxWidth.value / bitmap.width, maxHeight.value / bitmap.height)
                    Image(remember(bitmap) { bitmap.asImageBitmap() }, "Экран гостя",
                        Modifier.size((bitmap.width * scale).dp, (bitmap.height * scale).dp)
                            .pointerInput(Unit) {
                                detectTapGestures(onLongPress = { point ->
                                    send { it.click(point.x / size.width, point.y / size.height, true) }
                                }, onTap = { point ->
                                    val right = rightClick
                                    rightClick = false
                                    send { it.click(point.x / size.width, point.y / size.height, right) }
                                })
                            },
                        contentScale = ContentScale.FillBounds)
                }
                val message = error
                if (message != null) {
                    Column(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.86f)).padding(20.dp),
                        verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(message, color = Color.White)
                        Button(onClick = { attempt++ }) { Text("Подключиться снова") }
                    }
                } else if (bitmap == null) {
                    CircularProgressIndicator()
                }
            }
            if (frame != null && error == null) {
                Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 8.dp, vertical = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FilterChip(selected = keyboard, onClick = { keyboard = !keyboard }, label = { Text("Клавиатура") })
                    FilterChip(selected = rightClick, onClick = { rightClick = !rightClick }, label = { Text("Правый клик") })
                    val keys = listOf("Enter" to NativeVnc.ENTER, "Tab" to 0xff09, "Esc" to 0xff1b,
                        "⌫" to 0xff08, "←" to 0xff51, "↑" to 0xff52, "→" to 0xff53, "↓" to 0xff54)
                    keys.forEach { (name, code) ->
                        FilledTonalButton(onClick = { send { it.press(code) } }) { Text(name) }
                    }
                    FilledTonalButton(onClick = { send { it.press(0x63, 0xffe3) } }) { Text("Ctrl+C") }
                    if (guest.optString("type") == "qemu") {
                        FilledTonalButton(onClick = { send { it.press(0xffff, 0xffe3, 0xffe9) } }) {
                            Text("Ctrl+Alt+Del")
                        }
                    }
                }
                if (keyboard) {
                    Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically) {
                        OutlinedTextField(value = text, onValueChange = { text = it.take(1024) },
                            modifier = Modifier.weight(1f), label = { Text(if (secret) "Пароль гостя" else "Текст для гостя") },
                            singleLine = true,
                            visualTransformation = if (secret) PasswordVisualTransformation() else VisualTransformation.None,
                            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Go, autoCorrectEnabled = false),
                            keyboardActions = KeyboardActions(onGo = { submit(true) }))
                        TextButton(onClick = { submit(false) }, enabled = text.isNotEmpty()) { Text("Ввести") }
                    }
                    Row(Modifier.padding(horizontal = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Switch(checked = secret, onCheckedChange = { secret = it })
                        Text("  Скрыть ввод")
                    }
                }
            }
        }
    }
}
