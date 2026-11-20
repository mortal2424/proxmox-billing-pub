package ru.homevlad.cloud

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.text.NumberFormat
import java.util.Locale

private const val origin = "https://" + BuildConfig.HOST

class CloudModel : ViewModel() {
    private var generation = 0
    var snapshot by mutableStateOf<JSONObject?>(null)
        private set
    var error by mutableStateOf<String?>(null)
        private set
    var loading by mutableStateOf(false)
        private set

    fun refresh() {
        if (loading) return
        val requestGeneration = generation
        loading = true
        val cookies = CookieManager.getInstance().getCookie(origin).orEmpty()
        viewModelScope.launch {
            try {
                val result = withContext(Dispatchers.IO) {
                    val connection = URL("$origin/api/mobile_dashboard.php").openConnection() as HttpURLConnection
                    try {
                        connection.instanceFollowRedirects = false
                        connection.connectTimeout = 15000
                        connection.readTimeout = 20000
                        connection.setRequestProperty("Cookie", cookies)
                        connection.setRequestProperty("Accept", "application/json")
                        when (connection.responseCode) {
                            401 -> null
                            200 -> connection.inputStream.bufferedReader().use { JSONObject(it.readText()) }
                            else -> throw IllegalStateException("Не удалось загрузить кабинет")
                        }
                    } finally {
                        connection.disconnect()
                    }
                }
                if (requestGeneration != generation) return@launch
                snapshot = result
                error = if (result == null) "Войдите в кабинет" else null
            } catch (exception: Exception) {
                if (requestGeneration != generation) return@launch
                error = "Не удалось загрузить данные. Проверьте соединение."
            } finally {
                if (requestGeneration == generation) loading = false
            }
        }
    }

    fun logout() {
        generation++
        loading = true
        val cookies = CookieManager.getInstance().getCookie(origin).orEmpty()
        viewModelScope.launch(Dispatchers.IO) {
            val connection = URL("$origin/login/logout.php").openConnection() as HttpURLConnection
            try {
                connection.instanceFollowRedirects = false
                connection.connectTimeout = 10000
                connection.readTimeout = 10000
                connection.setRequestProperty("Cookie", cookies)
                connection.inputStream.close()
            } catch (_: Exception) {
                // Local session data is cleared even when the server is unreachable.
            } finally {
                connection.disconnect()
            }
        }
        CookieManager.getInstance().removeAllCookies { loading = false }
        CookieManager.getInstance().flush()
        snapshot = null
        error = null
    }
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { MaterialTheme(colorScheme = lightColorScheme(primary = androidx.compose.ui.graphics.Color(0xFF287963))) { Cabinet() } }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Cabinet(model: CloudModel = viewModel()) {
    var page by remember { mutableStateOf<String?>(null) }
    var pageTitle by remember { mutableStateOf("Кабинет") }
    val open: (String, String) -> Unit = { title, path -> pageTitle = title; page = path }
    LaunchedEffect(Unit) { model.refresh() }
    BackHandler(enabled = page != null) { page = null; model.refresh() }
    Scaffold(topBar = {
        TopAppBar(title = { Text(if (page == null) "HomeVlad" else pageTitle) }, actions = {
            if (page != null) TextButton(onClick = { page = null; model.refresh() }) { Text("Кабинет") }
            else TextButton(onClick = { model.refresh() }) { Text("Обновить") }
        })
    }) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            if (model.loading) LinearProgressIndicator(Modifier.fillMaxWidth())
            if (page != null) {
                WebCabinet(page!!, model, onSignedIn = { page = null })
            } else {
                model.error?.let { Text(it, Modifier.padding(16.dp)) }
                val snapshot = model.snapshot
                if (snapshot == null) {
                    Spacer(Modifier.height(40.dp))
                    Text("HomeVlad Cloud", style = MaterialTheme.typography.headlineLarge, modifier = Modifier.padding(16.dp))
                    Button(enabled = !model.loading, onClick = { open("Вход", "/login/login.php") }, modifier = Modifier.padding(16.dp)) { Text("Войти в кабинет") }
                } else {
                    val money = NumberFormat.getCurrencyInstance(Locale.forLanguageTag("ru-RU"))
                    val guests = snapshot.getJSONArray("guests")
                    val nodes = snapshot.getJSONArray("nodes")
                    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        item {
                            Text(snapshot.getString("name"), style = MaterialTheme.typography.headlineSmall)
                            Text("Баланс " + money.format(snapshot.getDouble("balance")))
                            Text("Бонусы " + money.format(snapshot.getDouble("bonusBalance")))
                        }
                        item { Text("Машины", style = MaterialTheme.typography.titleLarge) }
                        if (guests.length() == 0) item { Text("Серверов пока нет") }
                        items(guests.length()) { index ->
                            val guest = guests.getJSONObject(index)
                            Card(Modifier.fillMaxWidth().clickable {
                                val type = if (guest.getString("type") == "lxc") "lxc" else "qemu"
                                open(guest.getString("name"), "/templates/my_vms.php?guest=${guest.getInt("vmId")}&type=$type")
                            }) {
                                Column(Modifier.padding(16.dp)) {
                                    Text(guest.getString("name"), style = MaterialTheme.typography.titleMedium)
                                    Text(status(guest.getString("status")))
                                    Text("${guest.getInt("cpu")} vCPU · ${guest.getInt("ramMiB") / 1024} ГБ RAM · ${guest.getInt("diskGiB")} ГБ")
                                }
                            }
                        }
                        item { Text("Инфраструктура", style = MaterialTheme.typography.titleLarge) }
                        if (nodes.length() == 0) item { Text("Доступных узлов пока нет") }
                        items(nodes.length()) { index ->
                            val node = nodes.getJSONObject(index)
                            Card(Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(16.dp)) {
                                    Text(node.getString("zone") + " / " + node.getString("name"))
                                    Text(status(node.getString("status")))
                                    Text(if (node.isNull("cpu") || node.isNull("memory")) "Нет свежих замеров"
                                        else "CPU ${node.getDouble("cpu")}% · RAM ${node.getDouble("memory")}%")
                                }
                            }
                        }
                        item {
                            listOf("Заказать сервер" to "/templates/order_vm.php", "Финансы" to "/templates/billing.php",
                                "Поддержка" to "/templates/support.php", "Профиль" to "/templates/settings.php").forEach { (title, path) ->
                                TextButton(onClick = { open(title, path) }) { Text(title) }
                            }
                        }
                        if (snapshot.getBoolean("isAdmin")) item {
                            Text("Администрирование", style = MaterialTheme.typography.titleLarge)
                            listOf("Панель администратора" to "/admin/", "Платежи" to "/admin/payments.php",
                                "Инфраструктура" to "/admin/nodes.php", "Обновления" to "/admin/update.php").forEach { (title, path) ->
                                TextButton(onClick = { open(title, path) }) { Text(title) }
                            }
                        }
                        item { TextButton(onClick = { model.logout() }) { Text("Выйти") } }
                    }
                }
            }
        }
    }
}

@SuppressLint("SetJavaScriptEnabled")
@Composable
private fun WebCabinet(path: String, model: CloudModel, onSignedIn: () -> Unit) {
    val signedIn by rememberUpdatedState(onSignedIn)
    AndroidView(modifier = Modifier.fillMaxSize(), factory = { context ->
        WebView(context).apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.allowFileAccess = false
            settings.allowContentAccess = false
            settings.setSupportMultipleWindows(false)
            CookieManager.getInstance().setAcceptCookie(true)
            CookieManager.getInstance().setAcceptThirdPartyCookies(this, false)
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                    val url = request.url
                    if (url.scheme == "https" && url.host == BuildConfig.HOST && (url.port == -1 || url.port == 443)) return false
                    if (request.isForMainFrame && request.hasGesture() && url.scheme in listOf("https", "mailto", "tg")) {
                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url)) }
                    }
                    return true
                }
                override fun onPageFinished(view: WebView, url: String) {
                    if (path == "/login/login.php" && Uri.parse(url).path.orEmpty().startsWith("/templates/")) {
                        model.refresh()
                        signedIn()
                    }
                }
            }
            loadUrl(origin + path)
        }
    }, onRelease = { it.stopLoading(); it.destroy() })
}

private fun status(value: String): String = when (value) {
    "running", "online" -> "Работает"
    "offline" -> "Нет ответа"
    "stopped" -> "Остановлен"
    "suspended" -> "Приостановлен"
    "creating" -> "Создаётся"
    else -> "Неизвестно"
}
