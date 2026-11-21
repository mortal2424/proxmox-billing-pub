package ru.homevlad.cloud

import android.os.Bundle
import androidx.fragment.app.FragmentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.CookieManager
import java.net.CookiePolicy
import java.net.HttpURLConnection
import java.net.URL
import java.text.NumberFormat
import java.util.Locale

private const val origin = "https://" + BuildConfig.HOST
private val mint = Color(0xFF176B55)
private val mintBackground = Color(0xFFDCF3E9)
private val mintGradient = Brush.verticalGradient(listOf(mintBackground, Color(0xFFF7FBF9)))
private const val orderApi = "/api/mobile_order.php"
private const val authApi = "/api/mobile_auth.php"
private class CloudHttpException(val status: Int, message: String) : IllegalStateException(message)
private const val api = "/api/mobile_resources.php"
private fun money(amount: Double) = NumberFormat.getCurrencyInstance(Locale.forLanguageTag("ru-RU")).format(amount)
private fun objects(array: JSONArray): List<JSONObject> = (0 until array.length()).map { array.getJSONObject(it) }

class CloudModel : ViewModel() {
    private val cookies = CookieManager(null, CookiePolicy.ACCEPT_ORIGINAL_SERVER)
    private var csrf = ""
    private var generation = 0
    var snapshot by mutableStateOf<JSONObject?>(null)
        private set
    var challenge by mutableStateOf<JSONObject?>(null)
        private set
    var error by mutableStateOf<String?>(null)
        private set
    var biometricEnabled by mutableStateOf(false)
        private set
    var canEnrollBiometrics by mutableStateOf(false)
        private set
    var loading by mutableStateOf(false)
        private set

    suspend fun request(path: String, payload: JSONObject? = null): JSONObject {
        val url = URL(origin + path)
        require(url.protocol == "https" && url.host == BuildConfig.HOST && (url.port == -1 || url.port == 443))
        val body = payload?.put("csrf", csrf)?.toString()
        val (status, result) = withContext(Dispatchers.IO) {
            val connection = url.openConnection() as HttpURLConnection
            try {
                connection.instanceFollowRedirects = false
                connection.connectTimeout = 15000
                connection.readTimeout = if (path == orderApi && body != null) 680000 else 20000
                connection.setRequestProperty("Accept", "application/json")
                synchronized(cookies) {
                    cookies.get(url.toURI(), emptyMap()).forEach { (key, values) ->
                        connection.setRequestProperty(key, values.joinToString("; "))
                    }
                }
                if (body != null) {
                    connection.requestMethod = "POST"
                    connection.doOutput = true
                    connection.setRequestProperty("Content-Type", "application/json")
                    connection.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
                }
                val status = connection.responseCode
                synchronized(cookies) {
                    val headers = mutableMapOf<String, List<String>>()
                    connection.headerFields.forEach { (key, values) -> if (key != null) headers[key] = values }
                    cookies.put(url.toURI(), headers)
                }
                val stream = if (status in 200..299) connection.inputStream else connection.errorStream
                val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
                if (text.length > 8388608) throw IllegalStateException("Ответ сервера слишком большой")
                val result = runCatching { JSONObject(text) }.getOrElse { JSONObject().put("error", "Сервис временно недоступен") }
                status to result
            } finally {
                connection.disconnect()
            }
        }
        if (result.has("csrf")) csrf = result.getString("csrf")
        if (result.has("captcha")) challenge = result
        if (status != 200) {
            if (status == 401 && !path.contains("mobile_auth.php") && snapshot != null) {
                snapshot = null
                prepareLogin()
            }
            throw CloudHttpException(status, result.optString("error", "Не удалось выполнить запрос"))
        }
        return result
    }

    private suspend fun loadChallenge() {
        challenge = request("/api/mobile_auth.php")
        error = null
    }

    fun prepareLogin() {
        viewModelScope.launch {
            loading = true
            try { loadChallenge() }
            catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                error = exception.message
            } finally { loading = false }
        }
    }

    internal fun start(vault: DeviceTokenVault) {
        biometricEnabled = vault.hasToken
        if (loading || snapshot != null) return
        viewModelScope.launch {
            loading = true
            try { snapshot = request("/api/mobile_dashboard.php") }
            catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                runCatching { loadChallenge() }.onFailure { error = "Не удалось подключиться к серверу" }
            } finally { loading = false }
        }
    }

    internal fun login(email: String, password: String, answer: String, vault: DeviceTokenVault) {
        if (loading) return
        viewModelScope.launch {
            loading = true
            try {
                request("/api/mobile_auth.php", JSONObject().put("email", email).put("password", password).put("captcha", answer))
                if (biometricEnabled) {
                    vault.forget()
                    biometricEnabled = false
                }
                snapshot = request("/api/mobile_dashboard.php")
                canEnrollBiometrics = true
                challenge = null
                error = null
            } catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                error = exception.message
            } finally { loading = false }
        }
    }

    internal fun enroll(vault: DeviceTokenVault) {
        if (loading || !canEnrollBiometrics || !vault.available) return
        loading = true
        viewModelScope.launch {
            try {
                val result = request(authApi, JSONObject().put("action", "device_enable"))
                try { vault.save(result.getString("token")) }
                catch (exception: Exception) {
                    runCatching { request(authApi, JSONObject().put("action", "device_disable")) }
                    throw exception
                }
                biometricEnabled = true
                error = null
            } catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                error = exception.message
            } finally { loading = false }
        }
    }

    internal fun loginBiometrically(vault: DeviceTokenVault) {
        if (loading || !biometricEnabled || challenge == null) return
        loading = true
        viewModelScope.launch {
            try {
                val token = vault.read()
                request(authApi, JSONObject().put("action", "device_login").put("token", token))
                snapshot = request("/api/mobile_dashboard.php")
                canEnrollBiometrics = false
                challenge = null
                error = null
            } catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                if (exception is CloudHttpException && exception.status == 401 ||
                    exception is android.security.keystore.KeyPermanentlyInvalidatedException) {
                    runCatching { vault.forget() }
                    biometricEnabled = false
                }
                error = exception.message ?: "Войдите с паролем и заново включите биометрию"
            } finally { loading = false }
        }
    }

    internal fun disable(vault: DeviceTokenVault) {
        if (loading) return
        loading = true
        viewModelScope.launch {
            try {
                request(authApi, JSONObject().put("action", "device_disable"))
                vault.forget()
                biometricEnabled = false
                error = null
            } catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                error = exception.message
            } finally { loading = false }
        }
    }

    fun refresh() {
        if (loading) return
        val requestGeneration = generation
        viewModelScope.launch {
            loading = true
            try {
                val result = request("/api/mobile_dashboard.php")
                if (requestGeneration == generation) { snapshot = result; error = null }
            } catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                if (requestGeneration == generation) error = exception.message
            } finally { if (requestGeneration == generation) loading = false }
        }
    }

    internal fun logout(vault: DeviceTokenVault) {
        if (loading) return
        generation++
        viewModelScope.launch {
            loading = true
            runCatching { request("/api/mobile_auth.php", JSONObject().put("action", "logout")) }
            runCatching { vault.forget() }
            biometricEnabled = false
            canEnrollBiometrics = false
            synchronized(cookies) { cookies.cookieStore.removeAll() }
            snapshot = null
            challenge = null
            error = null
            try { loadChallenge() } catch (_: Exception) { error = "Не удалось подключиться к серверу" }
            loading = false
        }
    }
}

class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val vault = DeviceTokenVault(this)
        setContent {
            MaterialTheme(colorScheme = lightColorScheme(primary = mint, background = mintBackground, surface = Color(0xFFF7FCF9), secondaryContainer = Color(0xFFC4EBDC))) {
                Cabinet(vault)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Cabinet(vault: DeviceTokenVault, model: CloudModel = viewModel()) {
    var page by remember { mutableStateOf("overview") }
    var selectedGuest by remember { mutableStateOf<JSONObject?>(null) }
    var selectedTicket by remember { mutableStateOf(0) }
    var adminContext by remember { mutableStateOf(false) }
    var revision by remember { mutableIntStateOf(0) }
    LaunchedEffect(Unit) { model.start(vault) }
    val isAdmin = model.snapshot?.optBoolean("isAdmin") == true
    LaunchedEffect(isAdmin) {
        if (!isAdmin && (page.startsWith("admin") || adminContext)) {
            adminContext = false
            page = "overview"
        }
    }
    BackHandler(enabled = page !in listOf("overview", "monitor", "tickets", "payments", "admin")) {
        page = if (page == "ticket") { if (adminContext) "admin_tickets" else "tickets" }
            else if (page.startsWith("admin_")) "admin" else "overview"
    }
    Scaffold(containerColor = Color.Transparent, topBar = {
        if (model.snapshot != null) TopAppBar(title = { Text("HomeVlad") }, actions = {
            if (isAdmin) IconButton(onClick = { page = "admin" }) {
                Icon(Icons.Default.Settings, contentDescription = "Админка")
            }
            if (model.snapshot != null) TextButton(onClick = { model.refresh(); revision++ }) { Text("Обновить") }
        })
    }, bottomBar = {
        val snapshot = model.snapshot
        if (snapshot != null) NavigationBar {
            val tabs = mutableListOf("overview" to "Обзор", "monitor" to "Мониторинг", "tickets" to "Поддержка", "payments" to "Финансы")
            if (snapshot.getBoolean("isAdmin")) tabs += "admin" to "Админ"
            tabs.forEach { (target, label) ->
                NavigationBarItem(selected = page == target || (target == "admin" && page.startsWith("admin_")),
                    onClick = { page = target }, label = { Text(label) },
                    icon = { Icon(when (target) {
                        "overview" -> Icons.Default.Home
                        "monitor" -> Icons.Default.Info
                        "tickets" -> Icons.Default.MailOutline
                        "payments" -> Icons.Default.ShoppingCart
                        else -> Icons.Default.Settings
                    }, contentDescription = label) })
            }
        }
    }) { padding ->
        Column(Modifier.padding(padding).fillMaxSize().background(mintGradient)) {
            if (model.loading) LinearProgressIndicator(Modifier.fillMaxWidth())
            val snapshot = model.snapshot
            if (snapshot == null) {
                NativeLogin(model, vault)
            } else when (page) {
                "overview" -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    item {
                        Text(snapshot.getString("name"), style = MaterialTheme.typography.headlineSmall)
                        Text("Баланс " + money(snapshot.getDouble("balance")))
                        Text("Бонусы " + money(snapshot.getDouble("bonusBalance")))
                        model.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                    }
                    item {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                            Text("Машины", style = MaterialTheme.typography.titleLarge)
                            FilledTonalButton(onClick = { page = "order" }) { Text("Создать ВМ / LXC") }
                        }
                    }
                    items(objects(snapshot.getJSONArray("guests"))) { guest ->
                        Card(Modifier.fillMaxWidth().clickable { selectedGuest = guest; page = "guest" }) {
                            Column(Modifier.padding(16.dp)) {
                                Text(guest.getString("name"), style = MaterialTheme.typography.titleMedium)
                                Text(status(guest.getString("status")))
                                Text("${guest.getInt("cpu")} vCPU · ${guest.getInt("ramMiB")} МБ · ${guest.getInt("diskGiB")} ГБ")
                            }
                        }
                    }
                    item {
                        if (model.biometricEnabled) TextButton(onClick = { model.disable(vault) }, enabled = !model.loading) {
                            Text("Отключить вход по отпечатку")
                        } else if (model.canEnrollBiometrics && vault.available) {
                            TextButton(onClick = { model.enroll(vault) }, enabled = !model.loading) {
                                Text("Включить вход по отпечатку")
                            }
                        }
                        TextButton(onClick = { model.logout(vault); page = "overview" }, enabled = !model.loading) { Text("Выйти") }
                    }
                }
                "monitor" -> LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(objects(snapshot.getJSONArray("nodes"))) { node ->
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text(node.getString("zone") + " / " + node.getString("name"))
                                Text(status(node.getString("status")))
                                if (node.isNull("cpu") || node.isNull("memory")) Text("Нет свежих замеров")
                                else {
                                    Text("CPU ${node.getDouble("cpu")}%")
                                    LinearProgressIndicator(progress = { (node.getDouble("cpu") / 100).toFloat().coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth())
                                    Text("Память ${node.getDouble("memory")}%")
                                    LinearProgressIndicator(progress = { (node.getDouble("memory") / 100).toFloat().coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth())
                                }
                                if (!node.isNull("networkRx") && !node.isNull("networkTx")) {
                                    val rates = String.format(Locale.getDefault(), "%.2f / %.2f", node.getDouble("networkRx"), node.getDouble("networkTx"))
                                    Text("Сеть RX / TX: $rates Мбит/с")
                                }
                                if (!node.isNull("guestInternet")) Text("Интернет из гостя: " + node.getString("guestInternet"))
                            }
                        }
                    }
                }
                "order" -> NativeOrder(model) {
                    model.refresh()
                    page = "overview"
                }
                "guest" -> selectedGuest?.let { NativeGuest(model, it, revision) }
                "payments", "admin_payments" -> NativePayments(model, page.startsWith("admin"), revision)
                "tickets", "admin_tickets" -> NativeTickets(model, page.startsWith("admin"), revision) { id, admin ->
                    selectedTicket = id; adminContext = admin; page = "ticket"
                }
                "ticket" -> NativeTicket(model, selectedTicket, adminContext, revision)
                "admin" -> if (isAdmin) NativeAdminOverview(model, revision) { page = it }
                "admin_users" -> if (isAdmin) NativeAdminUsers(model, revision)
            }
        }
    }
}

@Composable
private fun NativeLogin(model: CloudModel, vault: DeviceTokenVault) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var answer by remember { mutableStateOf("") }
    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 24.dp, vertical = 36.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(20.dp)) {
        Image(painterResource(R.drawable.app_icon), "HomeVlad", Modifier.size(84.dp))
        Text("HomeVlad", style = MaterialTheme.typography.headlineLarge)
        Text("Вход в облачный кабинет", color = MaterialTheme.colorScheme.onSurfaceVariant)
        Card(Modifier.widthIn(max = 420.dp).fillMaxWidth(), shape = RoundedCornerShape(26.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(22.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        OutlinedTextField(email, { email = it }, label = { Text("Email") }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
        OutlinedTextField(password, { password = it }, label = { Text("Пароль") }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
        model.challenge?.let {
            OutlinedTextField(answer, { answer = it }, label = { Text("Проверка · ${it.getString("captcha")} = ?") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
        }
        TextButton(onClick = { answer = ""; model.prepareLogin() }, enabled = !model.loading) { Text("Обновить CAPTCHA") }
        Button(modifier = Modifier.fillMaxWidth(), onClick = { model.login(email, password, answer, vault); password = ""; answer = "" },
            enabled = !model.loading && model.challenge != null && email.isNotBlank() && password.isNotEmpty() && answer.isNotEmpty()) {
            Text("Войти")
        }
        if (model.biometricEnabled && vault.available) {
            OutlinedButton(onClick = { model.loginBiometrically(vault) }, enabled = !model.loading && model.challenge != null) {
                Text("Войти по отпечатку")
            }
        }
        model.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        }
        }
    }
}

@Composable
private fun NativeGuest(model: CloudModel, guest: JSONObject, revision: Int) {
    val scope = rememberCoroutineScope()
    var live by remember { mutableStateOf<JSONObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var command by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(guest.getInt("id"), revision) {
        try { live = model.request("$api?resource=guest&id=${guest.getInt("id")}") }
        catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
    }
    Column(Modifier.padding(20.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(guest.getString("name"), style = MaterialTheme.typography.headlineSmall)
        Text("№${guest.getInt("vmId")} · ${guest.getString("type").uppercase()}")
        Text(status(live?.optString("status") ?: guest.getString("status")))
        val ip = guest.takeUnless { it.isNull("ipAddress") }?.optString("ipAddress").orEmpty()
        Text("Последний известный IP: ${ip.ifBlank { "Пока не определён" }}")
        Text("${guest.getInt("cpu")} vCPU · ${guest.getInt("ramMiB")} МБ RAM · ${guest.getInt("diskGiB")} ГБ")
        live?.let {
            if (!it.isNull("cpu")) Text("CPU ${it.getDouble("cpu")}%")
            if (!it.isNull("memory")) Text("Память ${it.getDouble("memory")}%")
        }
        NativeGuestGraphs(model, guest.getInt("id"), revision)
        listOf("start" to "Запустить", "shutdown" to "Выключить ОС", "reboot" to "Перезагрузить", "stop" to "Остановить принудительно")
            .forEach { (action, label) -> Button(enabled = !busy, onClick = { command = action }) { Text(label) } }
        error?.let { Text(it) }
    }
    if (command != null) AlertDialog(onDismissRequest = { command = null }, title = { Text("Управление машиной") },
        text = { Text(if (command == "stop") "Принудительная остановка может привести к потере несохранённых данных." else "Выполнить выбранную команду?") },
        confirmButton = { TextButton(onClick = {
            val selected = command ?: return@TextButton
            command = null
            scope.launch {
                busy = true
                try {
                    model.request(api, JSONObject().put("action", "guest_power").put("id", guest.getInt("id")).put("command", selected))
                    error = "Команда принята. Обновите состояние через несколько секунд."
                    model.refresh()
                } catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
                finally { busy = false }
            }
        }) { Text("Выполнить") } },
        dismissButton = { TextButton(onClick = { command = null }) { Text("Отмена") } })
}

@Composable
private fun NativePayments(model: CloudModel, admin: Boolean, revision: Int) {
    val scope = rememberCoroutineScope()
    var payments by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    var pending by remember { mutableStateOf<JSONObject?>(null) }
    var approve by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var updated by remember { mutableIntStateOf(0) }
    LaunchedEffect(admin, revision, updated) {
        try { payments = objects(model.request("$api?resource=payments&admin=${if (admin) 1 else 0}").getJSONArray("items")); error = null }
        catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
    }
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item { Text("Последние 100 платежей", style = MaterialTheme.typography.titleLarge) }
        items(payments) { payment ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(money(payment.getDouble("amount")), style = MaterialTheme.typography.titleLarge)
                    Text("#${payment.getInt("id")} · ${status(payment.getString("status"))}")
                    Text(payment.getString("createdAt"))
                    if (admin) Text(payment.getString("userName"))
                    if (admin && payment.getString("status") == "pending") Row {
                        TextButton(enabled = !busy, onClick = { approve = true; pending = payment }) { Text("Подтвердить") }
                        TextButton(enabled = !busy, onClick = { approve = false; pending = payment }) { Text("Отклонить") }
                    }
                }
            }
        }
        error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }
    }
    if (pending != null) AlertDialog(onDismissRequest = { pending = null }, title = { Text(if (approve) "Подтвердить платёж?" else "Отклонить платёж?") },
        text = { Text("#${pending!!.getInt("id")} · ${pending!!.getString("userName")} · ${money(pending!!.getDouble("amount"))}") },
        confirmButton = { TextButton(onClick = {
            val id = pending!!.getInt("id")
            val action = if (approve) "payment_approve" else "payment_reject"
            pending = null
            scope.launch {
                busy = true
                try { model.request(api, JSONObject().put("action", action).put("id", id)); updated++; model.refresh() }
                catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
                finally { busy = false }
            }
        }) { Text("Подтвердить действие") } }, dismissButton = { TextButton(onClick = { pending = null }) { Text("Отмена") } })
}

@Composable
private fun NativeTickets(model: CloudModel, admin: Boolean, revision: Int, open: (Int, Boolean) -> Unit) {
    var tickets by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var closed by remember { mutableStateOf(false) }
    var creating by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var updated by remember { mutableIntStateOf(0) }
    LaunchedEffect(admin, revision, updated, closed) {
        try { tickets = objects(model.request("$api?resource=tickets&admin=${if (admin) 1 else 0}&closed=${if (closed) 1 else 0}").getJSONArray("items")); error = null }
        catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
    }
    Column {
        Row(Modifier.padding(horizontal = 16.dp)) {
            FilterChip(selected = !closed, onClick = { closed = false }, label = { Text("Активные") })
            Spacer(Modifier.width(8.dp))
            FilterChip(selected = closed, onClick = { closed = true }, label = { Text("Закрытые") })
            Spacer(Modifier.weight(1f))
            TextButton(onClick = { creating = true }) { Text("Создать") }
        }
        LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Text("Последние 100 обращений") }
            items(tickets) { ticket ->
                Card(Modifier.fillMaxWidth().clickable { open(ticket.getInt("id"), admin) }) {
                    Column(Modifier.padding(16.dp)) {
                        Text(ticket.getString("subject"), style = MaterialTheme.typography.titleMedium)
                        Text("#${ticket.getInt("id")} · ${status(ticket.getString("status"))}")
                        if (admin) Text(ticket.getString("userName"))
                    }
                }
            }
            error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }
        }
    }
    if (creating) NativeNewTicket(model, close = { creating = false; updated++ })
}

@Composable
private fun NativeNewTicket(model: CloudModel, close: () -> Unit) {
    val scope = rememberCoroutineScope()
    var subject by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(onDismissRequest = { if (!busy) close() }, title = { Text("Новое обращение") },
        text = { Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedTextField(subject, { subject = it }, label = { Text("Тема · 5–200 символов") })
            OutlinedTextField(message, { message = it }, label = { Text("Сообщение · 20–5000 символов") }, minLines = 5)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        } },
        confirmButton = { TextButton(enabled = !busy && subject.length in 5..200 && message.length in 20..5000, onClick = {
            scope.launch {
                busy = true
                try { model.request(api, JSONObject().put("action", "ticket_create").put("subject", subject).put("message", message).put("department", "general")); close() }
                catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
                finally { busy = false }
            }
        }) { Text("Отправить") } },
        dismissButton = { TextButton(enabled = !busy, onClick = close) { Text("Отмена") } })
}

@Composable
private fun NativeTicket(model: CloudModel, id: Int, admin: Boolean, revision: Int) {
    val scope = rememberCoroutineScope()
    var thread by remember(id) { mutableStateOf<JSONObject?>(null) }
    var reply by remember(id) { mutableStateOf("") }
    var error by remember(id) { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var updated by remember { mutableIntStateOf(0) }
    LaunchedEffect(id, admin, revision, updated) {
        try { thread = model.request("$api?resource=ticket&id=$id&admin=${if (admin) 1 else 0}"); error = null }
        catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
    }
    val act: (String) -> Unit = { action ->
        scope.launch {
            busy = true
            try { model.request(api, JSONObject().put("action", action).put("id", id).put("message", reply)); reply = ""; updated++ }
            catch (exception: Exception) { if (exception is CancellationException) throw exception; error = exception.message }
            finally { busy = false }
        }
    }
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val content = thread
        if (content != null) {
            val ticket = content.getJSONObject("ticket")
            item {
                Text(ticket.getString("subject"), style = MaterialTheme.typography.headlineSmall)
                Text(status(ticket.getString("status")))
                Text(ticket.getString("message"))
                Text("Последние 200 ответов", style = MaterialTheme.typography.titleMedium)
            }
            items(objects(content.getJSONArray("replies"))) { message ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp)) {
                        Text((if (message.getBoolean("isAdmin")) "Поддержка · " else "") + message.getString("userName"))
                        Text(message.getString("message"))
                        Text(message.getString("createdAt"), style = MaterialTheme.typography.labelSmall)
                    }
                }
            }
            if (ticket.getString("status") != "closed") item {
                OutlinedTextField(reply, { reply = it }, label = { Text("Ответ · 10–2000 символов") }, minLines = 3)
                Button(enabled = !busy && reply.length in 10..2000, onClick = { act("ticket_reply") }) { Text("Отправить") }
                TextButton(enabled = !busy, onClick = { act("ticket_close") }) { Text("Закрыть обращение") }
            } else if (admin) item {
                TextButton(enabled = !busy, onClick = { act("ticket_reopen") }) { Text("Открыть обращение") }
            }
        }
        error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }
    }
}

private fun status(value: String): String = when (value) {
    "running", "online" -> "Работает"
    "offline" -> "Нет ответа"
    "stopped" -> "Остановлен"
    "suspended" -> "Приостановлен"
    "creating" -> "Создаётся"
    "pending" -> "Ожидает"
    "completed" -> "Подтверждён"
    "failed" -> "Отклонён"
    "open" -> "Открыто"
    "answered" -> "Есть ответ"
    "closed" -> "Закрыто"
    else -> "Неизвестно"
}

@Composable
private fun NativeAdminOverview(model: CloudModel, revision: Int, navigate: (String) -> Unit) {
    var summary by remember { mutableStateOf<JSONObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(revision) {
        try {
            summary = model.request("$api?resource=admin_overview")
            error = null
        } catch (exception: Exception) {
            if (exception is CancellationException) throw exception
            error = exception.message
        }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Администрирование", style = MaterialTheme.typography.headlineSmall)
        summary?.let {
            Text("${it.getInt("active_users")} активных пользователей из ${it.getInt("users")}")
            Text("Зоны доступности: ${it.getInt("zones")}")
            Text("Активные ноды: ${it.getInt("nodes")}")
            Text("Платежи к проверке: ${it.getInt("pending_payments")}")
            Text("Открытые обращения: ${it.getInt("open_tickets")}")
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        TextButton(onClick = { navigate("admin_users") }) { Text("Пользователи") }
        TextButton(onClick = { navigate("admin_payments") }) { Text("Платежи пользователей") }
        TextButton(onClick = { navigate("admin_tickets") }) { Text("Все обращения") }
    }
}

@Composable
private fun NativeAdminUsers(model: CloudModel, revision: Int) {
    val scope = rememberCoroutineScope()
    var users by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var next by remember { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    suspend fun load(before: Int? = null) {
        if (busy) return
        busy = true
        try {
            val suffix = before?.let { "&before=$it" }.orEmpty()
            val result = model.request("$api?resource=admin_users$suffix")
            val page = objects(result.getJSONArray("items"))
            users = if (before == null) page else users + page
            next = if (result.isNull("next")) null else result.getInt("next")
            error = null
        } catch (exception: Exception) {
            if (exception is CancellationException) throw exception
            error = exception.message
        } finally { busy = false }
    }
    LaunchedEffect(revision) { load() }
    LazyColumn(contentPadding = PaddingValues(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item { Text("Пользователи", style = MaterialTheme.typography.headlineSmall) }
        items(users, key = { it.getInt("id") }) { user ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    Text(user.getString("name"), style = MaterialTheme.typography.titleMedium)
                    Text(user.getString("email"))
                    val role = if (user.getBoolean("isAdmin")) "Администратор" else "Пользователь"
                    val activity = if (user.getBoolean("isActive")) "Активен" else "Неактивен"
                    Text("$role · $activity", style = MaterialTheme.typography.bodySmall)
                }
            }
        }
        if (busy) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        next?.let { cursor -> item {
            TextButton(onClick = { scope.launch { load(cursor) } }, enabled = !busy) { Text("Загрузить ещё") }
        } }
        error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }
    }
}

private data class GuestOrderOption(val id: String, val label: String, val os: String?)

private fun orderOptions(data: JSONObject?, key: String): List<GuestOrderOption> {
    val array = data?.optJSONArray(key) ?: return emptyList()
    return (0 until array.length()).mapNotNull { index ->
        array.optJSONObject(index)?.let {
            GuestOrderOption(it.getString("id"), it.getString("label"), it.optString("os_type").ifBlank { null })
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OrderDropdown(label: String, selected: String, choices: List<GuestOrderOption>, onSelect: (String) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded, { expanded = it && choices.isNotEmpty() }) {
        OutlinedTextField(choices.firstOrNull { it.id == selected }?.label.orEmpty(), {}, readOnly = true,
            enabled = choices.isNotEmpty(), label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
            modifier = Modifier.menuAnchor().fillMaxWidth())
        ExposedDropdownMenu(expanded, { expanded = false }) {
            choices.forEach { item ->
                DropdownMenuItem({ Text(item.label) }, { onSelect(item.id); expanded = false })
            }
        }
    }
}

@Composable
private fun NativeOrder(model: CloudModel, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var type by remember { mutableStateOf("qemu") }
    var catalogue by remember { mutableStateOf<JSONObject?>(null) }
    var resources by remember { mutableStateOf<JSONObject?>(null) }
    var hostname by remember { mutableStateOf("") }
    var zone by remember { mutableStateOf("") }
    var network by remember { mutableStateOf("") }
    var storage by remember { mutableStateOf("") }
    var image by remember { mutableStateOf("") }
    var tariff by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var submitting by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    var created by remember { mutableStateOf(false) }
    BackHandler(enabled = submitting) {}
    LaunchedEffect(type) {
        try { catalogue = model.request("$orderApi?vm_type=$type") }
        catch (error: Exception) {
            if (error is CancellationException) throw error
            message = error.message
        }
    }
    LaunchedEffect(type, zone) {
        if (zone.isBlank()) return@LaunchedEffect
        loading = true
        try { resources = model.request("/templates/get_zone_placement.php?zone_id=$zone&vm_type=$type") }
        catch (error: Exception) {
            if (error is CancellationException) throw error
            message = error.message
        } finally { loading = false }
    }
    val images = orderOptions(resources, if (type == "qemu") "cloud" else "media")
    val os = images.firstOrNull { it.id == image }?.os
    val plans = orderOptions(catalogue, "tariffs").filter { os == null || it.os == null || it.os == os }
    val canSubmit = !loading && !submitting && hostname.isNotBlank() && zone.isNotBlank() &&
        network.isNotBlank() && storage.isNotBlank() && image.isNotBlank() && plans.any { it.id == tariff }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Создание машины", style = MaterialTheme.typography.headlineSmall)
        if (created) {
            Text("Гость создан")
            message?.let { Text(it) }
            Button(onClick = onDone) { Text("К списку машин") }
            return@Column
        }
        if (!submitting) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                listOf("qemu" to "Виртуальная машина", "lxc" to "LXC").forEach { (id, label) ->
                    FilterChip(type == id, onClick = {
                        if (type != id) {
                            type = id; catalogue = null; resources = null; zone = ""; network = ""
                            storage = ""; image = ""; tariff = ""; message = null
                        }
                    }, label = { Text(label) })
                }
            }
            OutlinedTextField(hostname, { hostname = it }, label = { Text("Имя хоста") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OrderDropdown("Зона доступности", zone, orderOptions(catalogue, "zones")) {
                if (zone != it) { zone = it; resources = null; network = ""; storage = ""; image = ""; tariff = ""; message = null }
            }
            if (zone.isNotBlank()) {
                if (loading) LinearProgressIndicator(Modifier.fillMaxWidth())
                OrderDropdown("Пул сети", network, orderOptions(resources, "network")) { network = it }
                OrderDropdown("Пул хранилищ", storage, orderOptions(resources, "storage")) { storage = it }
                OrderDropdown(if (type == "qemu") "Cloud-образ" else "Шаблон LXC", image, images) { image = it; tariff = "" }
                OrderDropdown("Тариф", tariff, plans) { tariff = it }
                if (resources != null && (images.isEmpty() || orderOptions(resources, "network").isEmpty() || orderOptions(resources, "storage").isEmpty())) {
                    Text("Для этого типа гостя в зоне доступны не все ресурсы. Выберите другую зону.")
                }
            }
            Text("Пароль создаст сервер. Его можно посмотреть в веб-кабинете в течение 24 часов.", style = MaterialTheme.typography.bodySmall)
            Button(onClick = { confirm = true }, enabled = canSubmit, modifier = Modifier.fillMaxWidth()) { Text("Создать") }
        } else {
            LinearProgressIndicator(Modifier.fillMaxWidth())
            Text("Создаём гостя…")
        }
        message?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
    if (confirm) AlertDialog(
        onDismissRequest = { confirm = false }, title = { Text("Подтвердить заказ") },
        text = { Text(plans.firstOrNull { it.id == tariff }?.label.orEmpty()) },
        confirmButton = {
            TextButton(onClick = {
                confirm = false
                if (!canSubmit) return@TextButton
                submitting = true; message = null
                scope.launch {
                    try {
                        val response = model.request(orderApi, JSONObject().put("vm_type", type).put("zone_id", zone)
                            .put("hostname", hostname).put("network_pool", network).put("storage_pool", storage)
                            .put("tariff_id", tariff).put(if (type == "qemu") "cloud_image" else "template", image))
                        created = response.getBoolean("success")
                        message = if (response.isNull("warning")) null else response.optString("warning")
                    } catch (error: Exception) {
                        if (error is CancellationException) throw error
                        message = if (error is CloudHttpException && error.status == 422) error.message
                            else "Заказ не подтверждён. Проверьте список машин перед повтором."
                    } finally { submitting = false }
                }
            }) { Text("Создать") }
        }, dismissButton = { TextButton(onClick = { confirm = false }) { Text("Отмена") } }
    )
}

@Composable
private fun NativeGuestGraphs(model: CloudModel, guestId: Int, revision: Int) {
    val periods = listOf("hour" to "Час", "day" to "День", "week" to "Неделя", "month" to "Месяц", "year" to "Год")
    var period by remember { mutableStateOf("hour") }
    var metrics by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(guestId, period, revision) {
        loading = true; error = null
        try { metrics = objects(model.request("$api?resource=guest_metrics&id=$guestId&period=$period").getJSONArray("items")) }
        catch (failure: Exception) {
            if (failure is CancellationException) throw failure
            error = failure.message
        } finally { loading = false }
    }
    Text("Графики", style = MaterialTheme.typography.titleMedium)
    OrderDropdown("Период", period, periods.map { GuestOrderOption(it.first, it.second, null) }) { period = it }
    if (loading) LinearProgressIndicator(Modifier.fillMaxWidth())
    else if (error != null) Text(error.orEmpty(), color = MaterialTheme.colorScheme.error)
    else {
        MetricChart("CPU и память · %", "CPU" to "cpu", "Память" to "memory", metrics)
        MetricChart("Сеть · Мбит/с", "Вход" to "netIn", "Выход" to "netOut", metrics)
        MetricChart("Диск · МиБ/с", "Чтение" to "diskRead", "Запись" to "diskWrite", metrics)
    }
}

@Composable
private fun MetricChart(title: String, first: Pair<String, String>, second: Pair<String, String>, points: List<JSONObject>) {
    val colors = listOf(mint, Color(0xFF4364B5))
    val series = listOf(first, second)
    val values = series.map { (_, key) -> points.map { it.optDouble(key).takeIf(Double::isFinite) } }
    val available = values.flatten().filterNotNull()
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Text(title, style = MaterialTheme.typography.titleSmall)
            if (available.isEmpty()) {
                Text("Нет замеров за этот период", style = MaterialTheme.typography.bodySmall)
            } else {
                val maxValue = available.max().coerceAtLeast(0.01)
                val start = points.first().getLong("time")
                val span = (points.last().getLong("time") - start).coerceAtLeast(1)
                Canvas(Modifier.fillMaxWidth().height(112.dp).semantics { contentDescription = title }) {
                    listOf(0.25f, 0.5f, 0.75f, 1f).forEach { tick ->
                        drawLine(Color(0xFFD0E7DE), Offset(0f, size.height * tick), Offset(size.width, size.height * tick))
                    }
                    values.forEachIndexed { index, row ->
                        var previous: Offset? = null
                        row.forEachIndexed { point, value ->
                            val next = value?.let {
                                Offset(((points[point].getLong("time") - start).toDouble() / span).toFloat() * size.width,
                                    size.height * (1f - (it / maxValue).toFloat().coerceIn(0f, 1f)))
                            }
                            if (next != null) {
                                previous?.let { drawLine(colors[index], it, next, strokeWidth = 2.dp.toPx()) }
                                    ?: drawCircle(colors[index], 2.dp.toPx(), next)
                            }
                            previous = next
                        }
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                    series.forEachIndexed { index, item -> Text(item.first, color = colors[index], style = MaterialTheme.typography.labelMedium) }
                }
                Text(String.format(Locale.getDefault(), "Шкала 0–%.2f", maxValue), style = MaterialTheme.typography.labelSmall)
            }
        }
    }
}
