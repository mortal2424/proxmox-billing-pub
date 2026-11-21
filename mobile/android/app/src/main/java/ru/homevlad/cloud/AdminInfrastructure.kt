package ru.homevlad.cloud

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.net.URLEncoder

internal const val mobileAdminApi = "/api/mobile_admin.php"

private fun JSONArray.rows() = (0 until length()).map(::getJSONObject)

@Composable
internal fun AdminGuestList(model: CloudModel, revision: Int, open: (JSONObject) -> Unit) {
    var search by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("") }
    var retry by remember { mutableIntStateOf(0) }
    var items by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var next by remember { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var generation by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()

    suspend fun load(before: Int? = null) {
        val request = ++generation
        busy = true
        error = null
        try {
            val suffix = before?.let { "&before=$it" }.orEmpty()
            val q = URLEncoder.encode(search.trim(), "UTF-8")
            val data = model.request("$mobileAdminApi?resource=guests&q=$q&type=$type$suffix")
            if (request == generation) {
                val page = data.getJSONArray("items").rows()
                items = if (before == null) page else items + page
                next = data.optInt("next").takeIf { it > 0 }
            }
        } catch (exception: Exception) {
            if (exception is CancellationException) throw exception
            if (request == generation) error = exception.localizedMessage
        } finally { if (request == generation) busy = false }
    }

    LaunchedEffect(search, type, revision, retry) {
        generation++
        items = emptyList()
        next = null
        busy = true
        delay(250)
        load()
    }
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item { Text("ВМ и LXC", style = MaterialTheme.typography.headlineSmall) }
        item { OutlinedTextField(search, { search = it.take(100) }, Modifier.fillMaxWidth(),
            label = { Text("Имя, VMID или пользователь") }, singleLine = true) }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("" to "Все", "qemu" to "ВМ", "lxc" to "LXC").forEach { (kind, label) ->
                    FilterChip(selected = kind == type, onClick = { type = kind }, label = { Text(label) })
                }
            }
        }
        items(items, key = { it.getInt("id") }) { guest ->
            Card(onClick = { open(guest) }, modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(guest.getString("name"), style = MaterialTheme.typography.titleMedium)
                    Text("${guest.getString("type").uppercase()} · №${guest.getInt("vmId")} · ${guestStatus(guest.getString("status"))}")
                    Text(listOf("owner", "zone", "node").mapNotNull { key ->
                        guest.optString(key).takeIf { !guest.isNull(key) && it.isNotEmpty() }
                    }.joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                }
            }
        }
        if (busy) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        error?.let { item {
            Text(it, color = MaterialTheme.colorScheme.error)
            TextButton(onClick = { retry++ }) { Text("Повторить") }
        } }
        if (items.isEmpty() && !busy && error == null) item { Text("Машины не найдены") }
        next?.let { cursor -> item { TextButton(onClick = { scope.launch { load(cursor) } }, enabled = !busy) {
            Text("Загрузить ещё")
        } } }
    }
}

private fun guestStatus(status: String) = when (status) {
    "running" -> "Работает"
    "stopped" -> "Остановлена"
    "creating" -> "Создаётся"
    "suspended" -> "Приостановлена"
    "error" -> "Ошибка"
    else -> "Неизвестно"
}

@Composable
internal fun AdminInfrastructure(model: CloudModel, revision: Int) {
    var data by remember { mutableStateOf<JSONObject?>(null) }
    var editor by remember { mutableStateOf<Pair<String, JSONObject>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var update by remember { mutableIntStateOf(0) }
    LaunchedEffect(revision, update) {
        try {
            data = model.request("$mobileAdminApi?resource=infrastructure")
            error = null
        } catch (exception: Exception) {
            if (exception is CancellationException) throw exception
            error = exception.localizedMessage
        }
    }

    val zones = data?.getJSONArray("zones")?.rows().orEmpty()
    val nodes = data?.getJSONArray("nodes")?.rows().orEmpty()
    val open = editor
    BackHandler(enabled = open != null) { editor = null }
    if (open != null) {
        InfrastructureForm(model, open.first, open.second, zones,
            close = { editor = null },
            saved = { message, completed ->
                notice = message
                update++
                if (completed) editor = null
            })
        return
    }
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        notice?.let { item { Text(it) } }
        error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }
        if (data == null && error == null) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        if (data != null) {
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically) {
                    Text("Зоны доступности", style = MaterialTheme.typography.titleLarge)
                    TextButton(onClick = { editor = "zone" to JSONObject() }) { Text("Добавить") }
                }
            }
            items(zones, key = { "zone-${it.getInt("id")}" }) { zone ->
                Card(onClick = { editor = "zone" to zone }, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(zone.getString("name"), style = MaterialTheme.typography.titleMedium)
                        Text(when {
                            zone.optBoolean("maintenance") -> "Обслуживание"
                            zone.optBoolean("active") -> "Активна"
                            else -> "Выключена"
                        })
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically) {
                    Text("Ноды Proxmox", style = MaterialTheme.typography.titleLarge)
                    TextButton(onClick = { editor = "node" to JSONObject() },
                        enabled = zones.any { it.optBoolean("active") }) { Text("Добавить") }
                }
            }
            items(nodes, key = { "node-${it.getInt("id")}" }) { node ->
                Card(onClick = { editor = "node" to node }, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(node.getString("name"), style = MaterialTheme.typography.titleMedium)
                        val zone = zones.firstOrNull { it.optInt("id") == node.optInt("zoneId") }?.optString("name")
                        val state = if (!node.optBoolean("active")) "Выключена" else when (node.optString("status")) {
                            "online" -> "Доступна"
                            "offline" -> "Недоступна"
                            else -> "Статус неизвестен"
                        }
                        Text(listOfNotNull(zone, state).joinToString(" · "))
                    }
                }
            }
        }
    }
}

@Composable
private fun InfrastructureForm(model: CloudModel, kind: String, item: JSONObject, zones: List<JSONObject>,
    close: () -> Unit, saved: (String?, Boolean) -> Unit) {
    val scope = rememberCoroutineScope()
    val node = kind == "node"
    val id = item.optInt("id")
    var name by remember { mutableStateOf(item.optString("name")) }
    var description by remember { mutableStateOf(item.optString("description")) }
    var active by remember { mutableStateOf(item.optBoolean("active", true)) }
    var maintenance by remember { mutableStateOf(item.optBoolean("maintenance")) }
    var limit by remember { mutableStateOf(item.optInt("limit", 50).toString()) }
    var threshold by remember { mutableStateOf(item.optInt("threshold", 80).toString()) }
    var zoneId by remember { mutableIntStateOf(item.optInt("zoneId")) }
    var host by remember { mutableStateOf(item.optString("host")) }
    var port by remember { mutableStateOf(item.optInt("port", 8006).toString()) }
    var token by remember { mutableStateOf(item.optString("tokenId")) }
    var secret by remember { mutableStateOf("") }
    var orders by remember { mutableStateOf(item.optBoolean("orders")) }
    var primary by remember { mutableStateOf(item.optBoolean("primary")) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    fun perform(action: String) {
        if (busy) return
        busy = true
        scope.launch {
            try {
                val body = JSONObject().put("action", action).put("id", id)
                if (action.endsWith("_save")) {
                    body.put("name", name).put("description", description).put("active", active)
                    if (node) body.put("zoneId", zoneId).put("host", host).put("port", port)
                        .put("tokenId", token).put("secret", secret).put("orders", orders).put("primary", primary)
                    else body.put("limit", limit).put("threshold", threshold).put("maintenance", maintenance)
                }
                val response = model.request(mobileAdminApi, body)
                error = null
                notice = response.optString("notice").ifBlank { "Настройки сохранены" }
                val completed = action.endsWith("_save")
                if (completed) secret = ""
                saved(notice, completed)
            } catch (exception: Exception) {
                if (exception is CancellationException) throw exception
                error = exception.localizedMessage
            } finally { busy = false }
        }
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)) {
        TextButton(onClick = close, enabled = !busy) { Text("Назад к списку") }
        Text(when {
            node && id == 0 -> "Новая нода"
            node -> "Настройки ноды"
            id == 0 -> "Новая зона"
            else -> "Настройки зоны"
        }, style = MaterialTheme.typography.headlineSmall)
        if (node) {
            var menu by remember { mutableStateOf(false) }
            Box {
                OutlinedButton(onClick = { menu = true }, enabled = !busy) {
                    Text(zones.firstOrNull { it.optInt("id") == zoneId }?.optString("name") ?: "Выберите зону")
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    zones.filter { it.optBoolean("active") || it.optInt("id") == zoneId }.forEach { zone ->
                        DropdownMenuItem(text = { Text(zone.getString("name")) }, onClick = {
                            zoneId = zone.getInt("id"); menu = false
                        })
                    }
                }
            }
        }
        OutlinedTextField(name, { name = it }, Modifier.fillMaxWidth(), enabled = !busy,
            label = { Text(if (node) "Имя ноды" else "Название зоны") }, singleLine = true)
        if (node) {
            OutlinedTextField(host, { host = it }, Modifier.fillMaxWidth(), enabled = !busy,
                label = { Text("Адрес API") }, singleLine = true)
            AdminNumberField("Порт API", port, { port = it }, !busy)
            OutlinedTextField(token, { token = it }, Modifier.fillMaxWidth(), enabled = !busy,
                label = { Text("ID API-токена") }, singleLine = true)
            OutlinedTextField(secret, { secret = it }, Modifier.fillMaxWidth(), enabled = !busy,
                label = { Text(if (id == 0) "Секрет API-токена" else "Новый секрет API-токена") },
                visualTransformation = PasswordVisualTransformation(), singleLine = true)
            AdminFlag("Принимать новые заказы", orders, { orders = it }, !busy)
            AdminFlag("Главная нода", primary, { primary = it }, !busy)
        } else {
            AdminNumberField("Лимит гостей на ноду", limit, { limit = it }, !busy)
            AdminNumberField("Порог нагрузки, %", threshold, { threshold = it }, !busy)
            AdminFlag("Режим обслуживания", maintenance, { maintenance = it }, !busy)
        }
        AdminFlag("Активна", active, { active = it }, !busy)
        OutlinedTextField(description, { description = it }, Modifier.fillMaxWidth(), enabled = !busy,
            label = { Text("Описание") })
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (error == null) notice?.let { Text(it) }
        Button(onClick = { perform("${kind}_save") },
            enabled = !busy && name.isNotBlank() && (!node ||
                (zoneId > 0 && host.isNotBlank() && token.isNotBlank() && (id > 0 || secret.isNotBlank())))) { Text("Сохранить") }
        if (node && id > 0) {
            if (!item.isNull("lastCheck")) Text("Последняя проверка · " + item.optString("lastCheck"))
            OutlinedButton(onClick = { perform("node_check") }, enabled = !busy) { Text("Проверить API") }
            OutlinedButton(onClick = { perform("node_import") }, enabled = !busy) { Text("Обновить список нод кластера") }
        }
    }
}

@Composable
private fun AdminFlag(label: String, value: Boolean, change: (Boolean) -> Unit, enabled: Boolean) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = value, onCheckedChange = change, enabled = enabled)
    }
}

@Composable
private fun AdminNumberField(label: String, value: String, change: (String) -> Unit, enabled: Boolean) {
    OutlinedTextField(value, { if (it.length <= 5 && it.all(Char::isDigit)) change(it) },
        Modifier.fillMaxWidth(), enabled = enabled, label = { Text(label) },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), singleLine = true)
}
